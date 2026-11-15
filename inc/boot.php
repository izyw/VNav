<?php
/**
 * 引导文件 —— 所有入口第一行 require 这里。
 * 只做三件事：载配置、开 DB、备好公共函数。
 */
declare(strict_types=1);

if (!defined('HP_ROOT')) {
    define('HP_ROOT', dirname(__DIR__));
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
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    global $CONFIG;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('HPSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------- 账号与角色 ----------
   会话里只存用户 id（$_SESSION['uid']），角色每次都回表查。
   不把 role 塞进 session 是有意的：管理员把某人降级之后，
   对方手里的旧会话必须立刻失去权限，而不是等他自己登出。 */

/** 当前登录用户 id；游客为 0 */
function current_user_id(): int
{
    session_boot();
    return (int)($_SESSION['uid'] ?? 0);
}

/**
 * 当前请求该读哪一份数据。
 *
 *   游客      → 0（站点默认，只读）
 *   admin     → 0（他就是站点所有者，编辑的就是默认数据）
 *   普通用户  → 自己的 id
 *
 * 把 admin 也映射到 0 是有意的：站点默认只有一份，admin 改完游客立刻
 * 就能看到；否则 admin 得同时维护「自己的」和「默认的」两份，必然漂移。
 */
function hp_data_uid(): int
{
    $u = current_user();
    if ($u === null) {
        return 0;
    }
    if (($u['role'] ?? '') === 'admin') {
        return 0;
    }
    return (int)$u['id'];
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
        $st = db()->prepare('SELECT id, username, role, created_at, last_login FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch();
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
    return current_user_id() > 0;
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

/** 个人偏好键：这些按用户存。其余都是站点级，只有 admin 能改。 */
function hp_user_setting_keys(): array
{
    return ['layout', 'theme', 'accent', 'groupnav', 'show_search',
            'search_engine', 'open_new_tab', 'show_desc', 'hint_dismissed'];
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