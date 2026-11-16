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

// 访问控制先于一切：被拦的请求不该建 session、不该查库、不该记统计。
$__isAdmin = is_logged_in();   // 能编辑（登录即可）
$__isSuper = is_admin();       // 真正的管理员：后台 / 统计 / 访问控制
$__ip      = hp_client_ip();
$__acl     = hp_acl_check($__ip, $__isAdmin);
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
if ($copyOwner === '') {
    $copyAuthor = trim(setting('author_name', '')) ?: 'izyw';
    $copyOwner  = 'Made By ' . $copyAuthor;
}
/* 项目主页地址：后台没填就用仓库地址兜底。这行本来就该指向「项目的家」，
   留空会让页脚少一行，看起来像没做完。 */
$authorGh = trim(setting('author_github', ''));
if ($authorGh === '') $authorGh = 'https://github.com/izyw/VNav';
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

/* 当前看的是哪一份库。管理员日常看「站点默认」（公共那份），
   想放私人条目就切到自己的库 —— V1.1.0 起这个切换有了明面入口，
   原先只能手敲 ?lib=mine，于是「隐藏链接」那个开关成了唯一手段。
   普通用户不参与：他看的一直是自己的库，没有可切的东西。 */
$onMine = $__isSuper && $dataUid !== 0;

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
    if (!empty($byGroup[$gid])) {
        $ordered[] = [
            'id'    => $gid,
            'name'  => $g['name'],
            // 后台选过的图标优先，空则前台按组名自动匹配（见 hp_group_icon）
            'icon'  => (string)($g['icon'] ?? ''),
            'items' => $byGroup[$gid],
        ];
    }
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
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23F0B429'/><path d='M9 16.5l4.5 4.5L23 11' stroke='%23241500' stroke-width='3.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<?php /* 字体预载只在真正会用到时才发：这两个 woff2 合计 71KB，
         是首屏最大的两块资源。用 crossorigin 是字体请求的硬要求
         （字体按 CORS 规则取，不带这个属性预载会被丢弃再重下）。 */ ?>
<link rel="preload" href="assets/fonts/bricolage.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/instrument.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css?v=43">
<?php /* 主页视觉系统叠在 app.css 之后。app.css 是后台与登录页共用的底座
          （设计令牌 + 按钮 + 表单），不能在上面重写主页样式，否则会连带
          改坏后台。所以主页的推倒重来单独落在 home.css，全部规则挂在
          body.hp 之下 —— 作用域只在主页，后台零影响。 */ ?>
<link rel="stylesheet" href="assets/home.css?v=45">
<?php /* 第六套皮肤 Atlas 单独一个文件，必须排在 home.css 之后：
         它的每条规则都带 html[data-theme='atlas'] 前缀，靠「特异性 +1
         属性选择器」与「后加载」双重压过 home.css 的结构规则。
         顺序反了，它就只能改到颜色，改不动结构。 */ ?>
<link rel="stylesheet" href="assets/skin-atlas.css?v=51">
<link rel="stylesheet" href="assets/skin-frame.css?v=51">
<?php if ($__isAdmin): ?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<?php /* 当前页面显示的是哪一份库。app.js 每次写请求都会带上它，
         保证「看到哪份就改哪份」—— 否则 ?lib=mine 的页面上编辑，
         实际会写进网站默认库。 */ ?>
<meta name="adm-lib" content="<?= $dataUid === 0 ? 'site' : 'mine' ?>">
<?php endif; ?>
<script>
/* 偏好要在首屏之前定，否则会先渲染默认形态/皮肤再被 JS 改，出现明显闪烁。
   这里放一段同步内联脚本，读 localStorage 后立刻写 documentElement.dataset
   —— 早于 CSS 首帧。白名单从 PHP 注入，新增皮肤不必回来改这段。 */
(function () {
  var el = document.documentElement;
  window.__VNAV = {
    guest: <?= is_logged_in() ? 'false' : 'true' ?>,
    /* 右下角提示是否已被永久关闭。以前只写进服务端、前端从不读，
       等于换了设备或清了缓存提示又冒出来；现在两边一起认。 */
    tipOff: <?= setting('hint_dismissed', '0') === '1' ? 'true' : 'false' ?>
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
    if (t && keys.indexOf(t) !== -1) el.dataset.theme = t;

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
<body class="hp <?= $__isAdmin ? 'is-admin' : 'is-guest' ?><?= $onMine ? ' is-mylib' : '' ?>">

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
    <span class="brand-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4 4L19 7"/></svg>
    </span>
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

      <form class="search" role="search" action="<?= h($action) ?>" method="get" target="_blank">
        <label for="q" class="sr-only">搜索</label>
        <svg class="search-icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/></svg>
        <input id="q" name="q" type="search" placeholder="搜索 <?= h($engines[$engine]['label']) ?>" autocomplete="off" enterkeyhint="search">
        <?php /* 快捷键提示：Homepage / Dashy 这些起始页都有，用户不用翻
                 快捷键面板就知道能按 Ctrl+K。聚焦后它淡出，把位置让给输入。
                 窄屏隐藏 —— 手机没有物理键盘，它只会挡住文字。 */ ?>
        <kbd class="search-kbd" aria-hidden="true">Ctrl K</kbd>
      </form>
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

    <?php /* 编辑模式开关。对**所有访问者**开放：
             登录用户改的是自己账号里的数据，游客改的是本机沙盒 ——
             两者在 UI 上走同一条路径，区别只在保存位置。 */ ?>
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
      <?php if ($__isSuper): /* 后台只有 admin 能进；普通账号这里什么都不输出 */ ?>
      <?php /* 未登录时这里叫「登录」，登录后必须换名字 ——
               同一个位置、同一个形状，只换字和链接。原先登录后只剩一枚
               没有文字的齿轮，看起来像「登录按钮消失了」，而不是
               「我已经在管理态里」。 */ ?>
      <a class="tbm-btn tbm-btn--login" href="admin.php" title="管理后台">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.11a1.7 1.7 0 0 0-1.11-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.11a1.7 1.7 0 0 0 1.55-1.11 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.11a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.11a1.7 1.7 0 0 0-1.55 1z"/></svg>
        </span>
        <span class="tbm-login-text">管理</span>
      </a>
      <?php endif; ?>
    <?php if (!$__isAdmin): ?>
      <?php /* 游客 / 普通账号的登录入口。
               v1.2.2 之前这里什么都没有 —— 用户只能自己去敲 admin.php，
               而且敲 /admin 还会落到主页（PATH_INFO 被忽略），
               等于「没有入口也没有捷径」。现在补一颗明确的按钮。
               窄屏只留图标（文字进 sr-only 会让按钮变成无字方块，
               所以这里用 CSS 隐藏文字 span，图标 + aria-label 仍然达意）。 */ ?>
      <a class="tbm-btn tbm-btn--login" href="login.php" title="登录管理后台">
        <span class="tbm-ico" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
        </span>
        <span class="tbm-login-text">登录</span>
      </a>
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
            <span class="gnav-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><?= hp_group_icon($nm, (string)($g['icon'] ?? '')) ?></svg>
            </span>
            <span class="gnav-name"><?= h($nm) ?></span>
            <span class="gnav-count"><?= count($g['items']) ?></span>
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
               没有「切到哪一份」这回事。 */ ?>
      <?php if ($onMine || $__isSuper): ?>
      <div class="gnav-lib" role="group" aria-label="数据范围">
        <a class="gnav-lib-btn<?= $onMine ? '' : ' is-on' ?>" href="./">站点库</a>
        <a class="gnav-lib-btn<?= $onMine ? ' is-on' : '' ?>" href="?lib=mine">我的库</a>
      </div>
      <?php endif; ?>
      <div class="gnav-foot">
        <p class="gnav-copy">&copy; <?= h($copyYear) ?> <span class="gnav-owner"><?= h($copyOwner) ?></span></p>        <p class="gnav-rights">
          <a class="gnav-license" href="LICENSE" rel="license">MIT License</a>
        </p>
        <?php if ($icpBeian !== ''): ?>
          <?php /* 备案号与开源协议是两回事：前者是国内主机的合规要求，
                   与是否开源无关。留空就整行不出现。 */ ?>
          <p class="gnav-beian">
            <a class="gnav-link" href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($icpBeian) ?></a>
          </p>
        <?php endif; ?>
        <p class="gnav-meta">
          <span class="gnav-ver">Vnav <?= h(hp_version()) ?></span>
          <a class="gnav-link" href="<?= h($authorGh) ?>"
             target="_blank" rel="noopener noreferrer">项目主页</a>
        </p>
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
  <?php if (!$ordered): ?>
    <div class="empty">
      <p class="empty-title">这里还没有东西</p>
      <p class="empty-sub"><?= $__isAdmin ? '按 Ctrl+V 粘贴任意网址，或者进「管理」页添加。' : '登录后即可添加你的常用站点。' ?></p>
      <?php /* 普通账号进不了后台，所以这里不能指 admin.php（会 403）。
               登录后回到首页，用顶栏的编辑按钮或 Ctrl+V 添加。 */ ?>
      <a class="btn-primary" href="<?= $__isAdmin ? './' : 'login.php' ?>"><?= $__isAdmin ? '去添加' : '登录开始' ?></a>
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
              <span class="gh-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= hp_group_icon($g['name'], (string)($g['icon'] ?? '')) ?></svg>
              </span>
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
              // 图标文件可能被手动清理/迁移丢失，先确认存在再输出，
              // 否则页面会留一个破图空框。缺失时自动降级为首字母头像。
              $hasIcon = $l['icon'] !== ''
                  && $l['icon_kind'] === 'file'
                  && is_file(HP_ROOT . '/' . $l['icon']);
            ?>
              <li class="link-item" style="--i:<?= $i ?>;--hue:<?= $hue ?>" data-id="<?= (int)$l['id'] ?>"
                  <?php if ($__isAdmin): ?>
                  data-title="<?= h($l['title']) ?>"
                  data-url="<?= h($l['url']) ?>"
                  data-descr="<?= h($l['descr']) ?>"
                  data-group="<?= (int)($l['group_id'] ?? 0) ?>"
                  data-hidden="<?= $isHidden ? '1' : '0' ?>"
                  data-icon="<?= h($l['icon']) ?>"
                  <?php endif; ?>>
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
                    <?php if ($hasIcon): ?>
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
                  <?php if ($isHidden): ?>
                    <?php /* 只有登录后才可能看到这个角标，所以它本身就是「私密」提示 */ ?>
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
       只在登录态输出 —— 游客的写操作本来就不联网，给一条按了没反应的
       操作条只会让人以为站坏了。 */ ?>
<?php if ($__isAdmin): ?>
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
<?php endif; ?>

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
    <input class="f-input" id="pUrl" name="url" type="url" inputmode="url" required autocomplete="off" spellcheck="false">

    <label class="f-label" for="pTitle">名称</label>
    <input class="f-input" id="pTitle" name="title" type="text" required maxlength="60" autocomplete="off">

    <label class="f-label" for="pGroup">分组</label>
    <select class="f-input" id="pGroup" name="group_id">
      <option value="0">未分组</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php /* 隐藏开关：滑块。默认关（公开），因为绝大多数链接是给
             自己看的公开收藏，把「隐藏」做成默认会让人莫名其妙
             看不到自己刚加的链接。 */ ?>
    <?php /* 粘贴浮层的「隐藏链接」开关也一起撤了，理由同编辑器里那处 */ ?>

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

<?php if ($__isAdmin): ?>
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
    <input class="f-input" id="eUrl" name="url" type="url" inputmode="url" required autocomplete="off" spellcheck="false">

    <label class="f-label" for="eTitle">名称</label>
    <input class="f-input" id="eTitle" name="title" type="text" required maxlength="60" autocomplete="off">

    <label class="f-label" for="eGroup">分组</label>
    <select class="f-input" id="eGroup" name="group_id">
      <option value="0">未分组</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <?php /* 这里原本有一个「隐藏链接」开关。V1.1.0 删掉了：
             它对普通用户完全无效（他的链接本来就在自己名下，别人看不到，
             而登录后前台又不过滤，等于连自己都藏不住）；对管理员则是
             「我的库」入口缺失时的补丁。入口补上之后，这个开关只剩下
             误导 —— 写着「关闭时所有访客可见」，而访客从来不会看到他的库。 */ ?>

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

<?php endif; /* 登录专属的那几个浮层到此为止：编辑框 / 快捷键面板 */ ?>

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
<script src="assets/ui.js?v=45" defer></script>
<script src="assets/guest.js?v=52" defer></script>
<script src="assets/app.js?v=45" defer></script>

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
