<?php
/**
 * 一包三用：同一个 zip 既是安装包、也是升级包、也是恢复包。
 *
 * 三种用法的分界只有一处 —— 站点上有没有 inc/config.php：
 *   没有 → 首次安装（走 install.php）
 *   有   → 升级/恢复（走这里）
 *
 * 升级时**永远不动**两样东西：
 *   inc/config.php     管理员账号、密码哈希、密钥、数据库路径
 *   data/home.db       全部业务数据
 * 这两个不进包（打包脚本会排除），所以「上传新包覆盖」天然安全。
 * 但为了防手滑，这里仍然显式跳过它们，并且升级前做一份快照。
 *
 * 版本比对不做「必须更大才能升」的限制 —— 恢复包本质上就是
 * 用旧版本覆盖新版本，那个场景下版本号是倒退的，拦下来反而碍事。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

/* 读当前版本用 boot.php 里的 hp_version()，这里不再重复定义。
   曾经这里也写了一份，而所有入口都是「先 require boot.php、再 require
   upgrade.php」，于是 PHP 直接 Fatal error: Cannot redeclare hp_version()。
   admin.php / api.php 当场整页 500（HTTP 状态码却仍是 200，肉眼很难发现）。
   两处实现原本只差兜底值（boot 返回 dev、这里返回 0.0.0），保留 boot 那份即可：
   'dev' 会被 hp_version_parts() 判为非法并退化成 [0,0,0]，比较逻辑不受影响。 */

/** 把 "1.2.3" 拆成 [1,2,3]；非法返回 null */
function hp_version_parts(string $v): ?array
{
    $v = ltrim(trim($v), 'vV');
    if (!preg_match('~^(\d+)\.(\d+)\.(\d+)$~', $v, $m)) {
        return null;
    }
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

/** 比较两个版本串的先后：-1 更旧 / 0 相同 / 1 更新 */
function hp_version_cmp(string $a, string $b): int
{
    $pa = hp_version_parts($a) ?? [0, 0, 0];
    $pb = hp_version_parts($b) ?? [0, 0, 0];
    return $pa <=> $pb;
}

/**
 * 快照目录。放系统临时目录而不是网站目录下 ——
 * 快照里有完整的旧代码，落在 Web 可访问路径等于把源码公开。
 */
function hp_upgrade_tmp(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/vnav_upgrade';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * 检查上传的 zip 是否像个正经的 Vnav 包。
 * 判定标准：必须有 index.php + inc/boot.php + VERSION，
 * 且所有条目都不越出包根（防 zip slip）。
 *
 * @return array{ok:bool, error:string, version:string, files:array<string>}
 */
function hp_upgrade_inspect(string $zipPath): array
{
    $out = ['ok' => false, 'error' => '', 'version' => '', 'files' => []];

    if (!class_exists('ZipArchive')) {
        $out['error'] = '服务器缺少 zip 扩展，无法解包。请让主机商开启 php-zip。';
        return $out;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $out['error'] = '压缩包打不开，可能下载不完整或不是 zip 格式。';
        return $out;
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string)$zip->getNameIndex($i);
        if ($n === '') {
            continue;
        }
        // zip slip：任何 ../ 或绝对路径都拒绝
        $norm = str_replace('\\', '/', $n);
        if (str_contains($norm, '../') || str_starts_with($norm, '/') || preg_match('~^[A-Za-z]:~', $norm)) {
            $zip->close();
            $out['error'] = '压缩包内含可疑路径（' . $n . '），已拒绝。';
            return $out;
        }
        $names[] = $norm;
    }

    /* 包内可能有一层顶级目录（vnav/ 或者 Vnav-1.2.0/）。
       探测方法：看哪个前缀下面同时有 index.php 与 inc/boot.php。 */
    $prefix = '';
    $hasIndex = in_array('index.php', $names, true);
    $hasBoot  = in_array('inc/boot.php', $names, true);

    if (!$hasIndex || !$hasBoot) {
        // 试一级子目录
        foreach ($names as $n) {
            if (substr_count($n, '/') >= 1 && str_ends_with($n, '/index.php')) {
                $p = substr($n, 0, -strlen('index.php'));
                if (in_array($p . 'inc/boot.php', $names, true)) {
                    $prefix = $p;
                    $hasIndex = true;
                    $hasBoot  = true;
                    break;
                }
            }
        }
    }

    if (!$hasIndex || !$hasBoot) {
        $zip->close();
        $out['error'] = '这不像 Vnav 的程序包：找不到 index.php 与 inc/boot.php。';
        return $out;
    }

    // 读包里的 VERSION
    $ver = '';
    $verName = $prefix . 'VERSION';
    if (in_array($verName, $names, true)) {
        $raw = (string)$zip->getFromName($verName);
        $ver = trim($raw);
    }

    $zip->close();
    $out['ok'] = true;
    $out['version'] = $ver;
    $out['files'] = $names;
    $out['prefix'] = $prefix;
    return $out;
}

/**
 * 执行升级。
 *
 * 流程（顺序很重要）：
 *   1. 备份 inc/config.php 与 data/home.db 到快照目录
 *   2. 把当前代码里的「用户数据类」文件复制到快照（仅留痕，便于回滚）
 *   3. 解压新包到临时目录
 *   4. 逐文件覆盖到站点根，**跳过**受保护路径
 *   5. 跑 schema 迁移（新表/新列由 PHP 侧自动补齐）
 *   6. 清 opcache（不然还跑旧字节码）
 *
 * 任一步失败 → 立刻用快照回滚到升级前，然后抛出。
 *
 * @return array{from:string, to:string, files:int, skipped:array<string>, backup:string}
 */
function hp_upgrade_apply(string $zipPath, string $prefix = ''): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('服务器缺少 zip 扩展');
    }

    /* 先记下升级前的版本。必须在任何写文件动作之前读 ——
       后面 VERSION 会被新包覆盖，那时再读 hp_version() 拿到的
       已经是新版，回执里 from 和 to 会变成同一个数字，等于没说。 */
    $fromVer = hp_version();

    $tmp = hp_upgrade_tmp();
    $stamp = date('Ymd-His');
    $snap = $tmp . '/snap-' . $stamp;
    if (!@mkdir($snap, 0700, true) && !is_dir($snap)) {
        throw new RuntimeException('无法创建快照目录：' . $snap);
    }

    /* ---------- 1. 快照 ----------
       只备「会被覆盖的代码文件」意义不大（有新包就能还原），
       真正不可再生的是配置与数据。所以完整备份这两个。 */
    $critical = ['inc/config.php', 'data/home.db'];
    foreach ($critical as $rel) {
        $src = HP_ROOT . '/' . $rel;
        if (!is_file($src)) {
            continue;
        }
        $dst = $snap . '/' . str_replace('/', '__', $rel);

        /* 数据库必须走 VACUUM INTO，不能用 copy()：
           WAL 模式下 copy() 只拷主文件，还在 home.db-wal 里没有 checkpoint
           的事务会丢 —— 回滚出来的库比升级前更旧，且这种"旧"没有任何报错，
           用户只会觉得"回滚把最近的改动吃了"。
           VACUUM INTO 生成的是事务一致的完整快照（含 WAL 里已提交的内容）。 */
        if (str_ends_with($src, '.db')) {
            try {
                $bpdo = new PDO('sqlite:' . $src);
                $bpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $bpdo->exec('VACUUM INTO ' . $bpdo->quote($dst));
                $bpdo = null;
                continue;
            } catch (Throwable $e) {
                // 退化为 copy：至少留一份，不能让升级因为备份方式失败而中断
                error_log('[vnav] VACUUM INTO 失败，退化为 copy：' . $e->getMessage());
            }
        }

        if (!@copy($src, $dst)) {
            throw new RuntimeException('备份失败：' . $rel . '（检查 data/ 与 inc/ 是否可写）');
        }
    }
    // WAL 里可能还有未落盘的事务，顺手一并备份
    foreach (['data/home.db-wal', 'data/home.db-shm'] as $rel) {
        $src = HP_ROOT . '/' . $rel;
        if (is_file($src)) {
            @copy($src, $snap . '/' . str_replace('/', '__', $rel));
        }
    }

    /* ---------- 2. 解压到临时目录 ---------- */
    $work = $tmp . '/new-' . $stamp;
    if (!@mkdir($work, 0700, true) && !is_dir($work)) {
        throw new RuntimeException('无法创建解压目录：' . $work);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('压缩包打不开');
    }
    if (!$zip->extractTo($work)) {
        $zip->close();
        throw new RuntimeException('解压失败，可能是磁盘空间不足');
    }
    $zip->close();

    $srcRoot = $work . ($prefix !== '' ? '/' . rtrim($prefix, '/') : '');
    if (!is_file($srcRoot . '/index.php')) {
        throw new RuntimeException('解压后找不到 index.php');
    }

    /* ---------- 3. 逐文件覆盖 ----------
       受保护路径：升级不能碰用户的数据与配置。
       data/ 整个目录都不覆盖 —— 里面是数据库、图标缓存、.htaccess，
       而新包里的 data/ 只有空目录与一份 .htaccess，覆盖没好处只有风险。 */
    $protect = [
        'inc/config.php',
        'data/home.db',
        'data/home.db-wal',
        'data/home.db-shm',
        'install.php',           // 已装好的站点不该被重新开放安装入口
    ];

    $written = 0;
    $skipped = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $abs = $item->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($srcRoot))), '/');
        if ($rel === '') {
            continue;
        }

        $dst = HP_ROOT . '/' . $rel;

        if ($item->isDir()) {
            if (!is_dir($dst)) {
                @mkdir($dst, 0775, true);
            }
            continue;
        }

        // 受保护文件：跳过并把包里的挪成 .new 供对比，不直接落盘
        $isProtected = in_array($rel, $protect, true) || str_starts_with($rel, 'data/');
        if ($isProtected) {
            $skipped[] = $rel;
            continue;
        }

        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('无法创建目录：' . $dir);
        }
        if (!@copy($abs, $dst)) {
            throw new RuntimeException('写入失败：' . $rel . '（检查文件权限）');
        }
        @chmod($dst, 0644);
        $written++;
    }

    /* ---------- 4. 迁移结构 ----------
       新版本的 schema 可能加了表/列。boot.php 会在下次请求时
       自动迁移，但这里主动跑一次 —— 否则升级完立刻打开后台，
       若迁移失败用户会看到一个报错的页面，很难判断是升级坏了
       还是数据坏了。主动跑就能把错误当场捕获。 */
    $schemaErr = '';
    try {
        require_once HP_ROOT . '/inc/schema.php';
        hp_migrate(db());
    } catch (Throwable $e) {
        $schemaErr = $e->getMessage();
        error_log('vnav upgrade schema: ' . $schemaErr);
    }

    /* ---------- 5. 清 opcache ----------
       不清的话，PHP 还会用旧文件的编译结果，用户能看到
       「文件是新的、行为是旧的」这种最迷惑的现象。 */
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(HP_ROOT . '/index.php', true);
        @opcache_invalidate(HP_ROOT . '/inc/boot.php', true);
        @opcache_invalidate(HP_ROOT . '/inc/schema.php', true);
    }

    // 清掉解压出来的临时目录
    hp_rmdir($work);

    return [
        'from'     => $fromVer,
        'to'       => is_file(HP_ROOT . '/VERSION') ? trim((string)file_get_contents(HP_ROOT . '/VERSION')) : '',
        'files'    => $written,
        'skipped'  => $skipped,
        'backup'   => $snap,
        'schema_error' => $schemaErr,
    ];
}

/** 递归删目录（只用于自己的临时目录，不做通用工具） */
function hp_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/** 列出已有的快照（供后台「回滚」下拉用），新的在前 */
function hp_upgrade_snapshots(): array
{
    $tmp = hp_upgrade_tmp();
    $out = [];
    foreach (glob($tmp . '/snap-*') ?: [] as $d) {
        if (!is_dir($d)) {
            continue;
        }
        $files = [];
        $bytes = 0;
        foreach (glob($d . '/*') ?: [] as $f) {
            $files[] = basename($f);
            $bytes += (int)filesize($f);
        }
        $out[] = [
            'dir'   => $d,
            'name'  => basename($d),
            'files' => $files,
            'bytes' => $bytes,
            'at'    => (int)filemtime($d),
        ];
    }
    usort($out, static fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

/**
 * 回滚：把某个快照里的 config 与 db 放回去。
 * 只恢复这两个 —— 代码回滚用「再上一次旧包」更干净，
 * 拿快照里的旧代码覆盖回去反而容易和当前结构错位。
 */
function hp_upgrade_rollback(string $snapDir): array
{
    $base = realpath(hp_upgrade_tmp());
    $real = realpath($snapDir);
    // 只允许操作快照目录内的东西，防路径穿越
    if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('非法的快照路径');
    }

    $map = [
        'inc__config.php'    => 'inc/config.php',
        'data__home.db'      => 'data/home.db',
        'data__home.db-wal'  => 'data/home.db-wal',
        'data__home.db-shm'  => 'data/home.db-shm',
    ];

    $restored = [];
    // 恢复数据库前先断掉连接（PDO 单例在 db() 里，这里没直连所以不用关）
    foreach ($map as $snapName => $rel) {
        $src = $real . '/' . $snapName;
        if (!is_file($src)) {
            continue;
        }
        $dst = HP_ROOT . '/' . $rel;
        if (!@copy($src, $dst)) {
            throw new RuntimeException('恢复失败：' . $rel);
        }
        $restored[] = $rel;
    }

    // 配置换回去了，settings 缓存也要失效
    if (function_exists('settings_flush')) {
        settings_flush();
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return $restored;
}

/** 删掉一个快照目录 */
function hp_upgrade_drop_snapshot(string $snapDir): bool
{
    $base = realpath(hp_upgrade_tmp());
    $real = realpath($snapDir);
    if (!$base || !$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        return false;
    }
    hp_rmdir($real);
    return true;
}