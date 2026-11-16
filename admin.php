<?php
/**
 * 后台管理页 —— 单页式：左侧导航，右侧内容区。
 * 数据全服务端渲染，交互靠 admin.js 里的 fetch。
 */
declare(strict_types=1);
define('HP_ROOT', __DIR__);
require __DIR__ . '/inc/boot.php';
require __DIR__ . '/inc/analytics.php';
require __DIR__ . '/inc/acl.php';
require __DIR__ . '/inc/geo.php';
require __DIR__ . '/inc/upgrade.php';

/* 后台整页是管理员专属：统计、访问控制、系统升级、账号密码都在这里。
   普通账号到这里直接 403，不是靠前端隐藏入口。 */
require_admin();
hp_compress_start(6);

if (isset($_GET['logout'])) {
    session_boot();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$tab = $_GET['tab'] ?? 'links';
$tab = in_array($tab, ['links', 'groups', 'look', 'stats', 'security', 'upgrade', 'changelog', 'help'], true) ? $tab : 'links';

/* 后台能编辑两份库：
     site  → user_id = 0，「网站默认」，访客看到的就是这一份
     mine  → admin 自己的 id，私人那份，访客看不到
   切换状态走 URL 的 ?lib=，刷新 / 书签 / 前进后退都不会丢，
   也不会出现「以为在改默认库，其实改了自己那份」。

   这两个查询原先没有 user_id 条件，普通用户的数据会一并列进来
   （admin 看到别人的私人书签，改却改不动 —— 写接口都带 user_id 条件）。 */
$seqLib = (string)($_GET['lib'] ?? 'site');
if ($seqLib !== 'mine') {
    $seqLib = 'site';
}
$AU = hp_data_uid_for($seqLib);
// 切 tab 时要把当前库带上，否则一点导航就跳回默认库
$libQS    = $seqLib === 'mine' ? '&lib=mine' : '';
$libLabel = $seqLib === 'mine' ? '我的链接库' : '网站默认';

/* link_count 一并查出来：分组页要显示「删掉这个组会影响多少条链接」，
   链接页的「选中本组」也用得到。子查询比逐组再查一遍省事。 */
$stG = db()->prepare(
    'SELECT g.id, g.name, g.icon,
            (SELECT COUNT(*) FROM links l WHERE l.group_id = g.id AND l.user_id = g.user_id) AS link_count
       FROM groups g WHERE g.user_id = ? ORDER BY g.sort ASC, g.id ASC'
);
$stG->execute([$AU]);
$groups = $stG->fetchAll();

$focus  = (int)($_GET['focus'] ?? 0);

$stL = db()->prepare(
    'SELECT id, group_id, title, url, icon, icon_kind, descr, sort, hits, hidden
       FROM links WHERE user_id = ? ORDER BY sort ASC, id ASC'
);
$stL->execute([$AU]);
$links = $stL->fetchAll();

$totalLinks = count($links);
$totalHits  = array_sum(array_map(fn($r) => (int)$r['hits'], $links));
$totalHidden = count(array_filter($links, fn($r) => (int)($r['hidden'] ?? 0) === 1));

// 后台访问也记一笔（is_admin=1），前台统计默认把它排除，但「谁进过后台」有据可查
hp_log_visit(true, '/admin.php');

/* ================= 统计页数据 =================
   全部用一条 SQL 聚合，不做循环查库。个人主页量级（万级行）
   下这些查询都在几毫秒内返回，所以不需要预聚合表。 */
$stats = null;
if ($tab === 'stats') {
    $pdo = db();

    /* 过期明细顺手清一次。不另外做定时任务 ——
       个人主页的流量用不上 cron，打开这一页的频率正好够当节拍器。
       放在最前面，后面的聚合就不用再滤老数据。 */
    if (setting('stats_on', '1') === '1') {
        try { hp_stats_prune(); } catch (Throwable $e) { /* 清理失败不该挡住看数据 */ }
    }

    // 时间窗：默认 30 天，可用 ?days= 调
    $days = (int)($_GET['days'] ?? 30);
    if (!in_array($days, [1, 7, 30, 90, 365], true)) $days = 30;
    $from = time() - $days * 86400;

    // 只统计前台访问：后台自己刷新的次数不该算进「访客」
    $W = 'FROM visits WHERE is_admin = 0 AND at >= ' . $from;

    $card = static function (PDO $p, string $sql, array $args = []) {
        $st = $p->prepare($sql);
        $st->execute($args);
        return $st->fetch();
    };

    $pv  = (int)$pdo->query('SELECT COUNT(*) ' . $W)->fetchColumn();
    $uv  = (int)$pdo->query('SELECT COUNT(DISTINCT ip_hash) ' . $W)->fetchColumn();
    $pvToday = (int)$pdo->query(
        'SELECT COUNT(*) FROM visits WHERE is_admin = 0 AND at >= ' . strtotime('today')
    )->fetchColumn();
    $uvToday = (int)$pdo->query(
        'SELECT COUNT(DISTINCT ip_hash) FROM visits WHERE is_admin = 0 AND at >= ' . strtotime('today')
    )->fetchColumn();

    // 逐日趋势：用 PHP 补齐没有访问的那几天，否则折线会跳
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $daily[date('Y-m-d', strtotime("-$i day"))] = 0;
    }
    foreach ($pdo->query(
        "SELECT date(at, 'unixepoch', 'localtime') d, COUNT(*) c
           FROM visits WHERE is_admin = 0 AND at >= $from GROUP BY d"
    ) as $r) {
        if (isset($daily[$r['d']])) $daily[$r['d']] = (int)$r['c'];
    }

    // 点击榜：hits 是累计值，同时给出窗口内明细点击数
    $topLinks = $pdo->query(
        "SELECT l.id, l.title, l.url, l.hits, l.hidden,
                (SELECT COUNT(*) FROM clicks c WHERE c.link_id = l.id AND c.at >= $from) recent
           FROM links l ORDER BY recent DESC, l.hits DESC LIMIT 15"
    )->fetchAll();

    $totalClicks = (int)$pdo->query('SELECT COUNT(*) FROM clicks WHERE at >= ' . $from)->fetchColumn();

    $refKinds = $pdo->query(
        "SELECT ref_kind, COUNT(*) c $W GROUP BY ref_kind ORDER BY c DESC"
    )->fetchAll();
    $refHosts = $pdo->query(
        "SELECT ref_host, COUNT(*) c $W AND ref_host <> '' GROUP BY ref_host ORDER BY c DESC LIMIT 12"
    )->fetchAll();

    $countries = $pdo->query(
        "SELECT country, COUNT(*) c $W GROUP BY country ORDER BY c DESC LIMIT 20"
    )->fetchAll();
    // 卡片上要的是「访客来自多少个国家」，不是「地理库覆盖多少国家」——
    // 后者是库的属性，与这站的流量无关，放卡片上属于答非所问。
    $visitorCountries = (int)$pdo->query(
        "SELECT COUNT(DISTINCT country) $W AND country <> ''"
    )->fetchColumn();

    $browsers = $pdo->query(
        "SELECT browser, COUNT(*) c $W GROUP BY browser ORDER BY c DESC LIMIT 10"
    )->fetchAll();
    $oses = $pdo->query(
        "SELECT os, COUNT(*) c $W GROUP BY os ORDER BY c DESC LIMIT 10"
    )->fetchAll();
    $devices = $pdo->query(
        "SELECT device, COUNT(*) c $W GROUP BY device ORDER BY c DESC LIMIT 6"
    )->fetchAll();

    // 明细：分页。50 条一页，倒序。
    $page   = max(1, (int)($_GET['p'] ?? 1));
    $per    = 50;
    $offset = ($page - 1) * $per;
    $rows = $pdo->query(
        'SELECT at, ip, country, region, city, isp, browser, os, device, ref_host, ref_kind, ua
           FROM visits WHERE is_admin = 0 ORDER BY at DESC LIMIT ' . $per . ' OFFSET ' . $offset
    )->fetchAll();
    $allVisits = (int)$pdo->query('SELECT COUNT(*) FROM visits WHERE is_admin = 0')->fetchColumn();
    $pages = max(1, (int)ceil($allVisits / $per));

    $stats = compact(
        'days', 'pv', 'uv', 'pvToday', 'uvToday', 'daily', 'topLinks',
        'totalClicks', 'refKinds', 'refHosts', 'countries', 'visitorCountries',
        'browsers', 'oses', 'devices', 'rows', 'page', 'pages', 'allVisits'
    );
    $stats['geo'] = hp_geo_status();
}

// 访问控制页需要的规则表
$aclRules  = [];
$geoStatus = null;
if ($tab === 'security') {
    foreach (db()->query('SELECT cc, mode FROM acl_countries ORDER BY cc') as $r) {
        $aclRules[(string)$r['cc']] = (string)$r['mode'];
    }
    $geoStatus = hp_geo_status();
}

// 图标缓存页（安全页里的一块）与升级页要用的数据
require_once __DIR__ . '/inc/icons.php';
$iconStats = hp_icon_stats();

// 链接页顶部「一键刷新」要显示的缺图/缺描述条数
require_once __DIR__ . '/inc/refresh.php';
$metaStats = $tab === 'links' ? hp_meta_refresh_stats() : ['total' => 0, 'no_icon' => 0, 'no_desc' => 0];

$sysInfo = null;
if ($tab === 'upgrade' || $tab === 'security') {
    $sysInfo = [
        'version'   => hp_version(),
        'php'       => PHP_VERSION,
        'schema'    => (int)setting('schema_version', '0'),
        'schema_ok' => (int)setting('schema_version', '0') >= 4,
        'zip'       => class_exists('ZipArchive'),
        'gd'        => function_exists('imagecreatetruecolor'),
        'curl'      => function_exists('curl_init'),
        'writable'  => [
            'root'  => is_writable(HP_ROOT),
            'inc'   => is_writable(HP_ROOT . '/inc'),
            'data'  => is_writable(HP_ROOT . '/data'),
            'icons' => is_dir(HP_ROOT . '/data/icons') ? is_writable(HP_ROOT . '/data/icons') : is_writable(HP_ROOT . '/data'),
        ],
        'snapshots' => hp_upgrade_snapshots(),
        'db_size'   => is_file(HP_ROOT . '/data/home.db') ? (int)filesize(HP_ROOT . '/data/home.db') : 0,
        'root_path' => HP_ROOT,
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= h(hp_skin_safe(setting('theme', 'atlas'))) ?>" data-accent="<?= h(setting('accent', 'amber')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<?php /* 当前在编辑哪一份库。admin.js 的 api() 每次请求都读它并带上 lib，
         服务端据此决定 $U —— 前端不必在每个调用点手写。 */ ?>
<meta name="adm-lib" content="<?= h($seqLib) ?>">
<title>管理 · <?= h(setting('site_title', 'Vnav')) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23F0B429'/><path d='M9 16.5l4.5 4.5L23 11' stroke='%23241500' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="preload" href="assets/fonts/bricolage.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/instrument.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=43">
<link rel="stylesheet" href="assets/skin-atlas.css?v=51">
<link rel="stylesheet" href="assets/skin-frame.css?v=51">
<link rel="stylesheet" href="assets/admin.css?v=45">
</head>
<body class="adm-body">

<div class="aurora" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>

<div class="adm-shell">

  <aside class="adm-side">
    <a class="adm-brand" href="./">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4 4L19 7"/></svg>
      </span>
      <span>Vnav</span>
      <span class="adm-ver"><?= h(hp_version()) ?></span>
    </a>

    <nav class="adm-nav" aria-label="后台导航">
      <?php
      $tabs = [
          'links'     => ['链接管理', '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5"/>'],
          'groups'    => ['分组管理', '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
          'look'      => ['外观偏好', '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r="1.2" fill="currentColor" stroke="none"/><circle cx="9.5" cy="15" r="1.2" fill="currentColor" stroke="none"/>'],
          'stats'     => ['统计分析', '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
          'security'  => ['安全控制', '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'],
          'upgrade'   => ['系统升级', '<path d="M12 20V8"/><path d="M7 13l5-5 5 5"/><path d="M4 20h16"/>'],
          'changelog' => ['更新日志', '<path d="M6 3h9l4 4v14H6z"/><path d="M9 9h6M9 13h6M9 17h4"/>'],
          'help'      => ['使用说明', '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.2a2.6 2.6 0 1 1 3.6 2.4c-.7.35-1.1.9-1.1 1.8"/><circle cx="12" cy="17" r=".9" fill="currentColor" stroke="none"/>'],
      ];
      foreach ($tabs as $k => [$label, $svg]): ?>
        <a class="adm-tab<?= $tab === $k ? ' is-on' : '' ?>" href="?tab=<?= $k ?><?= $libQS ?>">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $svg ?></svg>
          <span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php /* 数据源切换。
             两套库存在同一个表里，靠 user_id 区分：0 = 网站默认（访客看得到），
             admin 自己的 id = 私人库（访客看不到）。

             做成一条紧凑的两段切换器，**不是**带标题和说明的卡片：
             侧栏高度是 100vh，里面已经装了品牌 + 8 个导航项 + 底部统计，
             再加一块上百像素的说明文字，在 768 高的笔记本屏上会把
             下面几个导航项直接顶出可视区。现在整条只有 34px。 */ ?>
    <div class="adm-lib" role="group" aria-label="当前编辑的数据源">
      <a class="adm-lib-opt<?= $seqLib === 'site' ? ' is-on' : '' ?>"
         href="?tab=<?= h($tab) ?>&lib=site"
         title="访客看到的就是这一份"
         <?= $seqLib === 'site' ? 'aria-current="true"' : '' ?>>网站默认</a>
      <a class="adm-lib-opt<?= $seqLib === 'mine' ? ' is-on' : '' ?>"
         href="?tab=<?= h($tab) ?>&lib=mine"
         title="只有你自己看得到，访客用的是「网站默认」"
         <?= $seqLib === 'mine' ? 'aria-current="true"' : '' ?>>我的库</a>
    </div>
    <?php if ($seqLib === 'mine'): ?>
      <?php /* 私人库在前台默认不显示，给一个直达入口，否则改完没处验证。
               只在 mine 下出现，平时不占高度。 */ ?>
      <a class="adm-lib-peek" href="./?lib=mine" target="_blank" rel="noopener noreferrer">在前台预览我的库 ↗</a>
    <?php endif; ?>

    <div class="adm-side-foot">
      <div class="adm-stat">
        <span class="adm-stat-n"><?= $totalLinks ?></span>
        <span class="adm-stat-l">个链接</span>
      </div>
      <div class="adm-stat">
        <span class="adm-stat-n"><?= (int)$totalHits ?></span>
        <span class="adm-stat-l">次点击</span>
      </div>
      <?php if ($totalHidden > 0): ?>
        <div class="adm-stat">
          <span class="adm-stat-n"><?= $totalHidden ?></span>
          <span class="adm-stat-l">个隐藏</span>
        </div>
      <?php endif; ?>
      <a class="adm-exit" href="./">← 回主页</a>
      <a class="adm-exit" href="?logout=1">退出登录</a>
    </div>
  </aside>

  <main class="adm-main">

    <?php if ($tab === 'links'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">链接管理
            <span class="adm-lib-badge<?= $seqLib === 'mine' ? ' is-mine' : '' ?>"><?= h($libLabel) ?></span>
          </h1>
          <p class="adm-sub">在主页直接按 <kbd>Ctrl</kbd>+<kbd>V</kbd> 粘贴网址是最快的方式，这里是精修的地方。</p>
        </div>
        <button class="btn-primary" id="newLinkBtn" type="button">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
          新增
        </button>
      </header>

      <?php /* 一键刷新元信息。
               放在链接页最上方而不是「安全」页里 —— 它改的是链接数据本身
               （标题/描述/图标），和「链接管理」是同一件事的两个粒度。
               缺图标/缺描述的条数直接显示在按钮上，用户不用猜要不要刷。 */ ?>
      <section class="adm-panel meta-refresh" id="metaRefreshPanel">
        <div class="mr-head">
          <div class="mr-copy">
            <h2 class="panel-title">一键刷新名称、描述与图标</h2>
            <p class="panel-hint">
              逐条回到原站点重读页面信息，把标题、描述、logo 更新成最新。
              站点改版、换了 logo、当初抓得不好看的，刷一遍就好。
            </p>
          </div>
          <div class="mr-stats">
            <span class="mr-stat">
              <b><?= (int)$metaStats['no_icon'] ?></b> 条缺图标
            </span>
            <span class="mr-stat">
              <b><?= (int)$metaStats['no_desc'] ?></b> 条缺描述
            </span>
          </div>
        </div>

        <div class="mr-progress" id="mrProgress" hidden>
          <div class="mr-bar"><span class="mr-bar-fill" id="mrBarFill"></span></div>
          <p class="mr-status" id="mrStatus" role="status" aria-live="polite"></p>
        </div>

        <div class="panel-actions" style="justify-content:flex-start;flex-wrap:wrap">
          <button class="btn-primary" type="button" id="metaRefreshAll">
            <span class="btn-text">全部刷新（<?= (int)$metaStats['total'] ?> 条）</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <button class="btn-ghost" type="button" id="metaRefreshMissing">
            <span class="btn-text">只补缺失的</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
        </div>

        <ul class="adm-tips">
          <li>「全部刷新」会<strong>覆盖</strong>你手工改过的名称和描述；「只补缺失的」只填空着的那几项，已写的不动。</li>
          <li>要逐条访问对方站点，所以是分批跑的，进度条走完才算结束。中途可以去别的页，回来再点一次会接着刷。</li>
          <li>抓不到的（对方站点没有、被墙、或做了防盗链）会跳过并计数，不会把原值清空。</li>
        </ul>
      </section>

      <!-- 新增/编辑表单 -->
      <section class="adm-panel" id="linkEditor" <?= $focus ? '' : 'hidden' ?>>
        <h2 class="panel-title" id="editorTitle">新增链接</h2>
        <form id="linkForm" class="adm-form" novalidate>
          <input type="hidden" name="id" id="fId" value="">
          <div class="f-row">
            <div class="f-col f-col-wide">
              <label class="f-label" for="fUrl">网址</label>
              <input class="f-input" id="fUrl" name="url" type="url" inputmode="url" required placeholder="https://example.com" autocomplete="off" spellcheck="false">
            </div>
            <div class="f-col">
              <label class="f-label" for="fTitle">名称</label>
              <input class="f-input" id="fTitle" name="title" type="text" required maxlength="60" autocomplete="off">
            </div>
          </div>
          <div class="f-row">
            <div class="f-col">
              <label class="f-label" for="fGroup">分组</label>
              <select class="f-input" id="fGroup" name="group_id">
                <option value="0">未分组</option>
                <?php foreach ($groups as $g): ?>
                  <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="f-col f-col-wide">
              <label class="f-label" for="fDescr">描述（可选）</label>
              <input class="f-input" id="fDescr" name="descr" type="text" maxlength="120" autocomplete="off">
            </div>
          </div>
          <div class="switch-row switch-row--tight">
            <div>
              <p class="sw-label">隐藏链接</p>
              <p class="sw-desc" id="fHideDesc">关闭时所有访客可见</p>
            </div>
            <label class="switch">
              <input type="checkbox" id="fHidden" name="hidden">
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">隐藏链接（仅登录后可见）</span>
            </label>
          </div>
          <p class="f-error" id="linkErr" role="alert" hidden></p>
          <div class="panel-actions">
            <button type="button" class="btn-ghost" id="cancelEdit">取消</button>
            <button type="button" class="btn-ghost" id="identifyBtn">抓取名称与图标</button>
            <button type="submit" class="btn-primary" id="saveLink">
              <span class="btn-text">保存</span>
              <span class="spinner" hidden aria-hidden="true"></span>
            </button>
          </div>
        </form>
      </section>

      <!-- 列表 -->
      <?php if (!$links): ?>
        <?php if ($seqLib === 'mine'): ?>
          <?php /* 「我的链接库」是空的 —— 这是新拆出来的私人库，
                   默认库那几百条不会自动过来，所以给一个一键复制。
                   只在空库时出现，有内容就不再显示（后端也会再拦一次）。 */ ?>
          <div class="adm-empty">
            <p class="adm-empty-title">「我的链接库」还是空的</p>
            <p class="adm-empty-sub">这一份只有你自己看得到，访客用的是「网站默认」那一份。<br>
              可以从默认库复制一份过来打底，也可以直接新增。</p>
            <div class="adm-empty-act">
              <button class="btn-primary" id="cloneLibBtn" type="button">从「网站默认」复制一份</button>
              <button class="btn-ghost" id="newLinkBtn2" type="button">直接新增</button>
            </div>
          </div>
        <?php else: ?>
          <div class="adm-empty">
            <p class="adm-empty-title">「网站默认」里还没有链接</p>
            <p class="adm-empty-sub">访客看到的就是这一份。点「新增」，或者回主页按 Ctrl+V 粘贴一个网址。</p>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <?php
        // 按分组呈现，便于用户理解结构
        $gname = [];
        foreach ($groups as $g) { $gname[(int)$g['id']] = $g['name']; }
        $bucket = [];
        foreach ($links as $l) {
            $k = (int)($l['group_id'] ?? 0);
            $bucket[$k][] = $l;
        }
        ?>
        <?php /* 筛选栏。
                 原来 300 多条平铺在一个页面上，想处理几条冷门链接得一路
                 往下滚着找，滚到底已经不知道自己在选什么了。先按名称/网址
                 搜、或按分组收窄，剩下的才是要操作的对象。
                 筛选是纯前端隐藏，不打接口 —— 数据本来就已经在页面上了。 */ ?>
        <div class="adm-filter">
          <label class="adm-filter-q">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/></svg>
            <input type="search" id="linkFilter" placeholder="搜名称或网址" autocomplete="off" spellcheck="false">
          </label>
          <select id="filterGroup" class="adm-bulk-select" aria-label="按分组筛选">
            <option value="">全部分组</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?> · <?= (int)($g['link_count'] ?? 0) ?></option>
            <?php endforeach; ?>
            <option value="0">未分组</option>
          </select>
          <button type="button" class="adm-mini" id="filterReset">重置</button>
          <span class="adm-filter-n" id="filterCount" aria-live="polite"></span>
        </div>

        <?php /* 批量操作条：默认隐藏，勾了任意一行才出现。
                 放在列表顶部并 sticky，滚到下面勾选时也还看得见。 */ ?>
        <div class="adm-bulkbar" id="bulkBar" hidden>
          <label class="adm-bulk-check">
            <input type="checkbox" id="bulkAll">
            <span>全选</span>
          </label>
          <span class="adm-bulk-n" id="bulkCount">已选 0 条</span>
          <span class="adm-bulk-sp"></span>
          <select id="bulkGroup" class="adm-bulk-select" aria-label="目标分组">
            <option value="">移到分组…</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="adm-mini" data-bulk="move">移动</button>
          <button type="button" class="adm-mini" data-bulk="hide">隐藏</button>
          <button type="button" class="adm-mini" data-bulk="show">取消隐藏</button>
          <button type="button" class="adm-mini is-danger" data-bulk="delete">删除</button>
        </div>
        <div class="adm-list" id="admList">
          <?php foreach ($bucket as $gid => $items): ?>
            <div class="adm-list-group">
              <?php /* 每个分组头上挂一个「选中本组」：
                       要清理某个分组时，不必再一条条点 —— 这是最常用的一种批量。 */ ?>
              <p class="adm-list-label" data-group="<?= (int)$gid ?>">
                <span class="adm-list-name"><?= $gid ? h($gname[$gid] ?? '分组') : '未分组' ?> · <?= count($items) ?></span>
                <button type="button" class="adm-pick-group" data-pick-group="<?= (int)$gid ?>">选中本组</button>
              </p>
              <?php foreach ($items as $l):
                $hasIcon = $l['icon'] !== ''
                    && $l['icon_kind'] === 'file'
                    && is_file(HP_ROOT . '/' . $l['icon']);
                $hue = hue_of(host_of($l['url']));
                $isHidden = (int)($l['hidden'] ?? 0) === 1;
              ?>
                <article class="adm-row<?= $focus === (int)$l['id'] ? ' is-focus' : '' ?><?= $isHidden ? ' is-priv' : '' ?>"
                         data-id="<?= (int)$l['id'] ?>"
                         data-title="<?= h($l['title']) ?>"
                         data-url="<?= h($l['url']) ?>"
                         data-descr="<?= h($l['descr']) ?>"
                         data-group="<?= (int)($l['group_id'] ?? 0) ?>"
                         data-hidden="<?= $isHidden ? '1' : '0' ?>"
                         data-icon="<?= h($l['icon']) ?>"
                         style="--hue:<?= $hue ?>">
                  <label class="adm-cb" title="选中这一行">
                    <input type="checkbox" data-bulk-id="<?= (int)$l['id'] ?>">
                    <span class="sr-only">选中「<?= h($l['title']) ?>」</span>
                  </label>
                  <span class="adm-row-icon<?= $hasIcon ? ' has-img' : '' ?>" aria-hidden="true">
                    <?php if ($hasIcon): ?>
                      <img src="<?= h($l['icon']) ?>" alt="" width="22" height="22" loading="lazy">
                    <?php else: ?>
                      <span class="initials"><?= h(initials_of($l['title'])) ?></span>
                    <?php endif; ?>
                  </span>
                  <div class="adm-row-body">
                    <p class="adm-row-title"><?= h($l['title']) ?></p>
                    <p class="adm-row-url"><?= h($l['url']) ?></p>
                  </div>
                  <?php /* 隐藏开关直接放在行上：这些链接对外不可见，
                           最容易忘记的就是「哪几条藏了」，所以状态必须常驻可见。 */ ?>
                  <label class="adm-priv" title="隐藏链接（仅登录后可见）">
                    <input type="checkbox" data-act="hide" <?= $isHidden ? 'checked' : '' ?>>
                    <span class="adm-priv-track" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4 4L19 7"/></svg>
                    </span>
                    <span class="sr-only">隐藏「<?= h($l['title']) ?>」</span>
                  </label>
                  <span class="adm-row-hits" title="点击次数"><?= (int)$l['hits'] ?></span>
                  <div class="adm-row-act">
                    <button type="button" class="icon-btn" data-act="up" aria-label="上移">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
                    </button>
                    <button type="button" class="icon-btn" data-act="down" aria-label="下移">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                    </button>
                    <button type="button" class="icon-btn" data-act="edit" aria-label="编辑">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    </button>
                    <button type="button" class="icon-btn is-danger" data-act="del" aria-label="删除">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
                    </button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($tab === 'groups'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">分组管理
            <span class="adm-lib-badge<?= $seqLib === 'mine' ? ' is-mine' : '' ?>"><?= h($libLabel) ?></span>
          </h1>
          <p class="adm-sub">分组会变成主页上的分区标题。删掉分组不会删链接，它们会回到「未分组」。</p>
        </div>
      </header>

      <section class="adm-panel">
        <form id="groupForm" class="adm-inline-form" novalidate>
          <label class="f-label sr-only" for="gName">新分组名</label>
          <input class="f-input" id="gName" name="name" type="text" maxlength="24" placeholder="新分组名称" required>
          <button class="btn-primary" type="submit">添加分组</button>
        </form>
        <p class="f-error" id="groupErr" role="alert" hidden></p>

        <?php /* 分组批量条。
               分组一多，逐个点删除很费劲；而比删除更常用的是「这俩其实
               是一回事，合并掉」——所以合并放前面，删除留一个 danger。
               合并会把选中组的全部链接划到目标组，然后删掉空壳。 */ ?>
        <div class="adm-bulkbar" id="gBulkBar" hidden>
          <label class="adm-bulk-check">
            <input type="checkbox" id="gBulkAll">
            <span>全选</span>
          </label>
          <span class="adm-bulk-n" id="gBulkCount">已选 0 个</span>
          <span class="adm-bulk-sp"></span>
          <select id="gMergeTarget" class="adm-bulk-select" aria-label="合并到哪个分组">
            <option value="">合并到…</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="adm-mini" data-gbulk="merge">合并</button>
          <button type="button" class="adm-mini is-danger" data-gbulk="delete">删除</button>
        </div>

        <div class="adm-groups" id="groupList">
          <?php foreach ($groups as $g): ?>
            <?php
            $gIcon = (string)($g['icon'] ?? '');
            // 没手动选过时，按钮显示「按名称自动匹配」的结果，并用虚线框表示"自动"
            $gSvg  = hp_group_icon($g['name'], $gIcon);
            $gN    = (int)($g['link_count'] ?? 0);
            ?>
            <div class="adm-group-row" data-id="<?= (int)$g['id'] ?>" data-name="<?= h($g['name']) ?>" data-count="<?= $gN ?>">
              <label class="adm-cb" title="选中「<?= h($g['name']) ?>」">
                <input type="checkbox" data-gbulk-id="<?= (int)$g['id'] ?>">
                <span class="sr-only">选中分组「<?= h($g['name']) ?>」</span>
              </label>
              <button type="button" class="gicon-btn<?= $gIcon === '' ? ' is-auto' : '' ?>"
                      data-gact="icon" data-icon="<?= h($gIcon) ?>"
                      title="选择图标" aria-label="选择「<?= h($g['name']) ?>」的图标">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $gSvg ?></svg>
              </button>
              <input class="f-input f-input-tight" value="<?= h($g['name']) ?>" maxlength="24" aria-label="分组名">
              <?php /* 链接数常驻可见：删除/合并前得知道这一组里到底有多少东西，
                       否则「删掉」这个动作完全没有分量感。 */ ?>
              <span class="adm-group-n" title="<?= $gN ?> 条链接"><?= $gN ?></span>
              <?php /* 排序：上下两个小箭头。没有做拖拽 —— 这一行里有个文本输入框，
                       拖拽的按下动作会和「选词 / 放光标」抢指针，手感很糟；
                       箭头则是点一下动一格，十来个分组也够用。 */ ?>
              <span class="adm-sort">
                <button type="button" class="g-sort" data-gact="up"
                        aria-label="上移「<?= h($g['name']) ?>」" title="上移">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>
                </button>
                <button type="button" class="g-sort" data-gact="down"
                        aria-label="下移「<?= h($g['name']) ?>」" title="下移">
                  <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </button>
              </span>
              <button type="button" class="icon-btn is-ok" data-gact="save" aria-label="保存名称">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4 4L19 7"/></svg>
              </button>
              <button type="button" class="icon-btn is-danger" data-gact="del" aria-label="删除分组">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
              </button>
            </div>
          <?php endforeach; ?>
          <?php if (!$groups): ?>
            <p class="adm-hint">还没有分组。上面输入一个名字就能建。</p>
          <?php endif; ?>
        </div>
      </section>

      <?php /* 图标选择浮层。给分类配图标是「可辨识」的一步 ——
               左侧栏一列纯文字扫起来很累，这也是中文导航站的通行做法。
               图标库与前台共用 inc/groupnav.php 的 hp_group_icon_set()，
               新增图标只需改那一处。 */ ?>
      <div class="gicon-modal" id="giconModal" hidden>
        <div class="gicon-scrim" data-gicon-close></div>
        <div class="gicon-panel" role="dialog" aria-modal="true" aria-labelledby="giconTitle">
          <div class="gicon-head">
            <h2 id="giconTitle">选择图标</h2>
            <button type="button" class="icon-btn" data-gicon-close aria-label="关闭">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
          </div>
          <div class="gicon-grid">
            <?php $gLabels = hp_group_icon_labels(); ?>
            <button type="button" class="gicon-opt" data-pick="" title="自动匹配（按分组名）">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.6 4.6 1.9-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/><path d="M18 15l.9 2.1 2.1.9-2.1.9L18 21l-.9-2.1-2.1-.9 2.1-.9z"/></svg>
              <span class="gicon-name">自动</span>
            </button>
            <?php foreach (hp_group_icon_set() as $ik => $isvg): ?>
              <button type="button" class="gicon-opt" data-pick="<?= h($ik) ?>" title="<?= h($gLabels[$ik] ?? $ik) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $isvg ?></svg>
                <span class="gicon-name"><?= h($gLabels[$ik] ?? $ik) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    <?php elseif ($tab === 'look'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">外观偏好</h1>
          <p class="adm-sub">主页的标题、形态、配色与搜索框，都在这里定。</p>
        </div>
      </header>

      <form id="lookForm" class="adm-form" novalidate>
        <section class="adm-panel">
          <h2 class="panel-title">文字</h2>
          <p class="panel-hint">主页已经不放标题大字了，这里只保留浏览器标签页与书签用的名字。</p>
          <div class="f-row">
            <div class="f-col">
              <label class="f-label" for="sTitle">站点名称</label>
              <input class="f-input" id="sTitle" name="site_title" type="text" maxlength="40" value="<?= h(setting('site_title')) ?>">
            </div>
            <div class="f-col">
              <label class="f-label" for="sFoot">页脚寄语（可留空）</label>
              <input class="f-input" id="sFoot" name="footer_note" type="text" maxlength="120" value="<?= h(setting('footer_note')) ?>">
            </div>
          </div>
          <?php /* 版权与备案：页脚那行「© 年份 谁 · 保留所有权利」的署名来源。
                   留空时依次退回作者名、站点名，不会出现空署名。 */ ?>
          <div class="f-row">
            <div class="f-col">
              <label class="f-label" for="sCopy">版权署名</label>
              <input class="f-input" id="sCopy" name="copyright_name" type="text" maxlength="60"
                     placeholder="<?= h(setting('author_name') !== '' ? setting('author_name') : setting('site_title', 'Vnav')) ?>"
                     value="<?= h(setting('copyright_name')) ?>">
              <p class="f-hint">留空则用作者名，再留空用站点名称。</p>
            </div>
            <div class="f-col">
              <label class="f-label" for="sIcp">ICP 备案号</label>
              <input class="f-input" id="sIcp" name="icp_beian" type="text" maxlength="40"
                     placeholder="如 京ICP备12345678号-1" value="<?= h(setting('icp_beian')) ?>">
              <p class="f-hint">国内主机需要在首页底部展示。留空则不显示。</p>
            </div>
          </div>
        </section>

        <section class="adm-panel">
          <h2 class="panel-title">展示形态</h2>
          <p class="panel-hint">主页右上角也能随时切换，这里设的是默认值。</p>
          <div class="layout-radios" role="radiogroup" aria-label="展示形态">
            <?php
            $curLayout = hp_layout_safe(setting('layout', 'card'));
            foreach (hp_layouts() as $k => $lo): ?>
              <label class="layout-radio<?= $curLayout === $k ? ' is-on' : '' ?>">
                <input type="radio" name="layout" value="<?= h($k) ?>" <?= $curLayout === $k ? 'checked' : '' ?>>
                <span class="lr-icon">
                  <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $lo['svg'] ?></svg>
                </span>
                <span class="lr-text">
                  <span class="lr-name"><?= h($lo['name']) ?></span>
                  <span class="lr-desc"><?= h($lo['hint']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="adm-panel">
          <h2 class="panel-title">皮肤</h2>
          <p class="panel-hint">主页右上角也能随时切换，这里设的是默认皮肤。每套都做过对比度校验。</p>
          <div class="skin-radios" role="radiogroup" aria-label="界面皮肤">
            <?php
            $curSkin = hp_skin_safe(setting('theme', 'atlas'));
            foreach (hp_skins() as $k => $sk): ?>
              <label class="skin-radio<?= $curSkin === $k ? ' is-on' : '' ?>" data-skin="<?= h($k) ?>">
                <input type="radio" name="theme" value="<?= h($k) ?>" <?= $curSkin === $k ? 'checked' : '' ?>>
                <span class="sr-swatch sr-swatch--<?= h($k) ?>" aria-hidden="true"></span>
                <span class="sr-text">
                  <span class="sr-name"><?= h($sk['label']) ?></span>
                  <span class="sr-hint"><?= h($sk['hint']) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </section>

        <?php /* 强调色面板已在 V1.3.2 撤掉。
                原因：Atlas 是全页单一彩色的设计，靛蓝本来就是它的身份，
                不是可替换的装饰。留着五个色选项而前台不响应，等于摆一个
                点了没反应的控件 —— 比没有更让人困惑。
                设置项与 api 白名单仍然保留（accent 字段不动），
                这样旧备份导入不会报错，将来若再加多套皮肤也不必改协议。 */ ?>

        <section class="adm-panel">
          <h2 class="panel-title">行为</h2>
          <div class="switch-row">
            <div>
              <p class="sw-label">显示搜索框</p>
              <p class="sw-desc">在顶栏放一个搜索入口，按 / 可聚焦。</p>
            </div>
            <label class="switch">
              <input type="checkbox" name="show_search" <?= setting('show_search', '1') === '1' ? 'checked' : '' ?>>
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">显示搜索框</span>
            </label>
          </div>
          <div class="switch-row">
            <div>
              <p class="sw-label">显示描述</p>
              <p class="sw-desc">卡片里第二行显示描述或域名。</p>
            </div>
            <label class="switch">
              <input type="checkbox" name="show_desc" <?= setting('show_desc', '1') === '1' ? 'checked' : '' ?>>
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">显示描述</span>
            </label>
          </div>
          <div class="switch-row">
            <div>
              <p class="sw-label">新标签页打开</p>
              <p class="sw-desc">关掉则当前页跳转。作为主页时建议开启。</p>
            </div>
            <label class="switch">
              <input type="checkbox" name="open_new_tab" <?= setting('open_new_tab', '1') === '1' ? 'checked' : '' ?>>
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">新标签页打开</span>
            </label>
          </div>
          <div class="switch-row">
            <div>
              <p class="sw-label">记录访问统计</p>
              <p class="sw-desc">记 PV/UV、点击、来源、IP 与归属地。关掉后前台不再写库。</p>
            </div>
            <label class="switch">
              <input type="checkbox" name="stats_on" <?= setting('stats_on', '1') === '1' ? 'checked' : '' ?>>
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">记录访问统计</span>
            </label>
          </div>
          <div class="f-row" style="margin-top:22px">
            <div class="f-col">
              <label class="f-label" for="sEngine">默认搜索</label>
              <select class="f-input" id="sEngine" name="search_engine">
                <?php foreach (hp_engines() as $k => $e): ?>
                  <option value="<?= h($k) ?>" <?= setting('search_engine', 'bing') === $k ? 'selected' : '' ?>><?= h($e['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="f-col">
              <label class="f-label" for="sKeep">明细保留天数</label>
              <input class="f-input" id="sKeep" name="stats_keep" type="number" min="1" max="3650"
                     value="<?= (int)setting('stats_keep', '90') ?>">
            </div>
          </div>
          <p class="panel-hint" style="margin-top:8px">
            超过保留天数的明细会在每次有人打开统计页时顺手清掉，你不用自己记着点。
            聚合数字（链接的累计点击）不受影响。
          </p>
        </section>

        <div class="adm-savebar">
          <button type="submit" class="btn-primary" id="saveLook">
            <span class="btn-text">保存外观</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <a class="btn-ghost" href="./" target="_blank" rel="noopener">预览主页</a>
        </div>
        <p class="f-error" id="lookErr" role="alert" hidden></p>
      </form>

    <?php elseif ($tab === 'stats'): ?>
      <?php
      // 视图需要的小工具
      $kindLabel = [
          'direct' => '直接访问', 'search' => '搜索引擎', 'social' => '社交平台',
          'referral' => '外部链接', 'internal' => '站内跳转',
      ];
      $maxDaily = max(1, max($stats['daily'] ?: [1]));
      $pct = static fn(int $n, int $total): int => $total > 0 ? (int)round($n / $total * 100) : 0;
      ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">统计分析</h1>
          <p class="adm-sub">
            近 <?= (int)$stats['days'] ?> 天的访问与点击。后台自身的访问不计入 ——
            否则你每刷新一次这里，数字就自己涨一格。
          </p>
        </div>
        <div class="adm-range" role="group" aria-label="统计时间范围">
          <?php foreach ([1 => '今天', 7 => '7 天', 30 => '30 天', 90 => '90 天', 365 => '一年'] as $d => $lb): ?>
            <a class="adm-range-btn<?= (int)$stats['days'] === $d ? ' is-on' : '' ?>"
               href="?tab=stats&amp;days=<?= $d ?>"><?= $lb ?></a>
          <?php endforeach; ?>
        </div>
      </header>

      <?php if (setting('stats_on', '1') !== '1'): ?>
        <div class="adm-alert">
          统计已关闭。到「外观 → 行为」里打开「记录访问统计」后，新的访问才会被记录。
        </div>
      <?php endif; ?>

      <!-- 四个大数字 -->
      <section class="stat-cards">
        <div class="stat-card">
          <p class="stat-num"><?= number_format($stats['pv']) ?></p>
          <p class="stat-lab">浏览量（PV）</p>
          <p class="stat-sub">今日 <?= number_format($stats['pvToday']) ?></p>
        </div>
        <div class="stat-card">
          <p class="stat-num"><?= number_format($stats['uv']) ?></p>
          <p class="stat-lab">访客数（UV）</p>
          <p class="stat-sub">今日 <?= number_format($stats['uvToday']) ?></p>
        </div>
        <div class="stat-card">
          <p class="stat-num"><?= number_format($stats['totalClicks']) ?></p>
          <p class="stat-lab">链接点击</p>
          <p class="stat-sub">近 <?= (int)$stats['days'] ?> 天</p>
        </div>
        <div class="stat-card">
          <p class="stat-num"><?= number_format($stats['visitorCountries']) ?></p>
          <p class="stat-lab">访客国家 / 地区</p>
          <p class="stat-sub"><?= $stats['geo']['city'] > 0
            ? '本地库含城市级数据'
            : ($stats['geo']['country'] > 0 ? '本地库仅国家级数据' : '地理库未导入') ?></p>
        </div>
      </section>

      <!-- 逐日趋势：纯 CSS 柱状，不引图表库（一个库 100KB 起，不值） -->
      <section class="adm-panel">
        <h2 class="panel-title">每日访问</h2>
        <p class="panel-hint">柱子是当天的浏览量。鼠标悬停可看具体日期与数字。</p>
        <div class="spark" role="img" aria-label="近 <?= (int)$stats['days'] ?> 天每日访问量柱状图">
          <?php foreach ($stats['daily'] as $d => $c):
            $hh = max(2, (int)round($c / $maxDaily * 100));
            $isToday = $d === date('Y-m-d'); ?>
            <span class="spark-bar<?= $isToday ? ' is-today' : '' ?>" style="--h:<?= $hh ?>%"
                  title="<?= h($d) ?>：<?= $c ?> 次">
              <span class="spark-tip"><?= h(substr($d, 5)) ?> · <?= $c ?></span>
            </span>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="adm-cols">
        <!-- 点击榜 -->
        <section class="adm-panel">
          <h2 class="panel-title">点击榜</h2>
          <p class="panel-hint">
            左边深色数字是近 <?= (int)$stats['days'] ?> 天的点击数，竖线右侧灰色是历史累计。
          </p>
          <?php if (!$stats['topLinks']): ?>
            <p class="adm-hint">还没有点击记录。</p>
          <?php else: ?>
            <ol class="rank">
              <?php foreach ($stats['topLinks'] as $i => $t):
                $maxC = max(1, (int)$stats['topLinks'][0]['recent']); ?>
                <li class="rank-row">
                  <span class="rank-no"><?= $i + 1 ?></span>
                  <span class="rank-body">
                    <span class="rank-name">
                      <?= h($t['title']) ?>
                      <?php if ((int)$t['hidden'] === 1): ?><span class="rank-tag">隐藏</span><?php endif; ?>
                    </span>
                    <span class="rank-bar" aria-hidden="true"><i style="--w:<?= (int)round((int)$t['recent'] / $maxC * 100) ?>%"></i></span>
                  </span>
                  <span class="rank-n"><?= (int)$t['recent'] ?></span>
                  <span class="rank-total"><?= (int)$t['hits'] ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </section>

        <!-- 来源 -->
        <section class="adm-panel">
          <h2 class="panel-title">访问来源</h2>
          <p class="panel-hint">只记来源域名，不存完整 URL —— 搜索关键词这类信息统计用不上。</p>
          <?php if (!$stats['refKinds']): ?>
            <p class="adm-hint">还没有数据。</p>
          <?php else: ?>
            <ul class="bars">
              <?php foreach ($stats['refKinds'] as $r):
                $p = $pct((int)$r['c'], $stats['pv']); ?>
                <li class="bar-row">
                  <span class="bar-lab"><?= h($kindLabel[$r['ref_kind']] ?? $r['ref_kind']) ?></span>
                  <span class="bar-track" aria-hidden="true"><i style="--w:<?= $p ?>%"></i></span>
                  <span class="bar-n"><?= $p ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($stats['refHosts']): ?>
              <p class="panel-hint" style="margin:18px 0 8px">具体来源站点</p>
              <ul class="chips">
                <?php foreach ($stats['refHosts'] as $r): ?>
                  <li class="chip"><?= h($r['ref_host']) ?> <b><?= (int)$r['c'] ?></b></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>

      <div class="adm-cols">
        <!-- 国家/地区 -->
        <section class="adm-panel">
          <h2 class="panel-title">访客地区</h2>
          <?php if ($stats['geo']['country'] === 0 && $stats['geo']['city'] === 0): ?>
            <div class="adm-alert">
              还没导入 IP 归属地库，所以地区全是「未知」。到「安全 → 访问控制」里点一次导入即可，
              之后完全本地查询，不会再有任何外部请求。
            </div>
          <?php else: ?>
            <?php
            // 只在真有「历史盲区」时才出现这个按钮 —— 平时它一点用都没有
            $blank = (int)$pdo->query(
                "SELECT COUNT(*) FROM visits WHERE is_admin = 0 AND (country = '' OR country IS NULL)"
            )->fetchColumn();
            ?>
            <?php if ($blank > 0): ?>
              <div class="adm-alert">
                有 <?= number_format($blank) ?> 条历史访问还没归属地（多半是导库前记下的）。
                <button class="link-btn" type="button" id="geoBackfill">现在就回填</button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!$stats['countries']): ?>
            <p class="adm-hint">还没有数据。</p>
          <?php else: ?>
            <ul class="bars">
              <?php foreach ($stats['countries'] as $c):
                $p = $pct((int)$c['c'], $stats['pv']);
                $nm = $c['country'] === '' ? '未知' : hp_country_name((string)$c['country']); ?>
                <li class="bar-row">
                  <span class="bar-lab"><?= h($nm) ?></span>
                  <span class="bar-track" aria-hidden="true"><i style="--w:<?= $p ?>%"></i></span>
                  <span class="bar-n"><?= (int)$c['c'] ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <!-- 环境 -->
        <section class="adm-panel">
          <h2 class="panel-title">浏览器与设备</h2>
          <?php if (!$stats['browsers']): ?>
            <p class="adm-hint">还没有数据。</p>
          <?php else: ?>
            <ul class="bars">
              <?php foreach ($stats['browsers'] as $b):
                $p = $pct((int)$b['c'], $stats['pv']); ?>
                <li class="bar-row">
                  <span class="bar-lab"><?= h($b['browser']) ?></span>
                  <span class="bar-track" aria-hidden="true"><i style="--w:<?= $p ?>%"></i></span>
                  <span class="bar-n"><?= $p ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="chips" style="margin-top:14px">
              <?php foreach ($stats['devices'] as $d): ?>
                <span class="chip"><?= h($d['device']) ?> <b><?= (int)$d['c'] ?></b></span>
              <?php endforeach; ?>
              <?php foreach ($stats['oses'] as $o): ?>
                <span class="chip"><?= h($o['os']) ?> <b><?= (int)$o['c'] ?></b></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- 明细 -->
      <section class="adm-panel">
        <h2 class="panel-title">访问明细</h2>
        <p class="panel-hint">
          共 <?= number_format($stats['allVisits']) ?> 条 · 第 <?= (int)$stats['page'] ?>/<?= (int)$stats['pages'] ?> 页
        </p>
        <?php if (!$stats['rows']): ?>
          <p class="adm-hint">还没有访问记录。用手机或换一个网络打开一次主页就能看到。</p>
        <?php else: ?>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th scope="col">时间</th>
                  <th scope="col">IP</th>
                  <th scope="col">地区</th>
                  <th scope="col">运营商</th>
                  <th scope="col">浏览器 / 系统</th>
                  <th scope="col">来源</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($stats['rows'] as $v):
                  $loc = array_filter([
                      $v['country'] !== '' ? hp_country_name((string)$v['country']) : '',
                      (string)$v['region'], (string)$v['city'],
                  ], fn($x) => $x !== ''); ?>
                  <tr>
                    <td class="tbl-time" data-label="时间"><?= h(date('m-d H:i', (int)$v['at'])) ?></td>
                    <td class="tbl-ip" data-label="IP"><?= h($v['ip']) ?></td>
                    <td data-label="地区"><?= $loc ? h(implode(' · ', $loc)) : '<span class="dim">未知</span>' ?></td>
                    <td class="tbl-isp" data-label="运营商"><?= $v['isp'] !== '' ? h($v['isp']) : '<span class="dim">—</span>' ?></td>
                    <td data-label="环境"><?= h($v['browser']) ?> <span class="dim">/ <?= h($v['os']) ?> · <?= h($v['device']) ?></span></td>
                    <td data-label="来源"><?= h($kindLabel[$v['ref_kind']] ?? $v['ref_kind']) ?><?= $v['ref_host'] !== '' ? ' · ' . h($v['ref_host']) : '' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ($stats['pages'] > 1): ?>
            <nav class="pager" aria-label="分页">
              <?php
              // 只需要翻页，所以只带 days 与 p 两个参数，别把会话里无关的东西拖进来
              $mk = static fn(int $p, string $lb, bool $on = false) =>
                  '<a class="pager-btn' . ($on ? ' is-on' : '') . '"'
                  . ' href="?tab=stats&amp;days=' . (int)$stats['days'] . '&amp;p=' . $p . '"'
                  . ($on ? ' aria-current="page"' : '') . '>' . $lb . '</a>';
              if ($stats['page'] > 1) echo $mk($stats['page'] - 1, '← 上一页');
              $lo = max(1, $stats['page'] - 2);
              $hi = min($stats['pages'], $lo + 4);
              for ($p = $lo; $p <= $hi; $p++) echo $mk($p, (string)$p, $p === $stats['page']);
              if ($stats['page'] < $stats['pages']) echo $mk($stats['page'] + 1, '下一页 →');
              ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>

        <div class="panel-actions" style="margin-top:22px">
          <button class="btn-ghost" type="button" id="statsPrune">
            清理 <?= (int)setting('stats_keep', '90') ?> 天前的明细
          </button>
          <button class="btn-danger" type="button" id="statsClear">
            清空全部统计
          </button>
        </div>
        <p class="panel-hint" style="margin-top:10px">
          清空会把链接的累计点击次数一起归零，否则这一页会出现两套互相矛盾的数字。
          明细里不用 Cookie：UV 按「IP 哈希」去重，同一出口 IP 算一个人。
        </p>
      </section>

    <?php elseif ($tab === 'security'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">安全控制</h1>
          <p class="adm-sub">改管理员密码。密码以 bcrypt 存在配置里，不会明文落库。</p>
        </div>
      </header>

      <form id="passForm" class="adm-form" novalidate>
        <section class="adm-panel" style="max-width:520px">
          <label class="f-label" for="pOld">原密码</label>
          <input class="f-input" id="pOld" name="old" type="password" autocomplete="current-password" required>
          <label class="f-label" for="pNew">新密码（至少 6 位）</label>
          <input class="f-input" id="pNew" name="new" type="password" minlength="6" autocomplete="new-password" required>
          <label class="f-label" for="pNew2">再输一次</label>
          <input class="f-input" id="pNew2" name="new2" type="password" minlength="6" autocomplete="new-password" required>
          <p class="f-error" id="passErr" role="alert" hidden></p>
          <div class="panel-actions">
            <button type="submit" class="btn-primary" id="savePass">
              <span class="btn-text">更新密码</span>
              <span class="spinner" hidden aria-hidden="true"></span>
            </button>
          </div>
        </section>
      </form>

      <section class="adm-panel">
        <h2 class="panel-title">登录防护</h2>
        <p class="panel-hint">
          三层递进，越猜越难：默认只要账号密码 → 错一次就加验证码 → 连续错五次锁十五分钟。
          锁定按 <strong>IP</strong> 和 <strong>账号</strong> 两条线各记一把锁，换 IP 也绕不过账号那一把。
        </p>

        <div class="switch-row switch-row--tight">
          <div>
            <p class="sw-label">错一次后要求验证码</p>
            <p class="sw-desc" id="capModeDesc">
              <?= setting('login_captcha', '1') === '1' ? '已开启：第一次输错密码后出现验证码' : '已关闭：输错多少次都只提示密码错误' ?>
              <?php if (!function_exists('imagecreatetruecolor')): ?>
                <br>⚠ 当前 PHP 没有 GD 扩展，验证码图片生成不出来 —— 这个开关即使打开也不会生效，登录时不会要求验证码。装好 gd 后自动恢复。
              <?php endif; ?>
            </p>
          </div>
          <label class="switch">
            <input type="checkbox" id="loginCaptcha" <?= setting('login_captcha', '1') === '1' ? 'checked' : '' ?>>
            <span class="sw-track" aria-hidden="true"></span>
            <span class="sr-only">错一次后要求验证码</span>
          </label>
        </div>

        <?php
        // 当前是否有人正被锁着，直接在服务端算出来，省一次请求
        $nowTs   = time();
        $lockRows = [];
        try {
            foreach (db()->query('SELECT k, until, reason FROM login_locks WHERE until > ' . $nowTs . ' ORDER BY until ASC') as $r) {
                $lockRows[] = $r;
            }
        } catch (Throwable $e) {
        }
        ?>
        <h3 class="acl-sub">当前锁定</h3>
        <?php if (!$lockRows): ?>
          <p class="acl-empty">没有 IP 或账号处于锁定状态。</p>
        <?php else: ?>
          <ul class="acl-list">
            <?php foreach ($lockRows as $r): ?>
              <?php
                $k = (string)$r['k'];
                $isIp = str_starts_with($k, 'ip:');
                $who  = $isIp ? substr($k, 3) : '账号 ' . substr($k, 4, 10) . '…';
                $left = max(0, (int)$r['until'] - $nowTs);
              ?>
              <li>
                <span class="acl-badge acl-badge--deny"><?= $isIp ? 'IP' : '账号' ?></span>
                <code><?= h($who) ?></code>
                <span class="acl-note">剩余 <?= (int)ceil($left / 60) ?> 分钟</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <div class="panel-actions" style="justify-content:flex-start;flex-wrap:wrap">
          <button class="btn-ghost" type="button" id="unlockAll">立即解锁全部</button>
        </div>
        <p class="panel-hint" style="margin-top:8px">
          自己把自己锁在门外时用这个。验证码图片由 GD 现场绘制，不依赖任何字体文件，也不装第三方库。
        </p>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">访问控制</h2>
        <p class="panel-hint">
          按 IP 归属地放行或拦截。归属地查询全部在本机 SQLite 里完成，不外呼任何第三方接口——
          代价是得先把地理库导进来，否则所有 IP 都会被判成「未知」。
        </p>

        <div class="acl-master">
          <div class="switch-row switch-row--tight">
            <div>
              <p class="sw-label">启用访问控制</p>
              <p class="sw-desc" id="aclModeDesc">关闭时任何人都能访问</p>
            </div>
            <label class="switch">
              <input type="checkbox" id="aclMode" <?= setting('acl_mode', 'off') === 'on' ? 'checked' : '' ?>>
              <span class="sw-track" aria-hidden="true"></span>
              <span class="sr-only">启用访问控制</span>
            </label>
          </div>
        </div>

        <div class="acl-grid" id="aclGrid">
          <div>
            <label class="f-label" for="aclListType">名单语义</label>
            <select class="f-input" id="aclListType">
              <option value="deny"  <?= setting('acl_list_type', 'deny') === 'deny' ? 'selected' : '' ?>>黑名单：下表国家被拒绝，其余放行</option>
              <option value="allow" <?= setting('acl_list_type', 'deny') === 'allow' ? 'selected' : '' ?>>白名单：下表国家放行，其余被拒绝</option>
            </select>
          </div>
          <div>
            <label class="f-label" for="aclUnknown">未知地区 / 未知 IP</label>
            <select class="f-input" id="aclUnknown">
              <option value="allow" <?= setting('acl_unknown', 'allow') === 'allow' ? 'selected' : '' ?>>允许（推荐：宁可漏拦，不误伤）</option>
              <option value="deny"  <?= setting('acl_unknown', 'deny')  === 'deny'  ? 'selected' : '' ?>>拒绝（更严：只放行能识别的地区）</option>
            </select>
          </div>
        </div>
        <p class="panel-hint panel-hint--warn">
          已登录的管理员<strong>永远放行</strong>，配错了也不会把自己锁在门外。
        </p>

        <h3 class="acl-sub">国家 / 地区清单</h3>
        <?php if (!$aclRules): ?>
          <p class="acl-empty">还没有任何规则。下面的下拉选一个国家，再选它在名单里的角色。</p>
        <?php else: ?>
          <ul class="acl-list">
            <?php foreach ($aclRules as $cc => $mode): ?>
              <li class="acl-item">
                <span class="acl-cc"><?= h((string)$cc) ?></span>
                <span class="acl-name"><?= h(hp_country_name((string)$cc)) ?></span>
                <span class="acl-role acl-role--<?= $mode === 'allow' ? 'allow' : 'deny' ?>">
                  <?= $mode === 'allow' ? '放行' : '拒绝' ?>
                </span>
                <button class="acl-del" type="button" data-acl-del="<?= h((string)$cc) ?>"
                        aria-label="从名单移除 <?= h(hp_country_name((string)$cc)) ?>">移除</button>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <div class="acl-add">
          <select class="f-input" id="aclPick" aria-label="选择国家或地区">
            <option value="">— 选择国家 / 地区 —</option>
            <?php foreach (hp_country_choices() as $cc => $nm): ?>
              <?php if (isset($aclRules[$cc])) continue; ?>
              <option value="<?= h((string)$cc) ?>"><?= h($nm) ?>（<?= h((string)$cc) ?>）</option>
            <?php endforeach; ?>
          </select>
          <select class="f-input acl-role-pick" id="aclRole" aria-label="在名单中的角色">
            <option value="deny">拒绝</option>
            <option value="allow">放行</option>
          </select>
          <button class="btn-ghost" type="button" id="aclAdd">加入名单</button>
        </div>

        <h3 class="acl-sub">IP 归属地库</h3>
        <?php
          $gCity  = (int)($geoStatus['city'] ?? 0);
          $gSeg   = (int)($geoStatus['country'] ?? 0);   // 国家表里的 IP 段数
          $gCover = (int)($geoStatus['countries'] ?? 0); // 覆盖到多少个国家/地区
          $gAsn   = (int)($geoStatus['asn'] ?? 0);
          $gVer   = (string)($geoStatus['version'] ?? '');
          $gAt    = (string)($geoStatus['imported_at'] ?? '');
          $gOk    = $gCity > 0 || $gSeg > 0;
        ?>
        <div class="geo-stat">
          <div class="geo-cell">
            <span class="geo-num"><?= $gSeg ? number_format($gSeg) : '—' ?></span>
            <span class="geo-lab">IP 段<?= $gCover ? ' · 覆盖 ' . number_format($gCover) . ' 国' : '' ?></span>
          </div>
          <div class="geo-cell">
            <span class="geo-num"><?= $gCity ? number_format($gCity) : '—' ?></span>
            <span class="geo-lab">城市级段</span>
          </div>
          <div class="geo-cell">
            <span class="geo-num"><?= $gAsn ? number_format($gAsn) : '—' ?></span>
            <span class="geo-lab">运营商段</span>
          </div>
          <div class="geo-cell">
            <span class="geo-num geo-num--sm"><?= $gVer !== '' ? h($gVer) : '未导入' ?></span>
            <span class="geo-lab"><?= $gAt !== '' ? h($gAt) : '数据版本' ?></span>
          </div>
        </div>

        <?php if (!$gOk): ?>
          <p class="panel-hint panel-hint--warn">
            地理库还没导入。现在开启访问控制的话，所有访客都会被算作「未知地区」。
          </p>
        <?php endif; ?>

        <div class="panel-actions" style="justify-content:flex-start;flex-wrap:wrap">
          <button class="btn-primary" type="button" id="geoImportFast">
            <span class="btn-text">导入国家库（快，约 5 MB）</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <button class="btn-ghost" type="button" id="geoImportFull">
            <span class="btn-text">导入完整库（含城市与运营商，约 90 MB）</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <?php if ($gOk): ?>
            <button class="btn-danger" type="button" id="geoClear">清空地理库</button>
          <?php endif; ?>
        </div>
        <p class="panel-hint" id="geoLog" hidden></p>
        <ul class="adm-tips">
          <li>数据源 DB-IP Lite，CC BY 4.0 授权，无需注册、无需 API Key，从 <code>download.db-ip.com</code> 直接拉当月库。</li>
          <li><strong>国家库</strong>只够判断访问控制，速度快；<strong>完整库</strong>才有城市和运营商，导入时要一两分钟，库会涨 30 MB 上下。</li>
          <li>看得懂网络配置的话，导入完成后可以把服务器出网彻底关掉，本站不会再有任何外呼。</li>
          <li>已记录的历史访问（<code>country</code> 为空的）可以在统计页用「回填归属地」补齐。</li>
        </ul>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">数据备份与导出</h2>
        <p class="panel-hint">导出为 JSON，含全部分组、链接和设置。备份建议每月一次。</p>
        <div class="panel-actions" style="justify-content: flex-start; margin-top: 14px;">
          <a class="btn-primary" href="api.php?action=export&amp;csrf=<?= h(csrf_token()) ?>" download>
            下载 JSON 备份
          </a>
        </div>
        <ul class="adm-tips">
          <li>SQLite 数据库文件在 <code>data/home.db</code>，直接拷贝也能备份，但需先停服务或用 <code>sqlite3 .backup</code>。</li>
          <li>图标缓存 <code>data/icons/</code> 丢了不会报错，会自动降级为首字母色块。</li>
        </ul>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">重新安装</h2>
        <p class="panel-hint">
          安装程序 <code>install.php</code> 已随项目附带。它检测到站点已安装时会自动锁定，
          防止有人重跑它重置你的管理员密码——这是有意的安全设计。
        </p>
        <p class="panel-hint">确实要从零重装，按下述顺序操作（<strong>会清空全部数据</strong>）：</p>
        <ol class="adm-tips">
          <li>先点上面的「下载 JSON 备份」留一份。</li>
          <li>删除 <code>inc/config.php</code>。</li>
          <li>删除 <code>data/home.db</code>（以及同目录的 <code>home.db-wal</code>、<code>home.db-shm</code>）。</li>
          <li>浏览器打开 <code>/install.php</code>，重新设置账号密码。</li>
        </ol>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">图标缓存</h2>
        <p class="panel-hint">
          favicon 抓一次就落盘，之后主页直接读本地文件，不再逐条回源。
          这一块是整个站点首屏最快的那一笔——省掉的是几十次外站请求。
        </p>

        <div class="icon-stat">
          <div><span class="icon-stat-n"><?= (int)$iconStats['files'] ?></span><span class="icon-stat-l">个缓存文件</span></div>
          <div><span class="icon-stat-n"><?= h(hp_bytes($iconStats['bytes'])) ?></span><span class="icon-stat-l">占用空间</span></div>
          <div><span class="icon-stat-n"><?= (int)$iconStats['used'] ?></span><span class="icon-stat-l">条链接在用</span></div>
          <div>
            <span class="icon-stat-n <?= $iconStats['writable'] ? 'is-ok' : 'is-bad' ?>">
              <?= $iconStats['writable'] ? '可写' : '不可写' ?>
            </span>
            <span class="icon-stat-l">data/icons</span>
          </div>
        </div>

        <?php if ((int)$iconStats['files'] > 0): ?>
          <p class="panel-hint" style="margin-top:4px">
            最早一份
            <?= h(date('Y-m-d H:i', (int)$iconStats['oldest'])) ?>，
            最近一份
            <?= h(date('Y-m-d H:i', (int)$iconStats['newest'])) ?>。
          </p>
        <?php endif; ?>

        <div class="panel-actions" style="justify-content:flex-start;flex-wrap:wrap">
          <button class="btn-ghost" type="button" id="iconSync">
            <span class="btn-text">对齐缓存清单</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <button class="btn-ghost" type="button" id="iconRefill">
            <span class="btn-text">补抓缺失图标</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <button class="btn-ghost" type="button" id="iconGc">清理无主文件</button>
          <button class="btn-danger" type="button" id="iconPurge">清空缓存</button>
        </div>

        <ul class="adm-tips">
          <li>「补抓缺失图标」一次最多处理 12 条，可反复点——慢是因为要逐个访问对方站点，不是本站的问题。</li>
          <li>「清理无主文件」只删没有任何链接在用的图；删完会把对应链接的图标重置为自动识别，不会留下破图空框。</li>
          <li>清空缓存<strong>不会</strong>删掉链接本身，下次打开主页会自动重新抓取，只是第一次会慢一点。</li>
        </ul>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">部署提醒</h2>
        <ul class="adm-tips">
          <li>装完后删掉 <code>install.php</code>，并确保 <code>inc/config.php</code> 不在 Web 可读路径下（本方案已在 <code>inc/</code> 内，Apache 请加 <code>Require all denied</code>，Nginx 请 <code>location ^~ /inc/ { deny all; }</code>）。</li>
          <li><code>data/</code> 目录需要 PHP 可写（图标缓存与数据库都在这）。<strong>尤其要挡住 <code>data/home.db</code> 被直接下载</strong>，否则等于数据库全裸。</li>
          <li>生产环境把 <code>inc/config.php</code> 里的 <code>debug</code> 改为 <code>false</code>，避免报错泄露路径。</li>
        </ul>
      </section>
    <?php elseif ($tab === 'upgrade'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">系统升级</h1>
          <p class="adm-sub">
            一个包三种用法：<strong>没装过</strong>时是安装包，<strong>装过</strong>时是升级包，
            <strong>改坏了</strong>时把旧包再传一次就是恢复包。
          </p>
        </div>
      </header>

      <section class="adm-panel">
        <div class="ver-row">
          <div>
            <p class="ver-now-label">当前版本</p>
            <p class="ver-now"><?= h($sysInfo['version']) ?></p>
          </div>
          <dl class="ver-meta">
            <div><dt>PHP</dt><dd><?= h($sysInfo['php']) ?></dd></div>
            <div><dt>数据库版本</dt><dd><?= (int)$sysInfo['schema'] ?><?= $sysInfo['schema_ok'] ? '' : '（待迁移）' ?></dd></div>
            <div><dt>ZipArchive</dt><dd><?= $sysInfo['zip'] ? '可用' : '缺失' ?></dd></div>
            <div><dt>站点目录可写</dt><dd><?= $sysInfo['writable']['root'] ? '是' : '否' ?></dd></div>
          </dl>
        </div>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">上传升级包</h2>
        <p class="panel-hint">
          选一个 <code>.zip</code> 上传即可。包里有没有 <code>vnav/</code> 这层目录都行，程序会自己认。
          版本号比当前低也允许——那正是「恢复包」的用法。
        </p>

        <div class="drop-zone" id="upDrop" tabindex="0" role="button"
             aria-label="选择或拖入升级包">
          <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor"
               stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>
          </svg>
          <p class="drop-title">点这里选包，或直接把 zip 拖进来</p>
          <p class="drop-sub" id="upFileName">建议先点下面「下载 JSON 备份」留一份数据</p>
          <input type="file" id="upFile" accept=".zip,application/zip" hidden>
        </div>

        <div class="up-progress" id="upProgress" hidden>
          <div class="up-bar"><span id="upBar"></span></div>
          <p class="panel-hint" id="upLog">准备中…</p>
        </div>

        <div class="panel-actions" style="justify-content:flex-start;flex-wrap:wrap">
          <button class="btn-primary" type="button" id="upStart" disabled>
            <span class="btn-text">开始升级</span>
            <span class="spinner" hidden aria-hidden="true"></span>
          </button>
          <button class="btn-ghost" type="button" id="upCancel" hidden>取消</button>
        </div>

        <ul class="adm-tips">
          <li>升级会覆盖代码，但<strong>绝不动</strong> <code>inc/config.php</code>（账号密码）和 <code>data/</code>（数据库与图标缓存）。</li>
          <li>升级前自动做一份快照，存在系统临时目录，不进网站目录。出事在下面一键回滚。</li>
          <li>升级后会自动跑一次数据库迁移，新表新列由程序补齐，不用手工执行 SQL。</li>
          <li>如果站点不是 PHP 进程的主人（比如面板用了独立运行用户），文件可能没法被覆盖，会在这里直接报权限错误。</li>
        </ul>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">快照与回滚</h2>
        <p class="panel-hint">
          每次升级前自动存一份 <code>inc/config.php</code> 和 <code>data/home.db</code>。
          回滚只恢复这两样——代码要退回去，把旧包再传一次比用快照覆盖更干净。
        </p>
        <?php if (!$sysInfo['snapshots']): ?>
          <p class="acl-empty">还没有快照。第一次升级时才会生成。</p>
        <?php else: ?>
          <ul class="snap-list">
            <?php foreach ($sysInfo['snapshots'] as $sn): ?>
              <li>
                <div class="snap-main">
                  <code><?= h($sn['name']) ?></code>
                  <span class="snap-meta">
                    <?= h(date('Y-m-d H:i', (int)$sn['at'])) ?>
                    · <?= count($sn['files']) ?> 个文件
                    · <?= h(hp_bytes((int)$sn['bytes'])) ?>
                  </span>
                </div>
                <div class="snap-act">
                  <button class="btn-ghost" type="button" data-rollback="<?= h($sn['name']) ?>">回滚</button>
                  <button class="btn-ghost btn-quiet" type="button" data-dropsnap="<?= h($sn['name']) ?>">删除</button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="adm-panel">
        <h2 class="panel-title">环境自查</h2>
        <ul class="check-list">
          <?php
          $checks = [
              ['ZipArchive（解压升级包）', $sysInfo['zip'], 'PHP 没装 zip 扩展时无法解包，请联系主机商开启。'],
              ['GD（登录验证码）', $sysInfo['gd'], '没装 GD 时验证码出不来，登录防护会自动退化成「只靠密码」。'],
              ['cURL（抓图标与地理库）', $sysInfo['curl'], '没有 cURL 时图标抓取会失败，链接仍可正常添加。'],
              ['站点根目录可写', $sysInfo['writable']['root'], '升级要往这里写文件，不可写就只能手工覆盖。'],
              ['inc/ 可写', $sysInfo['writable']['inc'], '改密码要重写 inc/config.php。'],
              ['data/ 可写', $sysInfo['writable']['data'], '数据库和图标缓存都在这。'],
              ['data/icons/ 可写', $sysInfo['writable']['icons'], '图标缓存目录。'],
              ['数据库已是最新', $sysInfo['schema_ok'], '版本号落后时，访问任意页面会自动迁移一次。'],
          ];
          foreach ($checks as [$label, $ok, $why]): ?>
            <li class="<?= $ok ? 'is-ok' : 'is-bad' ?>">
              <span class="check-dot" aria-hidden="true"></span>
              <span class="check-label"><?= h($label) ?></span>
              <span class="check-state"><?= $ok ? '正常' : '异常' ?></span>
              <?php if (!$ok): ?><span class="check-why"><?= h($why) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="panel-hint" style="margin-top:10px">
          站点目录：<code><?= h($sysInfo['root_path']) ?></code> ·
          数据库：<code><?= h(hp_bytes((int)$sysInfo['db_size'])) ?></code>
        </p>
      </section>

    <?php elseif ($tab === 'changelog'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">更新日志</h1>
          <p class="adm-sub">按版本倒序。每条都说清「改了什么」和「为什么」，不写含糊的「优化若干」。</p>
        </div>
      </header>

      <?php require_once __DIR__ . '/inc/changelog.php'; ?>
      <div class="log-list">
        <?php foreach (hp_changelog() as $i => $rel): ?>
          <article class="log-item<?= $i === 0 ? ' is-latest' : '' ?>">
            <header class="log-head">
              <h2 class="log-ver"><?= h($rel['ver']) ?></h2>
              <?php if ($i === 0): ?><span class="log-tag">当前</span><?php endif; ?>
              <time class="log-date"><?= h($rel['date']) ?></time>
            </header>
            <?php if (!empty($rel['lead'])): ?>
              <p class="log-lead"><?= h($rel['lead']) ?></p>
            <?php endif; ?>
            <?php foreach ($rel['sections'] as $sec): ?>
              <div class="log-sec">
                <h3><?= h($sec['title']) ?></h3>
                <ul>
                  <?php foreach ($sec['items'] as $it): ?>
                    <li><?= $it ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          </article>
        <?php endforeach; ?>
      </div>

    <?php elseif ($tab === 'help'): ?>
      <header class="adm-head">
        <div>
          <h1 class="adm-title">使用说明</h1>
          <p class="adm-sub">从「第一次打开」到「改坏了怎么救」，都在这一页。</p>
        </div>
      </header>

      <nav class="help-toc" aria-label="本页目录">
        <a href="#h-start">快速上手</a>
        <a href="#h-keys">快捷键</a>
        <a href="#h-edit">前台编辑</a>
        <a href="#h-acl">访问控制</a>
        <a href="#h-icon">图标缓存</a>
        <a href="#h-backup">备份与恢复</a>
        <a href="#h-faq">常见问题</a>
      </nav>

      <section class="adm-panel" id="h-start">
        <h2 class="panel-title">快速上手</h2>
        <ol class="help-steps">
          <li>
            <strong>加第一条链接</strong>
            <p>回主页，按 <kbd>Ctrl</kbd>+<kbd>V</kbd> 粘贴任意网址，标题和图标会自动识别。不用先点「新增」。</p>
          </li>
          <li>
            <strong>调整顺序</strong>
            <p>按 <kbd>Ctrl</kbd>+<kbd>E</kbd> 进编辑模式，<strong>按住任意一张卡片拖</strong>就能换位置，松手即保存。手机上是<strong>长按约 0.3 秒</strong>再拖。</p>
          </li>
          <li>
            <strong>换个样子</strong>
            <p>主页右上角能切形态（卡片 / 横排 / 紧凑 / 宫格）和皮肤。<kbd>Ctrl</kbd>+<kbd>E</kbd> 之外的操作都不影响数据。</p>
          </li>
        </ol>
      </section>

      <section class="adm-panel" id="h-keys">
        <h2 class="panel-title">快捷键</h2>
        <p class="panel-hint">主页上随处可用。输入框里打字时不抢键，只有 <kbd>Enter</kbd> 和 <kbd>Esc</kbd> 例外。</p>
        <div class="help-keys">
          <table>
            <thead><tr><th>按键</th><th>作用</th></tr></thead>
            <tbody>
              <tr><td><kbd>Ctrl</kbd>+<kbd>V</kbd></td><td>粘贴网址，直接建一条新链接</td></tr>
              <tr><td><kbd>Ctrl</kbd>+<kbd>K</kbd> / <kbd>Ctrl</kbd>+<kbd>L</kbd></td><td>跳到搜索框</td></tr>
              <tr><td><kbd>Ctrl</kbd>+<kbd>E</kbd></td><td>开 / 关编辑模式</td></tr>
              <tr><td><kbd>Ctrl</kbd>+<kbd>S</kbd></td><td>保存当前编辑</td></tr>
              <tr><td><kbd>Ctrl</kbd>+<kbd>Z</kbd></td><td>撤销上一步（移动 / 拖动排序 / 删除 / 修改）</td></tr>
              <tr><td><kbd>Esc</kbd></td><td>关掉当前浮层，或取消当前编辑</td></tr>
              <tr><td><kbd>Enter</kbd></td><td>输入框里跳到下一格；最后一格就是保存</td></tr>
              <tr><td><kbd>Alt</kbd>+<kbd>↑</kbd> / <kbd>↓</kbd></td><td>把选中的链接往上 / 下挪一位</td></tr>
              <tr><td><kbd>Ctrl</kbd>+<kbd>/</kbd> 或 <kbd>?</kbd></td><td>弹出快捷键面板</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="adm-panel" id="h-edit">
        <h2 class="panel-title">前台编辑</h2>
        <p class="panel-hint">
          编辑模式是<strong>一个总开关</strong>，打开后整页进入编辑态，再点某张卡片就地改。
          没打开时卡片上没有任何多余按钮，鼠标划过也不会冒出一排图标——主页是用来看的，不是用来管的。
        </p>
        <ul class="adm-tips">
          <li>进编辑模式：顶栏铅笔按钮，或 <kbd>Ctrl</kbd>+<kbd>E</kbd>。仅登录后可见。</li>
          <li><strong>拖卡片换顺序</strong>：按住任意位置拖过 6px 就是拖动；少于 6px 算点击，会打开修改表单。左上角六点把手可以零延迟直接拖。</li>
          <li>手机 / 平板是<strong>长按约 0.3 秒</strong>起拖。长按期间手指滑超过一小段就算你在滚页面，会放弃拖动——所以滚屏和拖卡不会打架。</li>
          <li>同一行内左右拖也有效，宫格形态下能往右挪。</li>
          <li>点卡片本身进入修改表单，改标题 / 网址 / 备注 / 分组 / 是否隐藏。</li>
          <li>删除在编辑浮层的左下角，删了还能 <kbd>Ctrl</kbd>+<kbd>Z</kbd> 撤回。</li>
          <li>拖动排序同样能 <kbd>Ctrl</kbd>+<kbd>Z</kbd> 撤销，连续拖多次可以逐级退回。</li>
          <li>退出编辑模式时如果有未保存的改动，会先问一句，不会默默丢掉。</li>
        </ul>
      </section>

      <section class="adm-panel" id="h-acl">
        <h2 class="panel-title">访问控制</h2>
        <p class="panel-hint">
          按 IP 归属地放行或拦截，全部在本机 SQLite 里判断，不外呼接口。
          代价是得先把地理库导进数据库，否则所有 IP 都会被判成「未知」。
        </p>
        <ul class="adm-tips">
          <li>黑名单：名单里的地区被拒；白名单：只有名单里的地区能进。两种语义别混着用。</li>
          <li>「未知地区」单独一格开关。默认放行——宁可漏拦，也不要把自己人拦在外面。</li>
          <li>已登录的管理员<strong>永远放行</strong>，配错了也进得来。</li>
          <li>导入国家库约 5 MB，几秒钟；完整库约 90 MB，要一两分钟才有城市和运营商。</li>
          <li>导库之前记下的访问，可以在统计页用「回填归属地」补齐。</li>
        </ul>
      </section>

      <section class="adm-panel" id="h-icon">
        <h2 class="panel-title">图标缓存</h2>
        <p class="panel-hint">
          每个站点的 favicon 只抓一次，落到 <code>data/icons/</code>，主页直接读本地文件。
          这一块省掉的是几十次外站请求，对首屏的影响比任何 CSS 优化都大。
        </p>
        <ul class="adm-tips">
          <li>文件名带域名和源地址的短哈希，同一站点多个图标不会互相覆盖。</li>
          <li>位图统一压到 128px，够清晰又不占地方。</li>
          <li>缓存文件丢了不会报错，会自动降级成首字母色块，链接照常能用。</li>
          <li>「清理无主文件」只删没有任何链接引用的图，删完会把对应链接重置为自动识别。</li>
        </ul>
      </section>

      <section class="adm-panel" id="h-backup">
        <h2 class="panel-title">备份与恢复</h2>
        <p class="panel-hint">
          三种东西要分开看：<strong>数据</strong>、<strong>代码</strong>、<strong>配置</strong>。
          备份只管数据，升级包管代码，配置两边都不进包。
        </p>
        <ul class="adm-tips">
          <li><strong>数据</strong>：安全页的「下载 JSON 备份」，或直接拷 <code>data/home.db</code>（拷之前先停服务）。</li>
          <li><strong>代码</strong>：把当前的 zip 包留着，这就是最干净的代码备份，也是恢复包。</li>
          <li><strong>配置</strong>：<code>inc/config.php</code> 里有管理员密码哈希，单独抄一份放好。</li>
          <li><strong>从零重装</strong>：删 <code>inc/config.php</code> 和 <code>data/home.db*</code>，打开 <code>/install.php</code>。</li>
          <li><strong>升级出问题</strong>：升级页的「快照与回滚」能恢复配置和数据库，代码用旧包再传一次。</li>
        </ul>
      </section>

      <section class="adm-panel" id="h-faq">
        <h2 class="panel-title">常见问题</h2>
        <dl class="help-faq">
          <dt>加了链接但图标是个字母块？</dt>
          <dd>对方的 favicon 抓不到（站点没有、被墙、或做了防盗链）。可以到「链接」页手工填图标地址，或干脆让它当色块——色块其实更好认。</dd>

          <dt>主页打不开，怀疑是访问控制配错了？</dt>
          <dd>在另一台没被拦的机器上登录后台，把访问控制关掉；或直接改数据库 <code>settings</code> 表的 <code>acl_mode</code> 为 <code>off</code>。已登录的管理员不受限制，所以你只要有办法登录就进得去。</dd>

          <dt>登录页突然要验证码了，是不是被攻击了？</dt>
          <dd>不一定。规则是「错一次就出验证码」，你自己输错一次也会触发。连续错五次会锁 15 分钟，后台安全页能看当前锁定状态，也能一键解锁。</dd>

          <dt>升级后样式乱了？</dt>
          <dd>多半是浏览器缓存了旧 CSS。资源版本号跟着版号走，正常刷新一次就好；还不行就 <kbd>Ctrl</kbd>+<kbd>F5</kbd>。</dd>

          <dt>能换端口 / 放子目录吗？</dt>
          <dd>能。代码里没有写死绝对路径，全部基于 <code>__DIR__</code>。放子目录时 <code>.htaccess</code> 里的 <code>RewriteBase</code> 要跟着改。</dd>
        </dl>
      </section>
    <?php endif; ?>

  </main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<script src="assets/admin.js?v=22" defer></script>
</body>
</html>