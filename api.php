<?php
/**
 * 唯一写接口。所有写操作都要过：登录态 → CSRF → 参数校验。
 */
declare(strict_types=1);
define('HP_ROOT', __DIR__);
require __DIR__ . '/inc/boot.php';
require __DIR__ . '/inc/MetaFetcher.php';
require __DIR__ . '/inc/analytics.php';
require __DIR__ . '/inc/acl.php';
require __DIR__ . '/inc/geo.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// 接口响应都是小 JSON，压一下省流量；错误响应也一并压
hp_compress_start(5);

$method = $_SERVER['REQUEST_METHOD'];

// 「点击热度」用 sendBeacon 发，不带自定义头，所以单独放行（只写 hits，无风险）
$raw  = file_get_contents('php://input');
$body = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
$action = (string)($body['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '');

/* ---------- 数据导出 ----------
   下载必须走 GET（浏览器只能这样触发文件下载），所以放在 POST 检查之前。
   鉴权与 CSRF 一样不能少：CSRF 用查询参数传，因为 GET 带不了自定义头。 */
if ($action === 'export') {
    require_login();
    $tok = (string)($_GET['csrf'] ?? '');
    if (!hash_equals(csrf_token(), $tok)) {
        http_response_code(403);
        exit('CSRF 校验失败，请从后台页面重新点击导出。');
    }

    $pdo = db();
    /* 单人模式：全站只有站长那一份，非 admin 一律拒绝导出。
       否则一个"切模式之前登录、会话还没过期"的普通账号能把站点内容
       连配置与防御姿态一起导走（settings 共 23 项）。 */
    if (hp_single_mode() && !is_admin()) {
        http_response_code(403);
        exit('当前为单人模式，只有站长可以导出站点');
    }
    /* 之前这里三条查询全是全表：任意注册用户带自己的 csrf 就能导出
       站点默认库和其他所有用户的链接/分组/设置。现在按当前身份算 uid，
       三条都带 WHERE user_id = ?。导出格式（version=1、settings 扁平 k/v）
       保持不变，老备份照样能导入。管理员要整站备份请直接从服务器取
       data/home.db —— 不为此开一个可被越权调用的全量分支。 */
    $uid = hp_read_uid_for((string)($_GET['lib'] ?? 'site'));
    $gs = $pdo->prepare('SELECT * FROM groups WHERE user_id = ? ORDER BY sort ASC, id ASC');
    $gs->execute([$uid]);
    $ls = $pdo->prepare('SELECT * FROM links WHERE user_id = ? ORDER BY sort ASC, id ASC');
    $ls->execute([$uid]);
    $ss = $pdo->prepare('SELECT k, v FROM settings WHERE user_id = ? ORDER BY k ASC');
    $ss->execute([$uid]);
    $settings = [];
    foreach ($ss as $r) {
        $settings[$r['k']] = $r['v'];
    }
    $dump = [
        'format'     => 'vnav-backup',
        'version'    => 1,
        'exported_at'=> date('c'),
        'groups'     => $gs->fetchAll(),
        'links'      => $ls->fetchAll(),
        'settings'   => $settings,
    ];

    $json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        // 兜底：任何非法 UTF-8 都会让 json_encode 静默返回 false
        $json = json_encode($dump, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="vnav-backup-' . date('Ymd-His') . '.json"');
    /* 不要设 Content-Length：hp_compress_start() 的 ob 回调会把整个响应
       gzip 掉，而自定义回调不像 ob_gzhandler 那样自动清掉这个头 ——
       声明长度 ≠ 实际字节，浏览器会中途断开、文件截断
       （实测：声明 3600、实收 64 字节、curl 退出码 18）。 */
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

if ($method !== 'POST') {
    /* 搜索建议：公开只读，游客也要用。必须由服务端代取 ——
       本站 CSP 是 connect-src 'self'，浏览器直连百度 / 必应会被自己拦掉。
       拿不到就是空数组，前端不显示下拉，不影响搜索本身。 */
    if ($action === 'suggest') {
        json_out(hp_suggest((string)($_GET['engine'] ?? ''), (string)($_GET['q'] ?? '')));
    }
    // 换一张验证码走 GET：登录页的 <img> 与刷新按钮都只能发 GET
    if ($action === 'captcha_new') {
        require __DIR__ . '/inc/captcha.php';
        session_boot(); // 验证码答案要写 session

        /* 按 IP 限流：原来按 session 计数，请求时不带 cookie 就能无限
           绕过，把这里当成免费的 PNG 生成器（一次 GD 绘图 + 一条 INSERT）。
           固定窗口 10 秒最多 6 张。 */
        if (!hp_rate_allow('cap:' . hp_client_ip(), 6, 10)) {
            json_err('请求过于频繁，请稍后再试', 429);
        }
        [$token] = hp_captcha_new();
        json_ok(['token' => $token]);
    }
    json_err('仅支持 POST', 405);
}

// 热度+1：允许游客触发，但只动 hits 一列，无其他副作用。
// 同时往 clicks 明细表写一行（带 IP 归属与来源分类），统计页要用。
if ($action === 'hit') {
    $id = (int)($body['id'] ?? 0);
    if ($id > 0) {
        /* 同一 IP 对同一链接 30 秒内只计一次。之前游客可无限调用，
           既污染统计又让 clicks 明细表膨胀。静默忽略而非报错，
           不影响正常用户的点击体验。 */
        if (hp_rate_allow('hit:' . $id . ':' . hp_client_ip(), 1, 30)) {
            db()->prepare('UPDATE links SET hits = hits + 1 WHERE id = ?')->execute([$id]);
            hp_log_click($id);
        }
    }
    json_ok();
}

/* ---------- 首页弹窗登录（游客可用） ----------
   登录页 login.php 保留给注册与「渐进式验证码」的完整流程；
   这里给首页右上角那颗「登录」弹窗用，只做账号密码校验，
   但复用同一套 login_attempts / login_locks 限流表，不降低防护强度。
   验证码走 api.php?action=captcha_new 取 token、login.php?captcha= 出图。 */
if ($action === 'login') {
    require __DIR__ . '/inc/captcha.php';
    session_boot(); // 登录成功要写 session；限流计数落库不依赖 session

    if (is_logged_in()) json_ok(['already' => true]);

    $u = trim((string)($body['user'] ?? ''));
    $p = (string)($body['pass'] ?? '');
    $capTok = (string)($body['captcha_token'] ?? '');
    $capIn  = (string)($body['captcha'] ?? '');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lockIp  = 'ip:' . $ip;
    $lockUsr = 'usr:' . hash('sha256', mb_strtolower($u !== '' ? $u : '-'));
    $WINDOW = 900; $LIMIT = 5; $LOCKSEC = 900;
    $pdo = db();

    $lockLeft = static function (string $k) use ($pdo): int {
        $st = $pdo->prepare('SELECT until FROM login_locks WHERE k = ?');
        $st->execute([$k]);
        return max(0, (int)($st->fetchColumn() ?: 0) - time());
    };
    $wait = max($lockLeft($lockIp), $lockLeft($lockUsr));
    if ($wait > 0) {
        json_err('尝试次数过多，请 ' . (int)ceil($wait / 60) . ' 分钟后再试', 429);
    }

    $st = $pdo->prepare('SELECT fails, last_at FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch() ?: ['fails' => 0, 'last_at' => 0];
    $fails = ((time() - (int)$row['last_at']) > $WINDOW) ? 0 : (int)$row['fails'];
    $captchaOn = hp_captcha_available() && setting('login_captcha', '1') === '1';
    $needCaptcha = $captchaOn && $fails >= 1;

    // 验证码先验
    if ($needCaptcha && !hp_captcha_check($capTok, $capIn)) {
        /* $capTok 刚被 hp_captcha_check() 无条件删掉（无论对错），
           原样回传就是回传一个死码 —— 前端拿它取图只会得到「已过期」。
           这里发一张新的，与下面口令错误分支保持一致。 */
        [$nt] = hp_captcha_new();
        json_out(['ok' => false, 'error' => '验证码不正确', 'need_captcha' => true,
                  'captcha_token' => $nt], 401);
    }

    // 账号密码校验
    $st = $pdo->prepare('SELECT id, username, pass_hash, role FROM users WHERE username = ?');
    $st->execute([$u]);
    $acc = $st->fetch();
    if ($acc && password_verify($p, (string)$acc['pass_hash'])) {
        /* 单人模式：只有站长（admin）能登录。普通账号即使密码正确也拒绝，
           不删数据、不计入失败次数（否则会误触发锁定），明确告知原因。 */
        if (hp_single_mode() && (string)($acc['role'] ?? 'user') !== 'admin') {
            json_out(['ok' => false, 'error' => '当前为单人模式，只有站长可以登录'], 403);
        }
        $uid = (int)$acc['id'];
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
        $pdo->prepare('DELETE FROM login_locks WHERE k IN (?, ?)')->execute([$lockIp, $lockUsr]);
        $pdo->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([now(), $uid]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
        $_SESSION['logged_at'] = time();
        current_user_flush();
        json_ok(['username' => (string)$acc['username'],
                 'role'     => (string)($acc['role'] ?? 'user')]);
    }

    // 失败：计数 +1，必要时锁定
    $fails = ((time() - (int)$row['last_at']) > $WINDOW) ? 1 : ($fails + 1);
    $pdo->prepare(
        'INSERT INTO login_attempts(ip, fails, last_at) VALUES(?, ?, ?)
         ON CONFLICT(ip) DO UPDATE SET fails = excluded.fails, last_at = excluded.last_at'
    )->execute([$ip, $fails, time()]);

    if ($fails >= $LIMIT) {
        $why = 'fails=' . $fails;
        foreach ([$lockIp, $lockUsr] as $k) {
            $pdo->prepare(
                'INSERT INTO login_locks(k, until, reason, at) VALUES(?, ?, ?, ?)
                 ON CONFLICT(k) DO UPDATE SET until = excluded.until, reason = excluded.reason, at = excluded.at'
            )->execute([$k, time() + $LOCKSEC, $why, time()]);
        }
        json_out(['ok' => false, 'error' => '连续失败 ' . $LIMIT . ' 次，已锁定 15 分钟'], 429);
    }

    $err = '账号或密码不正确。';
    $left = $LIMIT - $fails;
    if ($left > 0 && $left <= 3) $err .= "（还可尝试 {$left} 次）";
    $payload = ['ok' => false, 'error' => $err];
    if ($captchaOn) {
        [$nt] = hp_captcha_new();
        $payload['need_captcha'] = true;
        $payload['captcha_token'] = $nt;
    }
    json_out($payload, 401);
}

require_login();
csrf_check();

if (!is_logged_in()) {
    json_err('游客数据只保存在本地浏览器，登录后才能同步到云端', 401);
}

/* 本次请求能操作哪一份数据。
 *
 * admin 有两份库（站点默认 / 自己的），由前端在每次请求里带 lib 字段
 * 指明改哪一份（见 admin.js 的 api()，它从 <meta name="adm-lib"> 读当前值）。
 * 服务端只认 site / mine 两个值，且只有 admin 能落到 mine ——
 * 普通用户即使手动伪造 lib=mine，hp_data_uid_for() 也只会返回他自己的 id。
 *
 * 这是**服务端**的归属判定，不接受客户端传 user_id：
 * 传什么 lib 决定「用哪个库」，但库与用户的绑定由服务端算。 */
$__lib = (string)($body['lib'] ?? $_GET['lib'] ?? 'site');
if ($__lib !== 'mine') {
    $__lib = 'site'; // 脏值一律回落到默认库，不做猜测
}
/* 单人模式：只有站长能写。非 admin 的会话若在切模式前就登录了（30 天），
   不拦的话 $U 会落到站长库 —— 那就是"普通账号改到访客看到的站点内容"。 */
if (hp_single_mode() && !is_admin()) {
    json_err('当前为单人模式，只有站长可以修改站点', 403);
}
$U = hp_write_uid_for($__lib);

/* 系统级接口：只有 admin。
   这一层是硬闸门 —— 前端把按钮藏起来只是体验，绕过按钮直接打
   api.php 一样要过这里。 */
$ADMIN_ONLY = [
    'acl_set', 'acl_clear', 'stats_clear', 'stats_prune',
    'geo_import', 'geo_clear', 'geo_backfill',
    'change_pass', 'user_reset_pass', 'user_delete', 'set_captcha', 'unlock_all',
    'set_site_mode',
    'icon_sync', 'icon_refill', 'icon_gc', 'icon_purge', 'icon_webp',
    'upgrade_apply', 'upgrade_rollback', 'upgrade_drop_snapshot', 'upgrade_inspect',
    'meta_refresh', 'meta_refresh_stats',
];
if (in_array($action, $ADMIN_ONLY, true)) {
    require_admin();
}

try {
    switch ($action) {
        /* ---------- 新增链接（粘贴或表单） ---------- */
        case 'add': {
            $url   = safe_url((string)($body['url'] ?? ''));
            $title = trim((string)($body['title'] ?? ''));
            $gid   = (int)($body['group_id'] ?? 0);
            $icon  = trim((string)($body['icon'] ?? ''));
            $descr = trim((string)($body['descr'] ?? ''));
            // 隐藏链接：1 = 只有登录后可见，0 = 所有人可见
            $hidden = !empty($body['hidden']) && $body['hidden'] !== '0' ? 1 : 0;

            if ($url === null) {
                json_err('网址格式不正确，只支持 http/https');
            }
            if ($title === '') {
                $title = host_of($url);
            }
            $title = mb_substr($title, 0, 60);

            // 重复检测：同一网址不重复入库
            $dup = db()->prepare('SELECT id FROM links WHERE url = ? AND user_id = ?');
            $dup->execute([$url, $U]);
            if ($dup->fetchColumn()) {
                json_err('这个网址已经在主页里了');
            }

            // 前端没给图标时（比如从管理页添加），后端补抓一次
            if ($icon === '' && $CONFIG['fetch_meta']) {
                $meta = (new MetaFetcher((int)$CONFIG['fetch_timeout']))->fetch($url);
                if ($icon === '' && !empty($meta['icon'])) {
                    $icon = $meta['icon'];
                }
                if ($descr === '' && !empty($meta['desc'])) {
                    $descr = $meta['desc'];
                }
            }
            /* 图标收两种：
                 file   → data/icons/ 下的本地缓存文件（服务器抓取并落地的）
                 remote → 公网 https 地址，不落地，由浏览器直接引用。
                remote 是给「服务器抓不到、但本机能访问」的站点准备的
               （典型是被墙站点）：那种站点的图标只有本地网络加载得出来。
               其余一律清空，绝不让内网地址或任意字符串进库。 */
            $iconKind = 'auto';
            if ($icon !== '') {
                if (preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', $icon)) {
                    $iconKind = 'file';
                } elseif (hp_remote_icon_ok($icon)) {
                    $iconKind = 'remote';
                } else {
                    $icon = '';
                }
            }

            if ($gid > 0) {
                $chk = db()->prepare('SELECT 1 FROM groups WHERE id = ? AND user_id = ?');
                $chk->execute([$gid, $U]);
                if (!$chk->fetchColumn()) {
                    $gid = 0;
                }
            }

            $stMax = db()->prepare('SELECT COALESCE(MAX(sort), 0) FROM links WHERE user_id = ?');
            $stMax->execute([$U]);
            $maxSort = (int)$stMax->fetchColumn();
            $descr = mb_substr($descr, 0, 120);
            $st = db()->prepare(
                'INSERT INTO links(user_id, group_id, title, url, icon, icon_kind, descr, sort, hidden, created_at, updated_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$U, $gid ?: null, $title, $url, $icon, $iconKind, $descr, $maxSort + 10, $hidden, now(), now()]);
            $id = (int)db()->lastInsertId();

            json_ok([
                'link' => [
                    'id'    => $id,
                    'title' => $title,
                    'url'   => $url,
                    'icon'  => $icon,
                    'descr' => $descr,
                    'host'  => host_of($url),
                    'hue'   => hue_of(host_of($url)),
                    'group_id' => $gid,
                    'hidden'   => $hidden,
                ],
            ]);
        }

        /* ---------- 抓取元信息（粘贴时先认名称/图标） ---------- */
        case 'identify': {
            if (!$CONFIG['fetch_meta']) {
                json_err('服务端已关闭自动识别');
            }
            $url = safe_url((string)($body['url'] ?? ''));
            if ($url === null) {
                json_err('网址格式不正确');
            }
            if (!MetaFetcher::isPublicUrl($url)) {
                json_err('不允许访问内网地址');
            }

            /* 前端手上已经有图标时（本机网络探到 ≥48px 的），让它带过来：
               · want_icon=0  → 跳过下载图标，省掉一次请求
               · local_icon   → 这张就用上，标成 remote（不落地）
               图标本身只有 1~2KB，省的不是流量，是「没必要的那一趟」。
               标题只能由服务器读，所以 HTML 照抓。 */
            $wantIcon = !(isset($body['want_icon']) && (string)$body['want_icon'] === '0');
            $localIcon = trim((string)($body['local_icon'] ?? ''));
            if ($localIcon !== '' && !hp_remote_icon_ok($localIcon)) {
                $localIcon = '';
            }

            $meta = (new MetaFetcher((int)$CONFIG['fetch_timeout']))->fetch($url, $wantIcon);
            if (!$wantIcon && $localIcon !== '') {
                $meta['icon'] = $localIcon;
                $meta['icon_kind'] = 'remote';
            }
            json_ok(['meta' => $meta]);
        }

        /* ---------- 编辑链接 ---------- */
        case 'update': {            $id    = (int)($body['id'] ?? 0);
            $title = trim((string)($body['title'] ?? ''));
            $url   = safe_url((string)($body['url'] ?? ''));
            $gid   = (int)($body['group_id'] ?? 0);
            $descr = trim((string)($body['descr'] ?? ''));
            $icon  = trim((string)($body['icon'] ?? ''));
            /* hidden 不再是「设置项」，但这一列还在库里（历史数据与老客户端）。
               规则改成「不传就不动」：前端已经把那个开关撤掉了，
               如果照旧无条件写 0，管理员编辑一条历史隐藏条目就会把它公开。
               传了才写，兼容仍在用旧前端的场景。 */
            $hiddenSet = array_key_exists('hidden', $body);
            $hidden    = $hiddenSet ? (!empty($body['hidden']) && $body['hidden'] !== '0' ? 1 : 0) : 0;

            if ($id <= 0) json_err('缺少链接 ID');
            if ($url === null) json_err('网址格式不正确');
            if ($title === '') $title = host_of($url);

            $dup = db()->prepare('SELECT id FROM links WHERE url = ? AND id <> ? AND user_id = ?');
            $dup->execute([$url, $id, $U]);
            if ($dup->fetchColumn()) json_err('另一个链接已经用了这个网址');

            /* 图标也是「传了非空才动」。
               原先无条件写：后台编辑表单根本不传图标（icon 恒为空串），
               于是一保存就把已有图标连同 icon_kind 一起清掉 ——
               本地缓存图和 remote 外链图都中招，只剩字母头像。
               现在空值一律视为「没改」，想换图标就传新地址。 */
            $writeIcon = array_key_exists('icon', $body);
            $iconKind  = '';
            if ($icon !== '') {
                /* 图标规则同 add：本地缓存文件，或本机网络取到的公网 https 地址 */
                if (preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', $icon)) {
                    $iconKind = 'file';
                } elseif (hp_remote_icon_ok($icon)) {
                    $iconKind = 'remote';
                } else {
                    $icon = '';
                }
            }
            if ($icon === '' || $iconKind === '') {
                $writeIcon = false;
            }

            $st = db()->prepare(
                'UPDATE links SET title = ?, url = ?, group_id = ?, descr = ?,'
                . ($writeIcon ? ' icon = ?, icon_kind = ?,' : '')
                . ($hiddenSet ? ' hidden = ?,' : '')
                . ' updated_at = ?
                 WHERE id = ? AND user_id = ?'
            );
            $vals = [mb_substr($title, 0, 60), $url, $gid ?: null, mb_substr($descr, 0, 120)];
            if ($writeIcon) {
                $vals[] = $icon;
                $vals[] = $iconKind;
            }
            if ($hiddenSet) {
                $vals[] = $hidden;
            }
            $vals[] = now();
            $vals[] = $id;
            $vals[] = $U;
            $st->execute($vals);
            /* 0 行受影响 = 这条链接不属于当前用户（WHERE 里的 user_id 挡住了）。
               必须报错而不是返回 ok —— 否则越权尝试会显示「保存成功」，
               用户以为改到了别人的数据。 */
            if ($st->rowCount() === 0) json_err('没有这条链接', 404);
            json_ok();
        }

        /* ---------- 只切隐藏开关（列表里一键切换，不用开编辑表单） ---------- */
        case 'set_hidden': {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) json_err('缺少链接 ID');
            $hidden = !empty($body['hidden']) && $body['hidden'] !== '0' ? 1 : 0;
            $stH = db()->prepare('UPDATE links SET hidden = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $stH->execute([$hidden, now(), $id, $U]);
            if ($stH->rowCount() === 0) json_err('没有这条链接', 404);
            json_ok(['hidden' => $hidden]);
        }

        /* ---------- 删除 ---------- */
        case 'delete': {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) json_err('缺少链接 ID');
            $stD = db()->prepare('DELETE FROM links WHERE id = ? AND user_id = ?');
            $stD->execute([$id, $U]);
            if ($stD->rowCount() === 0) json_err('没有这条链接', 404);
            json_ok();
        }

        /* ---------- 批量操作 ----------
           后台列表「勾选一批 → 一个动作」走这里。
           一律带 user_id 条件：管理员在默认数据模式下 $U 是 0，
           普通用户就是自己的 id —— 和单条操作共用同一道权限闸门，
           不因为「批量」就放宽。 */
        case 'bulk': {
            $ids = $body['ids'] ?? [];
            $op  = (string)($body['op'] ?? '');
            if (!is_array($ids) || !$ids) json_err('没有选中任何链接');
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $ids),
                static fn($v) => $v > 0
            )));
            if (!$ids) json_err('没有选中任何链接');
            if (count($ids) > 500) json_err('一次最多处理 500 条');

            $in   = implode(',', array_fill(0, count($ids), '?'));
            $args = $ids;
            $args[] = $U;

            switch ($op) {
                case 'delete':
                    $st = db()->prepare("DELETE FROM links WHERE id IN ($in) AND user_id = ?");
                    $st->execute($args);
                    json_ok(['affected' => $st->rowCount()]);

                case 'hide':
                case 'show':
                    $st = db()->prepare("UPDATE links SET hidden = ?, updated_at = ? WHERE id IN ($in) AND user_id = ?");
                    $st->execute([$op === 'hide' ? 1 : 0, now(), ...$args]);
                    json_ok(['affected' => $st->rowCount()]);

                case 'move': {
                    $gid = (int)($body['group_id'] ?? 0);
                    if ($gid <= 0) json_err('缺少目标分组');
                    $ok = db()->prepare('SELECT 1 FROM groups WHERE id = ? AND user_id = ?');
                    $ok->execute([$gid, $U]);
                    if (!$ok->fetchColumn()) json_err('目标分组不存在', 404);
                    $st = db()->prepare("UPDATE links SET group_id = ?, updated_at = ? WHERE id IN ($in) AND user_id = ?");
                    $st->execute([$gid, now(), ...$args]);
                    json_ok(['affected' => $st->rowCount()]);
                }

                default:
                    json_err('未知的批量操作');
            }
        }

        /* ---------- 批量拉取：只处理勾选的那几条 ----------
           和 icon_refill / meta_refresh 的区别不是参数，是权限模型：
             · 那两个是后台运维动作，扫的是整库，admin-only
             · 这个是前台编辑态给任何登录用户用的「刷新我选的这几条」
           所以 $U 不是可选参数而是这条接口的边界 —— 底下两个函数都带了
           user_id 条件，选中别人的 id 会查不到行，也就什么都不会发生。 */
        case 'bulk_fetch': {
            $raw = $body['ids'] ?? [];
            if (!is_array($raw) || !$raw) json_err('没有选中任何链接');
            $ids = [];
            foreach ($raw as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[] = $v;
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) json_err('没有选中任何链接');
            if (count($ids) > 20) json_err('一次最多处理 20 条');

            $mode = (string)($body['mode'] ?? 'meta');
            /* 抓外站是慢活：图标那一路是串行的（每个站点都要先取 HTML 再取图），
               20 条能跑到二三十秒，默认 30 秒的执行上限不够用。 */
            @set_time_limit(150);

            if ($mode === 'icon') {
                require_once __DIR__ . '/inc/icons.php';
                json_ok(['mode' => 'icon', 'result' => hp_icon_refill(20, $ids, $U)]);
            }
            require_once __DIR__ . '/inc/refresh.php';
            json_ok(['mode' => 'meta', 'result' => hp_meta_refresh(20, true, 0, $ids, $U)]);
        }

        /* ---------- 排序：一次提交整个顺序 ----------
           前台拖动只提交「本组内」的 id 列表，这里就只重排这些。
           不必关心别组 —— 主页渲染时先按 group_id 归并，再按
           sort 排组内顺序，所以跨组的 sort 值互不干扰。
           group_id 参数收下但不参与计算，留着是为了让调用方
           语义清楚（也方便以后真需要组基准时扩展）。 */
        case 'reorder': {
            $ids = $body['ids'] ?? [];
            if (!is_array($ids) || !$ids) json_err('顺序数据为空');
            $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
            if (!$ids) json_err('顺序数据为空');

            /* group_id 可选。
               传了：这一批卡片全部归到这个组（跨组拖拽用 —— 只改 sort 的话，
               卡片下次刷新会跳回原来的组，因为归属根本没变）。
               没传：只排序，不动归属（组内拖拽的老行为）。
               0 表示「未分组」，写成 NULL 而不是 0 —— links.group_id 有外键
               指向 groups(id)，而 groups 里没有 id=0 这条。 */
            $hasGroup = array_key_exists('group_id', $body);
            $gid = $hasGroup ? (int)$body['group_id'] : 0;

            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($hasGroup) {
                    $u = $pdo->prepare('UPDATE links SET sort = ?, group_id = ? WHERE id = ? AND user_id = ?');
                    $bind = $gid > 0 ? $gid : null;
                } else {
                    $u = $pdo->prepare('UPDATE links SET sort = ? WHERE id = ? AND user_id = ?');
                }
                $touched = 0;
                foreach ($ids as $i => $id) {
                    $sort = ($i + 1) * 10;
                    if ($hasGroup) {
                        $u->execute([$sort, $bind, $id, $U]);
                    } else {
                        $u->execute([$sort, $id, $U]);
                    }
                    $touched += $u->rowCount();
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            /* rowCount 用来兜底：如果传进来的 id 全都不属于当前用户，
               一条也不会被更新 —— 返回 0 比返回「成功处理 N 条」诚实。 */
            json_ok(['count' => $touched, 'group_id' => $hasGroup ? $gid : null]);
        }

        /* ---------- 分组 ---------- */
        case 'group_add': {
            $name = trim((string)($body['name'] ?? ''));
            if ($name === '') json_err('分组名不能为空');
            $stMax = db()->prepare('SELECT COALESCE(MAX(sort), 0) FROM groups WHERE user_id = ?');
            $stMax->execute([$U]);
            $max = (int)$stMax->fetchColumn();
            db()->prepare('INSERT INTO groups(name, sort, user_id, created_at) VALUES(?, ?, ?, ?)')
                ->execute([mb_substr($name, 0, 24), $max + 10, $U, now()]);
            json_ok(['id' => (int)db()->lastInsertId()]);
        }
        case 'group_rename': {
            $id = (int)($body['id'] ?? 0);
            $name = trim((string)($body['name'] ?? ''));
            if ($id <= 0 || $name === '') json_err('参数不完整');
            $stG = db()->prepare('UPDATE groups SET name = ? WHERE id = ? AND user_id = ?');
            $stG->execute([mb_substr($name, 0, 24), $id, $U]);
            if ($stG->rowCount() === 0) json_err('没有这个分组');
            json_ok();
        }
        case 'group_delete': {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) json_err('缺少分组 ID');
            $st = db()->prepare('DELETE FROM groups WHERE id = ? AND user_id = ?');
            $st->execute([$id, $U]); // 外键 ON DELETE SET NULL，链接自动回到未分组
            if ($st->rowCount() === 0) json_err('没有这个分组');
            json_ok();
        }
        /* 分组批量：删除 / 合并。
           为什么需要合并而不是只有删除：分组一多，想整理就得「先建一个、
           再把别的链接一条条移过去、最后删空的」——太绕。合并是一次到位：
           把选中分组的链接全部划到目标分组，然后删掉这些空壳。

           两个 op 都只碰 $U 名下的数据，行数不为 0 才算成功
           （和单条接口一样，不谎报 ok）。 */
        case 'groups_bulk': {
            $op  = (string)($body['op'] ?? '');
            $raw = $body['ids'] ?? [];
            if (!is_array($raw)) json_err('参数格式不正确');
            $ids = [];
            foreach ($raw as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[] = $v;
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) json_err('先选择要处理的分组');
            $ph = implode(',', array_fill(0, count($ids), '?'));

            if ($op === 'delete') {
                $st = db()->prepare("DELETE FROM groups WHERE id IN ($ph) AND user_id = ?");
                $st->execute([...$ids, $U]);
                if ($st->rowCount() === 0) json_err('没有可删除的分组');
                json_ok(['affected' => $st->rowCount()]);
            }

            if ($op === 'merge') {
                $target = (int)($body['target_id'] ?? 0);
                if ($target <= 0) json_err('先选一个目标分组');
                $stT = db()->prepare('SELECT id FROM groups WHERE id = ? AND user_id = ?');
                $stT->execute([$target, $U]);
                if (!$stT->fetchColumn()) json_err('目标分组不存在');
                // 目标自己在选中列表里也没关系：不能把目标删掉
                $src = array_values(array_diff($ids, [$target]));
                if (!$src) json_err('选中的就是目标分组本身，没有可合并的');
                $ph2 = implode(',', array_fill(0, count($src), '?'));

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // 先搬链接，再删空壳。顺序反了链接会被外键置空（回到未分组）
                    $st1 = $pdo->prepare("UPDATE links SET group_id = ? WHERE group_id IN ($ph2) AND user_id = ?");
                    $st1->execute([$target, ...$src, $U]);
                    $moved = $st1->rowCount();

                    $st2 = $pdo->prepare("DELETE FROM groups WHERE id IN ($ph2) AND user_id = ?");
                    $st2->execute([...$src, $U]);
                    $gone = $st2->rowCount();
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    json_err('合并失败：' . $e->getMessage(), 500);
                }
                json_ok(['affected' => $gone, 'moved' => $moved]);
            }

            json_err('不认识的操作');
        }
        /* 设置分组图标。空字符串表示「恢复自动匹配」。
           白名单来自 inc/groupnav.php 的 hp_group_icon_keys() ——
           新增图标只需改那一处，这里不必跟着补。 */
        case 'group_icon': {
            $id   = (int)($body['id'] ?? 0);
            $icon = (string)($body['icon'] ?? '');
            if ($id <= 0) json_err('缺少分组 ID');
            if ($icon !== '' && !in_array($icon, hp_group_icon_keys(), true)) {
                json_err('不认识的图标');
            }
            $stG = db()->prepare('UPDATE groups SET icon = ? WHERE id = ? AND user_id = ?');
            $stG->execute([$icon, $id, $U]);
            if ($stG->rowCount() === 0) json_err('没有这个分组');
            json_ok(['icon' => $icon]);
        }

        /* ---------- 分组排序：一次提交整个列表 ----------
           和链接的 reorder 不是一回事：链接有「组内顺序 + 跨组归属」两层，
           分组只有一层平铺列表，所以直接按提交顺序重写 sort 就行。

           校验方式选了「全量对账」而不是逐条 UPDATE 后看 rowCount：
           行数对不上就整个作废，因为排序是个整体 —— 只改一半的结果是
           既不是新顺序也不是旧顺序，比失败更糟。 */
        case 'group_reorder': {
            $raw = $body['ids'] ?? [];
            if (!is_array($raw) || !$raw) json_err('顺序数据为空');
            $ids = [];
            foreach ($raw as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[] = $v;
            }
            $ids = array_values(array_unique($ids));
            if (!$ids) json_err('顺序数据为空');

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT COUNT(*) FROM groups WHERE id IN ($ph) AND user_id = ?");
            $st->execute([...$ids, $U]);
            if ((int)$st->fetchColumn() !== count($ids)) {
                json_err('列表里有不属于你的分组', 403);
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // 步长 10 与 group_add 的 MAX(sort)+10 一致，留出手工插入的余量
                $up = $pdo->prepare('UPDATE groups SET sort = ? WHERE id = ? AND user_id = ?');
                foreach ($ids as $i => $gid) {
                    $up->execute([($i + 1) * 10, $gid, $U]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_err('排序失败：' . $e->getMessage(), 500);
            }
            json_ok(['affected' => count($ids)]);
        }

        /* ---------- 把「网站默认」整库复制到我自己的库 ----------
           两份库拆开之后，「我的」一开始是空的，而默认库已经有几百条。
           让用户从零一条条加显然不合理，所以给一次整库复制。

           只在目标库为空时允许：这个动作会写几百行，一旦覆盖了用户
           自己攒的东西没法撤回。想重来就先自己清空（或直接删）。
           不搬 settings：那是站点与外观配置，跟「我的书签」不是一回事。 */
        case 'library_clone': {
            if (!is_admin()) json_err('只有管理员有这个入口', 403);
            $me = (int)current_user_id();
            if ($me <= 0) json_err('未登录', 401);

            $pdo = db();
            $has = $pdo->prepare('SELECT 1 FROM links WHERE user_id = ? LIMIT 1');
            $has->execute([$me]);
            if ($has->fetchColumn() !== false) {
                json_err('「我的链接库」里已经有内容了，不能覆盖。要重新复制请先把它清空。');
            }
            // 分组也要判：可能只建了分组还没加链接
            $hasG = $pdo->prepare('SELECT 1 FROM groups WHERE user_id = ? LIMIT 1');
            $hasG->execute([$me]);
            if ($hasG->fetchColumn() !== false) {
                json_err('「我的链接库」里已经有分组了，不能覆盖。');
            }

            $pdo->beginTransaction();
            try {
                /* 分组要建 旧id → 新id 的映射：链接的 group_id 必须指向
                   复制出来的新分组，直接照抄旧 id 会把链接挂到默认库的分组上
                   （外键不跨 user 校验，但读的时候按 user_id 过滤就全丢了）。 */
                $map = [];
                $gs = $pdo->query('SELECT id, name, icon, sort FROM groups WHERE user_id = 0 ORDER BY sort ASC, id ASC');
                $insG = $pdo->prepare('INSERT INTO groups(name, icon, sort, user_id, created_at) VALUES(?, ?, ?, ?, ?)');
                foreach ($gs as $g) {
                    $insG->execute([$g['name'], (string)$g['icon'], (int)$g['sort'], $me, now()]);
                    $map[(int)$g['id']] = (int)$pdo->lastInsertId();
                }

                $ls = $pdo->query(
                    'SELECT group_id, title, url, icon, icon_kind, descr, sort, hits, hidden, created_at, updated_at
                       FROM links WHERE user_id = 0 ORDER BY sort ASC, id ASC'
                );
                $insL = $pdo->prepare(
                    'INSERT INTO links(group_id, title, url, icon, icon_kind, descr, sort, hits, hidden, user_id, created_at, updated_at)
                     VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $n = 0;
                foreach ($ls as $l) {
                    $gid = $l['group_id'] === null ? null : ($map[(int)$l['group_id']] ?? null);
                    $insL->execute([
                        $gid, $l['title'], $l['url'], $l['icon'], $l['icon_kind'], $l['descr'],
                        (int)$l['sort'], (int)$l['hits'], (int)$l['hidden'], $me,
                        $l['created_at'], $l['updated_at'],
                    ]);
                    $n++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_err('复制失败：' . $e->getMessage(), 500);
            }

            json_ok(['links' => $n, 'groups' => count($map)]);
        }

        /* ---------- 把游客在本地攒的数据并进来 ----------
           只做追加：云端已有的网址跳过，本地独有的插入。
           这样合并是幂等的，也不会覆盖用户已有的东西。 */
        case 'merge_guest': {
            $payload = $body['payload'] ?? [];
            if (!is_array($payload)) json_err('数据格式不正确');
            $r = hp_merge_guest_data(db(), $U, $payload);
            json_ok(['merged' => $r]);
        }

        /* ---------- 设置 ---------- */
        case 'save_settings': {
            $allow = [
                'site_title'    => 'str',
                // 主页不再显示副标题（已从界面移除），但保留此项以兼容旧备份导入
                'site_subtitle' => 'str',
                // 皮肤白名单从 inc/skins.php 生成，新增皮肤不必回来改这里
                'theme'         => 'enum:' . implode(',', hp_skin_keys()),
                'accent'        => 'enum:amber,coral,mint,sky,violet',
                'show_search'   => 'bool',
                // 引擎白名单从 inc/engines.php 生成
                'search_engine' => 'enum:' . implode(',', hp_engine_keys()),
                'open_new_tab'  => 'bool',
                'show_desc'     => 'bool',
                'footer_note'   => 'str',
                // 页脚版权：署名（留空退回作者名、再退站点名）与 ICP 备案号（留空不渲染）
                'copyright_name' => 'str',
                'icp_beian'      => 'str',
                // 形态白名单同样来自 inc/layouts.php
                'layout'        => 'enum:' . implode(',', hp_layout_keys()),
                // 分组导航：side / tab / ''（空串表示不启用，清理时用）
                'groupnav'      => 'enum:side,',
                // 统计开关与保留天数
                'stats_on'      => 'bool',
                'stats_keep'    => 'int',
                // 访问控制
                'acl_mode'      => 'enum:off,on',
                'acl_unknown'   => 'enum:allow,deny',
                'acl_list_type' => 'enum:deny,allow',
            ];
            $saved = 0;
            foreach ($allow as $k => $rule) {
                if (!array_key_exists($k, $body)) continue;
                $v = $body[$k];
                if ($rule === 'bool') {
                    $v = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? '1' : '0';
                } elseif ($rule === 'int') {
                    $v = (string)max(1, min(3650, (int)$v));
                } elseif (str_starts_with($rule, 'enum:')) {
                    $opts = explode(',', substr($rule, 5));
                    if (!in_array($v, $opts, true)) continue;
                } else {
                    $v = mb_substr(trim((string)$v), 0, 120);
                }
                setting_set($k, (string)$v);
                $saved++;
            }
            json_ok(['saved' => $saved]);
        }
        /* 站点模式：single（单人）/ multi（多人）。只允许 admin（在 $ADMIN_ONLY）。
           只写一个 setting，不动任何账号数据——切回 multi 时原有账号原样恢复。
           单人模式下禁止普通账号登录由登录层按 role 拦截，不靠删数据。 */
        case 'set_site_mode': {
            $mode = (string)($body['mode'] ?? '');
            if ($mode !== 'single' && $mode !== 'multi') json_err('未知的运行模式');
            setting_set('site_mode', $mode);
            settings_flush();
            /* 切模式吊销所有**非管理员**的会话：单人模式下普通账号本就不该
               再有访问权，而他们手里的会话可能是在切模式之前建立的。
               管理员的 epoch 不动，所以操作者自己不会掉线。 */
            db()->exec("UPDATE users SET auth_epoch = auth_epoch + 1 WHERE role <> 'admin'");
            json_ok(['mode' => $mode]);
        }
        case 'set_layout': {
            $l = (string)($body['layout'] ?? '');
            if (!in_array($l, hp_layout_keys(), true)) json_err('未知形态');
            setting_set('layout', $l);
            json_ok();
        }
        /* 皮肤切换。ui.js 走这个，普通用户换设备也能保持自己的选择 ——
           theme 早就在个人偏好键名单里，此前只写 localStorage 而已。 */
        case 'set_theme': {
            $t = (string)($body['theme'] ?? '');
            if (!in_array($t, hp_skin_keys(), true)) json_err('未知皮肤');
            setting_set('theme', $t);
            json_ok();
        }
        /* 关掉「这些链接是你自己的副本」提示。写 user 级 setting，
           下次登录/换设备都不再弹；管理员本来就看不到这条。 */
        case 'dismiss_clone_hint': {
            setting_set('clone_hint_seen', '1');
            json_ok();
        }

        /* 只切默认搜索引擎。主页顶栏的「设为默认」走这个 ——
           不必回后台就能改，少一次跳转。 */
        case 'set_engine': {
            $e = (string)($body['engine'] ?? '');
            if (!in_array($e, hp_engine_keys(), true)) json_err('未知搜索引擎');
            setting_set('search_engine', $e);
            json_ok();
        }

        /* ---------- 访问控制：单条增删 ---------- */
        case 'acl_set': {
            $cc   = strtoupper(preg_replace('~[^A-Za-z]~', '', (string)($body['cc'] ?? '')) ?? '');
            $mode = (string)($body['mode'] ?? '');
            if (strlen($cc) !== 2) json_err('国家/地区代码必须是两位字母');
            if (!in_array($mode, ['allow', 'deny', ''], true)) json_err('未知模式');
            hp_acl_set($cc, $mode);
            json_ok(['cc' => $cc, 'mode' => $mode]);
        }
        case 'acl_clear': {
            db()->exec('DELETE FROM acl_countries');
            json_ok();
        }

        /* ---------- 统计：清空 / 清理过期 ---------- */
        case 'stats_clear': {
            $what = (string)($body['what'] ?? 'all'); // all | visits | clicks
            if ($what === 'visits' || $what === 'all') db()->exec('DELETE FROM visits');
            if ($what === 'clicks' || $what === 'all') {
                db()->exec('DELETE FROM clicks');
                // hits 是累计值，清明细时一并归零，否则统计页两栏数字对不上
                db()->exec('UPDATE links SET hits = 0');
            }
            json_ok();
        }
        case 'stats_prune': {
            $n = hp_stats_prune();
            json_ok(['deleted' => $n]);
        }

        /* ---------- IP 归属地库导入 ----------
           下载 86MB 可能要几十秒，所以：先返回「已开始」，真正的导入
           在 fastcgi_finish_request 之后跑？—— 不行，那样失败就没有回执了。
           这里选择同步执行，前端把超时放到 10 分钟，并显示进度文案。
           共享主机上 max_execution_time 可能只有 30s，所以先检查并提示。 */
        case 'geo_import': {
            @set_time_limit(900);
            $scope = (string)($body['scope'] ?? 'country');
            if (!in_array($scope, ['country', 'full'], true)) $scope = 'country';

            $logs = [];
            try {
                $res = hp_geo_import($scope, static function (string $s) use (&$logs): void {
                    $logs[] = $s;
                });
            } catch (Throwable $e) {
                json_out(['ok' => false, 'error' => $e->getMessage(), 'logs' => $logs], 500);
            }
            json_ok(['result' => $res, 'logs' => $logs, 'status' => hp_geo_status()]);
        }
        case 'geo_clear': {
            db()->exec('DELETE FROM geo_country');
            db()->exec('DELETE FROM geo_city');
            db()->exec('DELETE FROM geo_place');
            db()->exec('DELETE FROM geo_asn');
            db()->exec('DELETE FROM geo_meta');
            json_ok();
        }
        /* 回填历史上 country 为空的记录。导库前的那些访问不必重记一次，
           用当前库反查 IP 补上就行。单次上限 3000 条，避免一次请求跑太久。 */
        case 'geo_backfill': {
            $n = hp_geo_backfill(3000);
            $left = (int)db()->query(
                "SELECT COUNT(*) FROM visits WHERE is_admin = 0 AND (country = '' OR country IS NULL)"
            )->fetchColumn();
            json_ok(['fixed' => $n, 'left' => $left]);
        }

        /* ---------- 改密码 ---------- */
        case 'change_pass': {
            $old = (string)($body['old'] ?? '');
            $new = (string)($body['new'] ?? '');
            if (strlen($new) < 8) json_err('新密码至少 8 位');
            if (!password_verify($old, $CONFIG['admin_pass'])) json_err('原密码不正确');

            $tpl = file_get_contents(HP_ROOT . '/inc/config.php');
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 11]);
            /* 这里必须用 preg_replace_callback，不能直接传替换串：
               bcrypt 哈希是 $2y$11$.... 开头，而 preg_replace 的替换串里
               $2 / $11 会被当成反向引用吃掉，写回去的哈希前半截直接没了。
               实测症状：改完密码立刻登不进去，且没有任何报错。
               用回调就绕开整个转义问题。 */
            $tpl = preg_replace_callback(
                "~'admin_pass'\s*=>\s*'[^']*'~",
                static fn() => "'admin_pass'  => '" . addcslashes($hash, "'\\") . "'",
                $tpl
            );
            if ($tpl === null || @file_put_contents(HP_ROOT . '/inc/config.php', $tpl) === false) {
                json_err('配置不可写，请检查 inc/config.php 权限');
            }
            json_ok();
        }

        /* ---------- 改自己的登录密码（所有登录用户，含 admin） ----------
           change_pass 改的是 inc/config.php 里的遗留字段 admin_pass，
           v6 起登录只认 users.pass_hash，所以它其实早就不改登录密码了。
           这个接口直接改 users 表 —— 谁登录改谁的，管理员也一样。
           不在 $ADMIN_ONLY 里：普通用户也要能改自己的密码。 */
        case 'user_pass': {
            $uid = current_user_id();
            $old = (string)($body['old'] ?? '');
            $new = (string)($body['new'] ?? '');
            if (strlen($new) < 8) json_err('新密码至少 8 位');
            $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
            $st->execute([$uid]);
            $hash = (string)$st->fetchColumn();
            if ($hash === '' || !password_verify($old, $hash)) json_err('原密码不正确');
            $st = db()->prepare('UPDATE users SET pass_hash = ?, auth_epoch = auth_epoch + 1 WHERE id = ?');
            $st->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 11]), $uid]);
            if ($st->rowCount() < 1) json_err('保存失败，请重试', 500);
            /* 自己这一份会话也跟着升级，否则改完密码立刻被自己踢下线；
               同时换 session id —— 密码刚变，旧 id 不该继续可用。 */
            $st = db()->prepare('SELECT auth_epoch FROM users WHERE id = ?');
            $st->execute([$uid]);
            $_SESSION['auth_epoch'] = (int)$st->fetchColumn();
            session_regenerate_id(true);
            json_ok();
        }

        /* ---------- 管理员重置某个用户的密码 ----------
           和 user_pass 的区别：那个要本人输入旧密码、改自己的；这个是
           管理员在「用户管理」里直接给普通用户设新密码，不需要旧密码。
           只允许改 role=user，不能重置管理员（保护站点所有者）。
           在 $ADMIN_ONLY 里，且有 CSRF —— 绕过按钮直接打接口也要过闸门。 */
        case 'user_reset_pass': {
            $uid = (int)($body['id'] ?? 0);
            $new = (string)($body['new'] ?? '');
            if ($uid <= 0) json_err('用户不存在');
            if (strlen($new) < 8) json_err('新密码至少 8 位');
            $st = db()->prepare('SELECT id, role FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) json_err('用户不存在');
            if ($u['role'] === 'admin') json_err('不能重置管理员密码，请在「安全控制」里修改');
            $st = db()->prepare('UPDATE users SET pass_hash = ?, auth_epoch = auth_epoch + 1 WHERE id = ?');
            $st->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 11]), $uid]);
            if ($st->rowCount() < 1) json_err('保存失败，请重试', 500);
            json_ok(['id' => $uid]);
        }

        /* ---------- 管理员删除账号（含其他管理员） ----------
           三道护栏，缺一不可：
             1) 不能删自己 —— 删完当前会话立刻失效，等于把自己锁在门外；
             2) 不能删最后一个管理员 —— 否则站点再无人能进后台，无法恢复；
             3) 目标必须存在。
           删除是事务：账号 + 该 uid 的链接/分组/设置一起走，避免出现
           「账号没了但数据还在」的孤儿行，或反过来。统计明细（visits/
           clicks）不带 user_id，属于站点级历史，故意保留。 */
        case 'user_delete': {
            $uid = (int)($body['id'] ?? 0);
            if ($uid <= 0) json_err('用户不存在');
            $me = current_user_id();
            if ($uid === $me) json_err('不能删除当前登录的账号');

            $st = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) json_err('用户不存在，可能已被删除');

            if (($u['role'] ?? '') === 'admin') {
                $nAdmin = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($nAdmin <= 1) json_err('至少保留一位管理员，否则无人能再进后台');
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach (['links', 'groups', 'settings'] as $tbl) {
                    $pdo->prepare("DELETE FROM $tbl WHERE user_id = ?")->execute([$uid]);
                }
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                json_err('删除失败，请重试', 500);
            }
            json_ok(['id' => $uid, 'username' => (string)$u['username']]);
        }

        /* ---------- 登录防护 ---------- */
        case 'set_captcha': {
            $on = ($body['on'] ?? true) === true || (string)($body['on'] ?? '') === '1';
            setting_set('login_captcha', $on ? '1' : '0');
            json_ok(['on' => $on]);
        }
        /* 一键解锁：把两张表都清干净。
           login_attempts 是计数，login_locks 是封锁记录。
           只清后者的话，前面那 5 次失败还留着，再错一次立刻又锁。 */
        case 'unlock_all': {
            db()->exec('DELETE FROM login_attempts');
            db()->exec('DELETE FROM login_locks');
            json_ok();
        }

        /* ---------- 图标缓存 ---------- */
        case 'icon_sync': {
            require_once __DIR__ . '/inc/icons.php';
            $r = hp_icon_sync();
            json_ok(['result' => $r, 'stats' => hp_icon_stats()]);
        }
        case 'icon_refill': {
            require_once __DIR__ . '/inc/icons.php';
            // 抓取要逐个访问外站，12 条已经够跑十几秒了，超时就白干
            @set_time_limit(120);
            $limit = max(1, min(30, (int)($body['limit'] ?? 12)));
            $r = hp_icon_refill($limit);
            /* 压缩内置到抓取流程：抓取端（MetaFetcher）本来就会把新图标存成
               96px WebP，这里再跑一次存量转换，把优化前留下的旧 png/ico 一起
               收掉。这样页面上不再需要单独的「转为 WebP」按钮。
               转换幂等、SVG 与已是 WebP 的直接跳过，跑一次开销很小。 */
            $r['webp'] = hp_icon_to_webp();
            json_ok(['result' => $r, 'stats' => hp_icon_stats()]);
        }
        case 'icon_webp': {
            require_once __DIR__ . '/inc/icons.php';
            @set_time_limit(120);
            $r = hp_icon_to_webp();
            json_ok(['result' => $r, 'stats' => hp_icon_stats()]);
        }
        case 'icon_gc': {
            require_once __DIR__ . '/inc/icons.php';
            $r = hp_icon_collect_garbage();
            json_ok(['result' => $r, 'stats' => hp_icon_stats()]);
        }
        case 'icon_purge': {
            require_once __DIR__ . '/inc/icons.php';
            $days = (int)($body['days'] ?? 0);
            $r = hp_icon_purge($days);
            json_ok(['result' => $r, 'stats' => hp_icon_stats()]);
        }

        /* ---------- 一键刷新元信息（标题 + 描述 + 图标） ----------
           与 icon_refill 的区别：这个会覆盖已有值，且连标题描述一起更新。
           分批走 cursor 协议，前端循环调用直到 done=true。 */
        case 'meta_refresh': {
            require_once __DIR__ . '/inc/refresh.php';
            @set_time_limit(120);
            /* 并发之后单批可以放宽：耗时取决于「最慢那个站点」，
               与条数几乎无关。上限 40 是为了别让一次请求开太多连接。 */
            $limit = max(1, min(48, (int)($body['limit'] ?? 32)));
            $overwrite = !isset($body['overwrite']) || !empty($body['overwrite']);
            $cursor = max(0, (int)($body['cursor'] ?? 0));
            $r = hp_meta_refresh($limit, $overwrite, $cursor);
            json_ok(['result' => $r, 'stats' => hp_meta_refresh_stats(), 'icons' => hp_icon_stats()]);
        }
        case 'meta_refresh_stats': {
            require_once __DIR__ . '/inc/refresh.php';
            json_ok(['stats' => hp_meta_refresh_stats()]);
        }

        /* ---------- 升级 ----------
           走 multipart 上传，不是 JSON —— 所以从 $_FILES 读，
           不经过上面那段 json_decode 的 $body。 */
        case 'upgrade_apply': {
            require_once __DIR__ . '/inc/upgrade.php';
            @set_time_limit(300);

            $f = $_FILES['pkg'] ?? null;
            if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
                json_err(match ($code) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的上传大小',
                    UPLOAD_ERR_PARTIAL  => '上传中断，请重试',
                    UPLOAD_ERR_NO_FILE  => '没有收到文件',
                    UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '服务器临时目录不可写',
                    default => '上传失败（错误码 ' . $code . '）',
                });
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                json_err('非法上传');
            }
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip') {
                json_err('只接受 .zip 升级包');
            }
            $sizeMb = (int)$f['size'] / 1048576;
            if ($sizeMb > 64) {
                json_err('包体超过 64 MB，请确认是不是传错了文件');
            }

            // 挪到自己的临时文件再处理：PHP 的 tmp 文件在请求结束就被回收，
            // 中间要 inspect → apply 两次读，留在原地更稳
            $tmp = hp_upgrade_tmp() . '/upload-' . bin2hex(random_bytes(6)) . '.zip';
            if (!@move_uploaded_file($f['tmp_name'], $tmp)) {
                json_err('无法把上传文件移到临时目录，请检查系统临时目录权限');
            }

            try {
                $inspect = hp_upgrade_inspect($tmp);
                if (!$inspect['ok']) {
                    @unlink($tmp);
                    json_err($inspect['error']);
                }
                $res = hp_upgrade_apply($tmp, (string)$inspect['prefix']);
                @unlink($tmp);
                json_ok([
                    'from'    => $res['from'],
                    'to'      => $res['to'],
                    'files'   => $res['files'],
                    'skipped' => $res['skipped'],
                    'backup'  => basename((string)$res['backup']),
                ]);
            } catch (Throwable $e) {
                @unlink($tmp);
                json_err('升级失败：' . $e->getMessage(), 500);
            }
        }
        /* 只检查不执行：让用户先看到「包里是哪个版本、有多少文件」再决定 */
        case 'upgrade_inspect': {
            require_once __DIR__ . '/inc/upgrade.php';
            $f = $_FILES['pkg'] ?? null;
            if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_err('没有收到文件');
            }
            if (!is_uploaded_file($f['tmp_name'])) {
                json_err('非法上传');
            }
            $tmp = hp_upgrade_tmp() . '/probe-' . bin2hex(random_bytes(6)) . '.zip';
            if (!@move_uploaded_file($f['tmp_name'], $tmp)) {
                json_err('无法读取上传文件');
            }
            $r = hp_upgrade_inspect($tmp);
            @unlink($tmp);
            if (!$r['ok']) {
                json_err($r['error']);
            }
            json_ok([
                'version' => $r['version'],
                'files'   => count($r['files']),
                'prefix'  => $r['prefix'],
                'current' => hp_version(),
            ]);
        }
        case 'upgrade_rollback': {
            require_once __DIR__ . '/inc/upgrade.php';
            $name = (string)($body['snap'] ?? '');
            // 只接受快照目录名，不接受路径 —— 拼路径的活儿交给 hp_upgrade_rollback 里那道 realpath 检查
            if (!preg_match('~^snap-\d{8}-\d{6}$~', $name)) {
                json_err('快照名不合法');
            }
            try {
                $restored = hp_upgrade_rollback(hp_upgrade_tmp() . '/' . $name);
                json_ok(['restored' => $restored]);
            } catch (Throwable $e) {
                json_err('回滚失败：' . $e->getMessage(), 500);
            }
        }
        case 'upgrade_drop_snapshot': {
            require_once __DIR__ . '/inc/upgrade.php';
            $name = (string)($body['snap'] ?? '');
            if (!preg_match('~^snap-\d{8}-\d{6}$~', $name)) {
                json_err('快照名不合法');
            }
            $ok = hp_upgrade_drop_snapshot(hp_upgrade_tmp() . '/' . $name);
            $ok ? json_ok() : json_err('删除失败，检查目录权限');
        }

        default:
            json_err('未知操作：' . $action, 404);
    }
} catch (PDOException $e) {
    // 错误详情只进服务端日志：以前 debug=true 时会把 SQL/路径原文回给客户端。
    error_log('[vnav] DB error @ ' . $action . ': ' . $e->getMessage());
    json_err('数据库操作失败', 500);
} catch (Throwable $e) {
    error_log('[vnav] error @ ' . $action . ': ' . $e->getMessage());
    json_err('服务器内部错误', 500);
}