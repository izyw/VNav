<?php
/**
 * 网页元信息抓取：标题、描述、站点图标。
 * 只允许 http/https，拒绝内网/回环地址（防 SSRF）。
 */
declare(strict_types=1);

final class MetaFetcher
{
    private int $timeout;
    private int $maxBytes = 512 * 1024; // 只读前 512KB，够解析 <head> 了

    public function __construct(int $timeout = 6)
    {
        $this->timeout = max(2, min(20, $timeout));
    }

    /**
     * 抓取一个站点的元信息。
     * @return array{title:string, desc:string, icon:string, icon_kind:string}
     */
    public function fetch(string $url): array
    {
        $out = ['title' => '', 'desc' => '', 'icon' => '', 'icon_kind' => 'auto'];

        $html = $this->get($url, $finalUrl);
        $host = host_of($finalUrl ?: $url);

        if ($html !== '') {
            $out = $this->parse($html, $finalUrl ?: $url) + $out;
        }

        if ($out['title'] === '') {
            $out['title'] = $this->prettyHost($host);
        }

        // 兜底：任何来源的脏字节都不能让 json_encode 整包失败
        $out['title'] = $this->scrub($out['title']);
        $out['desc']  = $this->scrub($out['desc']);

        // 图标下载并落地到本地，避免每次访问都去源站拉图
        $iconUrl = $out['icon'] !== '' ? $out['icon'] : $this->guessFavicon($finalUrl ?: $url);
        $saved   = $iconUrl !== '' ? $this->cacheIcon($iconUrl, $host) : '';
        if ($saved !== '') {
            $out['icon'] = $saved;
            $out['icon_kind'] = 'file';
        } else {
            $out['icon'] = '';
            $out['icon_kind'] = 'auto'; // 前端用字母头像兜底
        }

        return $out;
    }

    /** 只解析 head 区域，正则在 head 内跑，避免整页正则回溯 */
    private function parse(string $html, string $baseUrl): array
    {
        $r = ['title' => '', 'desc' => '', 'icon' => ''];

        $headEnd = stripos($html, '</head>');
        $head = $headEnd !== false ? substr($html, 0, $headEnd) : substr($html, 0, 20000);

        if (preg_match('~<title[^>]*>(.*?)</title>~is', $head, $m)) {
            $r['title'] = $this->cleanText($m[1]);
        }

        // og:title 不能无条件盖过 <title>：Bing 的 og:title 是当日壁纸主题词
        // （比如「霜染枝叶」），只有 <title> 为空时才用它兜底。
        if ($r['title'] === '') {
            foreach (['og:site_name', 'application-name', 'apple-mobile-web-app-title', 'og:title'] as $prop) {
                if (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '~') . '["\'][^>]+content=["\']([^"\']*)~i', $head, $m)
                    || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . preg_quote($prop, '~') . '["\']~i', $head, $m)) {
                    $v = $this->cleanText($m[1]);
                    if ($v !== '') {
                        $r['title'] = $v;
                        break;
                    }
                }
            }
        }

        // 标题清洗：去掉「页面名 - 站点名」里与域名重复的尾巴
        $r['title'] = $this->tidyTitle($r['title'], host_of($baseUrl));
        if (preg_match('~<meta[^>]+(?:name|property)=["\'](?:description|og:description)["\'][^>]+content=["\']([^"\']*)~i', $head, $m)) {
            $r['desc'] = mb_substr($this->cleanText($m[1]), 0, 120);
        }

        // 按优先级找图标：apple-touch-icon > 高尺寸 icon > 普通 icon
        $candidates = [];
        if (preg_match_all('~<link[^>]+>~i', $head, $links)) {
            foreach ($links[0] as $tag) {
                if (!preg_match('~rel=["\']([^"\']*)["\']~i', $tag, $rm)) {
                    continue;
                }
                $rel = strtolower($rm[1]);
                if (!str_contains($rel, 'icon')) {
                    continue;
                }
                if (!preg_match('~href=["\']([^"\']+)["\']~i', $tag, $hm)) {
                    continue;
                }
                $href = html_entity_decode($hm[1], ENT_QUOTES, 'UTF-8');
                $size = 0;
                if (preg_match('~sizes=["\'](\d+)~i', $tag, $sm)) {
                    $size = (int)$sm[1];
                }
                $weight = str_contains($rel, 'apple-touch') ? 100 : (str_contains($rel, 'mask') ? 60 : 40);
                $weight += min($size, 512) / 10;
                $candidates[] = ['href' => $href, 'w' => $weight];
            }
        }
        if ($candidates) {
            usort($candidates, fn($a, $b) => $b['w'] <=> $a['w']);
            $r['icon'] = $this->absolutize($candidates[0]['href'], $baseUrl);
        }

        return $r;
    }

    private function guessFavicon(string $url): string
    {
        $p = parse_url($url);
        if (empty($p['host'])) {
            return '';
        }
        $scheme = $p['scheme'] ?? 'https';
        return $scheme . '://' . $p['host'] . '/favicon.ico';
    }

    private function absolutize(string $href, string $base): string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (str_starts_with($href, '/')) {
            return $root . $href;
        }
        $dir = isset($p['path']) ? rtrim(dirname($p['path']), '/') : '';
        return $root . $dir . '/' . $href;
    }

    private function prettyHost(string $host): string
    {
        $name = preg_replace('~^www\.~i', '', $host);
        $name = preg_replace('~\.(com|cn|net|org|io|co|me|dev|app|top|xyz|info|site|tv|cc)(\.[a-z]{2})?$~i', '', $name ?? '');
        $name = str_replace(['-', '_', '.'], ' ', (string)$name);
        return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8') ?: $host;
    }

    /**
     * 标题清洗：
     * 1) 去掉「页面名 - 站点名」中与域名重复的尾巴
     * 2) 去掉表情符号/颜文字堆叠（bilibili 那种）
     * 3) 还是太长就按分隔符截前段
     */
    private function tidyTitle(string $title, string $host): string
    {
        $t = trim($title);
        if ($t === '') {
            return '';
        }

        $bare = preg_replace('~^www\.~i', '', $host);
        $label = strtolower(explode('.', $bare)[0]);

        // 尾巴是域名本身（bilibili / bilibili.com / www.bilibili.com），砍掉
        if (preg_match('~^(.*?)\s*[-_|—–·•]\s*(?:www\.)?[a-z0-9\-]*' . preg_quote($label, '~') . '(?:\.[a-z]{2,})?\s*$~iu', $t, $m)) {
            $head = trim($m[1]);
            if (mb_strlen($head) >= 2) {
                $t = $head;
            }
        }
        // 尾巴是「官网」「首页」这种，也砍
        if (preg_match('~^(.*?)\s*[-_|—–·•]\s*(?:官方网站?|官网|首页|主页)\s*$~u', $t, $m)) {
            $head = trim($m[1]);
            if (mb_strlen($head) >= 2) {
                $t = $head;
            }
        }

        // 去掉颜文字与符号簇（保留中文、字母、数字、常见标点）
        $t = preg_replace('~[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2E00}-\x{2E7F}\x{FF5F}-\x{FF65}]+~u', '', $t) ?? $t;
        $t = preg_replace('~[\(（【\[][^)）】\]]{0,18}[\)）】\]]~u', '', $t) ?? $t;
        $t = preg_replace('~\s{2,}~u', ' ', $t) ?? $t;
        // 注意：绝不能用 trim($t, " ·•—") 这种写法。
        // trim 的第二参数按「单字节」匹配，而 · • — 是多字节字符，
        // 会把汉字尾字节（如「应」= E5 BA 94 中的 0x94）一起剥掉，
        // 留下半个字符 → 非法 UTF-8 → json_encode 直接失败。
        // 必须用带 /u 的正则做多字节安全的裁剪。
        $t = preg_replace('~^[\s\x{00B7}\x{2022}\x{2014}\x{2013}\-|_]+~u', '', $t) ?? $t;
        $t = preg_replace('~[\s\x{00B7}\x{2022}\x{2014}\x{2013}\-|_]+$~u', '', $t) ?? $t;

        if ($t === '') {
            $t = trim($title);
        }

        // 仍然太长：取第一个分隔符前的一段
        if (mb_strlen($t) > 28 && preg_match('~^(.{2,26}?)\s*[-_|—–·•]\s*\S~u', $t, $m)) {
            $t = trim($m[1]);
        }
        if (mb_strlen($t) > 40) {
            $t = mb_substr($t, 0, 40);
        }
        return $t;
    }

    private function cleanText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = $this->scrub($s);
        $s = preg_replace('~\s+~u', ' ', strip_tags($s)) ?? '';
        return trim($s);
    }

    /**
     * 把任意字节串强制修成合法 UTF-8。
     * 抓来的页面编码五花八门，只要有一处非法字节，json_encode 就会返回 false，
     * 前端拿到空响应 → 整个自动识别功能静默失效。这里做最后一道闸。
     */
    private function scrub(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        // 带 //IGNORE 的转换会丢掉非法序列，比直接截断安全
        $fixed = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        if (is_string($fixed) && $fixed !== '' && mb_check_encoding($fixed, 'UTF-8')) {
            return $fixed;
        }
        // 极端情况：逐字节过滤
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (ord($c) < 0x80) {
                $out .= $c;
                continue;
            }
            // 找到该字符应有的字节数
            $b = ord($c);
            $need = $b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 0));
            if ($need && $i + $need <= $len) {
                $chunk = substr($s, $i, $need);
                if (mb_check_encoding($chunk, 'UTF-8')) {
                    $out .= $chunk;
                    $i += $need - 1;
                    continue;
                }
            }
            $i += max(0, $need - 1);
        }
        return $out;
    }

    /** GET 前 512KB，重定向逐跳校验、最多 3 跳（SSRF 防护见 curlSafely） */
    private function get(string $url, ?string &$finalUrl = null): string
    {
        $r = self::curlSafely($url, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_ENCODING        => '',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_BUFFERSIZE      => 16384,
        ], 3, $this->maxBytes);
        $finalUrl = $r['finalUrl'];
        if ($r['body'] === '' || $r['code'] >= 400) {
            return '';
        }
        // 非 HTML 就别解析了
        if (stripos(substr($r['body'], 0, 200), '<html') === false && stripos(substr($r['body'], 0, 400), '<!doctype') === false) {
            return '';
        }
        return $r['body'];
    }

    /** 下载图标、校验类型、转成 png/ico 落盘，返回相对路径 */
    public function cacheIcon(string $iconUrl, string $host): string
    {
        // 重定向逐跳校验，且校验响应类型 —— cacheIcon 原本没有「须含 <html>」
        // 那道软限制，若不查重定向，内网图片会被落到 data/icons/ 对外可访问。
        $r = self::curlSafely($iconUrl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
        ], 2);
        $bin  = $r['body'];
        $code = $r['code'];
        $mime = $r['mime'];

        if ($bin === '' || $code >= 400 || strlen($bin) < 64 || strlen($bin) > 2 * 1024 * 1024) {
            return '';
        }
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $ext = match (true) {
            str_contains($mime, 'svg')                    => 'svg',
            str_contains($mime, 'png')                    => 'png',
            str_contains($mime, 'webp')                   => 'webp',
            str_contains($mime, 'gif')                    => 'gif',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'icon')                   => 'ico',
            default                                       => '',
        };
        if ($ext === '') {
            // 内容嗅探兜底
            $ext = match (true) {
                str_starts_with($bin, "\x89PNG")     => 'png',
                str_starts_with($bin, "\xFF\xD8")    => 'jpg',
                str_starts_with($bin, 'GIF8')        => 'gif',
                str_starts_with($bin, '<svg') || str_starts_with($bin, '<?xml') => 'svg',
                str_starts_with($bin, "\x00\x00\x01\x00") => 'ico',
                default => '',
            };
        }
        if ($ext === '') {
            return '';
        }

        $dir = HP_ROOT . '/data/icons';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return '';
        }
        $name = preg_replace('~[^a-z0-9.\-]~i', '_', $host) . '-' . substr(md5($iconUrl), 0, 8) . '.' . $ext;
        $path = $dir . '/' . $name;

        // 降采样：站点图标常见 1024×1024，而页面上最多显示 52px。
        // 原样存盘会让首屏白白解码几十万像素，也拖慢首屏。
        // 位图统一缩到 128px（2x 够用），SVG/ICO 原样保留。
        if (in_array($ext, ['png', 'jpg', 'webp'], true)) {
            $bin = $this->shrink($bin, 128) ?: $bin;
        }

        if (@file_put_contents($path, $bin) === false) {
            return '';
        }
        return 'data/icons/' . $name;
    }

    /** 用 GD 把位图缩到最长边 <= $max，失败返回 null（调用方回退原图） */
    private function shrink(string $bin, int $max): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $im = @imagecreatefromstring($bin);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w <= $max && $h <= $max) {
            $im2 = $im;
        } else {
            $scale = $max / max($w, $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $im2 = @imagescale($im, $nw, $nh, IMG_BICUBIC);
            if (!$im2) {
                return null;
            }
        }
        ob_start();
        imagepng($im2, null, 6);
        $out = (string)ob_get_clean();
        return $out !== '' ? $out : null;
    }

    /** 拒绝回环、内网、链路本地等地址 */
    public static function isPublicUrl(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return false;
        }
        if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($p['host']);
        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = @gethostbynamel($host);
            if (is_array($a)) {
                $ips = $a;
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /* ----------------------------------------------------------------------
       SSRF 防护：curl 自带的 FOLLOWLOCATION 只认协议、不认目标 IP ——
       自己的公网域名 302 到 127.0.0.1 / 169.254.169.254（云元数据）时
       会照单全收。下面这组函数让重定向逐跳经过 isPublicUrl()，并把
       已验证的 IP 用 CURLOPT_RESOLVE 钉死，杜绝二次解析 rebinding。
       ---------------------------------------------------------------------- */

    /** 把 Location（可能是相对地址）解析成绝对 URL */
    private static function absoluteUrl(string $base, string $ref): string
    {
        $ref = trim($ref);
        if (preg_match('~^https?://~i', $ref)) {
            return $ref;
        }
        $p = parse_url($base);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $scheme = strtolower($p['scheme']);
        $auth   = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $scheme . '://' . $auth . $ref;
        }
        $path = $p['path'] ?? '/';
        $pos  = strrpos($path, '/');
        $dir  = $pos === false ? '/' : substr($path, 0, $pos + 1);
        return $scheme . '://' . $auth . $dir . $ref;
    }

    /** 从响应头里取最后一处 Location */
    private static function locationFromHeaders(string $headers): string
    {
        $loc = '';
        foreach (preg_split('/\r?\n/', $headers) ?: [] as $line) {
            if (preg_match('~^Location\s*:\s*(.+)$~i', trim($line), $m)) {
                $loc = trim($m[1]);
            }
        }
        return $loc;
    }

    /** 已验证域名的 host:port:ip，交给 CURLOPT_RESOLVE 固定解析结果 */
    private static function pinnedResolve(string $url): array
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return [];
        }
        $host = strtolower($p['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return []; // 字面量 IP 已是连接目标，isPublicUrl 已校验过
        }
        $ip = @gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }
        $port = $p['port'] ?? (strtolower($p['scheme'] ?? '') === 'https' ? 443 : 80);
        return [$host . ':' . $port . ':' . $ip];
    }

    /**
     * 单次请求 + 手动逐跳跟随重定向，每一跳都过 isPublicUrl()。
     *
     * @param array $opts       curl_setopt_array 的选项，FOLLOWLOCATION 会被强制关闭
     * @param int   $maxRedirects 最多跟随几跳
     * @param int   $maxBytes  进度回调的字节上限，0 表示不限制
     * @return array{body:string, code:int, mime:string, finalUrl:string}
     */
    private static function curlSafely(string $url, array $opts, int $maxRedirects, int $maxBytes = 0): array
    {
        $finalUrl = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (!self::isPublicUrl($url)) {
                return ['body' => '', 'code' => 0, 'mime' => '', 'finalUrl' => $finalUrl];
            }
            $ch = curl_init();
            $o = $opts;
            $o[CURLOPT_URL] = $url;
            $o[CURLOPT_FOLLOWLOCATION] = false; // 关键：绝不交给 curl 自动跟
            $o[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $pin = self::pinnedResolve($url);
            if ($pin) {
                $o[CURLOPT_RESOLVE] = $pin;
            }
            $headers = '';
            $o[CURLOPT_HEADERFUNCTION] = static function ($c, $line) use (&$headers) {
                $headers .= $line;
                return strlen($line);
            };
            curl_setopt_array($ch, $o);
            if ($maxBytes > 0) {
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function ($res, $dlTotal, $dlNow) use ($maxBytes) {
                    return $dlNow > $maxBytes ? 1 : 0;
                });
            }
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            $finalUrl = $url;

            if (!is_string($body) || $code >= 400) {
                return ['body' => '', 'code' => $code, 'mime' => $mime, 'finalUrl' => $finalUrl];
            }
            if ($code >= 300 && $code < 400 && $hop < $maxRedirects) {
                $loc  = self::locationFromHeaders($headers);
                $next = $loc !== '' ? self::absoluteUrl($url, $loc) : '';
                if ($next === '' || !self::isPublicUrl($next)) {
                    return ['body' => '', 'code' => $code, 'mime' => $mime, 'finalUrl' => $finalUrl];
                }
                $url = $next;
                continue;
            }
            return ['body' => $body, 'code' => $code, 'mime' => $mime, 'finalUrl' => $finalUrl];
        }
        return ['body' => '', 'code' => 0, 'mime' => '', 'finalUrl' => $finalUrl];
    }

    /* ======================================================================
       并发抓取
       ----------------------------------------------------------------------
       批量刷新原本是「一条一条串行」：每条约 2 个请求（页面 + 图标），
       46 条就是 90 多次往返，一次 1~2 秒，用户要等半分钟以上。

       这里把流程拆成两个并发阶段：
         阶段 1  curl_multi 同时抓所有页面的 HTML
         阶段 2  解析出图标地址后，再同时抓所有图标
       总耗时从「条数 × 单条耗时」降到「2 × 最慢那一个」。
       ====================================================================== */

    /** 页面抓取用的 curl 选项。multi 与单条共用同一份，避免两处漂移。 */
    private static function baseOpts(int $timeout): array
    {
        return [
            CURLOPT_RETURNTRANSFER  => true,
            /* SSRF：不自动跟随。重定向由 multiGet() 逐跳解析 Location 并过
               isPublicUrl() 后才重新入队，避免公网域名 302 打进内网。 */
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_TIMEOUT         => $timeout,
            /* 连接超时不能等于总超时：连不上和读得慢是两件事。
               连不上要尽快判死（2 秒），把时间还给它后面的正常站点。 */
            CURLOPT_CONNECTTIMEOUT  => max(2, min(3, (int)floor($timeout * 0.6))),
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_ENCODING        => '',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // multi 下必须关信号，否则 curl 用 SIGALRM 收超时，会和 PHP 的
            // 其它扩展抢信号；NOSIGNAL 之后超时改由 select 计时负责。
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_BUFFERSIZE      => 16384,
        ];
    }

    /**
     * 并发抓一批 URL（滑动窗口，最多 $concurrency 个同时在飞）。
     *
     * @param array<int|string,string> $urls  key 用来回传结果，值是要抓的地址
     * @return array<int|string, array{body:string, finalUrl:string, code:int, mime:string}>
     *         与入参同 key；不合法或失败的条目 body 为空串
     */
    public static function multiGet(array $urls, int $timeout = 6, int $concurrency = 16): array
    {
        $out = [];
        $queue = [];
        foreach ($urls as $k => $u) {
            if (!self::isPublicUrl((string)$u)) {
                $out[$k] = ['body' => '', 'finalUrl' => (string)$u, 'code' => 0, 'mime' => ''];
                continue;
            }
            $queue[$k] = ['url' => (string)$u, 'hops' => 0];
        }
        if (!$queue) {
            return $out;
        }

        $limit = max(1, min(48, $concurrency));
        $mh = curl_multi_init();
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            @curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $limit);
        }

        $active = [];   // spl_object_id => ['ch'=>CurlHandle, 'key'=>原key, 'hops'=>跳数, 'headers'=>响应头]

        // 建一条请求：固定已验证 IP，并收集响应头供逐跳解析 Location
        $spawn = function ($key, string $url, int $hops) use (&$active, $mh, $timeout) {
            $ch = curl_init();
            curl_setopt_array($ch, self::baseOpts($timeout));
            curl_setopt($ch, CURLOPT_URL, $url);
            $pin = self::pinnedResolve($url);
            if ($pin) {
                curl_setopt($ch, CURLOPT_RESOLVE, $pin);
            }
            $headers = '';
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($c, $line) use (&$headers) {
                $headers .= $line;
                return strlen($line);
            });
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = [
                'ch' => $ch, 'key' => $key, 'hops' => $hops, 'headers' => &$headers,
            ];
        };

        $fill = function () use (&$active, &$queue, $spawn, $limit) {
            while (count($active) < $limit && $queue) {
                $key  = array_key_first($queue);
                $item = $queue[$key];
                unset($queue[$key]);
                $spawn($key, (string)$item['url'], (int)$item['hops']);
            }
        };

        $fill();
        $running = 0;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $sid = spl_object_id($ch);
                $key = $active[$sid]['key'] ?? null;
                if ($key === null) {
                    continue;
                }
                $code   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $hops   = (int)($active[$sid]['hops'] ?? 0);
                $header = (string)($active[$sid]['headers'] ?? '');

                // 3xx：逐跳校验 Location；合法就重新入队，非法/超跳则判失败。
                // 绝不让 curl 自动跳，否则公网域名可 302 到内网/云元数据。
                if ($code >= 300 && $code < 400) {
                    $loc  = self::locationFromHeaders($header);
                    $next = $loc !== '' ? self::absoluteUrl((string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $loc) : '';
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($active[$sid]);
                    if ($next !== '' && self::isPublicUrl($next) && $hops < 3) {
                        $queue[$key] = ['url' => $next, 'hops' => $hops + 1];
                    } else {
                        $out[$key] = ['body' => '', 'finalUrl' => $next ?: (string)$loc, 'code' => $code, 'mime' => ''];
                    }
                    continue;
                }

                $body = (string)curl_multi_getcontent($ch);
                $out[$key] = [
                    'body'     => $code >= 400 ? '' : $body,
                    'finalUrl' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    'code'     => $code,
                    'mime'     => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$sid]);
            }

            if ($running) {
                // select 有事件就立刻返回；没有事件时最多等 0.25s 再轮询，
                // 避免长时间阻塞住（和 -1 那个老写法相比更稳）。
                if (curl_multi_select($mh, 0.25) === -1) {
                    usleep(2000);
                }
            }

            $fill();
        } while ($running > 0 || $active || $queue);

        foreach ($active as $a) {
            curl_multi_remove_handle($mh, $a['ch']);
            curl_close($a['ch']);
        }
        curl_multi_close($mh);

        return $out;
    }

    /**
     * 只解析已经拿到手的 HTML，不下载图标。
     *
     * 和 fetch() 的区别：fetch() 是「一条龙」，抓页面 + 下图标都做；
     * 这里只做前半段，好让调用方把所有页面的图标攒起来一起并发下载。
     */
    public function metaOnly(string $html, string $url, string $finalUrl = ''): array
    {
        $finalUrl = $finalUrl !== '' ? $finalUrl : $url;
        $host = host_of($finalUrl);
        $out = ['title' => '', 'desc' => '', 'icon' => '', 'host' => $host];

        if ($html !== '' && self::looksHtml($html)) {
            $out = $this->parse($html, $finalUrl) + $out;
            $out['host'] = $host;
        }

        if ($out['title'] === '') {
            $out['title'] = $this->prettyHost($host);
        }
        $out['title'] = $this->scrub($out['title']);
        $out['desc']  = $this->scrub($out['desc']);

        // 页面里没声明图标就走 favicon.ico 约定
        if ($out['icon'] === '') {
            $out['icon'] = $this->guessFavicon($finalUrl);
        }
        return $out;
    }

    /**
     * 把已经下载好的图标字节落盘，返回相对路径。
     * 从 cacheIcon 里拆出来，是为了「并发下载 → 逐张落盘」——
     * 下载是网络等待，落盘是 CPU 与磁盘，串在一起会吃掉并发收益。
     */
    public function saveIconBin(string $bin, string $iconUrl, string $host, string $mime = ''): string
    {
        if (strlen($bin) < 64 || strlen($bin) > 2 * 1024 * 1024) {
            return '';
        }

        $mime = strtolower(trim(explode(';', (string)$mime)[0]));
        $ext = match (true) {
            str_contains($mime, 'svg')    => 'svg',
            str_contains($mime, 'png')    => 'png',
            str_contains($mime, 'webp')   => 'webp',
            str_contains($mime, 'gif')    => 'gif',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'icon')   => 'ico',
            default                       => '',
        };
        if ($ext === '') {
            $ext = match (true) {
                str_starts_with($bin, "\x89PNG")     => 'png',
                str_starts_with($bin, "\xFF\xD8")    => 'jpg',
                str_starts_with($bin, 'GIF8')        => 'gif',
                str_starts_with($bin, '<svg') || str_starts_with($bin, '<?xml') => 'svg',
                str_starts_with($bin, "\x00\x00\x01\x00") => 'ico',
                default => '',
            };
        }
        if ($ext === '') {
            return '';
        }

        $dir = HP_ROOT . '/data/icons';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return '';
        }
        $name = preg_replace('~[^a-z0-9.\-]~i', '_', $host) . '-' . substr(md5($iconUrl), 0, 8) . '.' . $ext;
        $path = $dir . '/' . $name;

        if (in_array($ext, ['png', 'jpg', 'webp'], true)) {
            $bin = $this->shrink($bin, 128) ?: $bin;
        }
        if (@file_put_contents($path, $bin) === false) {
            return '';
        }
        return 'data/icons/' . $name;
    }

    /** 粗判一段字节是不是 HTML（并发版不再逐条调 get()，这个判断搬到这里） */
    private static function looksHtml(string $body): bool
    {
        return stripos(substr($body, 0, 400), '<!doctype') !== false
            || stripos(substr($body, 0, 200), '<html') !== false;
    }
}