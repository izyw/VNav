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
  function read() {
    try {
      var raw = localStorage.getItem(KEY);
      if (!raw) return null;
      var d = JSON.parse(raw);
      return (d && Array.isArray(d.groups) && Array.isArray(d.links)) ? d : null;
    } catch (e) { return null; }
  }

  function write(d) {
    try { localStorage.setItem(KEY, JSON.stringify(d)); } catch (e) {}
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
    d.groups.forEach(function (g) {
      var items = d.links.filter(function (l) { return Number(l.group_id) === Number(g.id); });
      if (!items.length) return;
      html += '<section class="group">';
      if (g.name !== '') {
        html += '<div class="group-head" data-icon="' + esc(g.icon || '') + '">'
             + '<h2 class="group-name">' + esc(g.name) + '</h2>'
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

  var n = data.links.length;

  setTimeout(function () {
    var merge = window.confirm(
      '本机有 ' + n + ' 条未登录时添加的链接。\n\n' +
      '确定：合并到当前账号（重复的网址会自动跳过）\n' +
      '取消：丢弃本机这份数据'
    );

    if (!merge) {
      try { localStorage.removeItem(KEY); } catch (e) {}
      return;
    }

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
      } else {
        alert('合并失败：' + ((res && res.error) || '未知错误'));
      }
    }).catch(function () {
      alert('合并失败，请检查网络后重试');
    });
  }, 700);
})();
