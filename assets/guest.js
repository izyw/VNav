/* ==========================================================================
   游客本地数据层
   --------------------------------------------------------------------------
   游客（未登录）看到的是站点的默认数据，但他在自己浏览器里做的任何改动
   —— 加链接、改标题、删卡片、拖排序、建分组 —— 都只存在 localStorage，
   一个字节都不上传。

   实现思路：不重写一套编辑器。

   现有编辑器（粘贴添加、编辑浮层、拖拽排序）已经把 DOM 改好了，只是最后
   一步调 api() 把结果写回服务器。所以这里做两件事就够：

     1) 把 api() 拦下来，在游客模式下不联网，只把「新 id」这类前端需要的
        返回值编出来（真正的数据在 DOM 里）；
     2) 用 MutationObserver 盯着 .groups，DOM 一变就把整棵树快照存进
        localStorage。

   好处是编辑逻辑只有一份，登录与游客走的是同一条路径；坏处是快照依赖
   DOM 结构 —— 所以下面 render() 生成的标记必须和 index.php 的输出保持一致。

   登录之后这份数据不会被自动上传：合并与否由用户在登录成功时自己选
   （见 app.js 的合并对话框），服务端接口是 merge_guest。
   ========================================================================== */
(function () {
  'use strict';

  var KEY = 'vnav.guest';
  var FLAG = !!(window.__VNAV && window.__VNAV.guest);
  var seq = -1;          // 本地临时 id：一律负数，和服务器 id 不会撞
  var timer = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function initials(s) {
    s = (s || '').trim();
    if (!s) return '?';
    if (/[\u4e00-\u9fa5]/.test(s)) {
      return Array.from(s).filter(function (c) { return /[\u4e00-\u9fa5]/.test(c); }).slice(0, 2).join('');
    }
    return s.split(/[\s\-_./|·]+/).filter(Boolean).slice(0, 2)
      .map(function (w) { return w[0]; }).join('').toUpperCase();
  }

  function hue(host) {
    var h = 0;
    for (var i = 0; i < host.length; i++) h = (h * 31 + host.charCodeAt(i)) >>> 0;
    return h % 360;
  }

  function hostOf(url) {
    try { return new URL(url, location.href).hostname.replace(/^www\./, ''); }
    catch (e) { return ''; }
  }

  /* ---------- 读写 ---------- */
  /* 游客数据在本机保留 30 天。
     不设期限的话，半年前随手试加的东西会一直占着，而且它优先级高于
     服务端默认数据 —— 用户换了默认库也看不到，只会觉得「怎么不更新」。
     30 天足够覆盖「先试试、回头再登录」的窗口。 */
  var TTL = 30 * 24 * 60 * 60 * 1000;

  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      if (!raw) return null;
      var d = JSON.parse(raw);
      if (!(d && Array.isArray(d.groups) && Array.isArray(d.links))) return null;
      var t = Number(d.savedAt || 0);
      if (!t || Date.now() - t > TTL) {
        // 过期或老格式（没有 savedAt）：直接作废，回到服务端默认数据
        localStorage.removeItem(KEY);
        return null;
      }
      /* 旧格式（schema<2，没有 baseline 字段）无法确定是不是用户改过的。
         线上绝大多数是「首次访问自动存的默认数据」副本，把它重新标记为
         基线：既照常显示这份数据、不丢东西，登录后也不会误报「你保存过」。 */
      if (d.schema !== 2) d.baseline = true;
      return d;
    } catch (e) { return null; }
  }

  function write(d) {
    try {
      var out = d || {};
      out.savedAt = Date.now();   // 每次写入都续期
      out.schema = 2;             // 数据格式版本；旧格式（无 baseline 字段）靠它识别
      localStorage.setItem(KEY, JSON.stringify(out));
    } catch (e) {}
  }

  function clear() {
    try { localStorage.removeItem(KEY); } catch (e) {}
  }

  /* 只取真正代表「用户数据」的字段做指纹。图标 src、class、 aria、
     --i 这类渲染细节会被 ui.js / 图片加载反复改，不能算编辑。 */
  function contentKey(d) {
    return JSON.stringify({
      g: (d.groups || []).map(function (g) { return [g.id, g.name]; }),
      l: (d.links || []).map(function (l) {
        return [l.id, l.group_id, l.title, l.url, l.descr, l.hidden];
      })
    });
  }

  /* 这份 localStorage 是否只是「站点默认数据的副本」。
     线上旧版本把默认副本的 baseline 标成了 false（MutationObserver
     把渲染层的 DOM 变动误判成编辑），登录后就会误报「你保存了 67 条
     链接」。判据：本地没有游客新建的负 id，且每条链接的 (网址,标题)
     都能在服务端默认库里找到、每个分组名也都在其中。
     · 用「包含」而不是「集合相等」：默认库会更新（线上现在 68 条，
       用户浏览器里存的是更早的 67 条），数量不同也仍是默认副本；
     · 用户真改过任一标题/网址、或新建过链接/分组，就必有条目不在
       默认库里，返回 false，登录时照常提示、不误删他的数据。 */
  function isDefaultData(d) {
    var def = window.__VNAV_DEFAULT;
    if (!def || !Array.isArray(def.urls) || !def.urls.length || !d) return false;
    var links = d.links || [], groups = d.groups || [];
    if (!links.length) return false;
    /* 游客新建的链接/分组一律是负数 id（见 api()）；有负数就是真改过 */
    for (var i = 0; i < links.length; i++) {
      if (Number(links[i].id) < 0) return false;
    }
    for (var j = 0; j < groups.length; j++) {
      if (Number(groups[j].id) < 0) return false;
    }
    function has(list, v) { return (list || []).indexOf(v) !== -1; }
    for (var k = 0; k < links.length; k++) {
      var l = links[k];
      if (!has(def.urls, l.url) || !has(def.titles, l.title)) return false;
    }
    for (var m = 0; m < groups.length; m++) {
      if (!has(def.groups, groups[m].name)) return false;
    }
    return true;
  }

  /* ---------- 从 DOM 读当前状态 ---------- */
  function snapshot() {
    var groups = [], links = [];
    /* 分组从侧栏读，不从主区读：空分组在主区没有 .group-head 可抓，
       只从主区读会让「新建空组 → 刷新 → 组消失」（实测 bug）。
       侧栏每一项都在，含空组和未分组，顺序也和 section 的 gN 对齐。 */
    document.querySelectorAll('#gnav-track .gnav-item').forEach(function (it) {
      var gid = Number(it.dataset.group || 0);
      groups.push({
        id: gid,
        name: (it.querySelector('.gnav-name') || {}).textContent || '',
        icon: ''
      });
    });
    /* 侧栏还没输出（单分组页面不渲染 nav）时回退到主区，保证不丢数据 */
    if (!groups.length) {
      document.querySelectorAll('.groups .group').forEach(function (sec) {
        var ul = sec.querySelector('.links');
        if (!ul) return;
        groups.push({
          id: Number(ul.dataset.group || 0),
          name: ((sec.querySelector('.group-name') || {}).textContent || '').trim(),
          icon: ''
        });
      });
    }
    document.querySelectorAll('.groups .group').forEach(function (sec) {
      var ul = sec.querySelector('.links');
      if (!ul) return;
      var gid = Number(ul.dataset.group || 0);
      ul.querySelectorAll('.link-item').forEach(function (li) {
        var a = li.querySelector('a.link');
        if (!a) return;
        links.push({
          id: Number(li.dataset.id || 0),
          group_id: gid,
          title: li.dataset.title || (li.querySelector('.link-title') || {}).textContent || '',
          descr: li.dataset.descr || (li.querySelector('.link-sub') || {}).textContent || '',
          url: a.getAttribute('data-href') || a.getAttribute('href') || '',
          icon: li.dataset.icon || ((li.querySelector('.link-icon img') || {}).getAttribute
            ? li.querySelector('.link-icon img').getAttribute('src') : ''),
          hidden: 0
        });
      });
    });
    return { seq: seq, groups: groups, links: links };
  }

  function saveFromDom(isBaseline) {
    var d = snapshot();
    d.seq = seq;
    /* baseline=true 表示这是「服务端默认数据的副本」，用户还没动过。
       只要 MutationObserver 捕捉到任何一次真实 DOM 改动，就按 false 存，
       此后这份数据才够格在登录时被问「要不要导入」。 */
    d.baseline = isBaseline === true;
    write(d);
  }

  /* ---------- 用本地数据重建 DOM ---------- */
  function itemHtml(l, i) {
    var host = hostOf(l.url);
    var h = hue(host);
    var icon;
    if (l.icon) {
      icon = '<span class="link-icon has-img" aria-hidden="true">'
           + '<img src="' + esc(l.icon) + '" alt="" width="24" height="24" decoding="async">'
           + '</span>';
    } else {
      icon = '<span class="link-icon" aria-hidden="true"><span class="initials">'
           + esc(initials(l.title)) + '</span></span>';
    }
    /* dataset / 勾选框 / 铅笔必须和 index.php 首屏输出保持一致：
       编辑浮层靠 li.dataset.* 填表单，批量删除靠 .pick-box，
       点铅笔走 openEditor。早先这里漏了，游客一刷新（走 render 重建）
       就点卡片没反应、建不了组 —— 和 is-admin 挡住浮层是同一类 bug。 */
    return '<li class="link-item" style="--i:' + i + ';--hue:' + h + '" data-id="' + l.id + '"'
      + ' data-title="' + esc(l.title) + '" data-url="' + esc(l.url) + '"'
      + ' data-descr="' + esc(l.descr || '') + '" data-group="' + Number(l.group_id || 0) + '"'
      + ' data-hidden="0" data-icon="' + esc(l.icon || '') + '">'
      + '<label class="pick-box"><input type="checkbox" data-pick="' + l.id + '">'
      + '<span class="pick-mark" aria-hidden="true">'
      + '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" '
      + 'stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>'
      + '</span><span class="sr-only">选择「' + esc(l.title) + '」</span></label>'
      + '<a class="link" href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer"'
      + ' data-href="' + esc(l.url) + '">'
      + icon
      + '<span class="link-body">'
      + '<span class="link-title">' + esc(l.title) + '</span>'
      + (l.descr ? '<span class="link-sub">' + esc(l.descr) + '</span>' : '')
      + '</span>'
      + '<span class="link-edit" data-edit="' + l.id + '" role="button" tabindex="0" aria-label="编辑 ' + esc(l.title) + '">'
      + '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" '
      + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/>'
      + '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>'
      + '</span></a></li>';
  }

  function render(d) {
    var wrap = document.querySelector('.groups');
    if (!wrap || !d) return;

    var html = '';
    /* 下标用 forEach 的完整序号（含空组），和侧栏 .gnav-item 的
       data-gjump 一一对应：href="#gN" 才能跳到对的 section。
       空组照常渲染 —— 服务端也是这么做的，新建的空组刷新后必须还在，
       否则用户以为没建成。早先这里既跳过空组、又漏写 id，导致
       分组导航点了没反应 + 空组消失（实测 bug）。 */
    d.groups.forEach(function (g, idx) {
      var items = d.links.filter(function (l) { return Number(l.group_id) === Number(g.id); });
      html += '<section class="group" id="g' + idx + '"'
           + (g.name !== '' ? ' aria-labelledby="gh' + idx + '"' : '') + '>';
      if (g.name !== '') {
        html += '<div class="group-head" data-icon="' + esc(g.icon || '') + '">'
             + '<h2 class="group-name" id="gh' + idx + '">' + esc(g.name) + '</h2>'
             + '<span class="group-count">' + items.length + '</span>'
             + '<span class="group-rule" aria-hidden="true"></span></div>';
      }
      html += '<ul class="links" data-group="' + g.id + '">';
      items.forEach(function (l, i) { html += itemHtml(l, i); });
      html += '</ul></section>';
    });

    if (html) wrap.innerHTML = html;
  }

  /* ---------- 用本地数据同步侧栏（游客分组增删改后 reload 会走这里） ----------
     侧栏 originally 由服务端渲染，但游客的分组数据只在 localStorage。
     所以每次启动若命中本地数据，就按它重建侧栏项：
       · 复用现有项的图标 SVG（按 group id 缓存），新组用通用图标
       · 保留 data-gjump / data-group / 删除按钮，事件绑定靠 reload 后重挂
     只重建 track 的 innerHTML，不动 ui.js/app.js 的其它逻辑。 */
  var navIconCache = null;
  function collectNavIcons() {
    if (navIconCache) return navIconCache;
    navIconCache = {};
    document.querySelectorAll('#gnav-track .gnav-item').forEach(function (it) {
      var svg = it.querySelector('.gnav-ico svg');
      if (svg) navIconCache[it.dataset.group || '0'] = svg.outerHTML;
    });
    return navIconCache;
  }
  var GENERIC_GROUP_ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" '
    + 'stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" '
    + 'aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
  function navItemHtml(g, idx, count) {
    var nm = g.name !== '' ? g.name : '全部';
    var icons = collectNavIcons();
    var svg = icons[String(g.id)] || GENERIC_GROUP_ICON;
    var h = hue(nm);
    /* 游客新建组 id 是负数（本地临时 id），同样要给删除入口。
       只排除 id=0（未分组）—— 那不是真分组。 */
    var del = Number(g.id) !== 0
      ? '<span class="gnav-del" role="button" tabindex="0" data-del-group="' + Number(g.id) + '"'
        + ' title="删除分组「' + esc(nm) + '」" aria-label="删除分组：' + esc(nm) + '">'
        + '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" '
        + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/></svg>'
        + '<span class="gnav-del-text">删除</span></span>'
      : '';
    return '<a class="gnav-item' + (idx === 0 ? ' is-active' : '') + '" href="#g' + idx + '"'
      + ' data-gjump="' + idx + '" data-group="' + Number(g.id) + '" style="--gt-h:' + h + '"'
      + ' aria-current="' + (idx === 0 ? 'true' : 'false') + '" title="' + esc(nm) + '">'
      + '<span class="gnav-ico" aria-hidden="true">' + svg + '</span>'
      + '<span class="gnav-name">' + esc(nm) + '</span>'
      + '<span class="gnav-count">' + count + '</span>' + del + '</a>';
  }
  function renderNav(d) {
    var track = document.getElementById('gnav-track');
    if (!track || !d) return;
    var html = '';
    d.groups.forEach(function (g, idx) {
      var count = d.links.filter(function (l) { return Number(l.group_id) === Number(g.id); }).length;
      html += navItemHtml(g, idx, count);
    });
    if (html) track.innerHTML = html;
    // 窄屏折叠条上显示的当前组名同步一下（ui.js 首屏已算，这里数据变了）
    var cur = document.getElementById('gnavMCurrent');
    if (cur && d.groups[0]) cur.textContent = d.groups[0].name !== '' ? d.groups[0].name : '全部';
  }

  /* ---------- 拦截 api ---------- */
  function api(body) {
    var a = body.action;

    if (a === 'add') {
      var url = String(body.url || '');
      return Promise.resolve({
        ok: true,
        link: {
          id: --seq,
          title: body.title || hostOf(url),
          url: url,
          descr: body.descr || '',
          icon: body.icon || '',
          host: hostOf(url),
          hue: hue(hostOf(url)),
          group_id: Number(body.group_id || 0),
          hidden: body.hidden ? 1 : 0
        }
      });
    }

    /* 分组操作：前端改的是侧栏，而 MutationObserver 只盯 .groups 主区，
       不落盘就会在随后 reload 时全丢。这里显式改本地数据并 write，
       reload 后 boot() 用同一份数据重建主区 + 侧栏，操作才真正生效。 */
    if (a === 'group_add') {
      var d = read() || { groups: [], links: [], seq: seq };
      var gid = --seq;
      d.groups.push({ id: gid, name: String(body.name || ''), icon: '' });
      d.seq = seq; d.baseline = false;
      write(d);
      return Promise.resolve({ ok: true, id: gid });
    }

    if (a === 'group_rename') {
      var d2 = read();
      if (d2) {
        d2.groups.forEach(function (g) {
          if (Number(g.id) === Number(body.id)) g.name = String(body.name || '');
        });
        d2.baseline = false; write(d2);
      }
      return Promise.resolve({ ok: true });
    }

    if (a === 'group_delete') {
      var d3 = read();
      if (d3) {
        var did = Number(body.id);
        d3.groups = d3.groups.filter(function (g) { return Number(g.id) !== did; });
        d3.links.forEach(function (l) { if (Number(l.group_id) === did) l.group_id = 0; });
        d3.baseline = false; write(d3);
      }
      return Promise.resolve({ ok: true });
    }

    if (a === 'group_reorder') {
      var d4 = read();
      var ids = Array.isArray(body.ids) ? body.ids : [];
      if (d4 && ids.length) {
        var byId = {};
        d4.groups.forEach(function (g) { byId[Number(g.id)] = g; });
        var reordered = [];
        ids.forEach(function (id) { if (byId[Number(id)]) reordered.push(byId[Number(id)]); });
        d4.groups.forEach(function (g) { if (reordered.indexOf(g) < 0) reordered.push(g); });
        d4.groups = reordered; d4.baseline = false; write(d4);
      }
      return Promise.resolve({ ok: true });
    }

    /* 其余动作（update / delete / reorder）前端会自己改主区 DOM，
       MutationObserver 随后把结果落到 localStorage。这里只回一个 ok。 */
    return Promise.resolve({ ok: true });
  }

  /* ---------- 启动 ---------- */
  function boot() {
    var groups = document.querySelector('.groups');
    if (!groups) return;

    var saved = read();
    var lastKey = '';
    if (saved) {
      seq = Number(saved.seq || -1);
      lastKey = contentKey(saved);
      render(saved);
      /* 侧栏也按本地数据重建：游客新建/删除/改名分组后 reload，
         服务端那一份还是默认数据，只有这里能把改动真正显示出来。 */
      renderNav(saved);
    } else {
      // 第一次来：把服务端渲染的默认数据当基线存下来，
      // 之后用户改什么都以这份为起点。
      // 关键：打上 baseline 标记 —— 这份只是「站点的默认数据」，
      // 不是用户亲手攒的。没这个标记，登录后就会把默认数据误报成
      // 「你在未登录时保存了 67 条链接」，而且每次登录都弹（实测 bug）。
      lastKey = contentKey(snapshot());
      saveFromDom(true);
    }

    /* DOM 一变就存。debounce 300ms：拖拽时 DOM 每帧都在动，
       不 debounce 会把 localStorage 写穿。
       关键：先看「用户数据指纹」，内容没变就不改 baseline —— 图标加载、
       编辑浮层开关、拖动 class 都是渲染层变动，不是用户编辑。早期这里
       无脑写 false，把默认副本冲成用户数据，登录后每次误报 67 条 / 9 组。 */
    new MutationObserver(function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var d = snapshot();
        var k = contentKey(d);
        if (k === lastKey) {
          var cur = read();   // 只续 30 天期限，保留原 baseline
          if (cur) write(cur);
          return;
        }
        lastKey = k;
        saveFromDom(false);
      }, 300);
    }).observe(groups, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['href', 'data-href', 'data-group', 'data-id']
    });
  }

  window.VnavGuest = {
    isGuest: FLAG,
    api: api,
    read: read,
    write: write,
    clear: clear,
    snapshot: snapshot,
    contentKey: contentKey,
    isDefaultData: isDefaultData,
    render: render,
    renderNav: renderNav
  };

  if (FLAG) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  }
})();

/* ==========================================================================
   已登录时的收尾：本机还留着游客数据怎么办
   --------------------------------------------------------------------------
   场景：用户先以游客身份加了一堆链接，然后登录。
   那份数据在 localStorage 里，服务端完全不知道。这里在登录态下主动问一句：

     确定 → 合并进当前账号（只追加，重复网址跳过）
     取消 → 丢弃本机这份

   必须问而不是自动处理：自动合并可能把用户不想要的东西塞进账号，
   自动丢弃则可能删掉他攒了半天的东西。
   ========================================================================== */
(function () {
  'use strict';
  if (window.__VNAV && window.__VNAV.guest) return;   // 游客：上面那套已经处理了

  var KEY = 'vnav.guest';
  var raw = null;
  try { raw = localStorage.getItem(KEY); } catch (e) { return; }
  if (!raw) return;

  var data = null;
  try { data = JSON.parse(raw); } catch (e) { data = null; }
  if (!data || !Array.isArray(data.links) || !data.links.length) {
    try { localStorage.removeItem(KEY); } catch (e) {}
    return;
  }
  /* 基线数据 = 游客从没动过，只是第一次访问时把服务端默认数据存了一份副本。
     这不是用户攒的，登录后弹「你保存了 N 条链接」是误报 —— 而且点过
     「暂不导入」又不删 localStorage，于是每次登录都弹（实测 bug）。
     基线直接静默清掉：既不打扰，也不再误导。用户真动过的数据 baseline=false，
     下面照常提示。

     schema<2 的是旧格式数据：它根本没有 baseline 字段，无法判断是不是基线。
     线上大量用户/localStorage 里存的正是这种「首次访问自动存的默认数据」，
     一律当作基线静默清掉 —— 数据本就是服务端默认数据的副本，清了不丢东西。 */
  if (data.baseline === true || data.schema !== 2) {
    try { localStorage.removeItem(KEY); } catch (e) {}
    return;
  }
  /* 存量脏数据：旧版 MutationObserver 把「默认数据副本」的 baseline
     冲成了 false，但内容与站点默认库逐项一致。按内容认出来，静默清掉，
     不再每次登录误报。用户真攒过的数据不会命中：只要改过任一标题、
     网址或分组名，下面三个集合就不可能同时相等。 */
  var looksDefault = !!(window.VnavGuest && window.VnavGuest.isDefaultData
    && window.VnavGuest.isDefaultData(data));
  if (looksDefault) {
    try { localStorage.removeItem(KEY); } catch (e) {}
    return;
  }
  /* 本标签页已经提示过一次就不再弹。点「暂不导入」/关闭后，用户是明确
     选择了不合并；同一个标签页里刷新、再登录不该反复问同一件事。
     用 sessionStorage：关掉标签页就失效，下次新会话仍可再问。 */
  var SEEN_KEY = 'vnav.guest.mergeSeen';
  var seenAt = 0;
  try { seenAt = Number(sessionStorage.getItem(SEEN_KEY) || 0); } catch (e) {}
  if (seenAt && data.savedAt && seenAt >= data.savedAt) return;
  /* 过期的不再提示。这里读的是原始字符串（不是上面的 read()），
     所以要自己判一次 savedAt，否则半年前的数据也会弹窗来问。 */
  var savedAt = Number(data.savedAt || 0);
  if (!savedAt || Date.now() - savedAt > 30 * 24 * 60 * 60 * 1000) {
    /* 过期只表示「不再主动弹窗问」，不代表可以替用户删掉。
       数据留在本机，哪天想找还能找回来 —— 界面干净不能凌驾于用户数据。 */
    return;
  }

  var nLink = data.links.length;
  var nGroup = (data.groups || []).length;

  setTimeout(function () {
    /* 原生 confirm 换成页面内浮层：系统弹窗和这套界面完全是两种语言，
       手机上还会被浏览器换成自己的样式。这里复用 .paste-scrim / .paste-box
       的现成皮肤，动态建节点 —— 不必往 index.php 里塞只有登录态才用的 DOM。 */
    var scrim = document.createElement('div');
    scrim.className = 'paste-scrim';
    scrim.id = 'mergeScrim';
    var box = document.createElement('div');
    box.className = 'paste-box merge-box';
    box.id = 'mergeBox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<div class="paste-head"><h2 id="mergeTitle">导入本机数据</h2>' +
      '<button type="button" class="paste-close" id="mergeX" aria-label="关闭">' +
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
      'stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
      '</button></div>' +
      '<div class="paste-form">' +
      '<p class="paste-status">检测到你在未登录时保存了 ' + nLink + ' 条链接、' + nGroup + ' 个分组。</p>' +
      '<p class="keys-foot">导入只追加，重复网址自动跳过，现有数据不受影响；暂不导入也不会删除本机这份，以后还能再导入。</p>' +
      '<p class="f-error" id="mergeErr" role="alert" hidden></p>' +
      '<div class="paste-actions">' +
      '<button type="button" class="btn-ghost" id="mergeSkip">暂不导入</button>' +
      '<button type="button" class="btn-primary" id="mergeGo"><span class="btn-text">导入到当前账号</span>' +
      '<span class="spinner" hidden aria-hidden="true"></span></button>' +
      '</div></div>';
    document.body.appendChild(scrim);
    document.body.appendChild(box);
    scrim.hidden = false;
    box.hidden = false;

    var closed = false;
    function markSeen() {
      try { sessionStorage.setItem(SEEN_KEY, String(Date.now())); } catch (e) {}
    }
    function closeMerge() {
      if (closed) return;
      closed = true;
      /* 取消 / 关闭都不再删 localStorage —— 那是用户自己攒的数据。
         但要记一笔「本次已问过」，避免同一会话里反复弹同一个问题。 */
      markSeen();
      if (scrim.parentNode) scrim.parentNode.removeChild(scrim);
      if (box.parentNode) box.parentNode.removeChild(box);
    }
    document.getElementById('mergeX').addEventListener('click', closeMerge);
    document.getElementById('mergeSkip').addEventListener('click', closeMerge);
    scrim.addEventListener('click', closeMerge);
    document.addEventListener('keydown', function onEsc(e) {
      if (e.key !== 'Escape') return;
      if (closed) { document.removeEventListener('keydown', onEsc); return; }
      e.preventDefault(); closeMerge();
      document.removeEventListener('keydown', onEsc);
    });

    var go = document.getElementById('mergeGo');
    var errEl = document.getElementById('mergeErr');
    var btnText = go.querySelector('.btn-text');
    var spin = go.querySelector('.spinner');
    go.addEventListener('click', function () {
      errEl.hidden = true;
      go.disabled = true; btnText.textContent = '导入中'; spin.hidden = false;
      var tok = (document.querySelector('meta[name="csrf"]') || {}).content || '';
      fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': tok },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'merge_guest', payload: data })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok) {
          markSeen();
          try { localStorage.removeItem(KEY); } catch (e) {}
          location.reload();
          return;
        }
        errEl.textContent = '合并失败：' + ((res && res.error) || '未知错误');
        errEl.hidden = false;
        go.disabled = false; btnText.textContent = '导入到当前账号'; spin.hidden = true;
      }).catch(function () {
        errEl.textContent = '合并失败，请检查网络后重试';
        errEl.hidden = false;
        go.disabled = false; btnText.textContent = '导入到当前账号'; spin.hidden = true;
      });
    });
  }, 700);
})();
