<?php
/**
 * 访问统计 —— 采集与归一化。
 *
 * 设计原则：
 *  1. 记录失败绝不影响页面输出。整段包 try/catch，异常直接吞掉。
 *  2. 不做按天聚合表。明细留在 visits/clicks 两张表里，聚合靠 SQL。
 *     个人主页量级（万级行）下 COUNT/GROUP BY 走索引都是毫秒级，
 *     多一张聚合表就多一处可能对不上的真相。
 *  3. IP 明文存（用户明确要看 IP 地址），同时存一份 hash 用于去重计数。
 */
declare(strict_types=1);

/**
 * 取访客 IP。
 * 只在显式开启 trust_proxy 时才看 X-Forwarded-For —— 否则任何人都能
 * 伪造这个头来伪造统计与绕过访问控制。默认只信 REMOTE_ADDR。
 */
function hp_client_ip(): string
{
    global $CONFIG;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!empty($CONFIG['trust_proxy'])) {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            // 取最左边第一个合法地址（代理链里最靠近真实客户端）
            foreach (explode(',', $fwd) as $part) {
                $cand = trim($part);
                if (filter_var($cand, FILTER_VALIDATE_IP)) { $ip = $cand; break; }
            }
        }
        $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
            $ip = $real;
        }
    }

    $ip = trim($ip);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** IPv4 转无符号整数；IPv6 或非法值返回 null（地理库只收 IPv4） */
function hp_ip_int(string $ip): ?int
{
    if ($ip === '' || str_contains($ip, ':')) {
        return null;
    }
    $n = ip2long($ip);
    if ($n === false) {
        return null;
    }
    // 32 位 PHP 上 ip2long 可能返回负数，转成无符号
    return $n < 0 ? $n + 4294967296 : $n;
}

/** 用于 UV 去重的稳定哈希（带站点密钥，换个站就不通用） */
function hp_ip_hash(string $ip): string
{
    global $CONFIG;
    return substr(hash('sha256', $ip . '|' . ($CONFIG['secret'] ?? '')), 0, 16);
}

/**
 * 从 UA 里拆浏览器 / 系统 / 设备类型。
 * 不求精确，只求「一眼能分类」。顺序敏感：
 * Edge 的 UA 里同时含 Chrome 与 Safari，Chrome 里含 Safari，
 * 所以必须从最具体的往下排。
 */
function hp_ua_parse(string $ua): array
{
    $browser = '其他';
    $os      = '其他';
    $device  = '桌面';

    if ($ua !== '') {
        $map = [
            'Edge'      => '~Edg(?:e|A|iOS)?/~',
            'Opera'     => '~OPR/|Opera~',
            'Samsung'   => '~SamsungBrowser~',
            'Firefox'   => '~Firefox/|FxiOS~',
            'Chrome'    => '~Chrome/|CriOS~',
            'Safari'    => '~Safari/~',
            'IE'        => '~MSIE |Trident/~',
            '微信'       => '~MicroMessenger~',
            'QQ浏览器'   => '~QQBrowser~',
            'UC'        => '~UCBrowser~',
            '夸克'       => '~Quark~',
            '百度'       => '~baidubrowser|BIDUBrowser~',
            'Safari(移动)' => '~Mobile/.*Safari~',
        ];
        foreach ($map as $name => $re) {
            if (preg_match($re, $ua)) { $browser = $name; break; }
        }

        if (preg_match('~Windows NT 10~', $ua))      $os = 'Windows 10/11';
        elseif (preg_match('~Windows NT 6\.3~', $ua)) $os = 'Windows 8.1';
        elseif (preg_match('~Windows~', $ua))         $os = 'Windows';
        elseif (preg_match('~Android~', $ua))         $os = 'Android';
        elseif (preg_match('~iPhone|iPod~', $ua))     $os = 'iOS';
        elseif (preg_match('~iPad~', $ua))            $os = 'iPadOS';
        elseif (preg_match('~Mac OS X~', $ua))        $os = 'macOS';
        elseif (preg_match('~CrOS~', $ua))            $os = 'ChromeOS';
        elseif (preg_match('~Linux~', $ua))           $os = 'Linux';

        if (preg_match('~iPad|Tablet~', $ua))                $device = '平板';
        elseif (preg_match('~Mobile|Android|iPhone|iPod~', $ua)) $device = '手机';

        // 微信内置浏览器一律算手机（UA 里未必有 Mobile）
        if ($browser === '微信' && $device === '桌面') $device = '手机';
    }

    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}

/**
 * 来源分类。只解析 referer 的域名，不存完整 URL —— 完整 URL 可能带
 * 搜索关键词等敏感信息，统计用不上，存了就是负担。
 * @return array{host:string, kind:string}
 */
function hp_ref_info(string $referer, string $selfHost): array
{
    $referer = trim($referer);
    if ($referer === '') {
        return ['host' => '', 'kind' => 'direct'];
    }
    $host = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));
    $host = preg_replace('~^www\.~', '', $host) ?: '';
    if ($host === '') {
        return ['host' => '', 'kind' => 'direct'];
    }
    if ($selfHost !== '' && ($host === $selfHost || str_ends_with($host, '.' . $selfHost))) {
        return ['host' => $host, 'kind' => 'internal'];
    }

    $search = ['google.', 'bing.com', 'baidu.com', 'duckduckgo.com', 'sogou.com',
               'so.com', 'yandex.', 'sm.cn', 'ecosia.org', 'startpage.com', 'search.brave'];
    foreach ($search as $s) {
        if (str_contains($host, $s)) return ['host' => $host, 'kind' => 'search'];
    }

    $social = ['weibo.', 'zhihu.com', 'douyin.com', 'toutiao.com', 'xiaohongshu.com',
               'twitter.com', 'x.com', 'facebook.com', 'instagram.com', 'reddit.com',
               't.me', 'telegram.', 'discord.', 'linkedin.com', 'youtube.com', 'bilibili.com'];
    foreach ($social as $s) {
        if (str_contains($host, $s)) return ['host' => $host, 'kind' => 'social'];
    }

    return ['host' => $host, 'kind' => 'referral'];
}

/** 本站自身域名（用来判断「内部跳转」） */
function hp_self_host(): string
{
    $h = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $h = preg_replace('~:\d+$~', '', $h) ?: '';
    return preg_replace('~^www\.~', '', $h) ?: '';
}

/** 地理库有没有导入。单请求内只探一次，避免每条访问都去 counts */
function hp_geo_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        // 有 geo_meta 记录就说明至少导过一次；再用 country 表兜一道，
        // 防止「清了数据但忘了清 meta」这种半残状态
        $pdo = db();
        $has = $pdo->query('SELECT COUNT(*) FROM geo_country')->fetchColumn();
        $ready = ((int)$has) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** 地理查询用的 prepare 缓存。三条 SQL 在整个请求里只会 prepare 一次。 */
function hp_geo_stmt(string $sql): PDOStatement
{
    static $cache = [];
    // 用 SQL 原文当 key：这几条是硬编码常量，不会有注入面
    if (!isset($cache[$sql])) {
        $cache[$sql] = db()->prepare($sql);
    }
    return $cache[$sql];
}

/** 城市级数据在不在。同理只探一次。 */
function hp_geo_has_city(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = ((int)db()->query('SELECT COUNT(*) FROM geo_city')->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * 查 IP 归属地。全本地 SQLite，零外部请求。
 * 返回 country(两位国家码) / region / city / isp / known(是否查到)
 *
 * 三级降级：
 *   1. geo_city 命中 → 国家+省+市一次拿全（最理想）
 *   2. geo_country 命中 → 至少知道国家（只导了国家库时走这里）
 *   3. 都没有 → 未知。此时交给访问控制的 acl_unknown 策略决定
 */
function hp_geo_lookup(string $ip): array
{
    $out = ['country' => '', 'region' => '', 'city' => '', 'isp' => '', 'known' => false];
    $n = hp_ip_int($ip);
    if ($n === null) {
        return $out; // IPv6 或非法：一律当未知（库里只收 IPv4）
    }

    // 库没导入就别查了。三次 prepare+execute 在空表上也要各走一趟，
    // 而这是每个访客请求都要跑的路径 —— 省下来的是实打实的 TTFB。
    if (!hp_geo_ready()) {
        return $out;
    }

    // 同一请求内会被 ACL 与统计各查一次，静态缓存省掉重复查询
    static $memo = [];
    if (isset($memo[$n])) {
        return $memo[$n];
    }

    try {
        $pdo = db();

        /* 顺序有讲究：城市表通常为空（只导了国家库），
           所以先看城市表有没有数据，再决定要不要查它。 */
        if (hp_geo_has_city()) {
            $st = hp_geo_stmt(
                'SELECT p.cc, p.region, p.city FROM geo_city c
                   JOIN geo_place p ON p.id = c.pid
                  WHERE c.start <= ? AND c."end" >= ? LIMIT 1'
            );
            $st->execute([$n, $n]);
            if ($row = $st->fetch()) {
                $out['country'] = (string)$row['cc'];
                $out['region']  = (string)$row['region'];
                $out['city']    = (string)$row['city'];
                $out['known']   = $row['cc'] !== '';
            }
            $st->closeCursor();
        }

        if ($out['country'] === '') {
            $st = hp_geo_stmt('SELECT cc FROM geo_country WHERE start <= ? AND "end" >= ? LIMIT 1');
            $st->execute([$n, $n]);
            $cc = $st->fetchColumn();
            $st->closeCursor();
            if ($cc) {
                $out['country'] = (string)$cc;
                $out['known']   = true;
            }
        }

        $st = hp_geo_stmt('SELECT org FROM geo_asn WHERE start <= ? AND "end" >= ? LIMIT 1');
        $st->execute([$n, $n]);
        $org = $st->fetchColumn();
        $st->closeCursor();
        if ($org) {
            $out['isp'] = (string)$org;
        }
    } catch (Throwable $e) {
        // 表还没建 / 数据没导入：静默降级为「未知」
    }

    $memo[$n] = $out;
    return $out;
}

/** 本地内网/回环地址不记统计（自己调试时不污染数据，也省一次查询） */
function hp_ip_is_local(string $ip): bool
{
    if ($ip === '') return true;
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

/**
 * 记一次页面访问。
 * @param bool $isAdmin 后台访问单独标记，前台统计默认把它排除
 */
function hp_log_visit(bool $isAdmin = false, string $path = ''): void
{
    if (setting('stats_on', '1') !== '1') {
        return;
    }
    try {
        $ip   = hp_client_ip();

        // 本地调试访问不记：否则后台满屏都是 127.0.0.1，图表失真
        if (hp_ip_is_local($ip)) {
            return;
        }

        $geo  = hp_geo_lookup($ip);
        $ua   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 380);
        $kind = hp_ua_parse($ua);
        $ref  = hp_ref_info((string)($_SERVER['HTTP_REFERER'] ?? ''), hp_self_host());

        $st = db()->prepare(
            'INSERT INTO visits(at, ip, ip_hash, country, region, city, isp, ua, browser, os, device, ref_host, ref_kind, path, is_admin)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            time(), $ip, hp_ip_hash($ip),
            $geo['country'], mb_substr($geo['region'], 0, 60), mb_substr($geo['city'], 0, 60), mb_substr($geo['isp'], 0, 80),
            $ua, $kind['browser'], $kind['os'], $kind['device'],
            $ref['host'], $ref['kind'],
            $path !== '' ? mb_substr($path, 0, 80) : substr((string)($_SERVER['REQUEST_URI'] ?? '/'), 0, 80),
            $isAdmin ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        // 统计挂了不能影响页面
    }
}

/** 记一次链接点击（与 links.hits 并行，互不依赖） */
function hp_log_click(int $linkId): void
{
    if ($linkId <= 0 || setting('stats_on', '1') !== '1') {
        return;
    }
    try {
        $ip = hp_client_ip();
        if (hp_ip_is_local($ip)) {
            return;
        }
        $geo = hp_geo_lookup($ip);
        $ref = hp_ref_info((string)($_SERVER['HTTP_REFERER'] ?? ''), hp_self_host());

        db()->prepare('INSERT INTO clicks(at, link_id, ip_hash, country, ref_kind) VALUES(?,?,?,?,?)')
            ->execute([time(), $linkId, hp_ip_hash($ip), $geo['country'], $ref['kind']]);
    } catch (Throwable $e) {
    }
}

/** 清掉超过保留天数的明细，返回删除行数。后台按钮与自动清理共用。 */
function hp_stats_prune(int $days = 0): int
{
    $days = $days > 0 ? $days : (int)setting('stats_keep', '90');
    $days = max(1, min(3650, $days));
    $cut  = time() - $days * 86400;
    $n = 0;
    try {
        $pdo = db();
        // 用 rowCount()：PDO SQLite 的 DELETE 会正确返回受影响行数。
        // 别用 SELECT changes() —— 那是连接级状态，两次 query 之间
        // 只要有别的语句写进来就会被覆盖。
        $st = $pdo->prepare('DELETE FROM visits WHERE at < ?');
        $st->execute([$cut]);
        $n += $st->rowCount();

        $st = $pdo->prepare('DELETE FROM clicks WHERE at < ?');
        $st->execute([$cut]);
        $n += $st->rowCount();
    } catch (Throwable $e) {
    }
    return (int)$n;
}