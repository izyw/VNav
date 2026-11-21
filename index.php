<?php
/**
 * 主页 —— 服务端直出，首屏无需等 JS。
 *
 * v1.2.0 的变化：
 *   1) 登录后可在前台直接编辑：拖动排序、就地改名改址、删除、切隐藏，
 *      不再跳后台。编辑模式由顶栏铅笔按钮或 Ctrl+E 开关。
 *   2) 右下角常驻一条快捷键提示（可永久关闭），Ctrl+/ 有完整面板。
 *   3) 品牌名统一 Vnav。
 */
declare(strict_types=1);
define('HP_ROOT', __DIR__);

/* 简写路径兜底：/admin、/login 这类「不带 .php」的地址。
   Apache/Nginx 正常配置下不会走到这里（它们按文件匹配，找不到就 404），
   但 PHP 内置服务器把 /admin 当成 index.php + PATH_INFO=/admin，
   于是打开的是主页 —— 用户看到「输 admin 打不到登录界面」正是这个原因。
   这里识别到 PATH_INFO 是已知入口名就 302 过去。
   只认白名单，不做任意转发（否则等于开了个开放重定向）。 */
$__pi = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
if ($__pi !== '') {
    $__shortcuts = ['admin' => 'admin.php', 'login' => 'login.php', 'install' => 'install.php'];
    if (isset($__shortcuts[$__pi])) {
        header('Location: ' . $__shortcuts[$__pi], true, 302);
        exit;
    }
}
unset($__pi, $__shortcuts);

require __DIR__ . '/inc/boot.php';
require __DIR__ . '/inc/analytics.php';
require __DIR__ . '/inc/acl.php';

/* 退出登录：/?logout=<csrf>。
   没做成单独的 logout.php，因为升级包只能覆盖服务器上已有的文件 ——
   多一个文件就多一处「包里改了、线上还是老的」的裂缝。
   token 是必须的：少了它，任何外站放一张 <img src="/?logout=x">
   就能把人踢下线。 */
if (isset($_GET['logout'])) {
    $__outTok = (string)$_GET['logout'];
    if (is_logged_in() && $__outTok !== '' && hash_equals(csrf_token(), $__outTok)) {
        /* 先清数据，再清 cookie，最后销毁会话 —— 顺序反了就没得写了 */
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $__p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $__p['path'],
                'domain'   => $__p['domain'],
                'secure'   => (bool)$__p['secure'],
                'httponly' => (bool)$__p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }
    header('Location: ./');
    exit;
}
unset($__outTok);


// 访问控制先于一切：被拦的请求不该建 session、不该查库、不该记统计。
$__isAdmin = is_logged_in();   // 能编辑（登录即可）
$__isSuper = is_admin();       // 真正的管理员：后台 / 统计 / 访问控制
$__ip      = hp_client_ip();
/* 第二个参数是「是否管理员」，不是「是否登录」——
   hp_acl_check() 的语义是「管理员永远放行」，传 is_logged_in() 会让
   任何登录用户都跳过地区限制（含切模式前就登录的僵留会话）。 */
$__acl     = hp_acl_check($__ip, $__isSuper);
if (!$__acl['allow']) {
    // 拦截也留一条记录，否则管理员看不到「有人被挡在外面」
    hp_log_visit(false, 'blocked');
    hp_acl_deny((string)$__acl['country']);
}

/* 第二个参数开启 HTML 空白折叠：这是纯排版缩进，游客分支下大量
   <?php if ($__isAdmin): ?> 不成立会留下成片的空格 —— 实测占首页
   原始体积的一半。只对首页开，api.php 的 JSON 绝不能走这条路。 */
hp_compress_start(6, true);

// 只接受白名单值：数据库里若是历史遗留的脏值，直接回退默认，
// 否则 html[data-theme='...'] 匹配不到任何规则，整站变量全空（白屏）。
$layout   = hp_layout_safe(setting('layout', 'card'));
// 皮肤白名单来自 inc/skins.php —— 单一真源，新增皮肤不必改这里
$theme    = hp_skin_safe(setting('theme', 'atlas'));
/* 本机皮肤偏好写在 cookie 里（切主题时由 ui.js 同步），服务端据此输出
   对应的皮肤 CSS。只下载当前这一套：atlas 30KB / frame 13KB（gzip），
   两套全下等于白费一半。白名单用同一套 hp_skin_safe，防注入。
   没有 cookie 就用账号设置值 —— 此时和 localStorage 首次访问一致。 */
if (isset($_COOKIE['vnav_skin'])) {
    $__skinCookie = hp_skin_safe((string)$_COOKIE['vnav_skin']);
    if ($__skinCookie !== '') {
        $theme = $__skinCookie;
    }
}
unset($__skinCookie);
$accent   = setting('accent', 'amber');
$accent   = in_array($accent, ['amber', 'coral', 'mint', 'sky', 'violet'], true) ? $accent : 'amber';
$title    = setting('site_title', 'Vnav');
$newTab   = setting('open_new_tab', '1') === '1';
$showDesc = setting('show_desc', '1') === '1';
$showSrch = setting('show_search', '1') === '1';
// 引擎白名单来自 inc/engines.php
$engine   = hp_engine_safe(setting('search_engine', 'bing'));
$engines  = hp_engines();
$footer   = setting('footer_note', '');
/* 版权署名三级兜底：专门填的 → 「Made By 作者名」 → 「Made By izyw」。
   这样页脚永远不会出现「© 2026 」后面空着的半句。

   注意第二级不是直接把作者名甩上去：页脚要的是完整的一句署名，
   而 author_name 是个名字（izyw）。补上「Made By」这层壳，
   后台只填名字就够了，不用为了页脚好看去填一句英文。 */
$copyOwner = trim(setting('copyright_name', ''));
$copyAuthor = '';
if ($copyOwner === '') {
    $copyAuthor = trim(setting('author_name', '')) ?: 'izyw';
    $copyOwner  = 'Made By ' . $copyAuthor;
}
/* 署名里的名字点进去是「作者的主页」，项目主页是另一个链接。
   两者不能混：前者是人，后者是这个程序。后台留空就兜底到仓库。
   author_url 是新设置项，老库没有这一行，setting() 会返回空。 */
$authorUrl = trim(setting('author_url', '')) ?: 'https://github.com/izyw';
/* 项目主页地址：指到**仓库**，不是作者个人页。
   老库这条存的是作者主页（https://github.com/izyw），点「项目主页」
   会跳到个人首页而不是这个程序 —— 那种链接点了等于没到。
   库里的值只填到用户名一级时，一律换成仓库地址；
   填了完整仓库（或自己的域名）就照用，不瞎改。 */
$authorGh = trim(setting('author_github', ''));
if ($authorGh === '' || preg_match('~^https?://(www\.)?github\.com/[^/]+/?$~i', $authorGh)) {
    $authorGh = 'https://github.com/izyw/VNav';
}
// ICP 备案号：留空就整段不渲染，不占位也不显示「暂无备案」
$icpBeian  = trim(setting('icp_beian', ''));
$copyYear  = date('Y');
// 分组导航：side（左侧）/ tab（顶部标签）/ ''（不启用）
$groupnav = hp_groupnav_safe(setting('groupnav', 'tab'));

/* 一次查询取全部链接，分组在 PHP 里归并（比 N+1 次查询快得多）。
   隐藏链接在这里就过滤掉：游客的 HTML 里根本不会出现这些卡片，
   所以「隐藏」是真的隐藏，而不是靠 CSS 藏起来。 */
/* 前台看到的是哪一份数据。
   默认永远是「网站默认」（user_id = 0）—— 访客看它，管理员日常也看它，
   这样管理员改完当场就能用访客的视角验证。
   admin 想检查自己那份私人库时，带 ?lib=mine 即可（后台切换器里有入口）。
   普通人带这个参数没用：hp_data_uid_for() 对游客和普通用户不认 lib。 */
$dataUid = hp_data_uid_for(($_GET['lib'] ?? '') === 'mine' ? 'mine' : 'site');
/* 单人模式不在这里特判：hp_data_uid_for() 内部已经统一成
   「站长那份（空则回落 0）」，手敲 ?lib=mine 也拉不回另一份。 */

/* 当前看的是哪一份库。管理员日常看「站点默认」（公共那份），
   想放私人条目就切到自己的库 —— V1.1.0 起这个切换有了明面入口，
   原先只能手敲 ?lib=mine，于是「隐藏链接」那个开关成了唯一手段。
   普通用户不参与：他看的一直是自己的库，没有可切的东西。
   单人模式更不参与：全站只有一份，那个切换器已经整块不输出。 */
$onMine = !hp_single_mode() && $__isSuper && $dataUid !== 0;

$sql = 'SELECT id, group_id, title, url, icon, icon_kind, descr, hits, hidden
          FROM links WHERE user_id = ?';
/* 游客看的是站点默认，里面可能有 admin 设为隐藏的链接 —— 那些不能给游客。
   登录用户（含 admin）看的是自己那一份，隐藏与否都返回，前台照旧打角标。 */
if (!is_logged_in()) {
    $sql .= ' AND hidden = 0';
}
$sql .= ' ORDER BY sort ASC, id ASC';

$st = db()->prepare($sql);
$st->execute([$dataUid]);
$rows = $st->fetchAll();

$st = db()->prepare('SELECT id, name, icon FROM groups WHERE user_id = ? ORDER BY sort ASC, id ASC');
$st->execute([$dataUid]);
$groups = $st->fetchAll();

$byGroup = [];
foreach ($rows as $r) {
    $byGroup[(int)($r['group_id'] ?? 0)][] = $r;
}

// 未分组排最后显示（如果存在分组）
$ordered = [];
foreach ($groups as $g) {
    $gid = (int)$g['id'];
    /* 空分组也要渲染。以前只收有链接的组，于是前台新建一组、
       刷新页面它就凭空消失（只有添加浮层的下拉里还看得到），
       用户会以为没建成。侧栏和主区都得看得见这个空组，
       才能把它当成「往里放链接的地方」。 */
    $ordered[] = [
        'id'    => $gid,
        'name'  => $g['name'],
        // 后台选过的图标优先，空则前台按组名自动匹配（见 hp_group_icon）
        'icon'  => (string)($g['icon'] ?? ''),
        'items' => $byGroup[$gid] ?? [],
    ];
}
if (!empty($byGroup[0])) {
    $ordered[] = ['id' => 0, 'name' => count($ordered) ? '未分组' : '', 'icon' => '', 'items' => $byGroup[0]];
}

// 只有一个分组时，组内导航没有意义，直接不渲染（少一层视觉噪音）
$useNav = $groupnav !== '' && count($ordered) > 1;

$action  = $engines[$engine]['url'];
$layouts = hp_layouts();
$skins   = hp_skins();

// 记一次访问。放在查询之后：统计失败绝不该影响页面渲染，
// 而且这里已经确认请求能正常走到业务逻辑。
hp_log_visit(false, '/');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= h($theme) ?>" data-accent="<?= h($accent) ?>" data-layout="<?= h($layout) ?>"<?= $useNav ? ' data-gnav="' . h($groupnav) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark light">
<meta name="description" content="<?= h($title) ?>">
<title><?= h($title) ?></title>
<link rel="icon" href="<?php
  /* 站点图标：后台「基本信息」里设过就用那张图（原生 favicon 也一起换），
     没设才回落到内置的勾形标记。 */
  $__fav = hp_site_logo();
  echo $__fav !== '' ? h($__fav) : "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23F0B429'/><path d='M9 16.5l4.5 4.5L23 11' stroke='%23241500' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>";
?>">
<?php /* 不预载 bricolage/instrument 两个 woff2（省 71KB）：
         五套皮肤全部把 --font-display/--font-body 覆盖成系统字体栈
         （atlas 系 -apple-system、frame 系 Geist），实测 document.fonts.check
         对 Bricolage 恒为 false —— 这两个字体从没被真正用上。
         @font-face 定义保留（后台等非皮肤页仍可能引用），但只有实际
         用到才会下载，preload 等于每次白下 71KB。 */ ?>
<link rel="stylesheet" href="assets/app.css?v=56">
<?php /* 主页视觉系统叠在 app.css 之后。app.css 是后台与登录页共用的底座
          （设计令牌 + 按钮 + 表单），不能在上面重写主页样式，否则会连带
          改坏后台。所以主页的推倒重来单独落在 home.css，全部规则挂在
          body.hp 之下 —— 作用域只在主页，后台零影响。 */ ?>
<link rel="stylesheet" href="assets/home.css?v=62">
<?php /* 皮肤 CSS 必须排在 home.css 之后：靠「特异性 +1 属性选择器」与
         「后加载」双重压过 home.css 的结构规则，顺序反了只能改色改不动结构。

         按需只输出当前这一系：atlas 系（atlas/atlas-dark/auto）用
         skin-atlas.css，frame 系（frame/frame-dark）用 skin-frame.css。
         两套全下等于每次白费一套（gzip 后 13~30KB）。
         切到另一系时，ui.js 会动态补载对应 CSS（见下方 data-skin-css）。 */ ?>
<?php $__skinCss = str_starts_with($theme, 'frame') ? 'frame' : 'atlas'; ?>
<link rel="stylesheet" href="assets/skin-<?= $__skinCss ?>.css?v=<?= $__skinCss === 'frame' ? '60' : '64' ?>" data-skin-css="<?= $__skinCss ?>">
<script>
/* 记录当前已加载的皮肤系，供切换时判断是否要补载另一套。
   放在 body 之前，ui.js 执行时已可用。 */
window.__VNAV_SKIN_CSS = <?= json_encode($__skinCss) ?>;
window.__VNAV_SKIN_HREF = {
  atlas: 'assets/skin-atlas.css?v=64',
  frame: 'assets/skin-frame.css?v=60'
};
</script>
<?php /* 登录态要判断 localStorage 里那份「67 条链接、9 个分组」究竟是不是
   站点默认数据的副本：旧版把它的 baseline 误标成 false，每次登录都误报
   「你在未登录时保存了…」。把默认库的网址/标题/分组名注入前端按内容比对，
   一致就静默清掉；游客用不到，输出空数组不增加首屏体积。
   必须定义在 if ($__isAdmin) 之外：下面的内联 script 在 endif 之后。
   数据直接查 user_id=0 的线上默认库（不 require inc/seed.php —— 旧版
   网站没有那个文件，require 会让登录页 500）。 */
$__defLinks = array();
$__defGroups = array();
if ($__isAdmin) {
    try {
        /* 比对基准必须跟着**当前实际展示的那份**走（$dataUid），
           不能写死 user_id = 0：单人模式下数据在站长名下，
           基准仍取 0 那份会让游客沙盒每次都误判「内容不一致」。 */
        $__pdo = db();
        $__stD = $__pdo->prepare("SELECT url, title FROM links WHERE user_id = ? ORDER BY sort, id");
        $__stD->execute([$dataUid]);
        $__defLinks = $__stD->fetchAll(PDO::FETCH_ASSOC);
        $__stD = $__pdo->prepare("SELECT name FROM groups WHERE user_id = ? ORDER BY sort, id");
        $__stD->execute([$dataUid]);
        $__defGroups = $__stD->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $__defLinks = array(); $__defGroups = array();
    }
}
?>
<?php if ($__isAdmin): ?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="adm-lib" content="<?= (!hp_single_mode() && $dataUid !== 0) ? 'mine' : 'site' ?>">
<?php endif; ?>
<script>
/* 偏好要在首屏之前定，否则会先渲染默认形态/皮肤再被 JS 改，出现明显闪烁。
   这里放一段同步内联脚本，读 localStorage 后立刻写 documentElement.dataset
   —— 早于 CSS 首帧。白名单从 PHP 注入，新增皮肤不必回来改这段。 */
(function () {
  var el = document.documentElement;
  window.__VNAV = {
    guest: <?= is_logged_in() ? 'false' : 'true' ?>,
    /* 单人模式才提供「隐藏链接」。多人模式前台不显示隐藏角标与开关。 */
    singleMode: <?= hp_single_mode() ? 'true' : 'false' ?>,
    /* 右下角提示是否已被永久关闭。以前只写进服务端、前端从不读，
       等于换了设备或清了缓存提示又冒出来；现在两边一起认。 */
    tipOff: <?= setting('hint_dismissed', '0') === '1' ? 'true' : 'false' ?>
  };
  /* guest.js 用它认出「localStorage 里那份只是默认库副本」，避免每次登录
     误报 67 条 / 9 个分组。游客态数组为空，不花首屏体积。 */
  window.__VNAV_DEFAULT = {
    /* JSON_HEX_TAG/AMP/APOS/QUOT 不能省：这段是内联在 script 里的，
       标题里只要出现 script 的结束标签，就会提前闭合本块，后面的内容
       全部变成页面文本。JSON_UNESCAPED_SLASHES 会把 / 原样输出，单靠它挡不住。
       ⚠ 连注释里都不许写出那个字面量：浏览器先按 HTML 解析 script 内容，
       JS 注释对它没有约束力 —— 2026-11-18 首页顶部那段乱码就是这么来的。 */
    urls: <?= json_encode(array_column($__defLinks, 'url'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    titles: <?= json_encode(array_column($__defLinks, 'title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    groups: <?= json_encode(array_column($__defGroups, 'name'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
  };
  try {
    /* 改名迁移：存储键从 vlinks.* 换成 vnav.*。
       老用户浏览器里还留着旧键，不搬过来的话，升级后皮肤、布局、
       引擎选择会全部回到默认 —— 看起来像「设置被重置了」。
       搬完就删旧键，只跑一次，之后这里是空转。 */
    for (var i = 0, ks = ['theme', 'layout', 'nav', 'engine', 'tip']; i < ks.length; i++) {
      var oldKey = 'vlinks.' + ks[i], newKey = 'vnav.' + ks[i];
      var ov = localStorage.getItem(oldKey);
      if (ov !== null) {
        if (localStorage.getItem(newKey) === null) localStorage.setItem(newKey, ov);
        localStorage.removeItem(oldKey);
      }
    }

    var keys = <?= json_encode(hp_skin_keys(), JSON_UNESCAPED_UNICODE) ?>;
    var t = localStorage.getItem('vnav.theme');
    if (t && keys.indexOf(t) !== -1) {
      el.dataset.theme = t;
      /* 兜底：cookie 被清但 localStorage 还在时，服务端按默认输出了
         另一系 CSS。这里发现不一致就同步补载（首帧前插入，不等 JS 事件）。 */
      var wantSkin = t.indexOf('frame') === 0 ? 'frame' : 'atlas';
      if (window.__VNAV_SKIN_CSS && wantSkin !== window.__VNAV_SKIN_CSS) {
        var href = (window.__VNAV_SKIN_HREF || {})[wantSkin];
        if (href) {
          /* 与 ui.js 一致：目标系 link 已存在也要 appendChild 挪到末尾，
             否则切回的那套留在前面会被另一套 CSS 覆盖（卡片挤列/右移）。 */
          var lk = document.querySelector('link[data-skin-css="' + wantSkin + '"]');
          if (!lk) {
            lk = document.createElement('link');
            lk.rel = 'stylesheet'; lk.href = href; lk.dataset.skinCss = wantSkin;
          }
          document.head.appendChild(lk);
          window.__VNAV_SKIN_CSS = wantSkin;
        }
      }
    }

    var lkeys = <?= json_encode(hp_layout_keys(), JSON_UNESCAPED_UNICODE) ?>;
    var l = localStorage.getItem('vnav.layout');
    if (l && lkeys.indexOf(l) !== -1) el.dataset.layout = l;

    /* 侧栏收起态。和皮肤一样必须在首帧前定，否则会先画出 240px 的
       侧栏再跳成 68px，主区卡片跟着抖一下。 */
    if (localStorage.getItem('vnav.nav') === 'mini') el.dataset.nav = 'mini';
  } catch (e) {}
})();
</script>
</head>
<body class="hp <?= $__isAdmin ? 'is-admin' : 'is-guest' ?><?= $onMine ? ' is-mylib' : '' ?><?= (hp_single_mode() && !is_logged_in()) ? ' single-readonly' : '' ?>">

<div class="aurora" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>

<?php /* 应用壳。Atlas 皮肤下它是 display:contents（完全不参与布局，
           与改造前逐像素一致）；「程序框」皮肤下它变成一张带边框的圆角
           容器，顶栏 / 侧栏 / 主区在框内排成三块。 */ ?>
<div class="shell">

<header class="topbar">
  <?php /* 三栏栅格：站点名 | 搜索（绝对居中） | 工具。
           搜索框必须居中而不是靠 margin:auto 挤 —— margin:auto 会让它
           的位置随左右两侧宽度变化，窗口一窄就偏。用 grid 的
           1fr auto 1fr 让中间那格永远在正中。 */ ?>
  <a class="brand" href="./" aria-label="<?= h($title) ?> 首页">
    <?php /* 自定义了站点图标就整块换成图片：不再套强调色方块 ——
             用户的 logo 自带配色，套一层彩色底会把边缘吃掉，
             看起来像贴了两层色。 */
    $__logo = hp_site_logo(); ?>
    <?php if ($__logo !== ''): ?>
      <span class="brand-mark brand-mark--logo">
        <img src="<?= h($__logo) ?>" width="28" height="28" alt="" decoding="async">
      </span>
    <?php else: ?>
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4 4L19 7"/></svg>
      </span>
    <?php endif; ?>
    <span class="brand-text"><?= h($title) ?></span>
  </a>

  <?php if ($showSrch): ?>
    <?php /* 搜索区：左边引擎切换按钮 + 右边输入框，视觉上拼成一个整体。
             引擎按钮用 <button> 而非 <a>，因为它是「选引擎」不是「去搜索」。 */ ?>
    <div class="search-wrap">
      <div class="tb-menu eng-menu" data-menu="engine">
        <button class="eng-btn" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="mEngine">
          <span class="eng-logo" data-eng-logo="<?= h($engine) ?>" aria-hidden="true">
            <?= hp_engine_logo_html($engine, 20) ?>
          </span>
          <svg class="tbm-caret" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          <span class="sr-only">切换搜索引擎，当前 <?= h($engines[$engine]['label']) ?></span>
        </button>
        <div class="tbm-panel tbm-panel--eng" id="mEngine" role="listbox" aria-label="搜索引擎" hidden>
          <?php foreach ($engines as $ek => $en):
            $on = $ek === $engine; ?>
            <button class="tbm-opt" type="button" role="option" data-engine-set="<?= h($ek) ?>"
                    data-engine-url="<?= h($en['url']) ?>"
                    data-engine-param="<?= h($en['param']) ?>"
                    <?= $on ? 'data-engine-default-mark="1"' : '' ?>
                    aria-selected="<?= $on ? 'true' : 'false' ?>">
              <span class="tbo-ico eng-logo eng-logo--sm" data-eng-logo="<?= h($ek) ?>" aria-hidden="true">
                <?= hp_engine_logo_html($ek, 24) ?>
              </span>
              <span class="tbo-body">
                <span class="tbo-name"><?= h($en['label']) ?></span>
                <span class="tbo-hint tbo-hint--default"<?= $on ? '' : ' hidden' ?>>站点默认引擎</span>
              </span>
              <svg class="tbo-check" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4 4L19 7"/></svg>
            </button>
          <?php endforeach; ?>
          <?php /* 「设为默认」这一项对所有人开放，但语义不同：
                   登录用户写的是站点默认（服务端设置，换设备也生效）；
                   游客没有可写的地方，写的是本机 localStorage ——
                   那本来就是他「打开时用哪个引擎」的全部含义。
                   以前这里整块对游客不渲染，游客换完引擎找不到「记住」的动作，
                   会以为这次选择只在这一页有效。 */ ?>
          <div class="tbm-sep" role="separator"></div>
          <button class="tbm-opt tbm-opt--act" type="button" data-engine-default>
            <span class="tbo-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.6 6.2 6.4.5-4.9 4.3 1.5 6.3L12 16.9 6.4 20.3l1.5-6.3L3 9.7l6.4-.5z"/></svg>
            </span>
            <span class="tbo-body"><span class="tbo-name"><?= $__isAdmin ? '把当前引擎设为默认' : '在本机记住这个引擎' ?></span></span>
          </button>
        </div>
      </div>

      <form class="search" role="search" action="<?= h($action) ?>" method="get" target="_blank" rel="noreferrer">
        <label for="q" class="sr-only">搜索</label>
        <?php /* 参数名跟着引擎走：百度认 wd、搜狗认 query，其余是 q。
                 GET 提交会把 action 里的查询串整个丢掉，写死 q 等于让百度
                 去解析一个它不认识的参数 —— 结果就是用户看到的空白页。 */ ?>
        <input id="q" name="<?= h($engines[$engine]['param']) ?>" type="search" placeholder="搜索 <?= h($engines[$engine]['label']) ?>" autocomplete="off" enterkeyhint="search">
        <?php /* 提示与按钮是同一个东西：不聚焦时它就是一枚写着 Ctrl+K 的浅色胶囊，
                 光标进到输入框后收成实心方块换成箭头（CSS 用 :focus-within 切）。
                 图标用箭头而不是放大镜 —— 放大镜在这条框里已经删掉了。 */ ?>
        <button class="search-go" type="submit" title="搜索（Ctrl+K）">
          <span class="sr-only">搜索</span>
          <span class="go-kbd" aria-hidden="true">Ctrl+K</span>
          <svg class="go-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h12"/><path d="M12.5 6.5L18 12l-5.5 5.5"/></svg>
        </button>
      </form>
      <?php /* 搜索建议下拉。只是个空壳，内容由 ui.js 按需填 ——
               服务端不预渲染任何关键词，隐私上更干净。绝对定位挂在
               .search-wrap 的下沿，宽度与搜索框对齐（见 home.css）。 */ ?>
      <div class="search-sug" id="sug" role="listbox" aria-label="搜索建议" hidden></div>
    </div>
  <?php endif; ?>

  <?php /* 布局与皮肤各收成一个下拉按钮。v1.1.0 起按钮上只留图标：
           文字进 sr-only，屏幕阅读器仍能读到，视觉上只占 44×44。
           功能一个没少 —— 下拉里依然有名称与说明。 */ ?>
  <div class="tb-tools">

    <div class="tb-menu" data-menu="layout">
      <button class="tbm-btn" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="mLayout" title="展示形态：<?= h($layouts[$layout]['label']) ?>">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?= $layouts[$layout]['svg'] ?></svg>
        </span>
        <span class="sr-only">展示形态，当前 <?= h($layouts[$layout]['label']) ?></span>
      </button>
      <div class="tbm-panel" id="mLayout" role="listbox" aria-label="展示形态" hidden>
        <?php foreach ($layouts as $k => $lo):
          $on = $k === $layout; ?>
          <button class="tbm-opt" type="button" role="option" data-layout-set="<?= h($k) ?>"
                  aria-selected="<?= $on ? 'true' : 'false' ?>">
            <span class="tbo-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $lo['svg'] ?></svg>
            </span>
            <span class="tbo-body">
              <span class="tbo-name"><?= h($lo['name']) ?></span>
              <span class="tbo-hint"><?= h($lo['hint']) ?></span>
            </span>
            <svg class="tbo-check" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4 4L19 7"/></svg>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="tb-menu" data-menu="theme">
      <button class="tbm-btn" type="button" aria-haspopup="listbox" aria-expanded="false" aria-controls="mTheme" title="界面皮肤：<?= h($skins[$theme]['label']) ?>">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><?= $skins[$theme]['svg'] ?></svg>
        </span>
        <span class="sr-only">界面皮肤，当前 <?= h($skins[$theme]['label']) ?></span>
      </button>
      <div class="tbm-panel" id="mTheme" role="listbox" aria-label="界面皮肤" hidden>
        <?php
        // 初始 aria-selected 必须由服务端算好：硬编码后再靠 JS 修正，
        // 首屏会出现「选中态错位」的闪烁（截图抓到过）。
        foreach ($skins as $tk => $sk):
          $on = $tk === $theme; ?>
          <button class="tbm-opt" type="button" role="option" data-theme-set="<?= h($tk) ?>"
                  aria-selected="<?= $on ? 'true' : 'false' ?>">
            <span class="tbo-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $sk['svg'] ?></svg>
            </span>
            <span class="tbo-body">
              <span class="tbo-name"><?= h($sk['label']) ?></span>
              <span class="tbo-hint"><?= h($sk['hint']) ?></span>
            </span>
            <svg class="tbo-check" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4 4L19 7"/></svg>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <?php /* 编辑模式开关。多人模式对所有访问者开放（登录改云端、游客改本机沙盒）；
             单人模式只有站长能管理，未登录访客只读 —— 按钮不渲染，键盘入口也在
             app.js 里按 singleMode + 登录态拦掉。 */ ?>
    <?php if (!hp_single_mode() || is_logged_in()): ?>
    <?php /* 放这里而不是后台：
               用户要求「在主页上直接改」，这一颗就是那个入口。
               Ctrl+E 同效。
               图标用一条「铅笔 + 斜杠」的复合形状切换：实心时是铅笔，
               再点变成「关」，避免用户不知道该点哪里退出。
               具体由 CSS 的 [aria-pressed] 控制显示哪一枚。 */ ?>
      <button class="tbm-btn tbm-btn--edit" id="editToggle" type="button"
              aria-pressed="false" title="编辑模式（Ctrl+E）">
        <span class="tbm-ico tbm-ico--on" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </span>
        <span class="tbm-ico tbm-ico--off" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </span>
        <span class="sr-only" id="editToggleLabel">编辑模式</span>
      </button>
      <?php endif; /* 单人模式未登录不显示编辑开关 */ ?>
      <?php if ($__isSuper): /* 管理员：改密码按钮左侧的「管理齿轮」。
               后台只有 admin 能进。普通账号在它该在的位置看到的是自己的用户名。 */ ?>
      <a class="tbm-btn tbm-btn--login tbm-btn--quiet tbm-btn--gear" href="admin.php" title="管理后台" aria-label="管理后台">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.11a1.7 1.7 0 0 0-1.11-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.11a1.7 1.7 0 0 0 1.55-1.11 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.11a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.11a1.7 1.7 0 0 0-1.55 1z"/></svg>
        </span>
      </a>
      <?php endif; ?>
      <?php if ($__isAdmin): /* 改密码不单独占一个按钮：管理员与普通用户
               都必须从用户名菜单里的「修改密码」进入（见下），这样两者
               顶栏长得一模一样，管理员只多一个齿轮。这里保留 DOM 是给
               ui.js 的 #acctPass -> #passToggle.click() 转发当目标。 */ ?>
      <button class="tbm-btn tbm-btn--login tbm-btn--quiet tbm-btn--acct hp-hide" id="passToggle" type="button" title="修改密码" aria-haspopup="dialog" aria-controls="passBox">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M11 12l8-8"/><path d="M17 6l2 2"/><path d="M14 9l2 2"/></svg>
        </span>
        <span class="tbm-login-text">改密码</span>
      </button>
      <?php endif; ?>
      <?php if ($__isAdmin): /* 账户菜单：用户名的悬停菜单，装「修改密码 / 退出登录」。
               退出按钮不再单独显示，收进这里。token 防外站 <img> 踢人。 */ ?>
      <?php $__me = current_user(); $__uname = (string)($__me['username'] ?? ''); ?>
      <div class="tb-menu tb-menu--acct" id="acctMenu">
        <button class="tbm-btn tbm-btn--login tbm-btn--quiet tbm-btn--acct-name" type="button"
                title="<?= h($__uname) ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="acctPanel">
          <span class="tbm-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
          </span>
          <span class="tbm-login-text acct-name-text"><?= h($__uname) ?></span>
        </button>
        <div class="tbm-panel tbm-panel--acct" id="acctPanel" role="menu" hidden>
          <button class="tbm-opt" type="button" role="menuitem" id="acctPass">
            <span class="tbo-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M11 12l8-8"/><path d="M17 6l2 2"/><path d="M14 9l2 2"/></svg></span>
            <span class="tbo-body"><span class="tbo-name">修改密码</span></span>
          </button>
          <a class="tbm-opt" role="menuitem" href="?logout=<?= h(csrf_token()) ?>">
            <span class="tbo-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg></span>
            <span class="tbo-body"><span class="tbo-name">退出登录</span></span>
          </a>
        </div>
      </div>
      <?php endif; ?>
    <?php if (!$__isAdmin): ?>
      <?php /* 游客登录入口：点击弹卡片，不再跳 login.php。
               注册 / 完整登录页仍在登录卡片底部留链接。 */ ?>
      <button class="tbm-btn tbm-btn--login" id="loginToggle" type="button" title="登录" aria-haspopup="dialog" aria-controls="loginBox">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
        </span>
        <span class="tbm-login-text">登录</span>
      </button>
    <?php endif; ?>
  </div>
</header>

    <?php if ($useNav): ?>
    <?php /* 左侧侧边栏：点击平滑滚动到分组锚点，滚动时高亮当前组。
              V1.3.8 起每一项都带图标 —— 分类是用户自建的，没有现成图标，
              所以按组名匹配 inc/groupnav.php 里的内置图标库；
              一列纯文字的分类栏没有视觉锚点，扫起来很累。
              图标只加在侧栏，顶部标签保持纯文字（横排更紧凑）。 */ ?>
    <nav class="gnav gnav--side" id="gnav" data-mode="side" aria-label="分组导航">
      <?php /* 头部单独一行：分组标题 + 收起按钮。
               收起后侧栏只剩图标（68px），主区跟着铺开 —— 这是
               Linear / Notion 的做法，也是「侧栏占地方」这个矛盾
               唯一能由用户自己解除的开关。 */ ?>
      <div class="gnav-head">
        <p class="gnav-title">分组</p>
        <?php /* 登录用户可以在自己那份库里建分组。接口 group_add 早就开着，
                 但前台一直没有入口 —— 功能做了却够不着，这里补上。
                 双击任意组名可以改名，浮层里还有删除（链接会回到未分组）。
                 游客也输出：guest.js 把建组/删组/改名都拦在本地沙盒里，
                 改动只落 localStorage —— 编辑能力不该因登录状态而缺一块。 */ ?>
        <button class="gnav-fold gnav-add" id="grpAdd" type="button" title="新建分组" aria-label="新建分组">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/>          </svg>
        </button>
        <button class="gnav-fold" type="button" data-nav-fold aria-expanded="true"
                aria-controls="gnav" aria-label="收起侧栏" title="收起侧栏">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 6.5L8 12l5.5 5.5"/><path d="M18.5 6.5L13 12l5.5 5.5" opacity=".38"/></svg>
        </button>
      </div>
      <?php /* 移动端专用的折叠触发条。
               窄屏下分组有十几个，横着排必然要横向滑动 —— 滑到一半还
               看不清是第几组。改成「一行显示当前分组 + 点开下拉全选」：
               收起时只占一行、永远知道自己在哪组，展开时是竖排列表、
               手指直接上下滑，不用横向找。桌面端这条不显示。 */ ?>
      <button class="gnav-mtoggle" type="button" id="gnavMToggle"
              aria-expanded="false" aria-controls="gnav-track"
              <?php if (isset($ordered[0])): ?>style="--gt-h:<?= hp_group_hue($ordered[0]['name'], (string)($ordered[0]['icon'] ?? '')) ?>"<?php endif; ?>>
        <?php /* 当前分组的颜色由 ui.js 同步到 --gt-h，左侧那条 3px 竖条跟着变色。
               这里不做成彩色图标块：折叠条正下方紧挨着组标题，两个同款图标
               上下叠着会像同一件事说了两遍。竖条是「当前位置」的语言，
               和桌面侧栏选中项左边那条是同一个东西。 */ ?>
        <span class="gnav-mcurrent" id="gnavMCurrent">分组</span>
        <span class="gnav-mcount" id="gnavMCount"></span>
        <svg class="gnav-mchev" viewBox="0 0 24 24" width="16" height="16" fill="none"
             stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M6 9l6 6 6-6"/>
        </svg>
      </button>
      <?php /* 窄屏下 .gnav-head 整块被隐藏，桌面那颗「+」看不见。
               分组入口不能只在宽屏有 —— 这里给折叠条配一颗同款按钮。
               游客同样输出（改动落本地沙盒，见 guest.js）。 */ ?>
      <button class="gnav-madd" id="grpAddM" type="button" title="新建分组" aria-label="新建分组">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      </button>
      <div class="gnav-track" id="gnav-track">
        <?php foreach ($ordered as $gi => $g):
          $nm = $g['name'] !== '' ? $g['name'] : '全部';
          /* 分组色相：图标块、选中的底色、计数徽章、组标题、卡片悬停
             全用这一个数。写在 <a> 上而不是图标块上，是因为选中态的
             底色属于 <a> 自己 —— CSS 变量只往下继承，写在子元素上
             父级就取不到。 */
          $ghue = hp_group_hue($nm, (string)($g['icon'] ?? '')); ?>
          <a class="gnav-item<?= $gi === 0 ? ' is-active' : '' ?>" href="#g<?= $gi ?>"
             data-gjump="<?= $gi ?>" data-group="<?= (int)$g['id'] ?>"
             style="--gt-h:<?= $ghue ?>"
             aria-current="<?= $gi === 0 ? 'true' : 'false' ?>"
             title="<?= h($nm) ?>">
            <span class="gnav-ico" aria-hidden="true"><?= hp_group_icon_markup($nm, (string)($g['icon'] ?? ''), 15) ?></span>
            <span class="gnav-name"><?= h($nm) ?></span>
            <span class="gnav-count"><?= count($g['items']) ?></span>
            <?php /* 编辑态下的删除入口。放在 <a> 里用 <span role=button> 而不是
                     <button>：a 里嵌 button 是非法 HTML，且会破坏这一行的
                      整行点击跳转/拖拽。span 点击时 stopPropagation 即可。
                     id=0 是「未分组/全部」，不是真分组，不给删。
                     游客也输出：删除走本地沙盒，见 guest.js。 */ ?>
            <?php if ((int)$g['id'] > 0): ?>
            <span class="gnav-del" role="button" tabindex="0"
                  data-del-group="<?= (int)$g['id'] ?>"
                  title="删除分组「<?= h($nm) ?>」" aria-label="删除分组：<?= h($nm) ?>">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/></svg>
              <span class="gnav-del-text">删除</span>
            </span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php /* 侧栏最下方：版权与许可声明（用户要求只留侧栏）。
               本项目以 MIT 协议开源，所以这里**不能写「保留所有权利」** ——
               那句话与 MIT 直接冲突（MIT 恰恰是授权他人使用、修改、
               再分发）。开源项目的通行写法是：年份 + 作者 + 协议名，
               协议名链到根目录的 LICENSE 全文（rel="license" 是标准标注）。

               换协议时改两处：这里的名字，以及根目录 LICENSE 的正文。 */ ?>
      <?php /* 库切换：站点默认（公共）/ 我的（私人）。
               只有管理员看得到这两份 —— 普通用户的数据本来就在自己名下，
               没有「切到哪一份」这回事。
               单人模式下整块不输出：全站只有一份库（站长那份），
               留着一个点了也不会变的切换器，只会让人以为点坏了。 */ ?>
      <?php if (!hp_single_mode() && ($onMine || $__isSuper)): ?>
      <div class="gnav-lib" role="group" aria-label="数据范围">
        <a class="gnav-lib-btn<?= $onMine ? '' : ' is-on' ?>" href="./">站点库</a>
        <a class="gnav-lib-btn<?= $onMine ? ' is-on' : '' ?>" href="?lib=mine">我的库</a>
      </div>
      <?php endif; ?>
      <?php /* 页脚版权区，两行 + 可选备案。
               第一行只放「谁 + 哪个版本」，第二行是协议与项目入口，整体压淡一档。

               为什么不写「Made By izyw」：版权惯例本身是「© 年份 权利人」，
               加 Made By 既不增加法律含义，又让中文界面里多出一截英文；
               版本号跟在署名后面（用 · 分隔）比单独一行更像一句话，也省一行高度。 */ ?>
      <div class="gnav-foot">
        <p class="gnav-copy">&copy; <?= h($copyYear) ?> <?php if ($copyAuthor !== ''): ?><a class="gnav-owner" href="<?= h($authorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($copyAuthor) ?></a><?php else: ?><span class="gnav-owner"><?= h($copyOwner) ?></span><?php endif; ?><span class="gnav-dot" aria-hidden="true">·</span><span class="gnav-ver">Vnav <?= h(hp_version()) ?></span></p>
        <p class="gnav-rights">
          <a class="gnav-license" href="LICENSE" rel="license">MIT License</a><span class="gnav-dot" aria-hidden="true">·</span><a class="gnav-link" href="<?= h($authorGh) ?>" target="_blank" rel="noopener noreferrer">项目主页</a>
        </p>
        <?php if ($icpBeian !== ''): ?>
          <?php /* 备案号与开源协议是两回事：前者是国内主机的合规要求，
                   与是否开源无关。留空就整行不出现。 */ ?>
          <p class="gnav-beian">
            <a class="gnav-link" href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($icpBeian) ?></a>
          </p>
        <?php endif; ?>
      </div>
    </nav>
    <?php endif; ?>

<main class="stage" id="stage">

  <?php /* 切到私人库时给一条明示。少了它，管理员看到链接突然变少
           会以为是数据丢了 —— 这份数据只有他自己看得到。 */ ?>
  <?php if ($onMine): ?>
  <p class="mylib-note">
    你正在看自己的私人库，这里的链接只有你能看到。
    <a href="./">回到站点库</a>
  </p>
  <?php endif; ?>
  <?php /* 普通用户注册时从站点默认库克隆了链接和分组作为起点。
           没有这条提示，他会以为自己改的是所有人共用的那份 ——
           第一次使用最容易踩的认知坑。关掉一次写 user 级 setting。 */ ?>
  <?php if ($__isAdmin && !$__isSuper && !setting('clone_hint_seen')): ?>
  <p class="mylib-note clone-note" id="cloneNote">
    这里的链接和分组是从站点默认库复制到你账号里的，你做的任何改动只对你自己可见，不会影响其他访客。
    <button type="button" class="clone-note-x" id="cloneNoteClose">知道了</button>
  </p>
  <?php endif; ?>
  <?php if (!$ordered): ?>
    <div class="empty">
      <p class="empty-title">这里还没有东西</p>
      <p class="empty-sub"><?= $__isAdmin ? '按 Ctrl+V 粘贴任意网址，或者进「管理」页添加。' : '登录后即可添加你的常用站点。' ?></p>
      <?php /* 普通账号进不了后台，所以这里不能指 admin.php（会 403）。
               登录后回到首页，用顶栏的编辑按钮或 Ctrl+V 添加。 */ ?>
      <?php if ($__isAdmin): ?>
        <a class="btn-primary" href="./">去添加</a>
      <?php else: /* 游客走顶栏那套快捷登录浮层，不再跳完整登录页 */ ?>
        <button type="button" class="btn-primary" id="emptyLoginBtn">登录开始</button>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <div class="groups">
      <?php
      /* 每个 <section> 的可访问名只能有一个来源，否则 HTML 里会出现
         两个 aria-labelledby（后一个被忽略，语义就错了）。
         V1.3.0：导航统一成「目录」，不再有 tabpanel 语义 —— 所有分组
         同时可见，标签只是跳转入口。所以这里统一走 aria-labelledby 指 h2，
         组名是空的（唯一分组）才退回 aria-label。 */
      foreach ($ordered as $gi => $g):
        /* 侧栏那个循环里的 $ghue 循环结束就停在最后一组上了，
           这里必须按当前组重算 —— 复用会得到「所有分组同一个颜色」。 */
        $ghue = hp_group_hue($g['name'], (string)($g['icon'] ?? ''));
        if ($g['name'] !== '') {
          $a11y = 'aria-labelledby="gh' . $gi . '"';
        } else {
          $a11y = 'aria-label="未分组链接"';
        }
      ?>
        <section class="group" id="g<?= $gi ?>" style="--gt-h:<?= $ghue ?>" <?= $a11y ?>>
          <?php if ($g['name'] !== ''): ?>
            <?php /* 组标题前的彩色图标块：和侧栏是同一枚图标、同一个色相。
                     两边重复不是偷懒 —— 侧栏与正文的颜色对上之后，
                     「紫色那栏 = 紫色那组」才成立，颜色才成为导航线索。
                     组名前的 6px 圆点因此退役。 */ ?>
            <div class="group-head">
              <span class="gh-ico" aria-hidden="true"><?= hp_group_icon_markup($g['name'], (string)($g['icon'] ?? ''), 13) ?></span>
              <h2 id="gh<?= $gi ?>" class="group-name"><?= h($g['name']) ?></h2>
              <span class="group-count"><?= count($g['items']) ?></span>
              <span class="group-rule" aria-hidden="true"></span>
            </div>
          <?php endif; ?>

          <ul class="links" data-group="<?= (int)$g['id'] ?>">
            <?php foreach ($g['items'] as $i => $l):
              $host = host_of($l['url']);
              $hue  = hue_of($host);
              $init = initials_of($l['title']);
              $isHidden = (int)($l['hidden'] ?? 0) === 1;
              /* 图标文件可能被手动清理/迁移丢失，先确认存在再输出，
                 否则页面会留一个破图空框。缺失时自动降级为首字母头像。
                 remote 是「本机网络取到的外链图标」（服务器抓不到的站点），
                 不落地，直接引用 https 地址；加载失败时由内联 error
                 兜回首字母 —— 访客没开代理时那张图本来就下不来。 */
              $isRemote  = (string)$l['icon_kind'] === 'remote'
                  && preg_match('~^https://~i', (string)$l['icon']);
              $hasIcon = $isRemote
                  || ($l['icon'] !== ''
                      && $l['icon_kind'] === 'file'
                      && is_file(HP_ROOT . '/' . $l['icon']));
            ?>
              <li class="link-item" style="--i:<?= $i ?>;--hue:<?= $hue ?>" data-id="<?= (int)$l['id'] ?>"
                  data-title="<?= h($l['title']) ?>"
                  data-url="<?= h($l['url']) ?>"
                  data-descr="<?= h($l['descr']) ?>"
                  data-group="<?= (int)($l['group_id'] ?? 0) ?>"
                  data-hidden="<?= $isHidden ? '1' : '0' ?>"
                  data-icon="<?= h($l['icon']) ?>">
                <?php /* 勾选框：只在编辑模式显示。放在 <a> 外面，
                         否则点它会把链接点开（和当年放把手是同一个理由）。

                         原来是六点拖动把手，V1.0.0 换成勾选框：
                         把手解决的是「从哪儿拖」，而整张卡片本来就能拖
                         （鼠标 6px 阈值、触摸长按 260ms），把手是多余的；
                         勾选框解决的是「怎么一次改十条」，那才是编辑时
                         真正频繁的动作。 */ ?>
                <?php if ($__isAdmin): ?>
                  <label class="pick-box">
                    <input type="checkbox" data-pick="<?= (int)$l['id'] ?>">
                    <span class="pick-mark" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
                    </span>
                    <span class="sr-only">选择「<?= h($l['title']) ?>」</span>
                  </label>
                <?php endif; ?>
                <a class="link" href="<?= h($l['url']) ?>" <?= $newTab ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                   data-href="<?= h($l['url']) ?>">
                  <span class="link-icon<?= $hasIcon ? ' has-img' : '' ?>" aria-hidden="true">
                    <?php if ($isRemote): ?>
                      <?php /* 首字母先铺在底下：外链图标一旦加载失败（访客没代理、
                               源站防盗链），error 里撤掉 img 与 has-img，
                               露出底下这层同色相头像，不留破图。 */ ?>
                      <span class="initials"><?= h($init) ?></span>
                      <img src="<?= h($l['icon']) ?>" alt="" width="24" height="24" decoding="async"
                           referrerpolicy="no-referrer"
                           onerror="this.parentNode.classList.remove('has-img');this.remove();">
                    <?php elseif ($hasIcon): ?>
                      <?php /* 图标是首屏视觉的一部分，且只有几 KB：一律 eager。
                               用 lazy 会让首屏出现一片空白方块（无头截图与
                               部分浏览器下尤其明显），得不偿失。 */ ?>
                      <img src="<?= h($l['icon']) ?>" alt="" width="24" height="24" decoding="async">
                    <?php else: ?>
                      <span class="initials"><?= h($init) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="link-body">
                    <span class="link-title"><?= h($l['title']) ?></span>
                    <?php if ($showDesc): ?>
                      <span class="link-sub"><?= h($l['descr'] !== '' ? $l['descr'] : $host) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php if ($isHidden && hp_single_mode()): ?>
                    <?php /* 只有单人模式才有隐藏链接；且只有登录后才可能看到这个角标 */ ?>
                    <span class="link-priv" title="隐藏链接，仅登录可见" aria-label="隐藏链接">
                      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
                    </span>
                  <?php endif; ?>
                  <?php if ($__isAdmin): ?>
                    <span class="link-edit" data-edit="<?= (int)$l['id'] ?>" role="button" tabindex="0" aria-label="编辑 <?= h($l['title']) ?>">
                      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    </span>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>

      <?php /* 主区这里只放后台填的「页脚寄语」。
               完整的版权声明按用户要求统一收在侧栏底部，两处都有会造成
               同一页出现两个「©」，反而显得没收拾。 */ ?>
      <?php if ($footer !== ''): ?>
        <footer class="foot"><?= h($footer) ?></footer>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</div>

<?php /* 批量操作条：勾了卡片才浮出来，固定在视口底部。
       为什么不做成吸顶的横条：吸顶会占掉主区一条，而且和分组标题抢位置；
       底部浮层不参与布局，卡片一多也不会挡到正在看的内容
       （Gmail / Notion 的批量条都是这个形态）。
       游客也输出：删除/移动走本地沙盒（guest.js），会即时生效；
       「拉取图标/信息」对游客无意义，但那两个按钮本来也只是可选动作。 */ ?>
<div class="bulkbar" id="bulkBar" hidden role="region" aria-label="批量操作">
  <label class="bulkbar-all">
    <input type="checkbox" id="bulkAll">
    <span class="pick-mark pick-mark--sm" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>
    </span>
    <span>全选</span>
  </label>
  <span class="bulkbar-n" id="bulkCount">已选 0 项</span>
  <span class="bulkbar-sp" aria-hidden="true"></span>
  <select class="bulkbar-sel" id="bulkGroup" aria-label="移到哪个分组">
    <option value="">移到分组…</option>
    <?php /* 跳过 id=0（未分组）：bulk 的 move 要求目标组真实存在，
             把「未分组」列进去点一下就报错，不如不列。 */ ?>
    <?php foreach ($ordered as $g): ?>
      <?php if ((int)$g['id'] <= 0) continue; ?>
      <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="button" class="bulkbar-btn" data-bulk="icon" title="重新抓取图标">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>
    <span>拉取图标</span>
  </button>
  <button type="button" class="bulkbar-btn" data-bulk="meta" title="重新抓取标题、描述与图标">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></svg>
    <span>拉取信息</span>
  </button>
  <button type="button" class="bulkbar-btn is-danger" data-bulk="delete" title="删除选中的链接">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
    <span>删除</span>
  </button>
  <button type="button" class="bulkbar-x" data-bulk="clear" aria-label="取消选择">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
</div>

<?php /* 粘贴添加浮层：游客也输出。
         未登录时改动只落到 localStorage（assets/guest.js 的沙盒），
         服务端一个字节都收不到，所以开放它不等于开放写权限。
         它的兄弟浮层（就地编辑 / 快捷键面板）仍在下面的 is-admin 条件里。 */ ?>
<div class="paste-scrim" id="pasteScrim" hidden></div>
<div class="paste-box" id="pasteBox" role="dialog" aria-modal="true" aria-labelledby="pasteTitle" hidden>
  <div class="paste-head">
    <h2 id="pasteTitle">快速添加</h2>
    <button type="button" class="paste-close" id="pasteClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>

  <form class="paste-form" id="pasteForm" novalidate>
    <div class="paste-preview">
      <span class="link-icon paste-icon" id="pasteIcon" aria-hidden="true"><span class="initials">…</span></span>
      <div class="paste-preview-text">
        <span class="paste-status" id="pasteStatus" aria-live="polite">正在识别…</span>
        <span class="paste-host" id="pasteHost"></span>
      </div>
    </div>

    <label class="f-label" for="pUrl">网址</label>
    <?php /* 与编辑浮层保持一致：抓取按钮内嵌在网址框右侧。 */ ?>
    <div class="url-field">
      <input class="f-input" id="pUrl" name="url" type="url" inputmode="url" required autocomplete="off" spellcheck="false">
      <button type="button" class="btn-fetch" id="pFetch" title="从网址重新抓取名称、图标和描述">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/></svg>
        <span class="btn-fetch-text">抓取名称/图标/描述</span>
        <span class="spinner" hidden aria-hidden="true"></span>
      </button>
    </div>

    <label class="f-label" for="pTitle">名称</label>
    <input class="f-input" id="pTitle" name="title" type="text" required maxlength="60" autocomplete="off">

    <label class="f-label" for="pDescr">描述</label>
    <textarea class="f-input f-textarea" id="pDescr" name="descr" rows="2" maxlength="200" autocomplete="off" placeholder="一句话说明这个站点是做什么的（可留空）"></textarea>

    <label class="f-label" for="pGroup">分组</label>
    <select class="f-input" id="pGroup" name="group_id">
      <option value="0">未分组</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php /* 隐藏开关：滑块，与后台添加面板同一个控件（.switch / .sw-track）。
             以前这里是原生 checkbox —— 后台改了滑块，前台还留着方框勾，
             同一个功能两副长相。默认关（公开），因为绝大多数链接是给
             自己看的公开收藏，默认隐藏会让人找不到刚加的那条。 */ ?>
    <?php if (hp_single_mode()): /* 单人模式新增时可直接设为隐藏 */ ?>
    <div class="switch-row switch-row--tight">
      <div>
        <p class="sw-label">隐藏链接</p>
        <p class="sw-desc">关闭时所有访客可见</p>
      </div>
      <label class="switch">
        <input type="checkbox" id="pHidden" name="hidden">
        <span class="sw-track" aria-hidden="true"></span>
        <span class="sr-only">隐藏链接（仅登录后可见）</span>
      </label>
    </div>
    <?php endif; ?>

    <p class="f-error" id="pasteError" role="alert" hidden></p>

    <div class="paste-actions">
      <button type="button" class="btn-ghost" id="pasteCancel">取消</button>
      <button type="submit" class="btn-primary" id="pasteSave">
        <span class="btn-text">添加</span>
        <span class="spinner" hidden aria-hidden="true"></span>
      </button>
    </div>
  </form>
</div>

<!-- ============ 登录浮层（游客） ============
     右上角「登录」和空态「登录开始」都走这里，不再跳完整登录页。
     注册入口留在卡片底部；渐进式验证码图片仍由 login.php 承载。
     必须放在 is-admin 条件块之外 —— 游客才用得到它。 -->
<div class="paste-scrim" id="loginScrim" hidden></div>
<div class="paste-box login-box" id="loginBox" role="dialog" aria-modal="true" aria-labelledby="loginTitle" hidden>
  <div class="paste-head">
    <h2 id="loginTitle">登录</h2>
    <button type="button" class="paste-close" id="loginClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <form class="paste-form" id="loginForm" novalidate>
    <p class="paste-status">登录后即可管理你的分组和链接，换设备也同步。</p>
    <label class="f-label" for="loginUser">账号</label>
    <input class="f-input" id="loginUser" type="text" autocomplete="username" required>
    <label class="f-label" for="loginPass">密码</label>
    <input class="f-input" id="loginPass" type="password" autocomplete="current-password" required>
    <div class="login-cap" id="loginCap" hidden>
      <label class="f-label" for="loginCaptcha">验证码</label>
      <div class="login-cap-row">
        <input class="f-input" id="loginCaptcha" type="text" inputmode="latin" autocomplete="off" maxlength="8" required>
        <img id="loginCapImg" src="" alt="验证码" width="108" height="38">
        <button type="button" class="login-cap-new" id="loginCapNew" title="换一张" aria-label="换一张验证码">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/></svg>
        </button>
      </div>
    </div>
    <p class="f-error" id="loginErr" role="alert" hidden></p>
    <div class="paste-actions">
      <button type="button" class="btn-ghost" id="loginCancel">取消</button>
      <button type="submit" class="btn-primary" id="loginSave">
        <span class="btn-text">登录</span>
        <span class="spinner" hidden aria-hidden="true"></span>
      </button>
    </div>
    <?php if (!hp_single_mode()): ?>
    <p class="login-foot">还没有账号？<a href="login.php?mode=register">注册一个</a></p>
    <?php endif; ?>
  </form>
</div>

<?php /* 编辑浮层 / 快捷键面板 / 分组浮层对所有人输出。
       游客的写操作被 guest.js 拦在本地沙盒（只落 localStorage，不上传），
       开放这些浮层不等于开放服务端写权限 —— 和上面「粘贴添加浮层」
       同一个道理。之前把它们关在 is-admin 里，游客进了编辑模式却
       点卡片没反应、建不了分组（实测 bug），就是这里漏放。
       唯一例外是「修改密码」：游客没有账号，仍留在 is-admin 里。 */ ?>
<!-- ============ 就地编辑浮层 ============
     卡片上点铅笔走这里，不再跳后台。表单字段和后台那个编辑器
     保持一致，但少了「分组下拉以外的所有跳转」。 -->
<div class="paste-scrim" id="editScrim" hidden></div>
<div class="paste-box edit-box" id="editBox" role="dialog" aria-modal="true" aria-labelledby="editTitle" hidden>
  <div class="paste-head">
    <h2 id="editTitle">编辑链接</h2>
    <button type="button" class="paste-close" id="editClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>

  <form class="paste-form" id="editForm" novalidate>
    <input type="hidden" id="eId" value="">

    <div class="paste-preview">
      <span class="link-icon paste-icon" id="editIcon" aria-hidden="true"><span class="initials">…</span></span>
      <div class="paste-preview-text">
        <span class="paste-status" id="editStatus">可直接改，改完回车保存</span>
        <span class="paste-host" id="editHost"></span>
      </div>
    </div>

    <label class="f-label" for="eUrl">网址</label>
    <?php /* 抓取按钮内嵌在网址框右侧：它是「按这个网址重读一次」的附属动作，
             跟网址是同一件事，所以放进同一个框里；以前挂在名称标签右边，
             悬空一格、又压着输入框，看着像没摆好。 */ ?>
    <div class="url-field">
      <input class="f-input" id="eUrl" name="url" type="url" inputmode="url" required autocomplete="off" spellcheck="false">
      <button type="button" class="btn-fetch" id="eFetch" title="从网址重新抓取名称、图标和描述">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/></svg>
        <span class="btn-fetch-text">抓取名称/图标/描述</span>
        <span class="spinner" hidden aria-hidden="true"></span>
      </button>
    </div>

    <label class="f-label" for="eTitle">名称</label>
    <input class="f-input" id="eTitle" name="title" type="text" required maxlength="60" autocomplete="off">

    <label class="f-label" for="eDescr">描述</label>
    <textarea class="f-input f-textarea" id="eDescr" name="descr" rows="2" maxlength="200" autocomplete="off" placeholder="一句话说明这个站点是做什么的（可留空）"></textarea>

    <label class="f-label" for="eGroup">分组</label>
    <select class="f-input" id="eGroup" name="group_id">
      <option value="0">未分组</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if (hp_single_mode()): /* 单人模式才有隐藏链接；多人模式保持下线 */ ?>
    <div class="switch-row switch-row--tight">
      <div>
        <p class="sw-label">隐藏链接</p>
        <p class="sw-desc">关闭时所有访客可见</p>
      </div>
      <label class="switch">
        <input type="checkbox" id="eHidden" name="hidden">
        <span class="sw-track" aria-hidden="true"></span>
        <span class="sr-only">隐藏链接（仅登录后可见）</span>
      </label>
    </div>
    <?php endif; ?>

    <p class="f-error" id="editError" role="alert" hidden></p>

    <div class="paste-actions paste-actions--split">
      <button type="button" class="btn-danger btn-del" id="editDelete">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg>
        删除
      </button>
      <div class="paste-actions-right">
        <button type="button" class="btn-ghost" id="editCancel">取消</button>
        <button type="submit" class="btn-primary" id="editSave">
          <span class="btn-text">保存</span>
          <span class="spinner" hidden aria-hidden="true"></span>
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ============ 快捷键面板（Ctrl+/） ============ -->
<div class="paste-scrim" id="keysScrim" hidden></div>
<div class="keys-box" id="keysBox" role="dialog" aria-modal="true" aria-labelledby="keysTitle" hidden>
  <div class="paste-head">
    <h2 id="keysTitle">键盘快捷键</h2>
    <button type="button" class="paste-close" id="keysClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <div class="keys-body">
    <section class="keys-sec">
      <h3>添加与搜索</h3>
      <dl class="keys-list">
        <div><dt><kbd>Ctrl</kbd><kbd>V</kbd></dt><dd>粘贴网址，直接添加（只在登录后生效）</dd></div>
        <div><dt><kbd>Ctrl</kbd><kbd>K</kbd></dt><dd>聚焦搜索框</dd></div>
        <div><dt><kbd>/</kbd></dt><dd>聚焦搜索框（同 Ctrl+K）</dd></div>
        <div><dt><kbd>Enter</kbd></dt><dd>搜索框内直接搜索</dd></div>
      </dl>
    </section>
    <section class="keys-sec">
      <h3>编辑</h3>
      <dl class="keys-list">
        <div><dt><kbd>Ctrl</kbd><kbd>E</kbd></dt><dd>开关编辑模式（拖动排序、就地改）</dd></div>
        <div><dt>按住卡片拖动</dt><dd>换位置，松手即保存（手机上长按约 0.3 秒再拖）</dd></div>
        <div><dt><kbd>Ctrl</kbd><kbd>S</kbd></dt><dd>保存当前编辑</dd></div>
        <div><dt><kbd>Alt</kbd><kbd>↑</kbd> / <kbd>Alt</kbd><kbd>↓</kbd></dt><dd>把选中的链接上移 / 下移一位</dd></div>
        <div><dt><kbd>Ctrl</kbd><kbd>Z</kbd></dt><dd>撤销最近一次移动 / 拖动排序 / 删除</dd></div>
        <div><dt><kbd>Esc</kbd></dt><dd>关掉弹层 / 取消编辑（保留已输入内容）</dd></div>
      </dl>
    </section>
    <section class="keys-sec">
      <h3>其他</h3>
      <dl class="keys-list">
        <div><dt><kbd>Ctrl</kbd><kbd>/</kbd></dt><dd>打开这个面板</dd></div>
        <div><dt><kbd>?</kbd></dt><dd>同 Ctrl+/</dd></div>
        <div><dt><kbd>Tab</kbd></dt><dd>依次跳到下一个可操作元素</dd></div>
        <div><dt><kbd>Enter</kbd></dt><dd>在输入框内：有下一个输入框就跳过去，没有就保存</dd></div>
      </dl>
    </section>
  </div>
  <p class="keys-foot">输入框内按 Esc 先清空再退出；有未保存改动时会先问一句。</p>
  <?php /* 右下角提示关掉之后的召回入口。没有它，「不再提示」在用户眼里
           就等同于「提示坏了」—— 这是唯一能把它叫回来的地方。 */ ?>
  <p class="keys-foot" id="tipResetRow" hidden>
    右下角的小提示已被关闭。
    <button type="button" class="keys-reset" id="tipReset">重新显示</button>
  </p>
</div>

<!-- ============ 修改密码浮层（仅登录用户） ============
     admin.php 里那个表单走同一个 user_pass 接口，但普通用户进不去后台，
     所以这里必须单独有一份，否则他注册后一辈子改不了自己的密码。
     游客没有账号密码，这一段保持 is-admin 专属。 -->
<?php if ($__isAdmin): ?>
<div class="paste-scrim" id="passScrim" hidden></div>
<div class="paste-box pass-box" id="passBox" role="dialog" aria-modal="true" aria-labelledby="passTitle" hidden>
  <div class="paste-head">
    <h2 id="passTitle">修改密码</h2>
    <button type="button" class="paste-close" id="passClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <form class="paste-form" id="passForm" novalidate>
    <p class="paste-status">改的是当前账号的登录密码，下次登录起生效。</p>
    <label class="f-label" for="uOld">当前密码</label>
    <input class="f-input" id="uOld" type="password" autocomplete="current-password" required>
    <label class="f-label" for="uNew">新密码（至少 8 位）</label>
    <input class="f-input" id="uNew" type="password" minlength="8" autocomplete="new-password" required>
    <label class="f-label" for="uNew2">再输一次</label>
    <input class="f-input" id="uNew2" type="password" minlength="8" autocomplete="new-password" required>
    <p class="f-error" id="passErr" role="alert" hidden></p>
    <div class="paste-actions">
      <button type="button" class="btn-ghost" id="passCancel">取消</button>
      <button type="submit" class="btn-primary" id="passSave">
        <span class="btn-text">保存</span>
        <span class="spinner" hidden aria-hidden="true"></span>
      </button>
    </div>
  </form>
</div>
<?php endif; /* 改密码浮层到此为止 */ ?>

<!-- ============ 分组新建 / 改名浮层 ============
     侧栏「+」走新建，双击组名走改名；浮层里的删除会让组内链接
     回到「未分组」（外键 ON DELETE SET NULL），不会连带删链接。
     游客也输出：这些动作走 guest.js 本地沙盒，只落 localStorage。 -->
<div class="paste-scrim" id="grpScrim" hidden></div>
<div class="paste-box grp-box" id="grpBox" role="dialog" aria-modal="true" aria-labelledby="grpTitle" hidden>
  <div class="paste-head">
    <h2 id="grpTitle">新建分组</h2>
    <button type="button" class="paste-close" id="grpClose" aria-label="关闭">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <form class="paste-form" id="grpForm" novalidate>
    <label class="f-label" for="grpName">分组名称</label>
    <input class="f-input" id="grpName" type="text" maxlength="24" autocomplete="off" required>
    <p class="paste-status" id="grpHint" hidden>删除后，这个分组里的链接会回到「未分组」，不会被删掉。</p>
    <p class="f-error" id="grpErr" role="alert" hidden></p>
    <div class="paste-actions paste-actions--split">
      <button type="button" class="btn-danger btn-del" id="grpDelete" hidden>删除分组</button>
      <div class="paste-actions-right">
        <button type="button" class="btn-ghost" id="grpCancel">取消</button>
        <button type="submit" class="btn-primary" id="grpSave">
          <span class="btn-text">保存</span>
          <span class="spinner" hidden aria-hidden="true"></span>
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ============ 右下角提示（可永久关闭） ============ -->
<?php
/* 对所有访问者输出。原先只给登录用户，理由是「游客按 Ctrl+V 加不了」——
   但 guest.js 本来就支持本地添加（改动落在 localStorage），这个前提不成立；
   更要紧的是提示压根没渲染给游客，他连「关掉」这个动作都看不到。
   现在游客也能看见、也能关，关掉的标记写在本机（登录用户仍写服务端）。 */
?>
<div class="tip-bubble" id="tipBubble" hidden>
  <button type="button" class="tip-x" id="tipClose" aria-label="关闭提示">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <p class="tip-title">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="M8 10h8M8 14h5"/></svg>
    按 <kbd>Ctrl</kbd>+<kbd>V</kbd> 直接添加链接
  </p>
  <p class="tip-sub">复制任意网址，回到这里粘一下就行。按 <kbd>Ctrl</kbd>+<kbd>/</kbd> 看全部快捷键。</p>
  <div class="tip-act">
    <button type="button" class="tip-more" id="tipKeys">看快捷键</button>
    <button type="button" class="tip-never" id="tipNever">不再提示</button>
  </div>
</div>

<!-- toast 与三个脚本对所有人输出。
     游客没有服务端身份，但 guest.js 要负责把本地沙盒画出来、
     app.js 要挂编辑模式与提示条 —— 少一个，这类页面就是只读的。 -->
<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<!-- 顺序：guest.js 先按本地数据重建侧栏/主区，ui.js 和 app.js 再绑事件。
     反过来的话 ui.js 缓存的 .gnav-item 引用会在重建后失效，
     分组高亮和点击滚动全部失灵（实测坑）。三个都 defer，按文档顺序执行。 -->
<script src="assets/guest.js?v=57" defer></script>
<script src="assets/ui.js?v=53" defer></script>
<script src="assets/app.js?v=56" defer></script>

</body>
</html>
<?php
/* ---------- 条件请求：内容没变就回 304 ----------
 *
 * 为什么放在这里（文件最后一行之后）：
 *   hp_compress_start() 已经用 ob_start 把整页兜进了缓冲区，所以此刻
 *   缓冲区里就是完整 HTML。这里是唯一能「拿到全文、算指纹、再决定
 *   要不要发」的位置 —— 提前算就得再渲染一遍，不值得。
 *
 * 为什么只对游客开：
 *   登录后的页面带后台按钮、隐藏链接角标、data-* 属性，同一 URL 对不同
 *   身份内容不同。给这类响应挂 ETag，一旦中间有共享缓存就会串味
 *   （甲看到乙的页面）。游客看到的永远是同一份公开数据，安全。
 *
 * no-cache 而不是 no-store：
 *   no-store 连存都不许，每次都得传整个 50KB；
 *   no-cache 允许存但每次必须回源验证，内容没变就是几百字节的 304。
 *   本站数据随时可能被改，不能用 max-age 直接放行 —— 会让改动延迟生效。
 *
 * 304 之前必须把缓冲清空：否则 compress 的回调会照样把 body 压出来，
 * 变成「304 却带内容」的错误响应。
 */
if (!$__isAdmin) {
    $__pageBuf = ob_get_contents();
    if (is_string($__pageBuf) && $__pageBuf !== '') {
        $__etag = '"' . substr(md5($__pageBuf), 0, 16) . '"';
        header('ETag: ' . $__etag);
        header('Cache-Control: no-cache');
        // 兼容 W/"..." 形式的弱校验（部分代理会改写）
        $__inm = preg_replace('~^W/~', '', trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))) ?? '';
        if ($__inm !== '' && $__inm === $__etag) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(304);
            exit;
        }
    }
}
