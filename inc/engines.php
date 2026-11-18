<?php
/**
 * 搜索引擎清单 —— 单一真源。
 *
 * 首页顶栏的引擎切换、后台的默认引擎下拉，都从这里取。
 * 以前 key 和 URL 在 index.php 与 admin.php 各写一遍，加一个引擎
 * 要改两处，漏一处就出现「后台能选但前台不生效」的怪事。
 *
 * 新增引擎只需在这里加一条（含一个本地 logo 文件）。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

/**
 * @return array<string, array{label:string, url:string, param:string, short:string, logo:string}>
 *   short 是纯文字兜底（logo 文件缺失时仍能显示一个字）
 *   logo  是本地图标文件，相对站点根目录
 *   param 是查询参数名 —— 各家不一样：百度 wd、搜狗 query，其余都是 q。
 *         GET 表单提交时 action 里自带的查询串会被整体丢弃（浏览器规范行为），
 *         真正决定参数名的只有 input 的 name，所以必须逐家配，不能写死 q。
 *         曾经写死 q，百度收到 /s?q=… 不认，直接落到空白页。
 *
 * 为什么用各家官方 logo 文件，而不是自己描一个近似标记：
 *   V1.4.0 之前这里是手绘的 SVG（四条 path 拼出来的 G、五个圆点拼出来的熊掌）。
 *   它的三个理由（零第三方请求 / 避免位图 / 小尺寸够认）在「不联网」上是对的，
 *   但代价是——那不是 Google 的 G，是一个像 G 的形状。用户一眼就能看出别扭，
 *   而且越是天天见的品牌，越经不起近似。
 *
 *   现在改成：官方 logo 一次下载、存在 assets/engines/，仍然零第三方请求，
 *   但形状是官方的。矢量文件（google / bing / duckduckgo / baidu / sogou）
 *   在任意 DPR 下都锐利；只有 360 一家是官方 favicon 位图（32×32），
 *   因为它的官方矢量源拿不到。
 *
 *   授权说明：这些是各家的商标，用于「指向该服务的链接图标」属于通常的
 *   指示性使用；本程序不分发它们的品牌资产作为自身标识。
 */
function hp_engines(): array
{
    return [
        'bing' => [
            'label' => 'Bing',
            'short' => 'B',
            'logo'  => 'assets/engines/bing.svg',
            'url'   => 'https://www.bing.com/search?q=',
            'param' => 'q',
        ],
        'google' => [
            'label' => 'Google',
            'short' => 'G',
            'logo'  => 'assets/engines/google.svg',
            'url'   => 'https://www.google.com/search?q=',
            'param' => 'q',
        ],
        'baidu' => [
            'label' => '百度',
            'short' => '百',
            'logo'  => 'assets/engines/baidu.svg',
            'url'   => 'https://www.baidu.com/s?wd=',
            'param' => 'wd',
        ],
        'ddg' => [
            'label' => 'DuckDuckGo',
            'short' => 'D',
            'logo'  => 'assets/engines/duckduckgo.svg',
            'url'   => 'https://duckduckgo.com/?q=',
            'param' => 'q',
        ],
        'sogou' => [
            'label' => '搜狗',
            'short' => '搜',
            'logo'  => 'assets/engines/sogou.svg',
            'url'   => 'https://www.sogou.com/web?query=',
            'param' => 'query',
        ],
        'so360' => [
            'label' => '360 搜索',
            'short' => '3',
            'logo'  => 'assets/engines/so360.png',
            'url'   => 'https://www.so.com/s?q=',
            'param' => 'q',
        ],
    ];
}

function hp_engine_keys(): array
{
    return array_keys(hp_engines());
}

/** 非法值退回 bing，避免拼出一个空 action 的表单 */
function hp_engine_safe(?string $v): string
{
    $v = (string)$v;
    return in_array($v, hp_engine_keys(), true) ? $v : 'bing';
}

/**
 * 渲染引擎图标。两处（按钮 / 下拉项）共用，避免各写一份 <img>。
 *
 * alt 放的是单字简称而不是引擎全名：外层容器已有 aria-hidden，
 * 这个 alt 只在一个情况下会被看到 —— logo 文件丢了，浏览器把 alt
 * 画出来顶着，那时一个字比「Google」四个字更塞得进 22px 的格子。
 */
function hp_engine_logo_html(string $key, int $size = 20): string
{
    $e = hp_engines()[$key] ?? null;
    if ($e === null) {
        return '';
    }
    return '<img class="eng-img" src="' . h($e['logo']) . '" alt="' . h($e['short']) . '" '
         . 'width="' . $size . '" height="' . $size . '" '
         . 'decoding="async" draggable="false">';
}

/* ==========================================================================
   搜索建议（suggest）
   --------------------------------------------------------------------------
   为什么必须由服务端代取：
     ① 本站 CSP 是 `connect-src 'self'` / `script-src 'self'`，浏览器直连
        第三方接口（fetch 或 JSONP）会被自己的 CSP 拦掉；
     ② 各家跨域策略不统一：百度、360 只给 JSONP，Bing 才给 CORS；
     ③ 放服务端才能做缓存与限流，一次输入只打一次上游。

   隐私：输入的内容会原样发给对应搜索引擎 —— 这本来就是「搜索建议」的含义，
   不输入就不发请求。缓存只存「关键词 → 建议词」，10 分钟过期。

   为什么写进这个文件而不是新建 inc/suggest.php：
   线上只有覆盖已有文件这一条路（这套 1Panel 链路建不了新文件），
   新建文件等于部署不上，只能塞进同主题的已有文件。
   ========================================================================== */

/**
 * 取某个引擎的搜索建议。
 *
 * @return array{items: list<string>}
 */
function hp_suggest(string $engine, string $q): array
{
    $q = trim(str_replace(["\r", "\n", "\t", "\0"], ' ', $q));
    if ($q === '' || mb_strlen($q) > 48) {
        return ['items' => []];
    }

    /* 上游清单与解析方式。只有百度 / Bing / 360 实测稳定；
       Google / DuckDuckGo 在部分机房不可达，搜狗的公开接口会回空 ——
       都留着位置，拿不到就是空数组，前端不显示下拉，不影响搜索本身。 */
    $map = [
        'baidu'  => ['https://www.baidu.com/sugrec?pre=1&p=3&ie=utf-8&json=1&prod=pc&wd=%s', 'baidu'],
        'bing'   => ['https://api.bing.com/osjson.aspx?query=%s', 'bing'],
        'so360'  => ['https://sug.so.360.cn/suggest?word=%s', 'so360'],
        'google' => ['https://suggestqueries.google.com/complete/search?client=firefox&q=%s', 'list2'],
        'ddg'    => ['https://duckduckgo.com/ac/?type=list&q=%s', 'ddg'],
        'sogou'  => ['https://www.sogou.com/suggnew/ajajjson?type=web&key=%s', 'list2'],
    ];
    if (!isset($map[$engine])) {
        return ['items' => []];
    }
    [$tpl, $kind] = $map[$engine];

    $dir  = HP_ROOT . '/data/suggest';
    $file = $dir . '/' . md5($engine . '|' . $q) . '.json';

    if (is_file($file) && time() - (int)@filemtime($file) < 600) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached)) {
            return ['items' => $cached];
        }
    }

    /* 限流只在缓存未命中时算：正常打字每个词只打一次上游，
       刷子会被这 40 次/分钟挡住，返回空而不是报错（前端静默即可）。 */
    if (!hp_rate_allow('sug:' . hp_client_ip(), 40, 60)) {
        return ['items' => []];
    }

    $ch = curl_init(sprintf($tpl, rawurlencode($q)));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/javascript, */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200 || strlen((string)$body) > 65536) {
        return ['items' => []];
    }

    $j = json_decode((string)$body, true);
    $raw = [];
    if (is_array($j)) {
        if ($kind === 'baidu' && isset($j['g']) && is_array($j['g'])) {
            foreach ($j['g'] as $it) {
                $raw[] = (string)($it['q'] ?? '');
            }
        } elseif ($kind === 'so360' && isset($j['result']) && is_array($j['result'])) {
            foreach ($j['result'] as $it) {
                $raw[] = (string)($it['word'] ?? '');
            }
        } elseif ($kind === 'bing' && isset($j[1]) && is_array($j[1])) {
            foreach ($j[1] as $s) {
                $raw[] = (string)$s;
            }
        } elseif ($kind === 'list2' && isset($j[1]) && is_array($j[1])) {
            foreach ($j[1] as $s) {
                $raw[] = is_array($s) ? (string)($s[0] ?? '') : (string)$s;
            }
        } elseif ($kind === 'ddg' && is_array($j)) {
            foreach ($j as $it) {
                $raw[] = is_string($it) ? $it : (string)($it['phrase'] ?? '');
            }
        }
    }

    $items = [];
    foreach ($raw as $s) {
        $s = trim((string)preg_replace('~\s+~u', ' ', $s));
        if ($s === '' || mb_strlen($s) > 60 || isset($items[$s])) {
            continue;
        }
        $items[$s] = true;
        if (count($items) >= 8) {
            break;
        }
    }
    $list = array_keys($items);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (random_int(1, 80) === 1) {
            hp_suggest_gc($dir);
        }
    }

    return ['items' => $list];
}

/** 缓存文件攒到 400 个以上就删掉最旧的 200 个，别让 data/ 无限长。 */
function hp_suggest_gc(string $dir): void
{
    $files = glob($dir . '/*.json');
    if (!is_array($files) || count($files) < 400) {
        return;
    }
    usort($files, static fn(string $a, string $b): int => (int)@filemtime($a) <=> (int)@filemtime($b));
    foreach (array_slice($files, 0, 200) as $f) {
        @unlink($f);
    }
}
