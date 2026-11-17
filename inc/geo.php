<?php
/**
 * IP 归属地库 —— 从 DB-IP Lite 下载并导入本地 SQLite。
 *
 * 为什么这么做：
 *   - 在线 IP 查询 API（ip-api.com 之类）每查一次都会把访客 IP
 *     发给第三方，且丢包/限流会让统计变脏，长期也不可持续。
 *   - DB-IP Lite 是 CC BY 4.0 的免费库，按月发布，导入后完全离线，
 *     查询实测 0.05ms（见下）。
 *
 * 两档精度（后台可选）：
 *   「国家库」   ≈ 4.6MB 下载 → 16.5 万段 → 数据库 +2.5MB。几秒完成。
 *                够用来做访问控制与「访客来自哪个国家」。
 *   「完整库」   ≈ 86MB 下载  → 209 万段 → 数据库 +35MB。一两分钟。
 *                多出省/市与运营商，够用来做地理分布明细。
 *
 * 实现要点：
 *   1. 流式解析 csv.gz，绝不整库读进内存（城市库 780 万行）。
 *   2. 只收 IPv4。IPv6 段的合并与二分是另一套逻辑，收益不成比例，
 *      而且家用宽带出口绝大多数还是 IPv4（双栈时优先取 v4）。
 *   3. 先写临时表，全部成功后一次性 RENAME 替换 —— 中途失败不会
 *      让站点停在「半个库」的中间态。
 *   4. 相邻网段合并：同一地点的连续段并成一段。裸库 360 万段，
 *      按国家合并后只剩 16.5 万段，二分查找的层数也跟着降。
 *   5. 每 2 万行提交一次事务。逐行提交在 SQLite 上慢两个数量级。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

const HP_GEO_SOURCE  = 'https://download.db-ip.com/free/';
const HP_GEO_LICENSE = 'DB-IP Lite (CC BY 4.0)';

/** 当月与往前两个月的文件名候选（当月没发布时自动回退） */
function hp_geo_candidates(string $kind): array
{
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $out[] = 'dbip-' . $kind . '-lite-' . date('Y-m', strtotime("-$i month")) . '.csv.gz';
    }
    return $out;
}

/** 库里现有数据的状态，给后台显示 */
function hp_geo_status(): array
{
    $out = ['city' => 0, 'asn' => 0, 'country' => 0, 'countries' => 0,
            'version' => '', 'imported_at' => '', 'places' => 0];
    try {
        $pdo = db();
        $out['city']      = (int)$pdo->query('SELECT COUNT(*) FROM geo_city')->fetchColumn();
        $out['asn']       = (int)$pdo->query('SELECT COUNT(*) FROM geo_asn')->fetchColumn();
        $out['country']   = (int)$pdo->query('SELECT COUNT(*) FROM geo_country')->fetchColumn();
        $out['places']    = (int)$pdo->query('SELECT COUNT(*) FROM geo_place')->fetchColumn();
        $out['countries'] = (int)$pdo->query("SELECT COUNT(DISTINCT cc) FROM geo_country WHERE cc <> ''")->fetchColumn();
        foreach ($pdo->query('SELECT k, v FROM geo_meta') as $r) {
            if ($r['k'] === 'version')     $out['version'] = (string)$r['v'];
            if ($r['k'] === 'imported_at') $out['imported_at'] = (string)$r['v'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** 下载一个文件到指定路径；失败抛 RuntimeException */
function hp_geo_download(string $url, string $dest): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('服务器未启用 curl 扩展，无法下载。请按部署说明手动导入。');
    }
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        throw new RuntimeException('临时目录不可写，请检查 data/ 权限。');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_TIMEOUT         => 900,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_USERAGENT       => 'vnav-geo-import/1.0',
        CURLOPT_FAILONERROR     => true,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $ok  = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok) {
        @unlink($dest);
        throw new RuntimeException('下载失败：' . ($err !== '' ? $err : '服务器返回错误'));
    }
    if (!is_file($dest) || filesize($dest) < 1024) {
        @unlink($dest);
        throw new RuntimeException('下载内容异常（文件过小）');
    }
}

/**
 * 从 DB-IP 的 csv.gz 里逐行取 IPv4 网段。
 * @param callable $onRange fn(int $start, int $end, array $cols): void
 * @return int 处理的有效行数
 */
function hp_geo_scan(string $gzPath, callable $onRange): int
{
    $fh = gzopen($gzPath, 'rb');
    if (!$fh) {
        throw new RuntimeException('地理库文件无法读取');
    }
    $rows = 0;
    $batch = 0;
    $pdo = db();
    $pdo->beginTransaction();

    while (($line = gzgets($fh)) !== false) {
        $p = explode(',', rtrim($line, "\r\n"));
        if (count($p) < 3) continue;
        // IPv6 行（形如 2001:200::）直接跳过
        if (str_contains($p[0], ':')) continue;

        $s = ip2long($p[0]);
        $e = ip2long($p[1]);
        if ($s === false || $e === false) continue;
        if ($s < 0) $s += 4294967296;
        if ($e < 0) $e += 4294967296;
        if ($e < $s) continue;

        $onRange($s, $e, $p);
        $rows++;

        if (++$batch >= 20000) {
            $pdo->commit();
            $pdo->beginTransaction();
            $batch = 0;
        }
    }
    $pdo->commit();
    gzclose($fh);
    return $rows;
}

/**
 * 导入国家表：raw 段 → 按国家合并成连续段。
 * 实测 360 万行 → 16.5 万段。
 */
function hp_geo_import_country(string $gzPath): int
{
    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS geo_country_new');
    $pdo->exec('CREATE TABLE geo_country_new (start INTEGER PRIMARY KEY, "end" INTEGER NOT NULL, cc TEXT NOT NULL)');
    $st = $pdo->prepare('INSERT OR REPLACE INTO geo_country_new(start,"end",cc) VALUES(?,?,?)');

    $cur = null; $cs = 0; $ce = -2; $seg = 0;

    hp_geo_scan($gzPath, function (int $s, int $e, array $p) use (&$cur, &$cs, &$ce, &$seg, $st) {
        $cc = strtoupper(trim($p[2] ?? ''));
        // ZZ = IANA 保留/未分配，按未知处理，不入库（查不到即未知）
        if ($cc === 'ZZ' || $cc === '') {
            if ($cur !== null) { $st->execute([$cs, $ce, $cur]); $seg++; $cur = null; }
            return;
        }
        if ($cc === $cur && $s === $ce + 1) { $ce = $e; return; }
        if ($cur !== null) { $st->execute([$cs, $ce, $cur]); $seg++; }
        $cur = $cc; $cs = $s; $ce = $e;
    });
    if ($cur !== null) { $st->execute([$cs, $ce, $cur]); $seg++; }

    $pdo->beginTransaction();
    $pdo->exec('DROP TABLE IF EXISTS geo_country');
    $pdo->exec('ALTER TABLE geo_country_new RENAME TO geo_country');
    $pdo->commit();

    return $seg;
}

/**
 * 导入城市表：raw 段 → 按「国家|省|市」合并，地点名去重到 geo_place，
 * 段表只存 pid（外键），避免 209 万行重复存同样的字符串。
 * 实测 360 万行 → 209 万段 + 约 3700 个地点。
 */
function hp_geo_import_city(string $gzPath): array
{
    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS geo_city_new');
    $pdo->exec('DROP TABLE IF EXISTS geo_place_new');
    $pdo->exec('CREATE TABLE geo_city_new (start INTEGER PRIMARY KEY, "end" INTEGER NOT NULL, pid INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE geo_place_new (id INTEGER PRIMARY KEY, cc TEXT NOT NULL, region TEXT NOT NULL, city TEXT NOT NULL)');

    $stSeg = $pdo->prepare('INSERT OR REPLACE INTO geo_city_new(start,"end",pid) VALUES(?,?,?)');
    $stPl  = $pdo->prepare('INSERT OR REPLACE INTO geo_place_new(id,cc,region,city) VALUES(?,?,?,?)');

    $places = [];          // key => id
    $pending = [];         // id => [cc, region, city]
    $curId = null; $cs = 0; $ce = -2; $seg = 0;

    hp_geo_scan($gzPath, function (int $s, int $e, array $p) use (&$curId, &$cs, &$ce, &$seg, &$places, &$pending, $stSeg) {
        $cc     = strtoupper(trim($p[2] ?? ''));
        $region = isset($p[3]) ? trim($p[3], " \"") : '';
        $city   = isset($p[4]) ? trim($p[4], " \"") : '';
        if ($cc === 'ZZ') $cc = '';
        if ($cc === '' && $region === '' && $city === '') {
            if ($curId !== null) { $stSeg->execute([$cs, $ce, $curId]); $seg++; $curId = null; }
            return;
        }
        $key = $cc . "\x01" . $region . "\x01" . $city;
        if (!isset($places[$key])) {
            $id = count($places) + 1;
            $places[$key] = $id;
            $pending[$id] = [$cc, mb_substr($region, 0, 60), mb_substr($city, 0, 60)];
        }
        $pid = $places[$key];

        if ($pid === $curId && $s === $ce + 1) { $ce = $e; return; }
        if ($curId !== null) { $stSeg->execute([$cs, $ce, $curId]); $seg++; }
        $curId = $pid; $cs = $s; $ce = $e;
    });
    if ($curId !== null) { $stSeg->execute([$cs, $ce, $curId]); $seg++; }

    // 地点表最后一次性写入（3700 行，一次事务）
    $pdo->beginTransaction();
    foreach ($pending as $id => $v) {
        $stPl->execute([$id, $v[0], $v[1], $v[2]]);
    }
    $pdo->exec('DROP TABLE IF EXISTS geo_city');
    $pdo->exec('ALTER TABLE geo_city_new RENAME TO geo_city');
    $pdo->exec('DROP TABLE IF EXISTS geo_place');
    $pdo->exec('ALTER TABLE geo_place_new RENAME TO geo_place');
    $pdo->commit();

    return ['seg' => $seg, 'places' => count($places)];
}

/** 导入 ASN（运营商 / 机构）。实测 48.5 万行。 */
function hp_geo_import_asn(string $gzPath): int
{
    $pdo = db();
    $pdo->exec('DROP TABLE IF EXISTS geo_asn_new');
    $pdo->exec('CREATE TABLE geo_asn_new (start INTEGER PRIMARY KEY, "end" INTEGER NOT NULL, asn TEXT NOT NULL DEFAULT \'\', org TEXT NOT NULL DEFAULT \'\')');
    $st = $pdo->prepare('INSERT OR REPLACE INTO geo_asn_new(start,"end",asn,org) VALUES(?,?,?,?)');

    $n = hp_geo_scan($gzPath, function (int $s, int $e, array $p) use ($st) {
        $st->execute([$s, $e, trim($p[2] ?? '', " \""), mb_substr(trim($p[3] ?? '', " \""), 0, 80)]);
    });

    $pdo->beginTransaction();
    $pdo->exec('DROP TABLE IF EXISTS geo_asn');
    $pdo->exec('ALTER TABLE geo_asn_new RENAME TO geo_asn');
    $pdo->commit();

    return $n;
}

/**
 * 完整流程。
 * @param string   $scope 'country'（只导国家+运营商，快）| 'full'（再加城市，慢）
 * @param callable|null $log 进度回调，参数是一句中文说明
 */
function hp_geo_import(string $scope = 'country', ?callable $log = null): array
{
    $say = $log ?: static function (string $s): void {};

    /* 临时文件放系统临时目录，不放 data/ 下。
       理由：这里是 90MB 量级的下载中转，落在网站可访问目录里，
       一旦 .htaccess 没生效（Nginx 忘配、或主机忽略 AllowOverride），
       等于把整个地理库公开给人下。系统临时目录天然不在 Web 根下。 */
    $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/vnav_geo_' . bin2hex(random_bytes(6));
    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new RuntimeException('无法创建临时目录：' . $tmpDir);
    }

    $pdo = db();
    // 大批量写入时关闭同步能快 3~5 倍；导入完立刻恢复。
    // 这是离线导入路径，不承担在线读写的一致性责任。
    $pdo->exec('PRAGMA synchronous=OFF');

    $res = ['version' => '', 'country' => 0, 'city' => 0, 'places' => 0, 'asn' => 0];

    try {
        /* ---- 1. 下载需要的文件 ---- */
        $need = $scope === 'full' ? ['country', 'city', 'asn'] : ['country', 'asn'];
        $files = [];

        foreach ($need as $kind) {
            $got = null;
            foreach (hp_geo_candidates($kind) as $name) {
                $say('正在下载 ' . $name . ' …（' . ($kind === 'city' ? '约 86MB，请耐心等' : '约 5MB') . '）');
                $dest = $tmpDir . '/' . $name;
                try {
                    hp_geo_download(HP_GEO_SOURCE . $name, $dest);
                    $got = $dest;
                    $eta = preg_replace('~^dbip-' . $kind . '-lite-|\.csv\.gz$~', '', $name) ?? '';
                    if ($kind === 'country') $res['version'] = $eta;
                    break;
                } catch (RuntimeException $e) {
                    $say('该月份不可用，试上一个…');
                }
            }
            if ($got === null) {
                if ($kind === 'asn') {
                    $say('运营商库下载失败，跳过（国家与城市不受影响）。');
                    continue;
                }
                throw new RuntimeException($kind === 'city'
                    ? '城市库三个月份都下载失败。可先只导国家库。'
                    : '国家库三个月份都下载失败，请检查服务器网络。');
            }
            $files[$kind] = $got;
        }

        /* ---- 2. 导入 ---- */
        if (isset($files['country'])) {
            $say('正在导入国家表…');
            $res['country'] = hp_geo_import_country($files['country']);
            @unlink($files['country']);
        }

        if (isset($files['city'])) {
            $say('正在导入城市表（780 万行，约一分钟）…');
            $c = hp_geo_import_city($files['city']);
            $res['city']   = $c['seg'];
            $res['places'] = $c['places'];
            @unlink($files['city']);
        }

        if (isset($files['asn'])) {
            $say('正在导入运营商表…');
            $res['asn'] = hp_geo_import_asn($files['asn']);
            @unlink($files['asn']);
        }

        /* ---- 3. 元信息 ---- */
        $st = $pdo->prepare('INSERT OR REPLACE INTO geo_meta(k, v) VALUES(?, ?)');
        foreach ([
            'version'     => $res['version'] !== '' ? $res['version'] : date('Y-m'),
            'imported_at' => now(),
            'source'      => HP_GEO_LICENSE,
            'scope'       => $scope,
            'city_rows'   => (string)$res['city'],
            'asn_rows'    => (string)$res['asn'],
        ] as $k => $v) {
            $st->execute([$k, $v]);
        }

        /* ---- 4. 回填历史上标成「未知」的访问记录 ---- */
        $say('正在回填历史未知地区…');
        $fixed = hp_geo_backfill(3000);
        if ($fixed > 0) $say('已回填 ' . $fixed . ' 条历史记录。');

        $say('全部完成。');
    } finally {
        // 失败时也必须清临时文件：留 90MB 垃圾比失败本身更糟
        foreach (glob($tmpDir . '/*.csv.gz') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmpDir);

        /* 必须先收尾事务再改 PRAGMA。
           SQLite 的 synchronous 是连接级设置，事务里改会直接抛
           "Safety level may not be changed inside a transaction"。
           中途出错时事务正开着，不 rollback 就永远走不到恢复那一步 ——
           结果这条连接在整个请求里都是 synchronous=OFF，
           后面写库的每一次 commit 都不落盘。 */
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
        }
        try {
            $pdo->exec('PRAGMA synchronous=NORMAL');
        } catch (Throwable $e) {
        }
    }

    return $res;
}

/** 回填历史上 country 为空的访问记录（只处理最近的 N 条，老记录价值低） */
function hp_geo_backfill(int $limit = 3000): int
{
    try {
        $pdo = db();
        $rows = $pdo->query(
            "SELECT id, ip FROM visits WHERE country = '' AND ip <> '' ORDER BY id DESC LIMIT " . max(1, $limit)
        )->fetchAll();
        if (!$rows) return 0;

        $up = $pdo->prepare('UPDATE visits SET country = ?, region = ?, city = ?, isp = ? WHERE id = ?');
        $pdo->beginTransaction();
        $n = 0;
        try {
            foreach ($rows as $r) {
                $g = hp_geo_lookup((string)$r['ip']);
                if ($g['country'] === '') continue;
                $up->execute([
                    $g['country'], mb_substr($g['region'], 0, 60),
                    mb_substr($g['city'], 0, 60), mb_substr($g['isp'], 0, 80), $r['id'],
                ]);
                $n++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            // 半截提交比不提交更糟：数字看着变了但只变了一半
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}