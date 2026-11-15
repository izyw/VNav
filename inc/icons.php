<?php
/**
 * 图标缓存维护 —— 后台用，主页渲染不依赖这里。
 *
 * 主页的图标判断只有一句 is_file()，因为那是每次请求都要跑的路径，
 * 多一次 SQL 查询不值得。这张表纯为「维护」存在：
 * 查某个域名缓存了几份、哪些链接还在用已丢失的文件、
 * 一键清掉远古缓存、重新抓一遍。
 *
 * 缓存策略（三层，缺一层都会慢）：
 *   1. 抓取时落盘到 data/icons/，文件名 = 域名 + 源 URL 的 md5 前 8 位
 *      → 同一域名不同路径的图标不会互相覆盖
 *   2. 位图统一降到 128px（页面上最大显示 52px，2x 够用）
 *      → 1024×1024 的 11.6KB 降到 128px 的 3.9KB
 *   3. .htaccess 给 data/icons/ 打一年 immutable 缓存
 *      → 二次访问零请求（浏览器直接用本地副本）
 */
declare(strict_types=1);

/** 图标目录（绝对路径） */
function hp_icon_dir(): string
{
    return HP_ROOT . '/data/icons';
}

/** 确保目录存在且可写 */
function hp_icon_ensure_dir(): bool
{
    $dir = hp_icon_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * 记一条缓存元信息。同一个 path 重复写就更新（UPSERT）。
 * 失败静默 —— 图标已经落盘了，记账失败不该让抓取整体失败。
 */
function hp_icon_remember(string $path, string $host, string $src = '', string $mime = ''): void
{
    $abs = HP_ROOT . '/' . ltrim($path, '/');
    $bytes = is_file($abs) ? (int)filesize($abs) : 0;
    try {
        db()->prepare(
            'INSERT INTO icon_cache(host, path, bytes, mime, src, at) VALUES(?, ?, ?, ?, ?, ?)
             ON CONFLICT(path) DO UPDATE SET host = excluded.host, bytes = excluded.bytes,
                mime = excluded.mime, src = excluded.src, at = excluded.at'
        )->execute([$host, $path, $bytes, $mime, $src, time()]);
    } catch (Throwable $e) {
        // 表还没建（老库首次请求）时不该报错打断抓取
    }
}

/**
 * 扫一遍目录，把磁盘上有、表里没有的文件补记进表；
 * 把表里有、磁盘上没了的记录删掉。
 *
 * 为什么要这么做：图标可能是手动拷进来的（迁移、恢复包），
 * 也可能被人删了。对不上账的时候后台就看不到真实情况。
 */
function hp_icon_sync(): array
{
    $dir = hp_icon_dir();
    $added = 0;
    $removed = 0;

    $onDisk = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $rel = 'data/icons/' . basename($f);
            $onDisk[$rel] = $f;
        }
    }

    $known = [];
    foreach (db()->query('SELECT path FROM icon_cache') as $r) {
        $known[(string)$r['path']] = true;
    }

    // 磁盘有、表里没 → 补记
    foreach ($onDisk as $rel => $f) {
        if (isset($known[$rel])) {
            continue;
        }
        // 从文件名反推域名：域名-hash.ext
        $base = basename($rel);
        $host = preg_replace('~-[0-9a-f]{8}\.[a-z0-9]+$~i', '', $base) ?? $base;
        $mime = match (strtolower(pathinfo($base, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            default => '',
        };
        hp_icon_remember($rel, $host, '', $mime);
        $added++;
    }

    // 表里有、磁盘没了 → 删记录
    foreach ($known as $rel => $_) {
        if (!isset($onDisk[$rel])) {
            db()->prepare('DELETE FROM icon_cache WHERE path = ?')->execute([$rel]);
            $removed++;
        }
    }

    return ['added' => $added, 'removed' => $removed, 'files' => count($onDisk)];
}

/** 缓存总体情况：文件数、总字节、最老一份多久了 */
function hp_icon_stats(): array
{
    $dir = hp_icon_dir();
    $files = 0;
    $bytes = 0;
    $oldest = 0;
    $newest = 0;

    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $files++;
            $bytes += (int)filesize($f);
            $m = (int)filemtime($f);
            if ($oldest === 0 || $m < $oldest) $oldest = $m;
            if ($m > $newest) $newest = $m;
        }
    }

    // 有多少条链接正在用缓存文件（用来算「命中率」）
    $used = 0;
    try {
        $used = (int)db()->query("SELECT COUNT(*) FROM links WHERE icon_kind = 'file' AND icon <> ''")->fetchColumn();
    } catch (Throwable $e) {
    }

    return [
        'files'  => $files,
        'bytes'  => $bytes,
        'oldest' => $oldest,
        'newest' => $newest,
        'used'   => $used,
        'writable' => is_dir($dir) && is_writable($dir),
    ];
}

/** 清空缓存（可选只清 N 天以前的） */
function hp_icon_purge(int $olderThanDays = 0): array
{
    $dir = hp_icon_dir();
    $cut = $olderThanDays > 0 ? time() - $olderThanDays * 86400 : 0;
    $n = 0;
    $bytes = 0;

    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            if ($cut > 0 && (int)filemtime($f) >= $cut) {
                continue;
            }
            $bytes += (int)filesize($f);
            if (@unlink($f)) {
                $n++;
            }
        }
    }

    if ($cut > 0) {
        db()->prepare('DELETE FROM icon_cache WHERE at < ?')->execute([$cut]);
    } else {
        db()->exec('DELETE FROM icon_cache');
    }

    /* 文件被删掉后，链接上还挂着指向它的路径 —— 那会变成破图空框。
       这里把「图标文件已经不在了」的链接改回 auto（首字母头像），
       而不是等用户自己发现。 */
    $fixed = 0;
    foreach (db()->query("SELECT id, icon FROM links WHERE icon_kind = 'file' AND icon <> ''") as $r) {
        if (!is_file(HP_ROOT . '/' . $r['icon'])) {
            db()->prepare("UPDATE links SET icon = '', icon_kind = 'auto', updated_at = ? WHERE id = ?")
                ->execute([now(), (int)$r['id']]);
            $fixed++;
        }
    }

    return ['deleted' => $n, 'bytes' => $bytes, 'relinked' => $fixed];
}

/**
 * 给还没图标的链接批量补抓。每次最多 $limit 条，
 * 避免一个请求跑太久被 PHP 超时掐断。
 *
 * 返回 [成功数, 失败数, 剩余待抓数]。
 */
function hp_icon_refill(int $limit = 12): array
{
    require_once HP_ROOT . '/inc/MetaFetcher.php';

    $limit = max(1, min(50, $limit));
    $rows = db()->query(
        "SELECT id, url FROM links
          WHERE (icon = '' OR icon_kind <> 'file') AND url <> ''
          ORDER BY id ASC LIMIT " . $limit
    )->fetchAll();

    $ok = 0;
    $bad = 0;
    $fetcher = new MetaFetcher(8);

    foreach ($rows as $r) {
        $url = (string)$r['url'];
        if (!MetaFetcher::isPublicUrl($url)) {
            $bad++;
            continue;
        }
        try {
            $meta = $fetcher->fetch($url);
            if (!empty($meta['icon']) && preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', (string)$meta['icon'])) {
                $host = host_of($url);
                db()->prepare(
                    "UPDATE links SET icon = ?, icon_kind = 'file', updated_at = ? WHERE id = ?"
                )->execute([$meta['icon'], now(), (int)$r['id']]);
                hp_icon_remember((string)$meta['icon'], $host);
                $ok++;
            } else {
                $bad++;
            }
        } catch (Throwable $e) {
            $bad++;
        }
    }

    $left = (int)db()->query(
        "SELECT COUNT(*) FROM links WHERE (icon = '' OR icon_kind <> 'file') AND url <> ''"
    )->fetchColumn();

    return ['ok' => $ok, 'bad' => $bad, 'left' => $left];
}

/**
 * 拿掉长时间没人引用的孤儿图标文件。
 * 与 hp_icon_purge 的区别：purge 是按时间，这个是按「有没有链接还在用」。
 */
function hp_icon_collect_garbage(): array
{
    $inUse = [];
    foreach (db()->query("SELECT icon FROM links WHERE icon_kind = 'file' AND icon <> ''") as $r) {
        $inUse[(string)$r['icon']] = true;
    }

    $dir = hp_icon_dir();
    $n = 0;
    $bytes = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $rel = 'data/icons/' . basename($f);
            if (isset($inUse[$rel])) {
                continue;
            }
            $bytes += (int)filesize($f);
            if (@unlink($f)) {
                $n++;
            }
        }
    }
    if ($n > 0) {
        hp_icon_sync();
    }
    return ['deleted' => $n, 'bytes' => $bytes];
}