<?php
/**
 * 建表语句集合。install.php 与升级都用它，幂等（IF NOT EXISTS）。
 *
 * 升级策略：不维护 migration 脚本目录，只维护一个 SCHEMA_VERSION。
 * 每次结构有变就把新表/新列写进 hp_create_tables()，
 * 然后 hp_migrate() 在版本落后时整体跑一遍 —— 因为全部语句
 * 都是 IF NOT EXISTS，重复执行无副作用。
 * 唯一要小心的是 ALTER TABLE ADD COLUMN：SQLite 没有 IF NOT EXISTS，
 * 所以那一处必须先 PRAGMA table_info 判断列是否已存在。
 */
declare(strict_types=1);
if (!defined('HP_ROOT')) { http_response_code(404); exit; } // 入口守卫：与 .htaccess 的 404 保持一致，直接访问不暴露路径存在

/** 结构版本。加字段/加表就 +1，并在 hp_migrate() 里补一段。 */
/** v8：users 增加 auth_epoch —— 会话吊销（改密 / 重置 / 切模式后旧会话失效）。 */
/** v7：清掉历史的 UNIQUE(url) 单列索引，URL 唯一性只按 (user_id, url) 管。 */
/** v6：多用户（users 表 + links/groups/settings 的 user_id 归属列）。 */
/** v5：groups 增加 icon 列（分组图标可自定义）。 */
const HP_SCHEMA_VERSION = 8;

/**
 * 建全部表与索引。纯 DDL，幂等。
 */
function hp_create_tables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS groups (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    sort        INTEGER NOT NULL DEFAULT 0,
    -- 图标键。空 = 按组名自动匹配；非空 = 用户手动指定（见 inc/groupnav.php）
    icon        TEXT    NOT NULL DEFAULT '',
    created_at  TEXT    NOT NULL
);
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id    INTEGER NULL,
    title       TEXT    NOT NULL,
    url         TEXT    NOT NULL,
    icon        TEXT    NOT NULL DEFAULT '',
    icon_kind   TEXT    NOT NULL DEFAULT 'auto',
    descr       TEXT    NOT NULL DEFAULT '',
    sort        INTEGER NOT NULL DEFAULT 0,
    hits        INTEGER NOT NULL DEFAULT 0,
    hidden      INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL
);
SQL);

    // 主页是高频读、低频写，索引只留真正会被 WHERE/ORDER 用到的
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_links_sort ON links(group_id, sort, id)');
    /* 这里**不能**再建 UNIQUE(url) 单列索引。
       v6 起链接按 user_id 分成多份（0 = 站点默认，N = 用户 N 的私有），
       URL 的唯一性必须是 (user_id, url) —— 建在 url 上等于规定
       「全站所有账号加起来同一个网址只能出现一次」，两个账号收藏同一个
       站点就直接撞索引。V1.5.6 实测：把默认库复制到管理员私人库时
       报 UNIQUE constraint failed: links.url，就是这个索引干的。
       复合索引由 hp_migrate() 统一创建，见下面的 ux_links_owner_url。 */

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    -- 0 = 全局/系统级（站点名、访问控制、统计开关…）
    -- N = 用户 N 的个人偏好（皮肤、布局、默认引擎…）
    -- 现有老库只有 (k, v) 两列，hp_migrate() 里会重建这张表。
    user_id INTEGER NOT NULL DEFAULT 0,
    k TEXT NOT NULL,
    v TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (user_id, k)
);
SQL);

    /* 账号。role 只有两个值：
         admin —— 站点所有者，能进后台、改系统级设置
         user  —— 普通账号，只能用，不能碰任何系统层面的东西 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT    NOT NULL UNIQUE,
    pass_hash   TEXT    NOT NULL,
    role        TEXT    NOT NULL DEFAULT 'user',
    created_at  TEXT    NOT NULL,
    last_login  TEXT    NOT NULL DEFAULT '',
    auth_epoch  INTEGER NOT NULL DEFAULT 0
);
SQL);

    // 登录失败限流：按 IP 计数，避免后台被撞库
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    ip      TEXT PRIMARY KEY,
    fails   INTEGER NOT NULL DEFAULT 0,
    last_at INTEGER NOT NULL DEFAULT 0
);
SQL);

    /* ---------- 访问统计 ----------
       一行 = 一次页面访问。刻意不建「按天聚合表」：
       聚合查询在 SQLite 上 counts 走索引足够快（万级数据 <5ms），
       而多一张聚合表就多一处可能不一致的真相。
       保留策略由后台「统计」页的清理按钮控制（默认留 90 天）。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS visits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    at          INTEGER NOT NULL,
    ip          TEXT    NOT NULL DEFAULT '',
    ip_hash     TEXT    NOT NULL DEFAULT '',
    country     TEXT    NOT NULL DEFAULT '',
    region      TEXT    NOT NULL DEFAULT '',
    city        TEXT    NOT NULL DEFAULT '',
    isp         TEXT    NOT NULL DEFAULT '',
    ua          TEXT    NOT NULL DEFAULT '',
    browser     TEXT    NOT NULL DEFAULT '',
    os          TEXT    NOT NULL DEFAULT '',
    device      TEXT    NOT NULL DEFAULT '',
    ref_host    TEXT    NOT NULL DEFAULT '',
    ref_kind    TEXT    NOT NULL DEFAULT 'direct',
    path        TEXT    NOT NULL DEFAULT '/',
    is_admin    INTEGER NOT NULL DEFAULT 0
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_at ON visits(at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_ip ON visits(ip)');

    /* 链接点击。和 links.hits 冗余但不冲突：
       hits 是给人看的累计数（快，主页有用）；
       这张表是给分析用的明细（能按天/来源/归属地切）。
       写入失败不影响点击跳转，所以两边各写各的。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS clicks (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    at        INTEGER NOT NULL,
    link_id   INTEGER NOT NULL,
    ip_hash   TEXT    NOT NULL DEFAULT '',
    country   TEXT    NOT NULL DEFAULT '',
    ref_kind  TEXT    NOT NULL DEFAULT 'direct'
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_at ON clicks(at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_clicks_link ON clicks(link_id)');

    /* ---------- IP 归属地 ----------
       本地查表，零外部请求。数据由后台「一键导入」从 DB-IP Lite 拉取。
       用「起止整数 + 二分查找」而不是逐条 LIKE：IPv4 转 int 后
       start <= ip <= end 走主键索引，单次查询实测 0.05ms。

       城市段表只存 pid 指向 geo_place：209 万个网段如果各自存一遍
       「广东省/深圳市」会白占十几 MB，而去重后地点只有约 3700 个。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS geo_country (
    start INTEGER PRIMARY KEY,
    end   INTEGER NOT NULL,
    cc    TEXT    NOT NULL
);
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS geo_place (
    id     INTEGER PRIMARY KEY,
    cc     TEXT NOT NULL DEFAULT '',
    region TEXT NOT NULL DEFAULT '',
    city   TEXT NOT NULL DEFAULT ''
);
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS geo_city (
    start INTEGER PRIMARY KEY,
    end   INTEGER NOT NULL,
    pid   INTEGER NOT NULL
);
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS geo_asn (
    start INTEGER PRIMARY KEY,
    end   INTEGER NOT NULL,
    asn   TEXT    NOT NULL DEFAULT '',
    org   TEXT    NOT NULL DEFAULT ''
);
SQL);

    // 导入元信息：数据版本、导入时间、各表行数，给后台显示用
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS geo_meta (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL DEFAULT ''
);
SQL);

    /* ---------- 访问控制 ----------
       一个国家一行。mode = allow（白名单）/ deny（黑名单）。
       为什么不塞进 settings 的逗号串：国家可能上百条，
       每次都要 explode + in_array，且没有类型约束易脏。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS acl_countries (
    cc    TEXT PRIMARY KEY,
    mode  TEXT NOT NULL DEFAULT 'deny',
    note  TEXT NOT NULL DEFAULT ''
);
SQL);

    /* ---------- 登录验证码 ----------
       不存 session：游客态本站刻意不开 session，而验证码必须
       跨请求校验。落库 + 一次性消费，比 session 更稳 ——
       用户换个标签页或者 session 被清掉也不会「验证码失效」。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS captcha (
    token TEXT PRIMARY KEY,
    code  TEXT NOT NULL DEFAULT '',
    at    INTEGER NOT NULL DEFAULT 0
);
SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_captcha_at ON captcha(at)');

    /* ---------- 登录失败锁定 ----------
       与 login_attempts 分工不同：
       login_attempts 是「短窗口计数」（15 分钟 5 次），按 IP；
       这张是「封锁记录」，按 IP + 账号双维度，记录解封时刻。
       分开建表是为了让封锁状态可以单独延长/解除，不必动计数。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_locks (
    k       TEXT PRIMARY KEY,
    until   INTEGER NOT NULL DEFAULT 0,
    reason  TEXT NOT NULL DEFAULT '',
    at      INTEGER NOT NULL DEFAULT 0
);
SQL);

    /* ---------- 图标缓存索引 ----------
       图标文件本身在 data/icons/，这里记一行元信息：
       什么时候抓的、抓了几次、多大、源站是谁。
       作用是让后台能「一键重建」「清掉旧的」「看缓存命中情况」，
       也方便判断某条链接的图标是不是该重抓了。
       注意：主页渲染**不查这张表** —— 它只服务后台维护，
       前台靠 is_file() 直接判断，少一次查询。 */
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS icon_cache (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    host      TEXT    NOT NULL DEFAULT '',
    path      TEXT    NOT NULL DEFAULT '',
    bytes     INTEGER NOT NULL DEFAULT 0,
    mime      TEXT    NOT NULL DEFAULT '',
    src       TEXT    NOT NULL DEFAULT '',
    at        INTEGER NOT NULL DEFAULT 0
);
SQL);
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_icon_path ON icon_cache(path)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_icon_host ON icon_cache(host)');
}

/** 补齐所有设置项的默认值（幂等，不覆盖用户已改的值） */
function hp_seed_settings(PDO $pdo): void
{
    $defaults = [
        // 站点名。v1.2.0 起默认就是 Vnav，不再是「我的主页」——
        // 装完看到的就是品牌名，不必再进后台改一次。
        'site_title'    => 'Vnav',
        'site_subtitle' => '把每天要去的地方，放在一个地方',
        // 单人 / 多人模式。默认 multi（保持老的开放注册行为）；
        // 安装向导会让用户显式选一次，这里的值只作为「没走向导/老库升级」的兜底。
        'site_mode'     => 'multi',     // multi | single
        'layout'        => 'card',      // card | menu | compact | grid
        'theme'         => 'atlas',     // 见 inc/skins.php
        'accent'        => 'amber',
        'groupnav'      => 'side',      // 只剩 side；旧库里的 tab 由 hp_groupnav_safe() 归一
        'show_search'   => '1',
        'search_engine' => 'bing',
        'open_new_tab'  => '1',
        'show_desc'     => '1',
        'footer_note'   => '',
        // 侧栏底部的版权署名。author_name 是署名里的名字（可点，指向个人主页）；
        // author_github 是「项目主页」，指到仓库而不是个人页；
        // author_url 是署名里那个名字的链接。三者留空都有兜底。
        'author_name'   => '',
        'author_github' => 'https://github.com/izyw/VNav',
        'author_url'    => 'https://github.com/izyw',
        // 统计：默认开。个人主页量级下开销就是一条 INSERT。
        'stats_on'      => '1',
        'stats_keep'    => '90',        // 明细保留天数
        // 访问控制：off 时不拦任何人，避免一装就把自己锁在门外
        'acl_mode'      => 'off',       // off | on
        'acl_unknown'   => 'allow',     // allow | deny（查不到归属地时怎么办）
        'acl_list_type' => 'deny',      // deny = 黑名单 / allow = 白名单
        // 登录防护：错 1 次出验证码，错 5 次锁 15 分钟
        'login_captcha' => '1',         // 1 = 开启验证码升级策略
        'hint_dismissed'=> '0',         // 右下角快捷键提示是否已被永久关闭
    ];
    $st = $pdo->prepare('INSERT OR IGNORE INTO settings(user_id, k, v) VALUES(0, ?, ?)');
    foreach ($defaults as $k => $v) {
        $st->execute([$k, $v]);
    }
    $st->execute(['schema_version', (string)HP_SCHEMA_VERSION]);
}

/**
 * 全新安装 / 老库升级走同一个入口：建表 + 补默认值。
 * 幂等，可以每次请求都跑（实际只有 index 首行跑一次，见 boot.php）。
 */
function hp_install_schema(PDO $pdo): void
{
    hp_create_tables($pdo);

    /* ⚠️ 这里必须补一次 user_id 列，不能只靠 hp_migrate。
       CREATE TABLE 里没有 user_id —— 它是 v6 用 ALTER 加上去的，而
       hp_create_tables 在结尾已经把 schema_version 写成 HP_SCHEMA_VERSION，
       于是 boot.php 的「版本落后才迁移」判断直接通过，ALTER 永远不会跑。
       结果是一个「结构上属于 v5、版本号写着 v7」的库：links 和 groups 都缺
       user_id，而前台每条 SQL 都在 WHERE user_id = ? —— 全新安装必挂。
       线上当初是带着一份本机的 home.db 装的（那份是从老库升上来的，有列），
       所以这条路径一直没被踩到。 */
    foreach (['links', 'groups'] as $tbl) {
        $hasUid = false;
        foreach ($pdo->query('PRAGMA table_info(' . $tbl . ')') as $c) {
            if (($c['name'] ?? '') === 'user_id') { $hasUid = true; break; }
        }
        if (!$hasUid) {
            $pdo->exec('ALTER TABLE ' . $tbl . ' ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0');
        }
    }
    // 唯一索引同理：它是在 migrate 里建的，新库不补就少了这层约束
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_links_owner_url ON links(user_id, url)');

    /* 默认值要等迁移跑完再灌：settings 的写入语句用了 user_id 列，
       而那一列要等下面的「重建 settings 表」之后才存在。
       先灌就会撞上 table settings has no column named user_id。 */
}

/**
 * 老库升级。boot.php 在检测到版本落后时调一次。
 *
 * 注意：这里的 DDL 全部是 IF NOT EXISTS，所以「老库没有 visits 表」
 * 的情况自动补上；只有 ALTER TABLE 需要先查 PRAGMA。
 * 从不删列 —— 删列在 SQLite 里要重建整表，风险与收益不成比例。
 */
function hp_migrate(PDO $pdo): void
{
    hp_create_tables($pdo);

    /* ---------- v6：多用户 ----------
       1) settings 从 (k) 主键改成 (user_id, k)：以前所有设置都是全局的，
          现在要能按用户存皮肤/布局。SQLite 改不了主键，只能重建。
       2) links / groups 加 user_id：0 = 站点默认（游客看到的那份），
          N = 用户 N 的私有数据。
       3) 首个管理员从 config.php 的 admin_user / admin_pass 导入 ——
          升级前用户就是靠这一对登录的，升级后必须还能用同一个密码进去。 */

    $hasSettingUid = false;
    foreach ($pdo->query('PRAGMA table_info(settings)') as $c) {
        if (($c['name'] ?? '') === 'user_id') { $hasSettingUid = true; break; }
    }
    if (!$hasSettingUid) {
        $pdo->exec('ALTER TABLE settings RENAME TO settings_old');
        $pdo->exec("CREATE TABLE settings (
            user_id INTEGER NOT NULL DEFAULT 0,
            k TEXT NOT NULL,
            v TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, k)
        )");
        $pdo->exec('INSERT INTO settings(user_id, k, v) SELECT 0, k, v FROM settings_old');
        $pdo->exec('DROP TABLE settings_old');
    }

    foreach (['links', 'groups'] as $tbl) {
        $hasUid = false;
        foreach ($pdo->query('PRAGMA table_info(' . $tbl . ')') as $c) {
            if (($c['name'] ?? '') === 'user_id') { $hasUid = true; break; }
        }
        if (!$hasUid) {
            $pdo->exec('ALTER TABLE ' . $tbl . ' ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0');
        }
    }

    /* URL 唯一索引必须带上 user_id。
       原来是 UNIQUE(url)，多用户之后两个账号收藏同一个网址会直接撞索引 ——
       而且报错发生在「注册时复制默认数据」那一步，新账号当场建不出来。 */
    $pdo->exec('DROP INDEX IF EXISTS ux_links_url');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_links_owner_url ON links(user_id, url)');

    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $cfg = $GLOBALS['CONFIG'] ?? [];
        $an  = trim((string)($cfg['admin_user'] ?? 'admin'));
        $ah  = (string)($cfg['admin_pass'] ?? '');
        if ($ah !== '') {
            $pdo->prepare('INSERT INTO users(username, pass_hash, role, created_at) VALUES(?, ?, ?, ?)')
                ->execute([$an !== '' ? $an : 'admin', $ah, 'admin', now()]);
        }
    }

    // v5：分组图标。同样用 PRAGMA 判断列是否存在 —— v0～v4 的老库都缺它。
    $hasGroupIcon = false;
    foreach ($pdo->query('PRAGMA table_info(groups)') as $c) {
        if (($c['name'] ?? '') === 'icon') { $hasGroupIcon = true; break; }
    }
    if (!$hasGroupIcon) {
        $pdo->exec("ALTER TABLE groups ADD COLUMN icon TEXT NOT NULL DEFAULT ''");
    }

    // v2 → v3：隐藏链接标记。判断依据用「列是否存在」而不是版本号，
    // 因为 v0/v1 的老库跨版本过来也缺这一列。
    $has = false;
    foreach ($pdo->query('PRAGMA table_info(links)') as $c) {
        if ($c['name'] === 'hidden') { $has = true; break; }
    }
    if (!$has) {
        $pdo->exec('ALTER TABLE links ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0');
    }

    /* 会话吊销：改密 / 重置密码 / 切站点模式时把 auth_epoch +1，
       已发出的会话在下次请求比对时失效（见 current_user()）。
       老库补列默认 0，且老会话读不到该键也按 0 算 —— 所以升级本身
       不会把任何人踢下线；只有"凭据真的变了"才会。 */
    $hasEpoch = false;
    foreach ($pdo->query('PRAGMA table_info(users)') as $c) {
        if (($c['name'] ?? '') === 'auth_epoch') { $hasEpoch = true; break; }
    }
    if (!$hasEpoch) {
        $pdo->exec('ALTER TABLE users ADD COLUMN auth_epoch INTEGER NOT NULL DEFAULT 0');
    }

    /* v3 → v4：v4 只有新表（captcha / login_locks / icon_cache），
       DDL 已由上面的 hp_create_tables() 补齐，这里不需要额外动作。
       写这一段是为了让后面的人知道：跨版本时不必每个版本写一条，
       只要 DDL 幂等，缺什么表自动建什么表。 */

    // 品牌改名：老库里写着「我的主页」或「鹏城工具箱」的，一并迁到 Vnav。
    // 用户自己改过别的名字就不动 —— 只替换这两个已知的历史默认值。
    $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute(['site_title']);
    $cur = (string)($st->fetchColumn() ?: '');
    if (in_array($cur, ['我的主页', '鹏城工具箱', '鹏程工具箱', ''], true)) {
        $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?')->execute(['Vnav', 'site_title']);
    }

    $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?')
        ->execute([(string)HP_SCHEMA_VERSION, 'schema_version']);

    // 结构就位之后再灌默认值，最后写 schema_version
    hp_seed_settings($pdo);
}