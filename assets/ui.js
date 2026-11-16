/* vnav 通用界面逻辑 —— 游客与登录态共用。
   只管「看」：三个下拉菜单（形态 / 皮肤 / 搜索引擎）、分组导航、搜索聚焦。
   写操作（粘贴添加、编辑跳转、设为默认引擎）在 app.js，只有登录后加载。 */
(() => {
  'use strict';

  const root = document.documentElement;
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  const THEME_KEY  = 'vnav.theme';
  const LAYOUT_KEY = 'vnav.layout';
  const ENGINE_KEY = 'vnav.engine';

  /* 游客还是登录用户。文件里多处要按身份分叉，而这个判断必须放在最前面 ——
     markDefault() 会用到它，那个函数比下面那段游客逻辑更早被调用。 */
  const isGuest = !!(window.__VNAV && window.__VNAV.guest);

  const csrf = () => document.querySelector('meta[name="csrf"]')?.content || '';

  /* ================= 下拉菜单（形态 / 皮肤 / 引擎） =================
     三个菜单共用一套开关逻辑：任一处点击先关掉所有，
     再打开目标那个。这样按钮挨着放也不会出现「两个都开着」。 */

  const menus = $$('.tb-menu');
  const panelOf = m => $('.tbm-panel', m);

  /* 面板用 position:fixed。原因：若用 absolute + right:0，
     面板右边缘对齐按钮，而按钮本身可能只距视口右沿 14px，
     面板又比按钮宽得多 —— 320px 屏上左边缘会跑到视口外。
     fixed 之后由这里算出 left，并夹在 12px 安全边距内。
     副作用是好的：面板不会被 .topbar 的 backdrop-filter 裁切。 */
  function place(m) {
    const btn = m.querySelector('button');
    const pan = panelOf(m);
    if (!btn || !pan) return;
    const r = btn.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const margin = 12;
    /* 宽度取 CSS 声明值，不用 offsetWidth：面板此刻还是 hidden，
       offsetWidth 是 0，会一路落到 288 的兜底值 —— 引擎菜单本来设计成
       220px，结果被撑到 288，盖住半个搜索框。getComputedStyle 对
       display:none 的元素仍能读到宽度声明。 */
    const cssW = parseFloat(getComputedStyle(pan).width) || 288;
    const w = Math.min(cssW, vw - margin * 2);
    // 引擎菜单贴搜索框左侧展开（左对齐），其余右对齐按钮
    const alignLeft = m.classList.contains('eng-menu');
    let left = alignLeft ? r.left : r.right - w;
    left = Math.max(margin, Math.min(left, vw - w - margin));
    pan.style.left = Math.round(left) + 'px';
    pan.style.top  = Math.round(r.bottom + 8) + 'px';
    pan.style.width = Math.round(w) + 'px';
    pan.style.right = 'auto';
  }

  function closeMenus(except) {
    menus.forEach(m => {
      if (m === except) return;
      m.querySelector('button')?.setAttribute('aria-expanded', 'false');
      const p = panelOf(m);
      if (p) p.hidden = true;
      m.classList.remove('is-open');
    });
  }

  function openMenu(m) {
    const b = m.querySelector('button');
    const p = panelOf(m);
    if (!b || !p) return;
    // 先显示再量尺寸：hidden 的元素 offsetWidth 恒为 0，
    // 顺序反了会让 place() 算出错误宽度（踩过）。
    p.hidden = false;
    m.classList.add('is-open');
    b.setAttribute('aria-expanded', 'true');
    place(m);
    const sel = $('.tbm-opt[aria-selected="true"]', p) || $('.tbm-opt', p);
    sel?.focus?.({ preventScroll: true });
  }

  menus.forEach(m => {
    m.querySelector('button')?.addEventListener('click', e => {
      e.stopPropagation();
      const p = panelOf(m);
      if (p.hidden) { closeMenus(m); openMenu(m); }
      else { closeMenus(); }
    });
    /* 账户菜单额外支持悬停展开（用户要求「鼠标指向用户名时弹出菜单」）。
       触屏没有 hover，click 那条仍保留，两边都能用。
       openMenu 里已有 closeMenus(m)，所以移入账户菜单会顺手关掉其他菜单。 */
    if (m.classList.contains('tb-menu--acct')) {
      m.addEventListener('mouseenter', () => { if (panelOf(m)?.hidden) openMenu(m); });
      m.addEventListener('mouseleave', () => { if (!m.contains(document.activeElement)) closeMenus(); });
    }
  });

  // 点页面空白处关闭
  document.addEventListener('click', e => {
    if (!e.target.closest('.tb-menu')) closeMenus();
  });

  // 窗口尺寸变化时，开着的面板重新定位（否则会飘在旧位置）
  let rafId = 0;
  addEventListener('resize', () => {
    cancelAnimationFrame(rafId);
    rafId = requestAnimationFrame(() => {
      const open = menus.find(m => !panelOf(m).hidden);
      if (open) place(open);
    });
  }, { passive: true });

  /* Esc 关闭并把焦点还给按钮；↑↓ 在选项间移动（listbox 该有的键盘行为） */
  document.addEventListener('keydown', e => {
    const open = menus.find(m => !panelOf(m).hidden);
    if (!open) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      closeMenus();
      open.querySelector('button')?.focus();
      return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      const opts = $$('.tbm-opt', panelOf(open));
      if (!opts.length) return;
      e.preventDefault();
      const cur = opts.indexOf(document.activeElement);
      const next = e.key === 'ArrowDown'
        ? (cur + 1) % opts.length
        : (cur - 1 + opts.length) % opts.length;
      opts[next].focus();
    }
  });

  /* ================= 皮肤 / 形态：同步选中态与按钮图标 ================= */
  function syncOptions(attr, cur, menuName) {
    $$('[' + attr + ']').forEach(b => {
      /* 必须用 getAttribute(attr)，不能写 b.dataset[attr]：
         attr 是 'data-theme-set' 这种带连字符的完整属性名，而 dataset 的键
         只接受驼峰（'themeSet'）。用 dataset['data-theme-set'] 恒为 undefined，
         于是每一项都算「不等于当前值」，菜单里永远没有勾选标记，
         aria-selected 也永远 false（读屏与色弱用户都看不出当前选了哪套）。 */
      b.setAttribute('aria-selected', b.getAttribute(attr) === cur ? 'true' : 'false');
    });
    // 按钮上的图标跟着换，用户不用打开菜单也能看出当前是什么
    const btn = $('.tb-menu[data-menu="' + menuName + '"] .tbm-btn');
    const on  = document.querySelector('[' + attr + '="' + cur + '"]');
    if (!btn || !on) return;
    const ico = $('.tbm-ico', btn);
    const src = $('.tbo-ico svg', on);
    if (ico && src) ico.innerHTML = src.outerHTML;
    // 只有图标时，把名字塞进 title 与 sr-only，鼠标悬停与读屏都能知道
    const label = $('.tbo-name', on)?.textContent || '';
    const body  = btn.querySelector('.sr-only');
    if (body && label) {
      const prefix = menuName === 'theme' ? '界面皮肤，当前 ' : '展示形态，当前 ';
      body.textContent = prefix + label;
    }
    btn.title = (menuName === 'theme' ? '界面皮肤：' : '展示形态：') + label;
  }

  const syncTheme  = () => syncOptions('data-theme-set', root.dataset.theme, 'theme');
  const syncLayout = () => syncOptions('data-layout-set', root.dataset.layout, 'layout');

  syncTheme();
  syncLayout();

  document.addEventListener('click', e => {
    const tb = e.target.closest('[data-theme-set]');
    if (tb) {
      const t = tb.dataset.themeSet;
      root.dataset.theme = t;
      try { localStorage.setItem(THEME_KEY, t); } catch (err) {}
      syncTheme();
      closeMenus();
      $('.tb-menu[data-menu="theme"] .tbm-btn')?.focus();
      /* 皮肤偏好同步到服务端。theme 早就在个人偏好键名单里，
         但此前只写 localStorage —— 换一台设备就回到默认。
         游客态没有会话，接口会拒绝，静默失败即可（本地仍然生效）。 */
      fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
        body: JSON.stringify({ action: 'set_theme', theme: t }),
        credentials: 'same-origin',
      }).catch(() => {});
      return;
    }

    const lb = e.target.closest('[data-layout-set]');
    if (lb) {
      const l = lb.dataset.layoutSet;
      root.dataset.layout = l;
      try { localStorage.setItem(LAYOUT_KEY, l); } catch (err) {}
      syncLayout();
      closeMenus();
      $('.tb-menu[data-menu="layout"] .tbm-btn')?.focus();
      // 形态偏好同步到服务端（游客态没有会话，静默失败即可）
      fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
        body: JSON.stringify({ action: 'set_layout', layout: l }),
        credentials: 'same-origin',
      }).catch(() => {});
      return;
    }
  });

  /* ================= 搜索引擎切换 =================
     切换只改表单 action 与占位文案，不写数据库 ——
     写库是「设为默认」，那是登录后才有的动作，放在 app.js。
     这样游客也能自由换引擎，刷新回到站点的默认值。 */
  const searchForm = $('.search');
  const engState = { cur: '' };

  function applyEngine(key, url, label) {
    engState.cur = key;
    if (searchForm) searchForm.action = url;

    const btn = $('.eng-menu .eng-btn');
    if (btn) {
      const logo = $('.eng-logo', btn);
      if (logo) {
        /* 图标从「内联 SVG」改成「<img src="assets/engines/xxx.svg">」之后，
           这里必须搬 src 而不是搬 <svg> —— 搬 svg 的写法在 V1.4.1 之后
           一直静默失效：按钮里没有 <svg>，querySelector 取到 null，
           于是无论选哪个引擎，显示的都是首屏渲染时的那一个（默认百度）。 */
        const src = $('[data-engine-set="' + key + '"] .eng-logo');
        if (src) {
          const srcImg = src.querySelector('.eng-img');
          const dstImg = logo.querySelector('.eng-img');
          if (srcImg && dstImg) {
            dstImg.src = srcImg.getAttribute('src');
            dstImg.alt = srcImg.getAttribute('alt') || '';
          }
        }
        logo.dataset.engLogo = key;
      }
      const sr = $('.sr-only', btn);
      if (sr && label) sr.textContent = '切换搜索引擎，当前 ' + label;
    }
    const q = $('#q');
    if (q && label) q.placeholder = '搜索 ' + label;
  }

  /* 把「站点默认引擎」的标记挂到对应选项上。
     服务端已经把默认值写在 data-engine-default-mark 上，这里只负责
     在用户手动换引擎后、或者「设为默认」之后重新对齐一次 ——
     否则标记会停留在旧的那一条，看着像没有默认。

     关键区分：engState.cur 是「现在用的这个」（可能被 localStorage 覆盖过），
     engState.siteDefault 是「站点设置里那个」。
     标记跟 siteDefault 走，勾选跟 cur 走 —— 两者混用会出现
     「勾在 A 上、默认标在 B 上」的错位。 */
  function markDefault(siteDefault) {
    if (siteDefault !== undefined) engState.siteDefault = siteDefault;
    const def = engState.siteDefault || '';

    $$('[data-engine-set]').forEach(b => {
      const on = b.dataset.engineSet === def;
      if (on) b.dataset.engineDefaultMark = '1';
      else delete b.dataset.engineDefaultMark;
      // 那行小字提示跟着显隐
      const hint = b.querySelector('.tbo-hint--default');
      if (hint) hint.hidden = !on;
    });

    /* 「设为默认」那颗按钮：当前用的引擎已经是站点默认时置灰并换文案。
       否则用户点下去只是把同一个值再写一遍，屏幕上什么都不会变，
       会以为按钮坏了。 */
    const dfltBtn = $('[data-engine-default]');
    if (dfltBtn) {
      const already = !!def && engState.cur === def;
      dfltBtn.disabled = already;
      const nm = $('.tbo-name', dfltBtn);
      /* 文案要按身份分叉。游客的「默认」写的是本机，写成「站点默认引擎」
         会让他以为改了全站；反过来登录用户看到「本机默认」也会困惑。 */
      if (nm) {
        nm.textContent = already
          ? (isGuest ? '当前已是本机默认' : '当前已是默认引擎')
          : (isGuest ? '在本机记住这个引擎' : '把当前引擎设为默认');
      }
    }
  }

  // 从服务端渲染的初始 DOM 里读出站点默认值
  engState.siteDefault = $('[data-engine-default-mark]')?.dataset.engineSet || '';

  // 对外暴露：app.js 点完「设为默认」后要回调它重画标记
  window.__vnavMarkDefault = markDefault;

  // 初始化：服务端已渲染好 icon 与 action，这里只把当前状态记下来
  engState.cur = $('.eng-menu .eng-logo')?.dataset.engLogo || '';

  // 页面加载后若 localStorage 里存过用户手动选的引擎，则以它为准
  try {
    const saved = localStorage.getItem(ENGINE_KEY);
    const opt = saved ? document.querySelector('[data-engine-set="' + saved + '"]') : null;
    if (opt) {
      applyEngine(saved, opt.dataset.engineUrl, opt.querySelector('.tbo-name')?.textContent);
      $$('[data-engine-set]').forEach(b => {
        b.setAttribute('aria-selected', b.dataset.engineSet === saved ? 'true' : 'false');
      });
    }
  } catch (err) {}

  /* ---------- 游客：把「设为默认」降级成「在本机记住」 ----------
     游客没有服务端身份，写不了站点设置，但他显然需要一个
     「下次打开还是这个引擎」的动作 —— 那个动作对他就是全部含义。
     这里把「默认」就地换成本机 localStorage：
       · 下拉里那行「站点默认引擎」小字改成「本机默认」
       · 当前引擎等于本机记住的那个时，按钮置灰并换文案
     登录用户不走这段（assets/app.js 里有另一份处理，写的是服务端）。
     （isGuest 在文件顶部声明，markDefault 也要用。） */

  /* ui.js 不依赖 app.js（游客根本不加载它），所以这里自带一个最小提示 */
  function guestToast(msg) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('is-bad');
    el.classList.add('is-ok');
    el.hidden = false;
    requestAnimationFrame(() => el.classList.add('show'));
    clearTimeout(guestToast._t);
    guestToast._t = setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => { el.hidden = true; }, 260);
    }, 2400);
  }

  if (isGuest) {
    try {
      const mine = localStorage.getItem(ENGINE_KEY) || '';
      if (mine) engState.siteDefault = mine;
    } catch (err) {}

    $$('.tbo-hint--default').forEach(el => { el.textContent = '本机默认'; });
    markDefault();

    $('[data-engine-default]')?.addEventListener('click', e => {
      e.stopPropagation();
      const key = $('.eng-menu .eng-logo')?.dataset.engLogo || '';
      if (!key) return;
      try { localStorage.setItem(ENGINE_KEY, key); } catch (err) {}
      engState.siteDefault = key;
      markDefault(key);
      const name = $('[data-engine-set="' + key + '"] .tbo-name')?.textContent || key;
      guestToast('已记住：下次打开还是「' + name + '」');
      closeMenus();
    });
  }

  document.addEventListener('click', e => {
    const eb = e.target.closest('[data-engine-set]');
    if (!eb) return;
    const key = eb.dataset.engineSet;
    const label = eb.querySelector('.tbo-name')?.textContent || '';
    applyEngine(key, eb.dataset.engineUrl, label);
    $$('[data-engine-set]').forEach(b => {
      b.setAttribute('aria-selected', b.dataset.engineSet === key ? 'true' : 'false');
    });
    /* 换完引擎要重算「设为默认」那颗按钮的可用性：
       从「百度→Bing」这种操作后，按钮状态取决于新引擎是不是站点默认。 */
    markDefault();
    try { localStorage.setItem(ENGINE_KEY, key); } catch (err) {}
    closeMenus();
    $('.eng-menu .eng-btn')?.focus();
    $('#q')?.focus();
  });

  /* ================= 分组导航 =================
     V1.3.0 起 tab 与 side 共用同一套「目录」行为：
       点 → 平滑滚到该组；滚动 → 高亮当前组。
     区别只在排布（横排吸顶 / 竖排常驻），行为完全一致。

     旧版 tab 是「筛选」：点一个标签，其余分组 hidden。问题很实在 ——
     用户加过的东西突然看不见，第一反应是「我的链接丢了吗」。
     收藏夹的心智模型是目录，不是筛选器。 */

  const gnav = $('#gnav');

  if (gnav) {
    /* ---------- 收起 / 展开侧栏 ----------
       状态只写在 <html data-nav="mini"> 上：宽度变量 --hp-nav-w 跟着
       从 240 变成 68，主区的 padding-left 读的是同一个变量，所以一处
       改动两边同时生效，不用重算任何尺寸，也不会出现「侧栏收了但正文
       还空着一块」的错位。 */
    const foldBtn = $('[data-nav-fold]', gnav);
    if (foldBtn) {
      const syncFold = () => {
        const mini = root.dataset.nav === 'mini';
        const label = mini ? '展开侧栏' : '收起侧栏';
        foldBtn.setAttribute('aria-expanded', mini ? 'false' : 'true');
        foldBtn.setAttribute('aria-label', label);
        foldBtn.title = label;
      };
      syncFold();
      foldBtn.addEventListener('click', () => {
        const mini = root.dataset.nav === 'mini';
        if (mini) delete root.dataset.nav;
        else root.dataset.nav = 'mini';
        try {
          if (mini) localStorage.removeItem('vnav.nav');
          else localStorage.setItem('vnav.nav', 'mini');
        } catch (e) {}
        syncFold();
      });
    }

    /* --- 顶栏实际高度写进 --hp-bar ---
       窄屏顶栏是两行（工具行 + 搜索行），实测 89px，而 CSS 里写死 58px。
       下面这条 sticky 导航的 top 读的就是这个变量，写死会让它吸顶时
       钻到顶栏底下。这里量一次写进去，尺寸变化时再量。 */
    (function syncBarH() {
      const tb = document.querySelector('.topbar');
      if (!tb) return;
      const apply = () => document.body.style.setProperty('--hp-bar', tb.offsetHeight + 'px');
      apply();
      if (window.ResizeObserver) new ResizeObserver(apply).observe(tb);
      else window.addEventListener('resize', apply);
    })();

    /* --- 窄屏分组折叠条 ---
       收起时只显示当前分组，点开是一块竖排面板。 */
    const mtoggle = document.getElementById('gnavMToggle');
    const mCurrent = document.getElementById('gnavMCurrent');
    const mCount = document.getElementById('gnavMCount');

    const syncMToggle = () => {
      const on = gnav.querySelector('.gnav-item.is-active');
      if (!on) return;
      if (mCurrent) mCurrent.textContent = (on.querySelector('.gnav-name')?.textContent || '分组').trim();
      if (mCount) {
        const c = (on.querySelector('.gnav-count')?.textContent || '').trim();
        mCount.textContent = c ? c + ' 个' : '';
      }
      /* 折叠条左侧那条竖条跟着当前分组变色。--gt-h 直接取自侧栏那一项，
         不在这里另算一遍色相 —— 色相表在 PHP 里，JS 再存一份迟早会漂移。 */
      if (mtoggle) {
        const h = on.style.getPropertyValue('--gt-h');
        if (h) mtoggle.style.setProperty('--gt-h', h);
      }
    };

    const closeM = () => {
      gnav.classList.remove('is-mopen');
      mtoggle?.setAttribute('aria-expanded', 'false');
    };

    mtoggle?.addEventListener('click', e => {
      e.stopPropagation();
      const open = !gnav.classList.contains('is-mopen');
      gnav.classList.toggle('is-mopen', open);
      mtoggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) syncMToggle();
    });
    // 首屏就把当前分组名写进折叠条，否则初始显示的是占位文案「分组」
    syncMToggle();
    // 点面板外面、或按 Esc 收起
    document.addEventListener('click', e => {
      if (gnav.classList.contains('is-mopen') && !gnav.contains(e.target)) closeM();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeM(); });
    // 选了某一组就收起，不用再点一次折叠条
    gnav.addEventListener('click', e => { if (e.target.closest('.gnav-item')) closeM(); });

    /* 长按导航项时会弹系统菜单（打开新标签页 / 拷贝链接 / 分享），
       把整条导航盖住。CSS 的 touch-callout 在部分 Android WebView 上
       不生效，这里再用 contextmenu 兜一层。 */
    gnav.addEventListener('contextmenu', e => {
      if (e.target.closest('.gnav-item') || e.target.closest('.gnav-mtoggle')) e.preventDefault();
    });

    const items = $$('.gnav-item', gnav);
    /* 点击分组后到平滑滚动结束这段时间，锁住高亮不被 IntersectionObserver
       抢走。否则滚动途中「路过」哪组就点亮哪组，图标在彩色/中性之间来回
       切换，肉眼就是「一点侧栏图标就闪」。900ms 覆盖一屏内最长 smooth 滚动。 */
    let activeLockUntil = 0;
    /* 目标元素**每次点击时实时查**，不能在这里缓存引用。
       游客态下 guest.js 会用本地数据重建 .groups（innerHTML），
       初始化时抓到的 <section id="g3"> 那一刻就已经脱离文档了 ——
       对脱离文档的元素调 scrollIntoView() 什么也不会发生，
       表现就是「点了分组没反应」。登录态首屏不重建，所以看不出来。 */
    const targetOf = it => {
      const idx = it.dataset.gjump;
      return idx != null ? document.getElementById('g' + idx) : null;
    };

    const setActive = id => {
      items.forEach(it => {
        const on = it.dataset.gjump != null && ('g' + it.dataset.gjump) === id;
        it.classList.toggle('is-active', on);
        if (on) it.setAttribute('aria-current', 'true');
        else it.removeAttribute('aria-current');
      });
      // 窄屏折叠条上显示的分组名跟着变
      syncMToggle();
    };

    items.forEach(it => {
      it.addEventListener('click', e => {
        const t = targetOf(it);
        if (!t) return;              // 拿不到目标就让浏览器走默认锚点
        e.preventDefault();

        /* 锁住高亮 900ms：下面的平滑滚动会让 IntersectionObserver 连续
           触发，若不锁，它会把 is-active 在途经的各组之间反复搬，图标颜色
           跟着一闪一闪。锁定期内只认这一次点击的高亮，稳定不闪。 */
        activeLockUntil = Date.now() + 900;

        /* 先切高亮，再滚。高亮是即时反馈 —— 即使滚动还在路上，
           侧栏也立刻告诉你「点的就是这一组」，不会觉得点了没反应。 */
        setActive(t.id);
        history.replaceState(null, '', '#' + t.id);

        /* 距离远就瞬移，近了才平滑滚动。
           全是 smooth 的时候，跨 2000+ 像素要滑一秒多，中途画面几乎不动，
           很容易被当成「点了没跳」—— 用户就是这么报的。 */
        const dist = Math.abs(t.getBoundingClientRect().top);
        t.scrollIntoView({
          behavior: dist > window.innerHeight * 1.2 ? 'auto' : 'smooth',
          block: 'start',
        });
      });
    });

    if ('IntersectionObserver' in window) {
      /* 取「当前可见比例最高」的那组做高亮，而不是第一个相交的：
         两组交界处会有两个同时相交，取第一个会导致高亮乱跳。 */
      const seen = new Map();
      const io = new IntersectionObserver(entries => {
        /* 点击后的锁定期内不接管高亮 —— 这是「点图标闪烁」的根因修复。
           锁一过，正常滚动的高亮跟随照常生效。 */
        if (Date.now() < activeLockUntil) return;
        entries.forEach(en => seen.set(en.target.id, en.isIntersecting ? en.intersectionRatio : 0));
        let bestId = '', bestRatio = 0;
        seen.forEach((ratio, id) => {
          if (ratio > bestRatio) { bestRatio = ratio; bestId = id; }
        });
        if (!bestId) return;
        setActive(bestId);
        // 横排标签要跟着把当前项滚进视野，否则用户看不到高亮
        const act = items.find(it => it.classList.contains('is-active'));
        if (gnav.dataset.mode === 'tab' && act) {
          act.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        }
      }, { rootMargin: '-72px 0px -55% 0px', threshold: [0.01, 0.25, 0.5, 0.75] });

      /* 观察对象同样不能缓存：游客态下 guest.js 会整体重建 .groups，
         旧节点脱离文档就再也收不到交叉事件，高亮会永久停在首屏那一组。
         所以每次重建后重新扫一遍。 */
      const observeAll = () => {
        $$('.group').forEach(g => g.id && io.observe(g));
      };
      observeAll();
      const groupsWrap = document.querySelector('.groups');
      if (groupsWrap && window.MutationObserver) {
        new MutationObserver(() => {
          io.disconnect();
          observeAll();
          syncMToggle();
        }).observe(groupsWrap, { childList: true });
      }
    }
  }

  /* 滚动过一屏才给吸顶标签条画下边框 —— 静止时有一条线会显得多余 */
  let scrollTick = 0;
  addEventListener('scroll', () => {
    if (scrollTick) return;
    scrollTick = requestAnimationFrame(() => {
      scrollTick = 0;
      document.body.classList.toggle('is-scrolled', window.scrollY > 8);
    });
  }, { passive: true });

  /* ================= 按 / 聚焦搜索 ================= */
  document.addEventListener('keydown', e => {
    if (e.key !== '/') return;
    if (/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')) return;
    const q = document.getElementById('q');
    if (q) { e.preventDefault(); q.focus(); }
  });

  /* ================= 登录浮层（游客） =================
     点「登录」弹卡片输账号密码，走 api.php 的 login 接口（免 CSRF、带限流）。
     成功后 reload —— 顶栏身份区由服务端按 session 重新渲染。 */
  (() => {
    const toggle = document.getElementById('loginToggle');
    const scrim  = document.getElementById('loginScrim');
    const box    = document.getElementById('loginBox');
    const form   = document.getElementById('loginForm');

    // 普通用户从账户菜单进「修改密码」：转发到那颗按钮的 click
    document.getElementById('acctPass')?.addEventListener('click', () => {
      document.getElementById('passToggle')?.click();
    });

    if (!toggle || !scrim || !box || !form) return;

    const errEl = document.getElementById('loginErr');
    const save  = document.getElementById('loginSave');
    const txt   = save?.querySelector('.btn-text');
    const spin  = save?.querySelector('.spinner');
    const capWrap = document.getElementById('loginCap');
    const capImg  = document.getElementById('loginCapImg');
    const capIn   = document.getElementById('loginCaptcha');
    let capToken = '';
    let lastFocus = null;
    let busy = false;

    const showErr = msg => {
      if (!errEl) return;
      errEl.textContent = msg || ''; errEl.hidden = !msg;
    };
    const hideCap = () => {
      capWrap.hidden = true;
      if (capIn) capIn.value = '';
      capToken = '';
    };
    // 取一张新验证码：token 走 api.php，图片走 login.php?captcha=
    const newCap = async () => {
      try {
        const res = await fetch('api.php?action=captcha_new', { credentials: 'same-origin' });
        const d = await res.json();
        if (d.ok && d.token) {
          capToken = d.token;
          if (capImg) capImg.src = 'login.php?captcha=' + encodeURIComponent(d.token) + '&t=' + Date.now();
          capWrap.hidden = false;
          return true;
        }
      } catch (e) {}
      return false;
    };
    const open = () => {
      lastFocus = document.activeElement;
      form.reset(); showErr(''); hideCap();
      scrim.hidden = false; box.hidden = false;
      document.getElementById('loginUser')?.focus();
    };
    const close = () => {
      scrim.hidden = true; box.hidden = true;
      lastFocus?.focus?.();
    };

    toggle.addEventListener('click', open);
    document.getElementById('loginClose')?.addEventListener('click', close);
    document.getElementById('loginCancel')?.addEventListener('click', close);
    scrim.addEventListener('click', close);
    document.getElementById('loginCapNew')?.addEventListener('click', () => { newCap(); });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (busy) return;
      const user = document.getElementById('loginUser').value.trim();
      const pass = document.getElementById('loginPass').value;
      showErr('');
      if (!user) return showErr('请填写账号');
      if (!pass) return showErr('请填写密码');
      const cap = capWrap && !capWrap.hidden ? (capIn?.value || '') : '';

      busy = true;
      if (txt) txt.textContent = '登录中';
      if (spin) spin.hidden = false;
      try {
        const res = await fetch('api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ action: 'login', user, pass,
                                 captcha_token: capToken, captcha: cap }),
        });
        const d = await res.json();
        if (d.ok) { location.reload(); return; }
        showErr(d.error || '登录失败');
        // 服务端要求验证码：取一张新的填进来
        if (d.need_captcha) {
          if (d.captcha_token) {
            capToken = d.captcha_token;
            if (capImg) capImg.src = 'login.php?captcha=' + encodeURIComponent(d.captcha_token) + '&t=' + Date.now();
            capWrap.hidden = false;
          } else {
            await newCap();
          }
          document.getElementById('loginPass').value = '';
        }
      } catch (err) {
        showErr('网络异常，请重试');
      } finally {
        busy = false;
        if (txt) txt.textContent = '登录';
        if (spin) spin.hidden = true;
      }
    });

    // Esc 关登录浮层（改密浮层的 Esc 由 app.js 处理，两者不冲突）
    document.addEventListener('keydown', e => {
      if (e.key !== 'Escape' || box.hidden) return;
      close();
    });
  })();
})();