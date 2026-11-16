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
    $dump = [
        'format'     => 'vnav-backup',
        'version'    => 1,
        'exported_at'=> date('c'),
        'groups'     => $pdo->query('SELECT * FROM groups ORDER BY sort ASC, id ASC')->fetchAll(),
        'links'      => $pdo->query('SELECT * FROM links ORDER BY sort ASC, id ASC')->fetchAll(),
        'settings'   => (function (PDO $p) {
            $out = [];
            foreach ($p->query('SELECT k, v FROM settings') as $r) {
                $out[$r['k']] = $r['v'];
            }
            return $out;
        })($pdo),
    ];

    $json = json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        // 兜底：任何非法 UTF-8 都会让 json_encode 静默返回 false
        $json = json_encode($dump, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="vnav-backup-' . date('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen((string)$json));
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

if ($method !== 'POST') {
    // 换一张验证码走 GET：登录页的 <img> 与刷新按钮都只能发 GET
    if ($action === 'captcha_new') {
        require __DIR__ . '/inc/captcha.php';
        session_boot(); // 限流计数要存 session，先开

        /* 不限流会被当成免费的 PNG 生成器：一次请求就是一次 GD 绘图
           + 一条 INSERT。按会话每 10 秒最多 6 张，超了直接 429。
           用 session 而不是数据库：这是临时节流，不值得落盘。 */
        $now = time();
        $win = (int)($_SESSION['__cap_t'] ?? 0);
        if ($now - $win > 10) {
            $_SESSION['__cap_t'] = $now;
            $_SESSION['__cap_n'] = 0;
        }
        $_SESSION['__cap_n'] = (int)($_SESSION['__cap_n'] ?? 0) + 1;
        if ($_SESSION['__cap_n'] > 6) {
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
        db()->prepare('UPDATE links SET hits = hits + 1 WHERE id = ?')->execute([$id]);
        hp_log_click($id);
    }
    json_ok();
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
$U = hp_data_uid_for($__lib);

/* 系统级接口：只有 admin。
   这一层是硬闸门 —— 前端把按钮藏起来只是体验，绕过按钮直接打
   api.php 一样要过这里。 */
$ADMIN_ONLY = [
    'acl_set', 'acl_clear', 'stats_clear', 'stats_prune',
    'geo_import', 'geo_clear', 'geo_backfill',
    'change_pass', 'set_captcha', 'unlock_all',
    'icon_sync', 'icon_refill', 'icon_gc', 'icon_purge',
    'upgrade_apply', 'meta_refresh', 'meta_refresh_stats',
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
            // 只接受本地缓存的图标路径，防止被塞外链
            $iconKind = 'auto';
            if ($icon !== '') {
                if (preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', $icon)) {
                    $iconKind = 'file';
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
            $meta = (new MetaFetcher((int)$CONFIG['fetch_timeout']))->fetch($url);
            json_ok(['meta' => $meta]);
        }

        /* ---------- 编辑链接 ---------- */
        case 'update': {
            $id    = (int)($body['id'] ?? 0);
            $title = trim((string)($body['title'] ?? ''));
            $url   = safe_url((string)($body['url'] ?? ''));
            $gid   = (int)($body['group_id'] ?? 0);
            $descr = trim((string)($body['descr'] ?? ''));
            $icon  = trim((string)($body['icon'] ?? ''));
            $hidden = !empty($body['hidden']) && $body['hidden'] !== '0' ? 1 : 0;

            if ($id <= 0) json_err('缺少链接 ID');
            if ($url === null) json_err('网址格式不正确');
            if ($title === '') $title = host_of($url);

            $dup = db()->prepare('SELECT id FROM links WHERE url = ? AND id <> ? AND user_id = ?');
            $dup->execute([$url, $id, $U]);
            if ($dup->fetchColumn()) json_err('另一个链接已经用了这个网址');

            $iconKind = 'auto';
            if ($icon !== '') {
                if (preg_match('~^data/icons/[A-Za-z0-9._\-]+$~', $icon)) {
                    $iconKind = 'file';
                } else {
                    $icon = '';
                }
            }

            $st = db()->prepare(
                'UPDATE links SET title = ?, url = ?, group_id = ?, descr = ?, icon = ?, icon_kind = ?, hidden = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?'
            );
            $st->execute([mb_substr($title, 0, 60), $url, $gid ?: null, mb_substr($descr, 0, 120), $icon, $iconKind, $hidden, now(), $id, $U]);
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
        case 'set_layout': {
            $l = (string)($body['layout'] ?? '');
            if (!in_array($l, hp_layout_keys(), true)) json_err('未知形态');
            setting_set('layout', $l);
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
            if (strlen($new) < 6) json_err('新密码至少 6 位');
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
    if (!empty($CONFIG['debug'])) {
        json_err('数据库错误：' . $e->getMessage(), 500);
    }
    json_err('数据库操作失败', 500);
} catch (Throwable $e) {
    if (!empty($CONFIG['debug'])) {
        json_err($e->getMessage(), 500);
    }
    json_err('服务器内部错误', 500);
}