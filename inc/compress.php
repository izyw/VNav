<?php
/**
 * 输出压缩与缓存头。
 *
 * 为什么不只靠 .htaccess 的 mod_deflate：
 *   1) Nginx / Caddy / 一些共享主机的 Apache 不带 mod_deflate，
 *      用户上传完就以为「开了 gzip」，其实一点没压。
 *   2) PHP 输出压缩在应用层生效，部署方式无关，永远有效。
 *   两者同时存在的正确姿势是：这里先压，服务器发现已压过就不再动
 *   （Content-Encoding 已存在时 mod_deflate 会跳过）。
 *
 * 优先级：Brotli > gzip。Brotli 对 HTML/CSS/JS 通常再小 15~20%，
 * 但需要 PHP 8.1+ 且编译了 brotli 扩展，条件不满足就退回 gzip。
 *
 * 关键细节：
 *   - 必须 Vary: Accept-Encoding，否则 CDN/代理会把压缩版发给
 *     不支持 gzip 的客户端，页面直接白屏。
 *   - gzip 级别用 6（不是 9）：6 与 9 的体积差通常 <2%，
 *     但压缩耗时差一倍以上，动态页面 TTFB 更值钱。
 *   - 小于 1KB 的响应不压：gzip 头部开销反而让它变大。
 */
declare(strict_types=1);

/**
 * 折叠 HTML 里纯排版用的空白。
 *
 * 为什么值得做：首页是 PHP 模板边渲染边输出，为了可读性缩进得很规整。
 * 游客分支下大量 `<?php if ($__isAdmin): ?>` 都不成立，只剩缩进留在
 * 输出里 —— 实测 371KB 的首页里有约 190KB 是连续空格。gzip 能压掉大部分，
 * 但压完之后仍有 2~3KB，而且压缩器本身要为此多花时间。
 *
 * 安全边界（任何一个破了都会改变页面观感）：
 *   1. <pre> / <textarea> 里的空白是内容，原样保护。
 *   2. <script> / <style> 里的空白可能落在字符串字面量内（本站首屏就有一段
 *      内联脚本，里面有 '搜索 ' 这样的字符串），整块保护。
 *   3. 只把「标签与标签之间」的空白整个删掉（>\s+<）。标签之间夹着文字的
 *      （`按 <kbd>` 这种）不匹配，文字里的空格动不到。
 *      → 本站布局以 flex / grid 为主，块级子项之间的空白不参与排版，
 *        删掉不影响视觉；行内元素之间的空隙由 CSS gap 控制，不靠空白符。
 *   4. 连续的水平空白折叠成一个，不会把两个词粘成一体。
 *
 * 只对 HTML 开，JSON 一定不能走这里（api.php 的响应会变成非法 JSON）。
 */
function hp_html_minify(string $html): string
{
    if ($html === '' || strlen($html) < 512) {
        return $html;
    }

    // 1. 把不可动的块整段摘出来，换成占位符
    $hold = [];
    $out = preg_replace_callback(
        '~<(pre|textarea|script|style)\b[^>]*>.*?</\1\s*>~is',
        static function (array $m) use (&$hold): string {
            $k = "\x01HPHOLD" . count($hold) . "\x01";
            $hold[$k] = $m[0];
            return $k;
        },
        $html
    );
    if ($out === null) {
        return $html; // 正则出错（极端超长输入）：宁可不压，也不能吐坏内容
    }

    // 2. 标签之间的空白整体删除
    $out = preg_replace('~>\s+<~', '><', $out) ?? $out;
    // 3. 行内连续空白折叠成一个（保住词与词之间的那一个）
    $out = preg_replace('~[ \t]{2,}~', ' ', $out) ?? $out;

    // 4. 还原保护块
    return $hold ? strtr($out, $hold) : $out;
}

/**
 * 开启输出压缩。在输出任何内容之前调用。
 * @param int  $level  gzip 级别，1~9。动态页建议 6。
 * @param bool $minify 顺便折叠 HTML 排版空白。只给 HTML 页面开，JSON 绝对不要开。
 */
function hp_compress_start(int $level = 6, bool $minify = false): void
{
    // 已经有人开了压缩（ob_gzhandler 或 zlib.output_compression），别叠
    if (ini_get('zlib.output_compression')) {
        return;
    }
    if (in_array('ob_gzhandler', ob_list_handlers(), true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

    // Brotli 需要扩展；没有就老实走 gzip
    if (function_exists('brotli_compress') && str_contains($accept, 'br')) {
        ob_start(static function (string $buf) use ($minify): string {
            if ($minify) {
                $buf = hp_html_minify($buf);
            }
            // 太短的不压，压缩头开销反而亏
            if (strlen($buf) < 1024) return $buf;
            try {
                $out = brotli_compress($buf, 5);
                if ($out !== false && strlen($out) < strlen($buf)) {
                    header('Content-Encoding: br');
                    header('Vary: Accept-Encoding', false);
                    return $out;
                }
            } catch (Throwable $e) {
            }
            return $buf;
        });
        return;
    }

    if (str_contains($accept, 'gzip') && function_exists('gzencode')) {
        ob_start(static function (string $buf) use ($level, $minify): string {
            if ($minify) {
                $buf = hp_html_minify($buf);
            }
            if (strlen($buf) < 1024) return $buf;
            $out = gzencode($buf, max(1, min(9, $level)));
            if ($out !== false && strlen($out) < strlen($buf)) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding', false);
                // 老代理会缓冲 gzip 流，这一行让 Apache 的 mod_deflate 别重复处理
                header('X-Compressed-By: vnav');
                return $out;
            }
            return $buf;
        });
        return;
    }

    // 客户端不支持压缩：仍然要声明 Vary，避免缓存串味
    header('Vary: Accept-Encoding', false);
}

/**
 * 给静态资源（CSS/JS/字体）加上强缓存 + 内容指纹 ETag。
 * 这些文件用 ?v=N 版本号引入，所以可以放心长缓存。
 *
 * 注意：.htaccess 里已有等价配置（mod_expires + mod_headers），
 * 生产环境通常用不上这个函数。它存在是为了两类场景：
 *   1) 用 PHP 内置服务器 / 摘掉 .htaccess 的环境调试
 *   2) 想把资源收进 PHP 做鉴权或统计时挂上去
 */
function hp_asset_headers(string $file, int $days = 365): void
{
    if (headers_sent()) {
        return;
    }
    $path = HP_ROOT . '/' . ltrim($file, '/');
    if (!is_file($path)) {
        return;
    }
    $mtime = (int)filemtime($path);
    $size  = (int)filesize($path);
    $etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: public, max-age=' . ($days * 86400) . ', immutable');

    // 条件请求直接 304，省掉整个响应体
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($inm !== '' && trim($inm) === $etag) {
        http_response_code(304);
        header('Content-Length: 0');
        exit;
    }
}