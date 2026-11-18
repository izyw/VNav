<?php
/**
 * 引导文件 —— 所有入口第一行 require 这里。
 * 只做三件事：载配置、开 DB、备好公共函数。
 */
declare(strict_types=1);

if (!defined('HP_ROOT')) {
    define('HP_ROOT', dirname(__DIR__));
}

/* 直接访问 /inc/boot.php 时挡成 404。不能像其他 inc 文件那样用
   defined('HP_ROOT') 判 —— 这个常量就是本文件定义的，永远为真。
   改为看「当前真正执行的脚本是不是自己」：被 require 时 SCRIPT_FILENAME
   是入口文件（index.php 等），只有直接访问才会是 boot.php。 */
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'boot.php') {
    http_response_code(404);
    exit;
}

// ---------- 配置 ----------
$__cfgFile = HP_ROOT . '/inc/config.php';
if (!is_file($__cfgFile)) {
    if (is_file(HP_ROOT . '/install.php')) {
        header('Location: install.php');
        exit;
    }
    http_response_code(500);
    exit('缺少 inc/config.php，请先运行 install.php');
}
$CONFIG = require $__cfgFile;

date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Shanghai');
mb_internal_encoding('UTF-8');

/* ---------- 安全响应头 ----------
   放在 PHP 层而不只写 .htaccess：生产前面若挂 Nginx/反代，.htaccess 根本
   不会被读取。这里统一输出，Apache/Nginx 都生效。页面用了少量内联
   script/style（登录弹窗、主题切换），所以这两项保留 'unsafe-inline'；
   CSP 的价值在于不加载外域脚本、不被 iframe 套用、不执行 object。 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self' https://www.baidu.com https://www.bing.com https://www.google.com https://duckduckgo.com https://www.sogou.com https://www.so.com; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'");
    $__https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($__https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// 皮肤定义（hp_skins / hp_skin_safe）—— index、admin 都要用，放这里统一加载
require_once __DIR__ . '/skins.php';
// 展示形态清单（hp_layouts / hp_layout_safe）
require_once __DIR__ . '/layouts.php';
// 分组导航方式（hp_groupnavs / hp_groupnav_safe）
require_once __DIR__ . '/groupnav.php';

// 账号（注册、默认数据复制、游客数据合并）
require_once __DIR__ . '/accounts.php';
// 输出压缩（hp_compress_start）与静态资源缓存头
require_once __DIR__ . '/compress.php';
// 搜索引擎清单（hp_engines / hp_engine_safe）—— 顶栏搜索与后台共用
require_once __DIR__ . '/engines.php';

if (!empty($CONFIG['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}

/**
 * 轻量固定窗口限流。表懒创建，老库升级不用跑迁移脚本。
 * 用途是「挡刷」（验证码图片、点击计数）而非安全边界，所以组件异常时
 * 放行 —— 不能因为限流表坏了让全站不可用。
 */
function hp_rate_allow(string $key, int $limit, int $window): bool
{
    try {
        $pdo = db();
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            k TEXT PRIMARY KEY, n INTEGER NOT NULL, win INTEGER NOT NULL
        )');
        $now = time();
        $st = $pdo->prepare('SELECT n, win FROM rate_limits WHERE k = ?');
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $now - (int)$row['win'] < $window) {
            if ((int)$row['n'] >= $limit) {
                return false;
            }
            $pdo->prepare('UPDATE rate_limits SET n = n + 1 WHERE k = ?')->execute([$key]);
        } else {
            $pdo->prepare('INSERT INTO rate_limits(k, n, win) VALUES(?, 1, ?)
                           ON CONFLICT(k) DO UPDATE SET n = 1, win = excluded.win')
                ->execute([$key, $now]);
        }
        // 概率性清理，避免这张表无限膨胀
        if (random_int(1, 200) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE win < ?')->execute([$now - 86400]);
        }
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

// ---------- 数据库 ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $CONFIG;
    $path = $CONFIG['db_path'];
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // 性能三连：WAL 让读写不互斥，normal 同步在崩溃安全与速度间取平衡
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA temp_store=MEMORY');
    $pdo->exec('PRAGMA cache_size=-8000'); // 约 8MB 页缓存

    hp_db_bootstrap($pdo);
    return $pdo;
}

/**
 * 建表 / 迁移，每次进程只跑一次。
 *
 * 判断「要不要迁移」必须便宜：读一次 settings 里的 schema_version，
 * 版本对得上就直接返回，正常请求只有一条 SELECT（走主键，<0.01ms）。
 * 绝对不能每次请求都跑全套 CREATE TABLE IF NOT EXISTS ——
 * 那是 15 条 DDL，在共享主机上能占到 10ms 以上。
 */
function hp_db_bootstrap(PDO $pdo): void
{
    require_once __DIR__ . '/schema.php';

    $ver = -1;
    try {
        $ver = (int)($pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // settings 表还不存在 —— 全新库（install.php 刚建好文件但没跑 schema）
    }

    if ($ver === HP_SCHEMA_VERSION) {
        return; // 常态：一条 SELECT 就完事
    }

    try {
        hp_migrate($pdo);
    } catch (Throwable $e) {
        // 迁移失败不能让整站挂掉：记下来，继续用现有结构跑。
        // 大概率是并发首装撞车（两个请求同时进这里），后到的那个会报
        // 表已存在，此时结构其实是对的，直接忽略即可。
        error_log('vnav schema migrate: ' . $e->getMessage());
    }
}

// ---------- 小工具 ----------
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** 只允许 http/https，且不允许出现 user:pass@ 之类的钓鱼写法 */
function safe_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return null;
    }
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    $host = strtolower($p['host']);
    /* 只挡内网 / 回环 / 链路本地，公网 IP 放行。
       一刀切拒绝所有 IP 会误伤正常需求 —— 自建服务、NAS、路由器后台
       很多就是 https://1.2.3.4:8000/ 这种写法，它跟 SSRF 没关系：
       加一条链接不会让服务器去访问任何东西。
       真正的 SSRF 防护在 MetaFetcher::isPublicUrl()，那里会把域名
       先解析成 IP 再判一次私网，比这里只看字面 host 严得多。 */
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
    } elseif (!str_contains($host, '.')) {
        // 没有点又不是 IP：nas、intranet 这类内网短名，直接拒
        return null;
    }
    return $url;
}

/** 取可展示的域名，去掉 www. */
function host_of(string $url): string
{
    $h = parse_url($url, PHP_URL_HOST) ?: '';
    return preg_replace('~^www\.~i', '', $h) ?? $h;
}

/** 首字母头像用的缩写：中文取前 2 字，英文取前 2 个词首字母 */
function initials_of(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '?';
    }
    if (preg_match('~[\x{4e00}-\x{9fa5}]~u', $text)) {
        preg_match_all('~[\x{4e00}-\x{9fa5}]~u', $text, $m);
        return mb_substr(implode('', $m[0]), 0, 2);
    }
    $parts = preg_split('~[\s\-_./|·]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return mb_strtoupper(mb_substr($text, 0, 2));
}

/** 稳定色相：同一域名永远同一颜色，卡片不会花 */
function hue_of(string $seed): int
{
    return (int)(crc32($seed) % 360);
}

/** 人类可读的体积：后台展示缓存占用用，不做精确换算（1024 进制） */
function hp_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = []): never
{
    json_out(['ok' => true] + $data);
}

function json_err(string $msg, int $code = 400): never
{
    json_out(['ok' => false, 'error' => $msg], $code);
}

// ---------- 会话（只在需要时启动，主页游客不建 session，省一个 Cookie 与磁盘写） ----------

/* 会话名单独拎成常量：current_user_id() 要在「还没启动 session」时
   就能拿这个名字去查 cookie 有没有，不能用 session_name() ——
   它默认返回 PHPSESSID，而本站用的是 HPSESSID，会在启动前读错名字。 */
const HP_SESSION_NAME = 'HPSESSID';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    global $CONFIG;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    /* 登录态保留 30 天。cookie 过期时间和服务端 GC 必须一起改：
       只改前者，会话文件会在默认 24 分钟后被清掉，浏览器还拿着没过期的
       cookie，却已经掉登录 —— 比原来的会话 cookie 更让人困惑。 */
    $ttl = 30 * 86400;
    ini_set('session.gc_maxlifetime', (string)$ttl);
    /* 严格模式：不认识的 session id 一律重新生成，防止会话固定攻击
       （攻击者先给自己一个 id，再诱导受害者带着它登录）。 */
    ini_set('session.use_strict_mode', '1');
    session_name(HP_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => $ttl,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();

    /* 老会话也续期：session_start() 不一定重发 cookie，主动写一次带
       30 天过期时间的同名 cookie，已在登录态的人不用重新登录就吃到新策略。
       只在真登录时写，游客不会凭空多一个 Set-Cookie 响应头。 */
    if (!empty($_SESSION['uid']) && !headers_sent()) {
        setcookie(HP_SESSION_NAME, session_id(), [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
    }
}

/* ---------- 账号与角色 ----------
   会话里只存用户 id（$_SESSION['uid']），角色每次都回表查。
   不把 role 塞进 session 是有意的：管理员把某人降级之后，
   对方手里的旧会话必须立刻失去权限，而不是等他自己登出。 */

/** 当前登录用户 id；游客为 0 */
function current_user_id(): int
{
    /* 没有会话 cookie 就一定没有登录态 —— 此时绝不能 session_start()。
       这一句决定了游客请求的整条路径：
         · 不建 session 文件、不产生磁盘写
         · 不返 Set-Cookie（少了响应头，也少了每次请求带回去的 Cookie）
         · 不再被 PHP 默认的 no-store 拖累，页面头可以由我们自己说了算
       代价为零：真登录过的浏览器一定带着 HPSESSID。
       注意 login.php / admin.php / api.php 都是显式 session_boot() 的，
       不依赖这里，所以登录流程不受影响。 */
    if (empty($_COOKIE[HP_SESSION_NAME])) {
        return 0;
    }
    session_boot();
    return (int)($_SESSION['uid'] ?? 0);
}

/**
 * remote 图标地址是否可以存。
 *
 * 与 MetaFetcher::isPublicUrl() 的关键区别：**不做 DNS 解析**。
 * 这张图服务器根本不会去请求 —— 只是把地址存进库，由浏览器（访客的本机
 * 网络）去加载。所以「域名在服务器上解析不出来」不该成为拒绝理由：
 * 被墙站点的域名在境内 DNS 常被投到黑洞地址，而那正是这个功能的适用场景。
 * 用 isPublicUrl 判会把这类地址全拒掉，功能等于没做。
 *
 * 仍要挡的是字面量内网地址：存一个 https://127.0.0.1/x.png 进库，
 * 等于让每个访客的浏览器替站长探内网。
 */
function hp_remote_icon_ok(string $url): bool
{
    if ($url === '' || strlen($url) > 512) {
        return false;
    }
    $p = parse_url($url);
    if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https') {
        return false;
    }
    $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if ($host === '' || strlen($host) > 253) {
        return false;
    }
    if ($host === 'localhost'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')) {
        return false;
    }
    // 字面量 IP：只放行公网段（私有/保留/回环一律拒绝）
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    // 单标签主机名（https://intranet/x.png）在公网没有意义，直接拒
    return str_contains($host, '.');
}

/**
 * 归属判定分两套：读（渲染）与写（改数据），**不能共用一个函数**。
 *
 *   读 hp_read_uid_for()：
 *     游客      → 0（站点默认，只读）
 *     普通用户  → 自己的 id
 *     admin     → 'site' → 0；'mine' → 自己的 id
 *     单人模式  → 游客与站长都是 hp_single_uid()；**其他登录者读自己那份**
 *
 *   写 hp_write_uid_for()：
 *     游客      → 0（调用方 require_login 已拦，正常到不了）
 *     普通用户  → 自己的 id；**单人模式返回 0，调用方必须先拒绝**
 *     admin     → 单人模式 hp_single_uid()；多人模式按 $lib
 *
 * 为什么必须拆（V1.5.7）：单人模式下归属与多人**方向相反**。
 *   多人时"普通用户读自己那份"是对的；单人时全站只有一份（站长那份）。
 *   若读侧沿用"单人即站长库"，一个切模式前登录、会话还没过期的普通账号
 *   就能读到并改到访客看到的站点内容 —— 这正是本次修复的缺陷。
 *   拆开后读侧按身份分流、写侧对非 admin 在单人模式下直接拒绝，
 *   两侧不再互相污染。
 *
 * admin 两份库的来历（V1.5.6）：站点默认（user_id=0）与「我的库」，
 *   后台顶部显式切换，编辑的是哪一份一目了然。
 */
function hp_read_uid_for(string $lib = 'site'): int
{
    $u = current_user();

    /* 单人模式只有一份数据，不存在「站点默认 / 我的库」之分：
       站长自己那份就是全站唯一那份，访客读的也是它。
       但**只对游客与站长**成立 —— 其他登录者读自己那份，
       否则僵留会话可以读到站长库。 */
    if (hp_single_mode()) {
        if ($u === null || ($u['role'] ?? '') === 'admin') {
            return hp_single_uid();
        }
        return (int)$u['id'];
    }

    if ($u === null) {
        return 0;
    }
    if (($u['role'] ?? '') === 'admin') {
        return $lib === 'mine' ? (int)$u['id'] : 0;
    }
    // 普通用户没有「站点默认」这个概念，永远只碰自己那一份。
    // 即使前端伪造 lib=mine 也到不了这里以外的地方。
    return (int)$u['id'];
}

/** 历史别名：所有渲染路径仍可继续调用它（index.php / admin.php 不用改） */
function hp_data_uid_for(string $lib = 'site'): int
{
    return hp_read_uid_for($lib);
}

/**
 * 写归属（唯一来源）。单人模式下只对站长生效，
 * 普通用户返回 0 —— 调用方必须先判定并拒绝（见 api.php 的单人模式断言），
 * 不要指望这里"自动纠正"，因为 0 是合法 uid（站点默认库）。
 */
function hp_write_uid_for(string $lib = 'site'): int
{
    $u = current_user();
    if ($u === null) {
        return 0;
    }
    if (($u['role'] ?? '') === 'admin') {
        if (hp_single_mode()) {
            return hp_single_uid();
        }
        return $lib === 'mine' ? (int)$u['id'] : 0;
    }
    if (hp_single_mode()) {
        return 0;
    }
    return (int)$u['id'];
}

/** 前台用这个：admin 看站点默认（访客看到的那一份） */
function hp_data_uid(): int
{
    return hp_data_uid_for('site');
}

/** 当前用户记录（id/username/role）；游客返回 null */
function current_user(): ?array
{
    /* 缓存放 $GLOBALS 而不是 static：登录成功的那一次请求里，
       同一进程先后要「当作游客」和「当作刚登录的用户」各读一次，
       static 变量没法从外部清空，会导致登录后权限仍然按游客算。 */
    if (array_key_exists('__hp_user', $GLOBALS)) {
        return $GLOBALS['__hp_user'];
    }
    $uid = current_user_id();
    if ($uid <= 0) {
        $GLOBALS['__hp_user'] = null;
        return null;
    }
    try {
        $st = db()->prepare('SELECT id, username, role, created_at, last_login, auth_epoch FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch();
        /* 会话吊销：登录时把这个数记进 session，改密 / 重置密码 / 切站点模式
           时库里 +1 —— 于是攻击者手里那份"密码泄露时偷到的会话"下一次请求就失效。
           老会话读不到这个键时按 0 算，与老库补列的默认值一致，
           所以升级本身不会把任何人踢下线。 */
        if ($row && (int)($row['auth_epoch'] ?? 0) !== (int)($_SESSION['auth_epoch'] ?? 0)) {
            $row = false;
        }
    } catch (Throwable $e) {
        $row = false;
    }
    $GLOBALS['__hp_user'] = $row ?: null;
    return $GLOBALS['__hp_user'];
}

/** 登录 / 登出 / 切换账号之后必须调用，否则本请求内仍按旧身份判定 */
function current_user_flush(): void
{
    $GLOBALS['__hp_user'] = null;
    $GLOBALS['__hp_settings'] = null;
}

function is_logged_in(): bool
{
    /* 前半句保留「游客零数据库读」：没有 session cookie 时 current_user_id()
       直接返回 0，不会 session_start、不查库。
       后半句是删除账号功能的必要护栏：旧逻辑只看 session 里有 uid 就算
       登录，账号被管理员删掉后，对方手里的会话仍可通过 require_login()，
       而 current_user() 查不到人 → hp_data_uid_for() 把 uid 降级成 0
       （站点默认库），等于被删账号还能改访客看到的内容。这里回表确认账号
       仍在，删号后旧会话当场失效。current_user() 同请求内有缓存，不额外
       增加查询次数。 */
    return current_user_id() > 0 && current_user() !== null;
}

/** 只有 admin 角色才算管理员。普通账号一律不是。 */
function is_admin(): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', 'api.php')) {
            json_err('未登录或会话已过期', 401);
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * 管理员专属。后台页、统计、访问控制、升级、全局设置全都要先过这一关。
 * 这是**服务端**的闸门 —— 前端把入口藏起来只是体验，不是权限。
 */
function require_admin(): void
{
    if (!is_admin()) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', 'api.php')) {
            json_err('没有权限执行该操作', 403);
        }
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        exit('<!doctype html><meta charset="utf-8"><title>403</title>'
            . '<div style="font:15px/1.7 system-ui;padding:48px;color:#18181b">'
            . '<h1 style="font-size:20px;margin:0 0 8px">没有权限</h1>'
            . '<p style="color:#71717a;margin:0">这个页面只有站点管理员可以访问。</p>'
            . '</div>');
    }
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    session_boot();
    $sent = $_SERVER['HTTP_X_CSRF'] ?? $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        json_err('安全校验失败，请刷新页面重试', 419);
    }
}

// ---------- 设置读写 ----------
function settings_all(): array
{
    /* 缓存放在 $GLOBALS 里而不是函数的 static 变量里：
       static 变量在函数外部没有任何办法清空，而设置是「先写后读」
       的——保存访问控制后同一个请求里要立刻按新规则判定。
       用全局数组，settings_flush() 才能真的把它置空。 */
    if (isset($GLOBALS['__hp_settings']) && is_array($GLOBALS['__hp_settings'])) {
        return $GLOBALS['__hp_settings'];
    }
    $cache = [];
    // 先铺全局（user_id = 0），再用当前用户自己的值覆盖同名键。
    // 这样「用户没设过」就自动落回站点默认，不用在读取处写两遍兜底。
    foreach (db()->query('SELECT k, v FROM settings WHERE user_id = 0') as $row) {
        $cache[$row['k']] = (string)$row['v'];
    }
    $uid = current_user_id();
    if ($uid > 0) {
        $st = db()->prepare('SELECT k, v FROM settings WHERE user_id = ?');
        $st->execute([$uid]);
        foreach ($st as $row) {
            $cache[$row['k']] = (string)$row['v'];
        }
    }
    $GLOBALS['__hp_settings'] = $cache;
    return $cache;
}

/** 丢掉设置缓存。setting_set() 会调用它。 */
function settings_flush(): void
{
    $GLOBALS['__hp_settings'] = null;
}

/** 当前版本号。VERSION 文件缺失时返回 dev，不让页面因为读不到文件而报错。 */
function hp_version(): string
{
    static $v = null;
    if ($v === null) {
        $v = trim((string)@file_get_contents(HP_ROOT . '/VERSION'));
        if ($v === '') {
            $v = 'dev';
        }
    }
    return $v;
}

function setting(string $key, string $default = ''): string
{
    return settings_all()[$key] ?? $default;
}

/* ---------- 站点图标（logo） ----------
   后台「外观偏好 → 基本信息」里设的。存的是**本站相对路径**（data/icons/…），
   不是原始外链 —— 外链会在对方换图/删图/防盗链时静默变成一张破图，
   而且每个访客打开首页都要向第三方握手一次。
   设定时（api.php 的 site_logo / site_logo_upload）就下载并落盘了，
   这里只负责「读路径 + 确认文件还在」。 */
function hp_site_logo(): string
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $p = '';
    $v = trim(setting('site_logo', ''));
    // 只认本站图标目录里的东西：库被手改过也不会变成 <img src="javascript:…">
    if ($v !== '' && str_starts_with($v, 'data/icons/') && is_file(HP_ROOT . '/' . $v)) {
        $p = $v;
    }
    return $p;
}

/** 站点图标的 <img>；没设置时返回空串，调用方保留原来的默认图形 */
function hp_site_logo_img(string $class = '', int $size = 18): string
{
    $p = hp_site_logo();
    if ($p === '') {
        return '';
    }
    $cls = $class !== '' ? ' class="' . h($class) . '"' : '';
    return '<img' . $cls . ' src="' . h($p) . '" width="' . $size . '" height="' . $size
        . '" alt="" decoding="async">';
}

/** <link rel="icon"> 用的地址：有自定义图标就用它，没有交给调用方用内联 SVG */
function hp_site_favicon_href(): string
{
    $p = hp_site_logo();
    return $p === '' ? '' : h($p);
}

/**
 * 把刚落进 data/icons/ 的图换成**新文件名**，失败就原名返回。
 *
 * data/icons/ 被 .htaccess 打了一年 immutable 缓存，同一个 URL 换内容
 * 是换不掉的（浏览器和 CDN 都不会再回来问一次）。所以每次设新图标都产一个
 * 新路径 —— 旧文件由 hp_site_logo_drop() 删掉。
 */
function hp_site_logo_unique(string $rel): string
{
    if (!str_starts_with($rel, 'data/icons/')) {
        return $rel;
    }
    $abs = HP_ROOT . '/' . $rel;
    if (!is_file($abs)) {
        return $rel;
    }
    $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION)) ?: 'webp';
    $new = 'data/icons/sitelogo-' . bin2hex(random_bytes(6)) . '.' . $ext;
    return @rename($abs, HP_ROOT . '/' . $new) ? $new : $rel;
}

/** 删掉当前设置的图标文件（换图 / 恢复默认时调）。只碰 data/icons/ 里的 */
function hp_site_logo_drop(): void
{
    $old = trim(setting('site_logo', ''));
    if ($old !== '' && str_starts_with($old, 'data/icons/') && is_file(HP_ROOT . '/' . $old)) {
        @unlink(HP_ROOT . '/' . $old);
    }
}

/** 个人偏好键：这些按用户存。其余都是站点级，只有 admin 能改。 */
function hp_user_setting_keys(): array
{
    return ['layout', 'theme', 'accent', 'groupnav', 'show_search',
            'search_engine', 'open_new_tab', 'show_desc', 'hint_dismissed',
            /* 注册后看到的「这是你自己的副本」提示，关掉一次就不再出现。
               必须按用户存：管理员没有这条提示，也不该替所有人关掉。 */
            'clone_hint_seen'];
}

/**
 * 写设置。
 * $uid 不传时按规则自动归属：
 *   · 管理员 → 写 0（全局）。他是站点所有者，改的就是默认值。
 *   · 普通用户 → 只有个人偏好键能写到自己名下，其余键直接拒绝（返回 false）。
 * 这个判断必须在服务端做：前端把「站点名」输入框藏起来不算权限。
 */
function setting_set(string $key, string $value, ?int $uid = null): bool
{
    if ($uid === null) {
        if (is_admin()) {
            $uid = 0;
        } elseif (is_logged_in() && in_array($key, hp_user_setting_keys(), true)) {
            $uid = current_user_id();
        } else {
            return false;
        }
    }
    $st = db()->prepare('INSERT INTO settings(user_id, k, v) VALUES(?, ?, ?) '
        . 'ON CONFLICT(user_id, k) DO UPDATE SET v = excluded.v');
    $st->execute([$uid, $key, $value]);
    settings_flush();
    return true;
}

/* ---------- 站点运行模式 ----------
   单人模式 single：一个人部署、所有人只读、只有站长能登录、隐藏链接回归。
   多人模式 multi（缺省）：允许注册、每个账号一份数据、隐藏链接功能下线。
   缺省值取 multi —— 老站点读不到这个键时行为完全不变，
   只有站长在后台主动切到 single 才改变站点形态，升级不会误伤。 */
function hp_site_mode(): string
{
    $m = setting('site_mode', 'multi');
    return $m === 'single' ? 'single' : 'multi';
}

function hp_single_mode(): bool
{
    return hp_site_mode() === 'single';
}

/**
 * 单人模式下全站的唯一数据归属。
 *
 * 单人模式只有一个人能登录，所以「站点默认（user_id = 0）」与
 * 「我的库（站长 id）」的分野没有意义 —— 只留一份，优先用站长那份：
 * 站长通常在自己账号里编辑，数据也就落在那儿。
 *
 * 但站长那份**完全为空**时回落 0，这不是随手兜底：
 *   · 全新安装的种子数据写在 user_id = 0；
 *   · V1.1.5 之前从多人模式切过来的站点，内容也都还在 0。
 * 不做这个回落，一装好或一切模式整站就是空的，看着像数据全丢。
 *
 * 判定只看「内容」——链接 + 分组。只改过皮肤/布局的账号不算有库，
 * 否则换过一次主题就会把整站数据源抢过去。
 */
function hp_single_uid(): int
{
    static $uid = null;
    if ($uid !== null) {
        return $uid;
    }
    $uid = 0;
    try {
        $pdo = db();
        /* 一条查询拿到「最早的站长 + 他名下有多少内容」。
           两个标量子查询比再发一次往返省事，也让判定用同一份快照。 */
        $row = $pdo->query(
            "SELECT u.id,
                    (SELECT COUNT(*) FROM links  l WHERE l.user_id = u.id)
                  + (SELECT COUNT(*) FROM groups g WHERE g.user_id = u.id) AS n
               FROM users u
              WHERE u.role = 'admin'
              ORDER BY u.id ASC
              LIMIT 1"
        )->fetch();
        if ($row && (int)$row['n'] > 0) {
            $uid = (int)$row['id'];
        }
    } catch (Throwable $e) {
        // 未安装或表还没建好：当作站点默认库，绝不让首页因判定数据源而 500
    }
    return $uid;
}