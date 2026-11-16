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
      return d;
    } catch (e) { return null; }
  }

  function write(d) {
    try {
      var out = d || {};
      out.savedAt = Date.now();   // 每次写入都续期
      localStorage.setItem(KEY, JSON.stringify(out));
    } catch (e) {}
  }

  function clear() {
    try { localStorage.removeItem(KEY); } catch (e) {}
  }

  /* ---------- 从 DOM 读当前状态 ---------- */
  function snapshot() {
    var groups = [], links = [];
    document.querySelectorAll('.groups .group').forEach(function (sec) {
      var ul = sec.querySelector('.links');
      if (!ul) return;
      var gid = Number(ul.dataset.group || 0);
      var nameEl = sec.querySelector('.group-name');
      var head = sec.querySelector('.group-head');
      groups.push({
        id: gid,
        name: nameEl ? nameEl.textContent.trim() : '',
        icon: head ? (head.dataset.icon || '') : ''
      });
      ul.querySelectorAll('.link-item').forEach(function (li) {
        var a = li.querySelector('a.link');
        if (!a) return;
        links.push({
          id: Number(li.dataset.id || 0),
          group_id: gid,
          title: (li.querySelector('.link-title') || {}).textContent || '',
          descr: (li.querySelector('.link-sub') || {}).textContent || '',
          url: a.getAttribute('data-href') || a.getAttribute('href') || '',
          icon: (li.querySelector('.link-icon img') || {}).getAttribute
            ? li.querySelector('.link-icon img').getAttribute('src') : '',
          hidden: li.querySelector('.link-edit') ? 0 : 0
        });
      });
    });
    return { seq: seq, groups: groups, links: links };
  }

  function saveFromDom() {
    var d = snapshot();
    d.seq = seq;
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
    return '<li class="link-item" style="--i:' + i + ';--hue:' + h + '" data-id="' + l.id + '">'
      + '<a class="link" href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer"'
      + ' data-href="' + esc(l.url) + '">'
      + icon
      + '<span class="link-body">'
      + '<span class="link-title">' + esc(l.title) + '</span>'
      + (l.descr ? '<span class="link-sub">' + esc(l.descr) + '</span>' : '')
      + '</span></a></li>';
  }

  function render(d) {
    var wrap = document.querySelector('.groups');
    if (!wrap || !d) return;

    var html = '';
    /* 索引要单独数，不能用 forEach 的第二个参数。
       下面会跳过没有链接的分组，用 forEach 的下标会和侧栏对不上 ——
       侧栏的 data-gjump 是「只数非空组」的连续序号。

       id 必须写出来：服务端首屏渲染的 <section id="g0"> 是对的，
       但游客第二次进来会走这里用本地数据重建 DOM，早先漏了 id，
       结果 .gnav-item 的 href="#g0" 指向不存在的元素，
       整条分组导航点了完全没反应（实测就是这个原因）。 */
    var gi = 0;
    d.groups.forEach(function (g) {
      var items = d.links.filter(function (l) { return Number(l.group_id) === Number(g.id); });
      if (!items.length) return;
      var idx = gi++;
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

    if (a === 'group_add') {
      return Promise.resolve({ ok: true, id: --seq });
    }

    /* 其余动作（update / delete / reorder / group_*）前端会自己改 DOM，
       MutationObserver 随后把结果落到 localStorage。这里只回一个 ok。 */
    return Promise.resolve({ ok: true });
  }

  /* ---------- 启动 ---------- */
  function boot() {
    var groups = document.querySelector('.groups');
    if (!groups) return;

    var saved = read();
    if (saved) {
      seq = Number(saved.seq || -1);
      render(saved);
    } else {
      // 第一次来：把服务端渲染的默认数据当基线存下来，
      // 之后用户改什么都以这份为起点
      saveFromDom();
    }

    /* DOM 一变就存。debounce 300ms：拖拽时 DOM 每帧都在动，
       不 debounce 会把 localStorage 写穿。 */
    new MutationObserver(function () {
      clearTimeout(timer);
      timer = setTimeout(saveFromDom, 300);
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
    render: render
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
    function closeMerge() {
      if (closed) return;
      closed = true;
      /* 取消 / 关闭都不再删 localStorage —— 那是用户自己攒的数据。 */
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
