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

/**
 * @return array<string, array{label:string, url:string, short:string, logo:string}>
 *   short 是纯文字兜底（logo 文件缺失时仍能显示一个字）
 *   logo  是本地图标文件，相对站点根目录
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
        ],
        'google' => [
            'label' => 'Google',
            'short' => 'G',
            'logo'  => 'assets/engines/google.svg',
            'url'   => 'https://www.google.com/search?q=',
        ],
        'baidu' => [
            'label' => '百度',
            'short' => '百',
            'logo'  => 'assets/engines/baidu.svg',
            'url'   => 'https://www.baidu.com/s?wd=',
        ],
        'ddg' => [
            'label' => 'DuckDuckGo',
            'short' => 'D',
            'logo'  => 'assets/engines/duckduckgo.svg',
            'url'   => 'https://duckduckgo.com/?q=',
        ],
        'sogou' => [
            'label' => '搜狗',
            'short' => '搜',
            'logo'  => 'assets/engines/sogou.svg',
            'url'   => 'https://www.sogou.com/web?query=',
        ],
        'so360' => [
            'label' => '360 搜索',
            'short' => '3',
            'logo'  => 'assets/engines/so360.png',
            'url'   => 'https://www.so.com/s?q=',
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
