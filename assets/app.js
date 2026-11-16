/* Vnav 主页写操作 —— 仅登录后加载。
   通用界面（下拉菜单 / 皮肤 / 形态 / 分组导航 / 引擎切换）在 ui.js；
   这里做：粘贴添加、就地编辑、拖动排序、快捷键、右下角提示、点击上报。

   设计原则：
   - 所有写操作先乐观更新界面，失败再回滚并提示（100ms 内要有反馈）
   - 任何出错都不清空用户输入，只标红并给原因
   - 删除可撤销（Ctrl+Z / 提示条上的撤销），不弹确认框打断节奏 */
(() => {
  'use strict';

  const CSRF  = document.querySelector('meta[name="csrf"]')?.content || '';
  /* 前台当前显示的是哪一份库。默认 site（网站默认）；admin 用 ?lib=mine
     看自己那份时这里是 mine。写操作必须把同一个值传给服务端，
     否则会出现「看到的是私人库、改的却是默认库」这种最难察觉的错。
     游客不读这个（写操作全在本地沙盒里，见 guest.js）。 */
  const LIB   = document.querySelector('meta[name="adm-lib"]')?.content || 'site';
  const stage = document.getElementById('stage');
  const toastEl = document.getElementById('toast');
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  /* 勾选集合。放在最外层是因为它有两个使用方：
     fillItem（重建卡片时要还原勾选态）和批量操作条。
     键用字符串 id —— dataset 读回来是字符串，混用数字会让 Set 判重失效。 */
  const picked = new Set();

  /* ---------------- 基础工具 ---------------- */

  function toast(msg, kind) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-bad', kind === 'bad');
    toastEl.classList.toggle('is-ok', kind === 'ok');
    toastEl.hidden = false;
    requestAnimationFrame(() => toastEl.classList.add('show'));
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
      toastEl.classList.remove('show');
      setTimeout(() => { toastEl.hidden = true; }, 260);
    }, kind === 'bad' ? 4200 : 2400);
  }

  async function api(body) {
    /* 游客：一个字节都不上传。改动的落点是 localStorage，
       由 assets/guest.js 负责（它同时也要编出前端需要的返回值）。 */
    if (window.VnavGuest && window.VnavGuest.isGuest) {
      return window.VnavGuest.api(body);
    }
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      body: JSON.stringify({ lib: LIB, ...body }),
      credentials: 'same-origin',
    });
    let data;
    try { data = await res.json(); }
    catch { throw new Error('服务器返回异常，请刷新页面重试'); }
    if (!res.ok || !data.ok) throw new Error(data.error || '操作失败（' + res.status + '）');
    return data;
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function initials(s) {
    s = (s || '').trim();
    if (!s) return '?';
    if (/[\u4e00-\u9fa5]/.test(s)) return [...s].filter(c => /[\u4e00-\u9fa5]/.test(c)).slice(0, 2).join('');
    const p = s.split(/[\s\-_./|·]+/).filter(Boolean);
    return p.length >= 2 ? (p[0][0] + p[1][0]).toUpperCase() : s.slice(0, 2).toUpperCase();
  }

  function hue(host) {
    let h = 0;
    for (let i = 0; i < host.length; i++) h = (h * 31 + host.charCodeAt(i)) >>> 0;
    return h % 360;
  }

  const isTyping = () => /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')
    || document.activeElement?.isContentEditable;

  /* ==================================================================
     1. 点击热度上报（对用户无感）
     ================================================================== */
  stage?.addEventListener('click', e => {
    const a = e.target.closest('a.link');
    if (!a) return;
    // 编辑模式下手滑点到卡片不该跳走
    if (document.body.classList.contains('is-editing') && !e.ctrlKey && !e.metaKey) {
      e.preventDefault();
      const li = a.closest('.link-item');
      if (li) selectItem(li);
      return;
    }
    const id = a.closest('.link-item')?.dataset.id;
    if (!id) return;
    const body = JSON.stringify({ action: 'hit', id: Number(id) });
    if (navigator.sendBeacon) {
      navigator.sendBeacon('api.php', new Blob([body], { type: 'application/json' }));
    } else {
      fetch('api.php', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => {});
    }
  });

  /* ==================================================================
     2. 把当前引擎设为默认
     ================================================================== */
  $('[data-engine-default]')?.addEventListener('click', async e => {
    e.stopPropagation();
    const key = $('.eng-menu .eng-logo')?.dataset.engLogo || '';
    if (!key) return;
    const btn = e.currentTarget;
    btn.disabled = true;
    try {
      await api({ action: 'set_engine', engine: key });
      const name = btn.closest('.tbm-panel')?.querySelector('[data-engine-set="' + key + '"] .tbo-name')?.textContent || key;
      toast('已把「' + name + '」设为默认引擎', 'ok');
      /* 让下拉里的「站点默认引擎」小字立刻挪到刚设的那条上。
         不刷新也能等到下次加载，但用户点完看不到变化会以为没生效。 */
      window.__vnavMarkDefault?.(key);
      document.body.click();
      $('.eng-menu .eng-btn')?.focus();
    } catch (err) {
      toast(err.message, 'bad');
      btn.disabled = false;
    } finally {
      // 成功时 markDefault 已经按新状态设过 disabled，这里不要再覆盖它
      if (btn.disabled && !window.__vnavMarkDefault) btn.disabled = false;
    }
  });

  /* ==================================================================
     3. 分组导航：新增后同步数量 / 切到对应分组
     ================================================================== */
  function bumpGroupCount(gid, delta) {
    const panels = $$('.group');
    const panel = panels.find(p => p.querySelector(`.links[data-group="${gid}"]`));
    if (!panel) return;
    const idx = panels.indexOf(panel);
    const item = document.querySelector(`.gnav-item[data-gtab="${idx}"], .gnav-item[data-gjump="${idx}"]`);
    const cnt = item && $('.gnav-count', item);
    if (cnt) cnt.textContent = String(Math.max(0, Number(cnt.textContent || 0) + delta));
  }

  function showGroupOf(gid) {
    const gnav = document.getElementById('gnav');
    if (!gnav || gnav.dataset.mode !== 'tab') return;
    const panel = $$('.group').find(p => p.querySelector(`.links[data-group="${gid}"]`));
    if (!panel) return;
    const idx = $$('.group').indexOf(panel);
    document.querySelector(`.gnav-item[data-gtab="${idx}"]`)?.click();
  }

  function groupOptions() {
    return $$('#pGroup option').map(o => ({ v: o.value, t: o.textContent }));
  }

  /* ==================================================================
     4. Ctrl+V 快速添加
     ================================================================== */
  const scrim = document.getElementById('pasteScrim');
  const box = document.getElementById('pasteBox');
  const form = document.getElementById('pasteForm');
  const hasPaste = !!(box && form);

  if (hasPaste) {
    const fUrl = document.getElementById('pUrl');
    const fTitle = document.getElementById('pTitle');
    const fGroup = document.getElementById('pGroup');
    const fErr = document.getElementById('pasteError');
    const statusEl = document.getElementById('pasteStatus');
    const hostEl = document.getElementById('pasteHost');
    const iconEl = document.getElementById('pasteIcon');
    const saveBtn = document.getElementById('pasteSave');
    const btnText = saveBtn.querySelector('.btn-text');
    const spinner = saveBtn.querySelector('.spinner');
    let lastFocus = null;
    let busy = false;

    const URL_RE = /\bhttps?:\/\/[^\s<>"'）)】」]+/i;
    const BARE_RE = /\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?:\/[^\s<>"'）)】」]*)?/i;

    function extractUrl(text) {
      const t = (text || '').trim();
      if (!t || t.length > 2048) return '';
      const m = t.match(URL_RE);
      if (m) return m[0].replace(/[.,;!?]+$/, '');
      if (!/\s/.test(t)) {
        const b = t.match(BARE_RE);
        if (b && b[0].includes('.')) return 'https://' + b[0].replace(/[.,;!?]+$/, '');
      }
      return '';
    }

    function setStatus(text, kind) {
      statusEl.textContent = text;
      statusEl.className = 'paste-status' + (kind ? ' is-' + kind : '');
    }

    function paintIcon(el, icon, title, host) {
      if (icon) {
        el.classList.add('has-img');
        el.innerHTML = `<img src="${esc(icon)}" alt="" width="40" height="40">`;
      } else {
        el.classList.remove('has-img');
        el.innerHTML = `<span class="initials">${esc(initials(title || host))}</span>`;
      }
    }

    function showError(msg) {
      fErr.textContent = msg;
      fErr.hidden = false;
    }
    function clearError() {
      fErr.hidden = true;
      fErr.textContent = '';
      fUrl.removeAttribute('aria-invalid');
      fTitle.removeAttribute('aria-invalid');
    }

    function guessTitle(url) {
      try {
        const host = new URL(url).hostname.replace(/^www\./, '');
        let name = host.replace(/\.(com|cn|net|org|io|co|me|dev|app|top|xyz|info|site|tv|cc)(\.[a-z]{2})?$/i, '');
        name = name.split(/[.\-_]/).filter(Boolean).pop() || name;
        return name ? name.charAt(0).toUpperCase() + name.slice(1) : host;
      } catch { return ''; }
    }

    async function identify(url) {
      try {
        const data = await api({ action: 'identify', url });
        if (fUrl.value.trim() !== url) return;
        const m = data.meta || {};
        if (m.title) fTitle.value = m.title.slice(0, 60);
        if (m.desc) hostEl.textContent = m.desc;
        paintIcon(iconEl, m.icon || '', fTitle.value, hostEl.textContent);
        setStatus(m.title ? '已识别：' + m.title : '未能读取标题，已按域名生成', m.title ? 'ok' : 'warn');
        clearError();
      } catch (err) {
        setStatus('未能自动识别，请手动确认名称', 'warn');
        if (!fTitle.value) fTitle.value = guessTitle(url);
        paintIcon(iconEl, '', fTitle.value, hostEl.textContent);
      }
    }

    function openBox(url) {
      lastFocus = document.activeElement;
      clearError();
      fUrl.value = url;
      fTitle.value = '';
      try { hostEl.textContent = new URL(url).hostname.replace(/^www\./, ''); } catch { hostEl.textContent = ''; }
      paintIcon(iconEl, '', '', hostEl.textContent);
      setStatus('正在识别名称与图标…', 'loading');
      scrim.hidden = false;
      box.hidden = false;
      document.body.style.overflow = 'hidden';
      if (!fTitle.value) fTitle.value = guessTitle(url);
      fUrl.focus();
      fUrl.setSelectionRange(0, 0);
      identify(url);
    }

    /* 剪贴板读不到时的兜底：开一个空框，光标直接落在网址输入框。
       不做识别也不预填 —— 用户在输入框里按 Ctrl+V 走的是浏览器原生粘贴，
       不受任何剪贴板 API 权限限制，这条路一定能走通。 */
    function openBoxManual(msg) {
      lastFocus = document.activeElement;
      clearError();
      fUrl.value = '';
      fTitle.value = '';
      hostEl.textContent = '';
      paintIcon(iconEl, '', '', '');
      setStatus(msg, '');
      scrim.hidden = false;
      box.hidden = false;
      document.body.style.overflow = 'hidden';
      fUrl.focus();
      fUrl.setSelectionRange(0, 0);
    }

    function closeBox() {
      scrim.hidden = true;
      box.hidden = true;
      document.body.style.overflow = '';
      busy = false;
      setBusy(false);
      lastFocus?.focus?.();
    }

    function setBusy(on) {
      busy = on;
      saveBtn.disabled = on;
      spinner.hidden = !on;
      btnText.textContent = on ? '添加中' : '添加';
    }

    document.addEventListener('paste', e => {
      if (!box.hidden) return;
      const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
      const url = extractUrl(text);
      if (!url) return;
      e.preventDefault();
      openBox(url);
    });

    /* Ctrl+V 粘贴添加。
       两条路：
         1) 剪贴板 API 读得到 → 直接开框并预填，用户在页面任意处按一下就行
         2) 读不到 → 开一个空框、光标落在网址输入框，让他自己按 Ctrl+V

       第 2 条是必须的兜底。原先这里是：
             if (!navigator.clipboard?.readText) return;
             setTimeout(async () => { try { ... } catch {} }, 60);
       三个问题叠在一起：
         ① 不支持就直接 return，一点反馈都没有；
         ② setTimeout 让它脱离了浏览器的 user gesture 判定，
            readText() 更容易被判为「非用户触发」而拒绝；
         ③ catch 是空的，权限被拒时同样静默。
       结果就是「按了完全没反应」，看起来像功能坏了。 */
    async function grabClipboard() {
      if (!navigator.clipboard || typeof navigator.clipboard.readText !== 'function') {
        openBoxManual('这个浏览器不给读剪贴板，请把网址粘贴到下面');
        return;
      }
      try {
        const t = await navigator.clipboard.readText();
        const url = extractUrl(t);
        if (url) {
          openBox(url);
        } else {
          openBoxManual('剪贴板里没找到网址，请粘贴到下面');
        }
      } catch (err) {
        // NotAllowedError：页面未聚焦 / 拒绝授权 / 非安全上下文
        openBoxManual('浏览器没授权读取剪贴板，请把网址粘贴到下面');
      }
    }

    document.addEventListener('keydown', e => {
      if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'v') return;
      if (!box.hidden) return;
      if (isTyping()) return;
      // 拦掉默认行为只在确认要处理时做（输入框里已由 isTyping 提前退出）
      grabClipboard();
    });

    document.getElementById('pasteClose')?.addEventListener('click', closeBox);
    document.getElementById('pasteCancel')?.addEventListener('click', closeBox);
    scrim?.addEventListener('click', closeBox);

    box.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); closeBox(); return; }
      // Enter 在输入框里 = 跳到下一个；最后一格 = 提交
      if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
        const fields = [...box.querySelectorAll('input, select')].filter(el => el.offsetParent !== null && !el.disabled);
        const i = fields.indexOf(e.target);
        if (i > -1 && i < fields.length - 1) {
          e.preventDefault();
          fields[i + 1].focus();
          if (fields[i + 1].select) fields[i + 1].select();
        } else {
          e.preventDefault();
          form.requestSubmit();
        }
      }
    });

    fUrl.addEventListener('input', clearError);
    fTitle.addEventListener('input', clearError);

    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (busy) return;
      clearError();

      const url = fUrl.value.trim();
      const title = fTitle.value.trim();
      if (!url) { fUrl.setAttribute('aria-invalid', 'true'); fUrl.focus(); return showError('请填写网址'); }
      if (!title) { fTitle.setAttribute('aria-invalid', 'true'); fTitle.focus(); return showError('请填写名称'); }

      setBusy(true);
      try {
        const data = await api({
          action: 'add',
          url,
          title,
          group_id: Number(fGroup.value || 0),
          icon: iconEl.classList.contains('has-img') ? iconEl.querySelector('img')?.getAttribute('src') : '',
        });
        insertCard(data.link);
        closeBox();
        toast('已添加「' + data.link.title + '」', 'ok');
      } catch (err) {
        // 出错不清空输入，只提示
        showError(err.message);
        setBusy(false);
      }
    });
  }

  /* ==================================================================
     5. 卡片渲染（新增 / 编辑后都用它，保证两处一致）
     ================================================================== */
  function cardHTML(link, newTab) {
    const host = (() => { try { return new URL(link.url).hostname.replace(/^www\./, ''); } catch { return link.host || ''; } })();
    const showDesc = document.querySelector('.link-sub') !== null;
    const hasIcon = !!link.icon;

    return `
      <a class="link" href="${esc(link.url)}" ${newTab ? 'target="_blank" rel="noopener noreferrer"' : ''} data-href="${esc(link.url)}">
        <span class="link-icon${hasIcon ? ' has-img' : ''}" aria-hidden="true">
          ${hasIcon ? `<img src="${esc(link.icon)}" alt="" width="24" height="24" decoding="async">`
                    : `<span class="initials">${esc(initials(link.title))}</span>`}
        </span>
        <span class="link-body">
          <span class="link-title">${esc(link.title)}</span>
          ${showDesc ? `<span class="link-sub">${esc(link.descr || host)}</span>` : ''}
        </span>
        ${link.hidden ? `<span class="link-priv" title="隐藏链接，仅登录可见" aria-label="隐藏链接">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
        </span>` : ''}
        <span class="link-edit" data-edit="${link.id}" role="button" tabindex="0" aria-label="编辑 ${esc(link.title)}">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
        </span>
      </a>`;
  }

  const PICK_SVG = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';

  /* 生成勾选框。卡片每被重建一次就要重新造一个（innerHTML 会把旧的冲掉），
     所以做成函数而不是模板里的一段字符串 —— 勾选态也得跟着一起还原。 */
  function makePickBox(id, title) {
    const lb = document.createElement('label');
    lb.className = 'pick-box';
    lb.innerHTML = `<input type="checkbox" data-pick="${id}">`
      + `<span class="pick-mark" aria-hidden="true">${PICK_SVG}</span>`
      + `<span class="sr-only">选择「${esc(title)}」</span>`;
    lb.querySelector('input').checked = picked.has(String(id));
    return lb;
  }

  function fillItem(li, link) {
    const isPicked = picked.has(String(link.id));
    li.className = 'link-item' + (isPicked ? ' is-picked' : '');
    li.dataset.id = link.id;
    li.dataset.title = link.title;
    li.dataset.url = link.url;
    li.dataset.descr = link.descr || '';
    li.dataset.group = link.group_id || 0;
    li.dataset.hidden = link.hidden ? '1' : '0';
    li.dataset.icon = link.icon || '';
    const host = (() => { try { return new URL(link.url).hostname.replace(/^www\./, ''); } catch { return ''; } })();
    li.style.setProperty('--hue', link.hue ?? hue(host));
    const newTab = document.querySelector('a.link[target="_blank"]') !== null;
    li.innerHTML = cardHTML(link, newTab);
    if (document.body.classList.contains('is-admin')) {
      li.prepend(makePickBox(link.id, link.title));
    }
  }

  function insertCard(link) {
    let list = null;
    if (link.group_id) list = document.querySelector(`.links[data-group="${link.group_id}"]`);
    if (!list) list = document.querySelector('.links[data-group="0"]') || document.querySelector('.links');
    if (!list) { location.reload(); return; }

    const li = document.createElement('li');
    li.style.setProperty('--i', '0');
    fillItem(li, link);
    list.appendChild(li);
    bumpGroupCount(link.group_id || 0, 1);
    showGroupOf(link.group_id || 0);
    li.animate?.(
      [{ boxShadow: '0 0 0 0 rgba(240,180,41,.9)' }, { boxShadow: '0 0 0 14px rgba(240,180,41,0)' }],
      { duration: 900, easing: 'ease-out' }
    );
    li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  /* ==================================================================
     6. 就地编辑（不跳后台）
     ================================================================== */
  const eScrim  = document.getElementById('editScrim');
  const eBox    = document.getElementById('editBox');
  const eForm   = document.getElementById('editForm');
  const hasEdit = !!(eBox && eForm);
  /* openEditor 是在下面 if (hasEdit) 块里用 function 声明的，
     块级作用域外看不到。这里先立一个空变量，块内再赋值，
     块外统一用 openEditorRef(li) —— 比到处写 window.__vnavOpenEditor
     更短，也免得一处改名忘了另一处。 */
  let openEditorRef = null;

  if (hasEdit) {
    const eId     = document.getElementById('eId');
    const eUrl    = document.getElementById('eUrl');
    const eTitle  = document.getElementById('eTitle');
    const eGroup  = document.getElementById('eGroup');
    const eErr    = document.getElementById('editError');
    const eIcon   = document.getElementById('editIcon');
    const eHost   = document.getElementById('editHost');
    const eStatus = document.getElementById('editStatus');
    const eSave   = document.getElementById('editSave');
    const eSaveText = eSave.querySelector('.btn-text');
    const eSpinner = eSave.querySelector('.spinner');
    let editLastFocus = null;
    let editBusy = false;

    function showEditError(msg) {
      eErr.textContent = msg;
      eErr.hidden = false;
    }
    function clearEditError() {
      eErr.hidden = true;
      eErr.textContent = '';
      eUrl.removeAttribute('aria-invalid');
      eTitle.removeAttribute('aria-invalid');
    }

    function openEditor(li) {
      const id = li.dataset.id;
      if (!id) return;
      editLastFocus = document.activeElement;
      clearEditError();
      eId.value = id;
      eUrl.value = li.dataset.url || '';
      eTitle.value = li.dataset.title || '';
      eGroup.value = li.dataset.group || '0';

      try { eHost.textContent = new URL(eUrl.value).hostname.replace(/^www\./, ''); }
      catch { eHost.textContent = ''; }
      paintEditIcon(li.dataset.icon || '', eTitle.value, eHost.textContent);
      eStatus.textContent = '改完按 Ctrl+S 或回车保存';

      eScrim.hidden = false;
      eBox.hidden = false;
      document.body.style.overflow = 'hidden';
      eTitle.focus();
      eTitle.select();
    }

    function paintEditIcon(icon, title, host) {
      if (icon) {
        eIcon.classList.add('has-img');
        eIcon.innerHTML = `<img src="${esc(icon)}" alt="" width="40" height="40">`;
      } else {
        eIcon.classList.remove('has-img');
        eIcon.innerHTML = `<span class="initials">${esc(initials(title || host))}</span>`;
      }
    }

    function closeEditor(force) {
      // 有改动时先确认，避免「按 Esc 手滑丢掉刚写的」
      if (!force && isDirty()) {
        if (!confirm('有未保存的修改，确定放弃吗？')) return;
      }
      eScrim.hidden = true;
      eBox.hidden = true;
      document.body.style.overflow = '';
      editBusy = false;
      eSave.disabled = false;
      eSpinner.hidden = true;
      eSaveText.textContent = '保存';
      editLastFocus?.focus?.();
    }

    function isDirty() {
      const li = document.querySelector(`.link-item[data-id="${eId.value}"]`);
      if (!li) return false;
      return eUrl.value.trim() !== (li.dataset.url || '')
        || eTitle.value.trim() !== (li.dataset.title || '')
        || String(eGroup.value) !== String(li.dataset.group || '0')

    }

    async function saveEditor() {
      if (editBusy) return;
      clearEditError();

      const id = Number(eId.value || 0);
      const url = eUrl.value.trim();
      const title = eTitle.value.trim();
      if (!id) return showEditError('缺少链接 ID');
      if (!url) { eUrl.setAttribute('aria-invalid', 'true'); eUrl.focus(); return showEditError('请填写网址'); }
      if (!title) { eTitle.setAttribute('aria-invalid', 'true'); eTitle.focus(); return showEditError('请填写名称'); }

      const li = document.querySelector(`.link-item[data-id="${id}"]`);
      const oldGroup = Number(li?.dataset.group || 0);
      const newGroup = Number(eGroup.value || 0);
      const payload = {
        action: 'update',
        id,
        url,
        title,
        group_id: newGroup,
        descr: li?.dataset.descr || '',
        icon: li?.dataset.icon || '',
      };

      editBusy = true;
      eSave.disabled = true;
      eSpinner.hidden = false;
      eSaveText.textContent = '保存中';

      // 乐观更新：先改界面，失败再回滚
      const snapshot = li ? { ...li.dataset } : null;
      if (li) {
        fillItem(li, {
          id, url, title,
          group_id: newGroup,
            icon: li.dataset.icon || '',
          descr: li.dataset.descr || '',
          hue: hue((() => { try { return new URL(url).hostname; } catch { return ''; } })()),
        });
      }

      try {
        await api(payload);
        // 分组变了：把卡片搬到目标列表
        if (li && oldGroup !== newGroup) {
          const target = document.querySelector(`.links[data-group="${newGroup}"]`);
          if (target) {
            target.appendChild(li);
            bumpGroupCount(oldGroup, -1);
            bumpGroupCount(newGroup, 1);
          } else {
            location.reload();
          }
        }
        closeEditor(true);
        toast('已保存「' + title + '」', 'ok');
        pushUndo({ kind: 'edit', id, prev: snapshot });
      } catch (err) {
        // 回滚界面，保留用户已填的内容（不关弹层）
        if (li && snapshot) {
          fillItem(li, {
            id,
            url: snapshot.url || '',
            title: snapshot.title || '',
            group_id: Number(snapshot.group || 0),
            hidden: snapshot.hidden === '1' ? 1 : 0,
            icon: snapshot.icon || '',
            descr: snapshot.descr || '',
          });
        }
        showEditError(err.message);
      } finally {
        editBusy = false;
        eSave.disabled = false;
        eSpinner.hidden = true;
        eSaveText.textContent = '保存';
      }
    }

    // 卡片上的铅笔 → 打开就地编辑（不再跳后台）
    stage?.addEventListener('click', e => {
      const ed = e.target.closest('[data-edit]');
      if (!ed) return;
      e.preventDefault();
      e.stopPropagation();
      const li = ed.closest('.link-item');
      if (li) openEditor(li);
    });
    stage?.addEventListener('keydown', e => {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      const ed = e.target.closest('[data-edit]');
      if (!ed) return;
      e.preventDefault();
      e.stopPropagation();
      const li = ed.closest('.link-item');
      if (li) openEditor(li);
    });

    document.getElementById('editClose')?.addEventListener('click', () => closeEditor(false));
    document.getElementById('editCancel')?.addEventListener('click', () => closeEditor(false));
    eScrim?.addEventListener('click', () => closeEditor(false));

    eForm.addEventListener('submit', e => { e.preventDefault(); saveEditor(); });
    eUrl.addEventListener('input', clearEditError);
    eTitle.addEventListener('input', clearEditError);

    // Esc 关；Ctrl+S 存；Enter 跳下一个输入框（最后一格提交）
    eBox.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); closeEditor(false); return; }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault(); e.stopPropagation(); saveEditor(); return;
      }
      if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
        const fields = [...eBox.querySelectorAll('input:not([type=hidden]), select')]
          .filter(el => el.offsetParent !== null && !el.disabled);
        const i = fields.indexOf(e.target);
        if (i > -1 && i < fields.length - 1) {
          e.preventDefault();
          fields[i + 1].focus();
          if (fields[i + 1].select) fields[i + 1].select();
        } else {
          e.preventDefault();
          saveEditor();
        }
      }
    });

    document.getElementById('editDelete')?.addEventListener('click', async () => {
      const id = Number(eId.value || 0);
      const li = document.querySelector(`.link-item[data-id="${id}"]`);
      if (!id || !li) return;
      if (!confirm('删除「' + (li.dataset.title || '') + '」？删掉后可以用 Ctrl+Z 撤回。')) return;

      const gid = Number(li.dataset.group || 0);
      const prev = { ...li.dataset };
      const nextSibling = li.nextElementSibling;
      const parent = li.parentElement;

      // 乐观移除
      li.remove();
      bumpGroupCount(gid, -1);
      closeEditor(true);

      try {
        await api({ action: 'delete', id });
        toast('已删除「' + (prev.title || '') + '」，Ctrl+Z 可撤回', 'ok');
        pushUndo({ kind: 'delete', id, prev, parent, nextSibling, gid });
      } catch (err) {
        // 回滚：放回原位
        if (parent) {
          if (nextSibling && nextSibling.parentElement === parent) parent.insertBefore(li, nextSibling);
          else parent.appendChild(li);
          bumpGroupCount(gid, 1);
        }
        toast(err.message, 'bad');
      }
    });

    // 供快捷键调用
    window.__vnavSaveEditor = saveEditor;
    window.__vnavCloseEditor = closeEditor;
    window.__vnavOpenEditor = openEditor;
    window.__vnavIsEditorOpen = () => !eBox.hidden;
    // openEditor 定义在 if (hasEdit) 块里，块外拿不到这个名字。
    // 挂一份到 window 上，块外的「点卡片打开」和快捷键表都能用。
    openEditorRef = openEditor;
  }

  /* ==================================================================
     7. 撤销栈（只做「一步」，够用且不会误伤）
     ================================================================== */
  const undoStack = [];
  function pushUndo(op) {
    undoStack.push(op);
    if (undoStack.length > 20) undoStack.shift();
  }

  async function undo() {
    const op = undoStack.pop();
    if (!op) { toast('没有可撤销的操作'); return; }

    try {
      if (op.kind === 'move') {
        // 反向移动
        const li = document.querySelector(`.link-item[data-id="${op.id}"]`);
        if (li) {
          const sib = op.dir === 'up' ? li.nextElementSibling : li.previousElementSibling;
          if (sib) {
            if (op.dir === 'up') sib.after(li); else sib.before(li);
            await persistOrder(li.closest('.links'));
            toast('已撤销移动', 'ok');
          }
        }
      } else if (op.kind === 'reorderMulti' || op.kind === 'reorder') {
        /* 拖动排序的撤销：按记下的 id 串把 DOM 顺序还原。
           直接按 id 重排而不是「反向挪一位」，因为拖动会一次跨好几张卡。
           卡片可能已被拖到别的组，所以查找必须是全局的。 */
        const changes = op.changes || [{ list: op.list, before: op.orderBefore }];
        const touched = [];
        for (const c of changes) {
          if (!c.list || !c.list.isConnected) continue;
          for (const id of String(c.before).split(',').filter(Boolean)) {
            const el = document.querySelector(`.link-item[data-id="${id}"]`);
            if (el) c.list.appendChild(el);
          }
          touched.push({ list: c.list });
        }
        if (touched.length) {
          await persistOrderMulti(touched);
          toast('已撤销排序', 'ok');
        }
      } else if (op.kind === 'delete') {
        // 重新插入（新 id 会和旧的不同，所以直接重载最稳）
        await api({
          action: 'add',
          url: op.prev.url || '',
          title: op.prev.title || '',
          group_id: Number(op.gid || 0),
          hidden: op.prev.hidden === '1' ? 1 : 0,
          icon: op.prev.icon || '',
          descr: op.prev.descr || '',
        });
        toast('已恢复「' + (op.prev.title || '') + '」', 'ok');
        setTimeout(() => location.reload(), 400);
      } else if (op.kind === 'edit') {
        // 把旧值写回去
        if (op.prev) {
          await api({
            action: 'update',
            id: op.id,
            url: op.prev.url || '',
            title: op.prev.title || '',
            group_id: Number(op.prev.group || 0),
            hidden: op.prev.hidden === '1' ? 1 : 0,
            icon: op.prev.icon || '',
            descr: op.prev.descr || '',
          });
          toast('已撤销修改', 'ok');
          setTimeout(() => location.reload(), 400);
        }
      }
    } catch (err) {
      toast('撤销失败：' + err.message, 'bad');
    }
  }

  /* ==================================================================
     8. 编辑模式：拖动排序 + Alt+↑↓ 移动
     ================================================================== */
  let editing = false;
  let selected = null;

  const editToggle = document.getElementById('editToggle');
  const body = document.body;

  function setEditing(on) {
    editing = on;
    body.classList.toggle('is-editing', on);
    editToggle?.setAttribute('aria-pressed', on ? 'true' : 'false');

    /* 按钮的可见形态由 CSS 按 aria-pressed 切换（铅笔 ⇄ 叉），
       但 title 与 sr-only 文案必须同步 —— 否则读屏用户听到的还是
       「编辑模式」，而视觉上已经是「退出」了。 */
    if (editToggle) {
      editToggle.title = on ? '退出编辑模式（Esc 或 Ctrl+E）' : '编辑模式（Ctrl+E）';
      const lb = document.getElementById('editToggleLabel');
      if (lb) lb.textContent = on ? '退出编辑模式' : '编辑模式';
    }

    /* 编辑模式下必须把卡片里的 <a> 的原生拖拽关掉。
       <a href> 天生 draggable，鼠标按住一移动，浏览器就启动 HTML5 拖放，
       那套机制会接管指针并截断 pointermove —— 我们自己的拖动再也收不到
       移动事件。实测症状：整卡拖动毫无反应，但左上角把手能拖（把手不是链接）。
       进编辑态关掉、退出时恢复，非编辑态保持浏览器默认（链接可拖到收藏夹）。 */
    document.querySelectorAll('.links a.link').forEach(a => {
      if (on) {
        a.setAttribute('draggable', 'false');
      } else {
        a.removeAttribute('draggable');
      }
    });

    /* 退出编辑态必须清空勾选：批量条只在编辑态有意义，
       带着三个勾选退出去，底部那条会一直浮在那儿，而且下次进来
       会莫名其妙已经有选中项。 */
    if (!on) { selectItem(null); clearPicks(); }
    if (on) {
      /* 这里刻意不弹 toast：进入编辑模式后页面顶部已经有一条常驻提示条
         （.stage::before）。两条同时出现就是同一句话说两遍，
         还挡住卡片。常驻条能一直看着，比一闪而过的 toast 更有用。 */
      // 第一个卡片给焦点，键盘用户马上能上下移动
      $$('.link-item')[0]?.focus?.();
    }
  }

  /* noScroll：拖动时需要。selectItem 里默认会 scrollIntoView 把选中卡滚进视野，
     但触摸拖动一开始就触发平滑滚动，浏览器会立刻把手势判给滚屏并抛出
     pointercancel —— 表现为"手指按在把手上拖，页面却在滚，卡片纹丝不动"。
     鼠标端不会冲突（没有手势竞争），所以这个坑只在真触摸上暴露。 */
  function selectItem(li, noScroll) {
    $$('.link-item.is-selected').forEach(x => x.classList.remove('is-selected'));
    selected = li;
    if (li) {
      li.classList.add('is-selected');
      li.tabIndex = 0;
      if (!noScroll) {
        li.focus({ preventScroll: true });
        li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }
    }
  }

  editToggle?.addEventListener('click', () => setEditing(!editing));

  /* ==================================================================
     8.5 批量选择与批量操作
     ------------------------------------------------------------------
     勾选框只在编辑态出现（CSS 控制）；选中任意一张，底部浮出操作条。
     动作分两类：
       · 删除 / 移动 —— 就地改 DOM，不刷新（一次操作只影响几行）
       · 拉取图标 / 信息 —— 分批请求，拿服务端回来的 items 逐张改
     刻意不做「操作完 reload」：刷新会吞掉编辑态和滚动位置，
     而用户处理批量时往往要连着来好几趟。
     ================================================================== */
  const bulkBar   = document.getElementById('bulkBar');
  const bulkCount = document.getElementById('bulkCount');
  const bulkAll   = document.getElementById('bulkAll');
  const bulkGroup = document.getElementById('bulkGroup');

  function syncBulkBar() {
    if (!bulkBar) return;
    const n = picked.size;
    bulkBar.hidden = n === 0;
    /* 正文底部补留白：批量条是 fixed，不补的话滚到最底下时
       它会压住最后一行卡片（那一屏就永远看不全）。 */
    document.body.classList.toggle('is-picking', n > 0);
    if (bulkCount) bulkCount.textContent = '已选 ' + n + ' 项';
    if (bulkAll) {
      const all = $$('.link-item');
      const onAll = all.length > 0 && all.every(li => picked.has(String(li.dataset.id)));
      bulkAll.checked = onAll;
      /* 部分选中要显示成横杠。只置空框的话，用户会以为「全选」没生效。 */
      bulkAll.indeterminate = n > 0 && !onAll;
    }
  }

  function clearPicks() {
    picked.clear();
    $$('.link-item.is-picked').forEach(li => {
      li.classList.remove('is-picked');
      const cb = li.querySelector('.pick-box input');
      if (cb) cb.checked = false;
    });
    syncBulkBar();
  }

  function setPicked(li, on) {
    if (!li) return;
    const id = String(li.dataset.id);
    if (on) picked.add(id); else picked.delete(id);
    li.classList.toggle('is-picked', on);
    const cb = li.querySelector('.pick-box input');
    if (cb) cb.checked = on;
    syncBulkBar();
  }

  stage?.addEventListener('change', e => {
    const cb = e.target.closest('.pick-box input');
    if (!cb) return;
    setPicked(cb.closest('.link-item'), cb.checked);
  });

  bulkAll?.addEventListener('change', () => {
    const on = !!bulkAll.checked;
    $$('.link-item').forEach(li => setPicked(li, on));
  });

  /* 把一张卡换成服务端刚抓到的值。
     只改文本节点和图标，不整卡重建 —— 重建会把勾选框和动画状态一起丢掉。 */
  function applyMeta(id, got) {
    const li = document.querySelector('.link-item[data-id="' + Number(id) + '"]');
    if (!li || !got) return;
    if (got.title) {
      li.dataset.title = got.title;
      const t = li.querySelector('.link-title');
      if (t) t.textContent = got.title;
    }
    if (got.descr) {
      li.dataset.descr = got.descr;
      const d = li.querySelector('.link-sub');
      if (d) d.textContent = got.descr;
    }
    if (got.icon) {
      li.dataset.icon = got.icon;
      const box = li.querySelector('.link-icon');
      if (box) {
        box.classList.add('has-img');
        box.innerHTML = '<img src="' + esc(got.icon) + '" alt="" loading="lazy">';
      }
    }
  }

  /* 分批拉取。8 条一批：抓外站是慢活，一批太大容易撞 PHP 执行上限，
     太小则是十几次往返。服务端也限了 20 条上限。 */
  async function bulkFetch(mode, btn) {
    const ids = [...picked].map(Number);
    if (!ids.length) return;
    const isIcon = mode === 'icon';
    const label = isIcon ? '图标' : '信息';
    const span = btn?.querySelector('span');
    const oldText = span ? span.textContent : '';
    const CHUNK = 8;
    let ok = 0;
    let bad = 0;
    let done = 0;

    if (btn) btn.disabled = true;
    try {
      for (let i = 0; i < ids.length; i += CHUNK) {
        const part = ids.slice(i, i + CHUNK);
        const res = await api({ action: 'bulk_fetch', mode, ids: part });
        const r = res.result || {};
        ok += r.ok || 0;
        bad += r.bad || 0;
        if (r.items) {
          for (const [id, val] of Object.entries(r.items)) {
            applyMeta(id, isIcon ? { icon: val } : val);
          }
        }
        done += part.length;
        if (span) span.textContent = label + ' ' + done + '/' + ids.length;
      }
      toast(bad ? ('已更新 ' + ok + ' 条，' + bad + ' 条抓不到') : ('已更新 ' + ok + ' 条' + label),
        bad ? 'bad' : 'ok');
    } catch (err) {
      toast('拉取' + label + '失败：' + err.message, 'bad');
    } finally {
      if (btn) btn.disabled = false;
      if (span) span.textContent = oldText;
    }
  }

  async function bulkDelete() {
    const ids = [...picked].map(Number);
    if (!ids.length) return;
    /* 这里破例弹一次确认框：单条删除能靠 toast 上的「撤销」兜底，
       批量删除没有对应的恢复接口 —— 一次删掉二十条，没有二次确认
       就等于把不可逆的动作放在一次误触上。 */
    if (!confirm('删除选中的 ' + ids.length + ' 条链接？删掉之后不能撤销。')) return;
    try {
      const res = await api({ action: 'bulk', op: 'delete', ids });
      ids.forEach(id => {
        const li = document.querySelector('.link-item[data-id="' + id + '"]');
        if (!li) return;
        bumpGroupCount(Number(li.dataset.group) || 0, -1);
        li.remove();
      });
      clearPicks();
      toast('已删除 ' + (res.affected || ids.length) + ' 条');
    } catch (err) {
      toast(err.message, 'bad');
    }
  }

  async function bulkMoveTo(gid) {
    const ids = [...picked].map(Number);
    if (!ids.length) return;
    const target = document.querySelector('.links[data-group="' + gid + '"]');
    try {
      const res = await api({ action: 'bulk', op: 'move', ids, group_id: gid });
      ids.forEach(id => {
        const li = document.querySelector('.link-item[data-id="' + id + '"]');
        if (!li) return;
        const from = Number(li.dataset.group) || 0;
        bumpGroupCount(from, -1);
        bumpGroupCount(gid, 1);
        li.dataset.group = String(gid);
        if (target) target.appendChild(li);
      });
      toast(target
        ? ('已移动 ' + (res.affected || ids.length) + ' 条')
        : ('已移动 ' + (res.affected || ids.length) + ' 条，刷新后可在目标分组看到'));
      clearPicks();
    } catch (err) {
      toast(err.message, 'bad');
    } finally {
      if (bulkGroup) bulkGroup.value = '';
    }
  }

  bulkBar?.addEventListener('click', e => {
    const btn = e.target.closest('[data-bulk]');
    if (!btn) return;
    const op = btn.dataset.bulk;
    if (op === 'clear') { clearPicks(); return; }
    if (op === 'delete') { bulkDelete(); return; }
    if (op === 'icon' || op === 'meta') { bulkFetch(op, btn); }
  });

  /* 下拉选完就立刻执行 —— 批量条上没有「确定」按钮的位置，
     选一个分组＝下一个指令，这是这个控件的全部语义。 */
  bulkGroup?.addEventListener('change', () => {
    const gid = Number(bulkGroup.value);
    if (!gid) return;
    bulkMoveTo(gid);
  });

  /* 编辑模式下点卡片 = 直接改。
     用 pointerup 之后浏览器仍会再派发一次 click，而拖动结束时不希望它
     触发编辑器。这里用一个时间戳闸门：刚刚发生过拖动（或刚结束）就吞掉。
     ____suppressClickUntil 由拖动逻辑写入，比靠标志位更稳 ——
     标志位容易在异常路径上忘了复位，时间戳最多多吞 300ms 的点击。 */
  stage?.addEventListener('click', e => {
    if (!editing) return;
    if (Date.now() < __suppressClickUntil) { e.preventDefault(); e.stopPropagation(); return; }
    const li = e.target.closest('.link-item');
    if (!li) return;
    if (e.target.closest('[data-edit]')) return; // 铅笔走自己的逻辑，别开两次
    if (e.target.closest('.pick-box')) return; // 勾选框走自己的 change 事件
    e.preventDefault();
    selectItem(li);
    if (typeof openEditorRef === 'function') openEditorRef(li);
  });

  /* ---- 拖动排序 ----
     两种起手方式：
       ① 左上角六点把手 —— 按下即拖，没有阈值，最精确
       ② 卡片任意位置   —— 按住并按超过 6px 才算拖，松手没超过就算点击（开编辑器）
     为什么要阈值：同一张卡既要能「点开改」又要能「按住拖」，
     不设阈值的话，手一抖就会把一次点击变成一次排序，还会误存。
     6px 是个经验值：小于它人不会认为自己「拖了」，大于它鼠标抖不出来。 */
  let dragEl = null;

  const DRAG_THRESHOLD = 6;
  /* 触摸端要「长按 260ms 才起拖」。
     为什么不能像鼠标那样「移动 6px 就起拖」：手指的位移和滚屏手势是同一套输入，
     只要 touch-action 还允许纵向滚动，浏览器就会在位移超过一点时把手势
     判给滚屏并抛 pointercancel。等浏览器判完再抢已经晚了。
     所以触摸走另一条路 —— 先按住不动 260ms（这段时间内浏览器认定是长按、
     不起滚），起拖后立刻把 touch-action 降成 none，此后手势就归我们了。
     260ms 是调过的：短于 200ms 误触太多，长于 350ms 又要等得明显。 */
  const TOUCH_HOLD_MS = 260;
  /* 长按期间手指允许的抖动。超过它就算「用户在滑屏」，放弃起拖、让他滚。 */
  const TOUCH_HOLD_SLOP = 8;

  let __suppressClickUntil = 0;

  stage?.addEventListener('pointerdown', e => {
    if (!editing) return;
    // 铅笔有自己的职责，别抢
    if (e.target.closest('[data-edit]')) return;
    if (e.target.closest('.pick-box')) return; // 勾选框：点它就是打勾，不是起拖
    // 只接左键和触摸/笔；右键、中键留给系统菜单
    if (e.button !== 0 && e.pointerType === 'mouse') return;

    const li = e.target.closest('.link-item');
    if (!li) return;

    /* 就地兜一层：把本卡里的 <a> 临时改成不可原生拖拽。
       只在 setEditing 里统一改是不够的 —— 编辑态下粘贴新增的卡片、
       或拖动中重排后新插进来的节点，都可能还没被打上 draggable=false。
       这里在 pointerdown 的同一帧里改，成本极低，且一定赶在浏览器
       启动 HTML5 拖放之前。 */
    li.querySelectorAll('a.link').forEach(a => a.setAttribute('draggable', 'false'));

    const startX = e.clientX;
    const startY = e.clientY;
    let started = false;   // 是否已经越过阈值、真正进入拖动
    let moved = false;

    const list = li.parentElement;
    if (!list) return;

    /* 拖动可以跨组，所以「顺序快照」必须是全局的：只记本组的 id 串，
       就发现不了「这张卡已经被拖到别的组去了」。
       用 Map<容器, "id,id,id"> 记下每个组拖动前的顺序，松手时逐组比对，
       只保存真正变了的那些 —— 一次拖动最多两个（源组少一张、目标组多一张）。 */
    const snapshotOrder = () => {
      const m = new Map();
      for (const l of document.querySelectorAll('.links')) {
        m.set(l, [...l.querySelectorAll('.link-item')].map(x => x.dataset.id).join(','));
      }
      return m;
    };
    const orderBefore = snapshotOrder();

    /* ----------------------------------------------------------------
       为什么是「真的挪这张卡」而不是插一条占位线：

       之前的做法是造一个 <li class="drop-marker"> 插进网格示意落点。
       它在卡片形态（单列）下没问题，一换成多列宫格/横排就废了 ——
       因为那条占位线**本身也占一个网格单元**，一插进去后面所有卡片
       整体被推一格，于是「谁在谁的左半边」这个参照系在拖动过程中一直在漂。
       实测表现就是：同一行内从左往右拖，指示线永远黏在自己屁股后面不动。

       改成真挪之后有两个直接好处：
         ① 没有额外的网格单元，坐标参照系全程稳定；
         ② 用户看到的是卡片本身在让位，这才是「拖卡片」该有的手感。

       代价是要防一件事：拖动中不断重排 DOM，会不会打断 pointermove？
       不会 —— 事件监听挂在 document 上，而重排只动 list 的子节点，
       我们的指针从头到尾没离开过 document。这一点实测已确认。
       ---------------------------------------------------------------- */

    let snap = null;       // 拖动开始那一刻的矩形快照，见下方 takeSnapshot
    let hosts = null;      // 各组卡片容器的矩形，用来判定「指针现在在哪一组」
    let navTargets = null; // 侧栏各分组项的矩形：拖到组名上 = 直接归到那一组
    let navHoverEl = null; // 当前被指着的侧栏项（高亮用）

    const setNavHover = el => {
      if (navHoverEl === el) return;
      if (navHoverEl) navHoverEl.classList.remove('is-drop-target');
      navHoverEl = el;
      if (el) el.classList.add('is-drop-target');
    };

    /* 拖动中每次重排前先记下所有卡的旧位置，重排后用 FLIP 反向补偿一段
       transform，再让它过渡回 0 —— 卡片就是"滑"过去的，而不是瞬移。
       这不是炫技：没有它的话，每次跨位所有卡瞬间跳一下，人眼会以为点错了。
       尊重 prefers-reduced-motion：该模式下直接跳过，不做任何动画。 */
    const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    const flipAround = mutate => {
      if (reduceMotion()) { mutate(); return; }
      // 跨组拖动时被挪动的是另一个容器里的卡片，动画对象必须是全局的
      const kids = [...document.querySelectorAll('.link-item')];
      const before = new Map();
      for (const k of kids) before.set(k, k.getBoundingClientRect());

      mutate();

      for (const k of kids) {
        if (k === li) continue;              // 被拖的那张有独立样式，别抢
        if (!k.isConnected) continue;
        const b = before.get(k);
        if (!b) continue;
        const a = k.getBoundingClientRect();
        const dx = b.left - a.left;
        const dy = b.top - a.top;
        if (Math.abs(dx) < 1 && Math.abs(dy) < 1) continue;
        k.animate(
          [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }],
          { duration: 180, easing: 'cubic-bezier(.22,.61,.36,1)' }
        );
      }
    };

    /* 拖动全程用「开始那一刻」的几何快照判定，不每次重读 getBoundingClientRect。
       因为我们一边拖一边真挪 DOM，实时读到的坐标是"已经排好之后"的，
       拿它反过来决定该排到哪儿 —— 逻辑上就是自证，会抖。
       快照只在拖起来那一刻取一次，之后是纯粹的数学，稳定且可预测。 */
    const takeSnapshot = () => {
      snap = [];
      /* 快照从「本组」扩到「全站」：跨组落点要拿别组的卡片当参照，
         否则拖进另一个组时找不到插入点，只能追加到末尾。 */
      for (const it of document.querySelectorAll('.link-item')) {
        if (it === li) continue;
        const r = it.getBoundingClientRect();
        if (r.width < 1) continue;           // 被隐藏的卡片不参与定位
        snap.push({
          el: it,
          host: it.closest('.links'),
          top: r.top,
          bottom: r.bottom,
          midX: r.left + r.width / 2,
          midY: r.top + r.height / 2,
        });
      }
      // 各容器的矩形，用来判断指针此刻落在哪一组
      hosts = [];
      for (const l of document.querySelectorAll('.links')) {
        const r = l.getBoundingClientRect();
        hosts.push({ el: l, top: r.top, bottom: r.bottom, left: r.left, right: r.right });
      }
      /* 侧栏项也拍一份。拖到组名上是「归到那一组」最直接的动作 ——
         主区里卡片往往要滚很远才看到目标组，侧栏永远在视野里。 */
      navTargets = [];
      for (const it of document.querySelectorAll('.gnav-item')) {
        const r = it.getBoundingClientRect();
        if (r.width < 1) continue;
        navTargets.push({ el: it, top: r.top, bottom: r.bottom, left: r.left, right: r.right });
      }
    };

    const beginDrag = () => {
      started = true;
      dragEl = li;
      li.classList.add('is-dragging');
      /* noScroll=true：拖起来的瞬间绝不能滚页面。
         selectItem 默认会 scrollIntoView，触摸端这会立刻把手势判给滚屏
         并抛 pointercancel，拖动当场夭折（实测踩过，鼠标端不暴露）。 */
      selectItem(li, true);
      document.body.classList.add('is-dragging');
      // 一旦真的拖起来，就标记成"待吞点击"状态
      __suppressClickUntil = Date.now() + 400;
      takeSnapshot();
    };

    /** 按指针位置把 li 挪到正确的空档里去（可以跨组） */
    const placeCard = (x, y) => {
      if (!snap) takeSnapshot();

      /* ⓪ 侧栏优先。指针压在某个分组名上时，就地高亮、什么都不挪 ——
         松手那一刻才把卡片整张归过去（见 onUp）。拖动途中不挪，是为了
         让用户还能反悔：手移回主区，高亮灭掉，原样落回原位置。 */
      const hitNav = navTargets && navTargets.find(n =>
        x >= n.left && x <= n.right && y >= n.top && y <= n.bottom);
      if (hitNav) { setNavHover(hitNav.el); return; }
      setNavHover(null);

      /* ① 先判断指针落在哪一组的卡片区。
         严格包含优先；落空时退到「垂直距离最近、且水平在范围内」的那一组 ——
         组与组之间有一段 32px 的空档，指针在那里时不能突然跳回原组。 */
      let host = null;
      for (const h of hosts) {
        if (y >= h.top && y <= h.bottom && x >= h.left && x <= h.right) { host = h.el; break; }
      }
      if (!host) {
        let best = null, bestD = Infinity;
        for (const h of hosts) {
          if (x < h.left || x > h.right) continue;
          const d = y < h.top ? h.top - y : (y > h.bottom ? y - h.bottom : 0);
          if (d < bestD) { bestD = d; best = h.el; }
        }
        host = best || list;
      }

      /* ② 在这一组的卡片里找插入点。阅读顺序判定：先纵向，再横向 ——
         同一行内比左边缘中线，不同行比上边缘中线。只比 Y 的话
         「同一行内往右拖」完全没反应，多列形态下几乎没法往右挪。 */
      let target = null;
      for (const s of snap) {
        if (s.host !== host) continue;
        const inRow = y >= s.top && y <= s.bottom;
        const before = inRow ? (x < s.midX) : (y < s.midY);
        if (before) { target = s.el; break; }
      }

      // 目标位置没变就别动 DOM，否则 FLIP 会被无意义地反复触发
      const sameHost = li.parentElement === host;
      if (target) {
        if (sameHost && li.nextElementSibling === target) return;
      } else {
        if (sameHost && !li.nextElementSibling) return;
      }

      flipAround(() => {
        if (target) target.before(li);
        else host.appendChild(li);
      });

      /* ③ 只有换了组的才重拍快照。同组内重排的几何关系没变（卡片高度一致），
         重拍等于把「拖拽期间坐标稳定」这个前提白扔了。 */
      if (!sameHost) takeSnapshot();
    };

    /* 自动滚屏：拖到视口上下边缘时自己滚，不必先松手再滚再拖。
       没有这个的话，想把卡片挪出当前一屏基本不可能 ——
       手指一离开卡片就松手了，只能一格一格挪。
       边缘判定用 88px（比一行的常规高度略大），滚动速度随深入程度线性加快，
       越靠近边缘越快，手感接近原生拖放。 */
    const EDGE = 88;
    const MAX_STEP = 22;
    let autoScrollRaf = 0;
    let lastX = 0, lastY = 0;

    const stopAutoScroll = () => {
      if (autoScrollRaf) { cancelAnimationFrame(autoScrollRaf); autoScrollRaf = 0; }
    };

    const tickAutoScroll = () => {
      autoScrollRaf = 0;
      if (!started) return;
      const vh = window.innerHeight;
      let step = 0;
      if (lastY < EDGE) {
        step = -Math.ceil(((EDGE - lastY) / EDGE) * MAX_STEP);
      } else if (lastY > vh - EDGE) {
        step = Math.ceil(((lastY - (vh - EDGE)) / EDGE) * MAX_STEP);
      }
      if (!step) return;
      const before = window.scrollY;
      window.scrollBy(0, step);
      // 滚动了就重新算一次落点，否则卡片不会跟着新的可见区域走
      if (window.scrollY !== before) placeCard(lastX, lastY);
      autoScrollRaf = requestAnimationFrame(tickAutoScroll);
    };

    const kickAutoScroll = (x, y) => {
      lastX = x; lastY = y;
      const vh = window.innerHeight;
      const near = y < EDGE || y > vh - EDGE;
      if (near && !autoScrollRaf) autoScrollRaf = requestAnimationFrame(tickAutoScroll);
      else if (!near) stopAutoScroll();
    };

    /* 触摸端的起拖靠一个计时器：按住 260ms 不动才起拖。
       鼠标端不需要 —— 它一开始移动就能抢，浏览器不会来干预。 */
    let holdTimer = 0;
    const clearHold = () => { if (holdTimer) { clearTimeout(holdTimer); holdTimer = 0; } };

    /* 起拖之后要阻止页面跟着手指滚。
       注意：**不能**改 touch-action 来实现。规范里写得很明确 ——
       手势进行中改 touch-action 会让浏览器直接 pointercancel 掉这次手势，
       于是卡片刚拖起来就被取消、顺序又被还原回去（实测踩过，
       表现为 is-dragging 短暂为 true 但松手后顺序毫无变化）。

       正确做法：挂一个非被动（passive:false）的 touchmove 监听，
       在起拖之后把后续每一次 touchmove 都 preventDefault 掉。
       长按那 260ms 内手指没动，浏览器还没真正进入滚屏状态，
       所以此时开始拦正好来得及，滚屏不会启动。 */
    let scrollBlocked = false;
    const blockScroll = ev => { if (ev.cancelable) ev.preventDefault(); };
    const seizeGesture = on => {
      if (on) {
        if (scrollBlocked) return;
        document.addEventListener('touchmove', blockScroll, { passive: false });
        scrollBlocked = true;
      } else if (scrollBlocked) {
        document.removeEventListener('touchmove', blockScroll, { passive: false });
        scrollBlocked = false;
      }
    };

    const onMove = ev => {
      if (!started) {
        const dx = ev.clientX - startX;
        const dy = ev.clientY - startY;
        const dist = Math.hypot(dx, dy);

        /* 触摸：还没到长按时长就先只观察。
           手指一动超过 TOUCH_HOLD_SLOP 就说明用户在滑屏，放弃这次拖动。 */
        if (ev.pointerType === 'touch') {
          if (dist > TOUCH_HOLD_SLOP) { clearHold(); return; }
          return;
        }

        /* 鼠标 / 触控笔：越过 6px 才算拖，否则那一下点击要留给「打开编辑器」。
           取消把手之后这是唯一的起拖路径，阈值比以前更要紧 ——
           没有它，手一抖就会把一次点击变成一次排序，还会误存。 */
        if (dist < DRAG_THRESHOLD) return;
        beginDrag();
      }
      // 防止拖动时选中文字
      if (ev.cancelable) ev.preventDefault();
      placeCard(ev.clientX, ev.clientY);
      kickAutoScroll(ev.clientX, ev.clientY);
    };

    const onUp = async () => {
      clearHold();
      stopAutoScroll();
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onCancel);
      document.body.classList.remove('is-dragging');
      seizeGesture(false);

      if (!started) {
        /* 没起拖 = 用户本意是「点一下」。
           这里什么都不做，交给 click 处理器去开编辑器 ——
           一处开、一处不开才叫一致；两边都开就是弹两次。 */
        dragEl = null;
        return;
      }

      // 拖动结束，把紧随而来的 click 吞掉（否则一松手就弹编辑器）
      __suppressClickUntil = Date.now() + 400;

      li.classList.remove('is-dragging');

      /* 落在侧栏的分组名上：整张卡归到那一组，追加到该组末尾。
         这里只改 DOM —— 保存交给下面那套「逐组比对」，
         它已经能发现「源组少了一张、目标组多了一张」，不用另写一遍。 */
      if (navHoverEl && navHoverEl.dataset.group !== undefined) {
        const gid = Number(navHoverEl.dataset.group);
        const targetList = document.querySelector(`.links[data-group="${gid}"]`);
        if (targetList && li.parentElement !== targetList) {
          targetList.appendChild(li);
        }
      }
      setNavHover(null);

      /* 逐组比对：这次拖动可能把卡片挪进了另一个组，
         只看 list 的话「源组少了、目标组多了」两边都发现不了。 */
      const orderAfter = snapshotOrder();
      const changes = [];
      for (const [l, before] of orderBefore) {
        const after = orderAfter.get(l);
        if (after !== undefined && after !== before) changes.push({ list: l, before });
      }

      if (changes.length) {
        const okAll = await persistOrderMulti(changes);
        /* 失败时不能再报「已保存」—— persistOrder 里已经弹过具体错误，
           这里如果还弹一条绿色的成功提示，用户会以为存上了，
           下次刷新发现卡片跳回原组，只会觉得是随机 bug。 */
        if (okAll) {
          /* 记一笔，让 Ctrl+Z 能把这次拖动撤回。
             存完整的两串 order 而不是「上移/下移」——
             拖动是一次性把卡片从 A 挪到 B，不是挪一位。 */
          pushUndo({ kind: 'reorderMulti', changes });
          toast(changes.length > 1 ? '已移到别的分组' : '顺序已保存', 'ok');
        }
      }
      dragEl = null;
    };

    const onCancel = () => {
      clearHold();
      stopAutoScroll();
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onCancel);
      document.body.classList.remove('is-dragging');
      li.classList.remove('is-dragging');
      setNavHover(null);
      seizeGesture(false);
      // 取消：逐组按记下的顺序还原，别把半拉子的排列留在页面上。
      // 卡片可能已被拖到别的组，所以查找必须是全局的。
      for (const [l, before] of orderBefore) {
        for (const id of String(before).split(',').filter(Boolean)) {
          const el = document.querySelector(`.link-item[data-id="${id}"]`);
          if (el) l.appendChild(el);
        }
      }
      dragEl = null;
    };

    // 从把手起手时立刻进入拖动（不用等阈值），手感更直接
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
    document.addEventListener('pointercancel', onCancel);

    /* 关键：按住不放但还没移动时不 preventDefault，
       否则卡片里的链接连「按住看状态栏」都做不了，而且会挡掉长按菜单。
       真正进入拖动后才由 onMove 里的 preventDefault 接管。
       鼠标端一律等阈值（在 onMove 里判），只有触摸需要在这里起一条长按计时。 */
    if (e.pointerType === 'touch') {
      /* 触摸整卡：按住 260ms 不动才起拖。
         这段等待是必须的 —— 浏览器判定"这是不是滚屏手势"需要时间，
         我们抢先起拖会被 pointercancel 打断（实测）。等它判完、手指还在原位，
         起拖就稳了。期间手指一滑超过 8px 就放弃，让用户正常滚页面。 */
      holdTimer = setTimeout(() => {
        holdTimer = 0;
        if (started) return;
        beginDrag();
        seizeGesture(true);
        /* 起拖时给一次轻微振动，告诉用户"抓住了"。
           不支持振动的设备静默跳过，不影响功能。 */
        navigator.vibrate?.(12);
      }, TOUCH_HOLD_MS);
    }
  });

  /** 把这个列表里的 DOM 顺序写回数据库（连同归属组一起） */
  async function persistOrder(list) {
    if (!list) return false;
    const ids = [...list.querySelectorAll('.link-item')].map(li => Number(li.dataset.id)).filter(Boolean);
    try {
      /* group_id 是关键：跨组拖动之后，这一条 UPDATE 同时改 sort 和 group_id。
         只改 sort 的话，卡片下次刷新会跳回原来的组 —— 因为归属根本没变。 */
      await api({ action: 'reorder', ids, group_id: Number(list.dataset.group || 0) });
      return true;
    } catch (err) {
      toast('排序没保存成功：' + err.message, 'bad');
      return false;
    }
  }

  /**
   * 一次保存多个组。
   * 跨组拖动同时改变两个组：源组少一张、目标组多一张，两边都得写。
   * 串行而不是并发 —— 两条 UPDATE 在同一个 SQLite 文件上竞争写锁，
   * 并发反而更容易撞 database is locked。
   */
  async function persistOrderMulti(changes) {
    let ok = true;
    for (const c of changes) {
      if (!(await persistOrder(c.list))) ok = false;
    }
    return ok;
  }

  /** Alt+↑/↓ 上移下移一位 */
  async function moveSelected(dir) {
    const li = selected || document.querySelector('.link-item.is-selected');
    if (!li) {
      toast('先点一张卡片选中它（编辑模式下）');
      return;
    }
    const list = li.parentElement;
    if (!list) return;

    const prev = dir === 'up' ? li.previousElementSibling : li.nextElementSibling;
    if (!prev || !prev.classList.contains('link-item')) {
      toast(dir === 'up' ? '已经在最前面了' : '已经在最后面了');
      return;
    }

    if (dir === 'up') prev.before(li); else prev.after(li);
    selectItem(li);
    await persistOrder(list);
    pushUndo({ kind: 'move', id: Number(li.dataset.id), dir });
  }

  /* ==================================================================
     9. 快捷键面板
     ================================================================== */
  const keysScrim = document.getElementById('keysScrim');
  const keysBox   = document.getElementById('keysBox');

  function openKeys() {
    if (!keysBox) return;
    keysScrim.hidden = false;
    keysBox.hidden = false;
    document.body.style.overflow = 'hidden';
    keysBox.querySelector('.paste-close')?.focus();
  }
  function closeKeys() {
    if (!keysBox) return;
    keysScrim.hidden = true;
    keysBox.hidden = true;
    document.body.style.overflow = '';
  }
  document.getElementById('keysClose')?.addEventListener('click', closeKeys);
  keysScrim?.addEventListener('click', closeKeys);
  keysBox?.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); closeKeys(); }
  });

  /* ==================================================================
     10. 右下角提示
     ================================================================== */
  const tip = document.getElementById('tipBubble');
  const TIP_KEY = 'vnav.tip.dismissed';

  /* 提示是否已被永久关闭。两处标记**取并集**：本地关过、或服务端关过，
     都算关过。

     之前的写法是「服务端优先，本地只在拿不到服务端值时兜底」，看着合理，
     对游客却是死路：__VNAV.tipOff 对任何人都会输出（未登录时恒为 false），
     于是 typeof off === 'boolean' 永远成立，本地的标记被完全跳过 ——
     游客点了「不再提示」，刷新一下它又回来了，因为这个动作他根本没权限
     写到服务端。所以这里必须反过来：本地记过就别再问服务端。
     admin 关过一次仍然到处生效（服务端记着 1）。 */
  function tipGone() {
    try { if (localStorage.getItem(TIP_KEY) === '1') return true; } catch {}
    const off = window.__VNAV && window.__VNAV.tipOff;
    return typeof off === 'boolean' ? off : false;
  }

  if (tip && !tipGone()) {
    // 延迟 1.2s 出现：首屏正在入场动画，提示立刻弹出来会跟卡片抢注意力
    setTimeout(() => { tip.hidden = false; }, 1200);

    document.getElementById('tipClose')?.addEventListener('click', () => {
      tip.hidden = true;
    });
    document.getElementById('tipNever')?.addEventListener('click', () => {
      try { localStorage.setItem(TIP_KEY, '1'); } catch {}
      tip.hidden = true;
      toast('以后不再提示，按 Ctrl+/ 随时能看快捷键', 'ok');
      // 顺手记到服务端，换设备也生效
      api({ action: 'save_settings', hint_dismissed: '1' }).catch(() => {});
    });
    document.getElementById('tipKeys')?.addEventListener('click', () => {
      tip.hidden = true;
      openKeys();
    });
  }

  /* 提示被关掉之后的唯一召回入口，放在快捷键面板底部
     （那里本来就叫「快捷键」，用户找「怎么关的提示」自然会翻到）。
     没有这个入口的话，「关掉提示」在用户眼里就是「提示坏了」。 */
  if (tip) {
    const resetRow = document.getElementById('tipResetRow');
    const resetBtn = document.getElementById('tipReset');
    if (resetRow && tipGone()) resetRow.hidden = false;
    resetBtn?.addEventListener('click', () => {
      try { localStorage.removeItem(TIP_KEY); } catch {}
      api({ action: 'save_settings', hint_dismissed: '0' }).catch(() => {});
      if (resetRow) resetRow.hidden = true;
      tip.hidden = false;
      toast('右下角提示已恢复', 'ok');
    });
  }

  /* ==================================================================
     11. 全局快捷键
     ================================================================== */
  document.addEventListener('keydown', e => {
    const mod = e.ctrlKey || e.metaKey;
    const key = e.key.toLowerCase();

    /* Ctrl+/ 或 ? ：快捷键面板 */
    if ((mod && e.key === '/') || (!mod && e.key === '?' && !isTyping())) {
      e.preventDefault();
      openKeys();
      return;
    }

    /* Ctrl+K / Ctrl+L ：聚焦搜索（和浏览器地址栏习惯一致） */
    if (mod && (key === 'k' || key === 'l')) {
      const q = document.getElementById('q');
      if (q) { e.preventDefault(); q.focus(); q.select(); }
      return;
    }

    /* Ctrl+E ：开关编辑模式 */
    if (mod && key === 'e' && document.getElementById('editToggle')) {
      e.preventDefault();
      setEditing(!editing);
      return;
    }

    /* Ctrl+Z ：撤销 */
    if (mod && key === 'z' && !isTyping()) {
      e.preventDefault();
      undo();
      return;
    }

    /* Ctrl+S ：编辑中保存（阻止浏览器「保存网页」） */
    if (mod && key === 's') {
      if (window.__vnavIsEditorOpen?.()) {
        e.preventDefault();
        window.__vnavSaveEditor?.();
      }
      return;
    }

    /* Esc ：按层级关闭 —— 浮层 → 编辑模式 → 什么都不做 */
    if (e.key === 'Escape') {
      if (!keysBox?.hidden) { e.preventDefault(); closeKeys(); return; }
      const passBoxNow = document.getElementById('passBox');
      if (passBoxNow && !passBoxNow.hidden) { e.preventDefault(); document.getElementById('passCancel')?.click(); return; }
      const grpBoxNow = document.getElementById('grpBox');
      if (grpBoxNow && !grpBoxNow.hidden) { e.preventDefault(); document.getElementById('grpCancel')?.click(); return; }
      if (window.__vnavIsEditorOpen?.()) { e.preventDefault(); window.__vnavCloseEditor?.(false); return; }
      if (!box?.hidden) return; // 粘贴浮层自己处理
      if (editing && !isTyping()) { e.preventDefault(); setEditing(false); return; }
      return;
    }

    /* Alt+↑/↓ ：移动选中卡片 */
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      if (!editing) {
        toast('先按 Ctrl+E 打开编辑模式');
        return;
      }
      e.preventDefault();
      moveSelected(e.key === 'ArrowUp' ? 'up' : 'down');
      return;
    }

    /* 编辑模式里 ↑/↓/←/→ 在卡片间移动选中 */
    if (editing && !isTyping() && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      const items = $$('.link-item');
      if (!items.length) return;
      const cur = selected ? items.indexOf(selected) : -1;
      let next = cur;
      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = Math.min(items.length - 1, cur + 1);
      else next = Math.max(0, cur - 1);
      if (next !== cur) { e.preventDefault(); selectItem(items[next]); }
      return;
    }
  });

  // 拖动时禁止选中文字，否则拖完一片高亮很脏
  document.addEventListener('selectstart', e => {
    if (dragEl) e.preventDefault();
  });

  /* 收起提示：用户开始用快捷键后就不必再挂着 */
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v' && tip && !tip.hidden) {
      tip.hidden = true;
    }
  }, { once: false, passive: true });

  /* ==================================================================
     修改密码浮层。
     普通用户没有后台，这一颗是它唯一能管自己账号的地方；
     admin.php 那个表单走同一个 user_pass 接口，行为保持一致。
     ================================================================== */
  (() => {
    const toggle = document.getElementById('passToggle');
    const pScrim = document.getElementById('passScrim');
    const pBox   = document.getElementById('passBox');
    const pForm  = document.getElementById('passForm');
    if (!toggle || !pScrim || !pBox || !pForm) return;

    const pErr   = document.getElementById('passErr');
    const pSave  = document.getElementById('passSave');
    const pText  = pSave?.querySelector('.btn-text');
    const pSpin  = pSave?.querySelector('.spinner');
    let pLast = null;
    let pBusy = false;

    const clearErr = () => { if (pErr) { pErr.textContent = ''; pErr.hidden = true; } };
    const showErr  = msg => {
      if (!pErr) { toast(msg, 'bad'); return; }
      pErr.textContent = msg; pErr.hidden = false;
    };
    const openPass = () => {
      pLast = document.activeElement;
      pForm.reset(); clearErr();
      pScrim.hidden = false; pBox.hidden = false;
      document.getElementById('uOld')?.focus();
    };
    const closePass = () => {
      pScrim.hidden = true; pBox.hidden = true;
      pLast?.focus?.();
    };

    toggle.addEventListener('click', openPass);
    document.getElementById('passClose')?.addEventListener('click', closePass);
    document.getElementById('passCancel')?.addEventListener('click', closePass);
    pScrim.addEventListener('click', closePass);

    pForm.addEventListener('submit', async e => {
      e.preventDefault();
      if (pBusy) return;
      const old = document.getElementById('uOld').value;
      const n1  = document.getElementById('uNew').value;
      const n2  = document.getElementById('uNew2').value;
      clearErr();
      if (!old) return showErr('请填写当前密码');
      if (n1.length < 8) return showErr('新密码至少 8 位');
      if (n1 !== n2) return showErr('两次输入的新密码不一致');
      if (old === n1) return showErr('新密码不能和当前密码相同');
      pBusy = true;
      if (pText) pText.textContent = '保存中';
      if (pSpin) pSpin.hidden = false;
      try {
        await api({ action: 'user_pass', old, new: n1 });
        toast('密码已更新，下次登录请用新密码', 'ok');
        closePass();
      } catch (err) {
        showErr(err.message);
      } finally {
        pBusy = false;
        if (pText) pText.textContent = '保存';
        if (pSpin) pSpin.hidden = true;
      }
    });
  })();

  /* ==================================================================
     分组管理（前台）：侧栏「+」新建 / 双击组名改名 / 浮层里删除。
     接口 group_add / group_rename / group_delete 早就存在且带 user_id
     归属校验，这里只补一直缺着的入口。保存成功后整页刷新 ——
     分组一变，侧栏和主区分区都要重建，局部拼装反而容易漏。
     ================================================================== */
  (() => {
    /* 桌面侧栏头一颗、窄屏折叠条旁一颗，两颗都开同一个浮层 */
    const addBtns = ['grpAdd', 'grpAddM'].map(id => document.getElementById(id)).filter(Boolean);
    const gScrim = document.getElementById('grpScrim');
    const gBox   = document.getElementById('grpBox');
    const gForm  = document.getElementById('grpForm');
    if (!gScrim || !gBox || !gForm) return;

    const gTitle = document.getElementById('grpTitle');
    const gName  = document.getElementById('grpName');
    const gErr   = document.getElementById('grpErr');
    const gHint  = document.getElementById('grpHint');
    const gDel   = document.getElementById('grpDelete');
    const gSave  = document.getElementById('grpSave');
    const gText  = gSave?.querySelector('.btn-text');
    const gSpin  = gSave?.querySelector('.spinner');
    let gMode = 'add';
    let gId = 0;
    let gLast = null;
    let gBusy = false;
    let gDelArmed = false;

    const clearErr = () => { if (gErr) { gErr.textContent = ''; gErr.hidden = true; } };
    const showErr = msg => {
      if (!gErr) { toast(msg, 'bad'); return; }
      gErr.textContent = msg; gErr.hidden = false;
    };
    const disarmDel = () => { gDelArmed = false; if (gDel) gDel.textContent = '删除分组'; };

    function openGrp(mode, id, name) {
      gMode = mode; gId = id || 0;
      gLast = document.activeElement;
      gForm.reset(); clearErr(); disarmDel();
      if (gTitle) gTitle.textContent = mode === 'add' ? '新建分组' : '编辑分组';
      if (gHint) gHint.hidden = mode !== 'edit';
      if (gDel) gDel.hidden = mode !== 'edit';
      if (gName) gName.value = name || '';
      gScrim.hidden = false; gBox.hidden = false;
      gName?.focus(); gName?.select?.();
    }
    function closeGrp() {
      gScrim.hidden = true; gBox.hidden = true;
      gLast?.focus?.();
    }

    addBtns.forEach(b => b.addEventListener('click', () => openGrp('add')));
    $$('.gnav-item').forEach(item => {
      item.addEventListener('dblclick', e => {
        e.preventDefault();
        const id = Number(item.dataset.group || 0);
        const name = item.querySelector('.gnav-name')?.textContent?.trim() || '';
        if (id > 0) openGrp('edit', id, name);
      });
    });
    document.getElementById('grpClose')?.addEventListener('click', closeGrp);
    document.getElementById('grpCancel')?.addEventListener('click', closeGrp);
    gScrim.addEventListener('click', closeGrp);

    /* 删除做二次确认，但不用原生 confirm —— 弹窗和浮层叠在一起很难看，
       而且原生框在手机浏览器上会被系统样式接管。 */
    gDel?.addEventListener('click', async () => {
      if (gMode !== 'edit' || gId <= 0) return;
      if (!gDelArmed) {
        gDelArmed = true;
        gDel.textContent = '再点一次确认删除';
        return;
      }
      if (gBusy) return;
      gBusy = true;
      if (gText) gText.textContent = '处理中';
      if (gSpin) gSpin.hidden = false;
      try {
        await api({ action: 'group_delete', id: gId });
        toast('分组已删除，里面的链接回到未分组', 'ok');
        location.reload();
      } catch (err) {
        showErr(err.message);
        gBusy = false; disarmDel();
        if (gText) gText.textContent = '保存';
        if (gSpin) gSpin.hidden = true;
      }
    });

    gForm.addEventListener('submit', async e => {
      e.preventDefault();
      if (gBusy) return;
      const name = (gName?.value || '').trim();
      clearErr(); disarmDel();
      if (!name) return showErr('请填写分组名');
      gBusy = true;
      if (gText) gText.textContent = '保存中';
      if (gSpin) gSpin.hidden = false;
      try {
        if (gMode === 'add') await api({ action: 'group_add', name });
        else await api({ action: 'group_rename', id: gId, name });
        toast(gMode === 'add' ? '分组已创建' : '分组已改名', 'ok');
        location.reload();
      } catch (err) {
        showErr(err.message);
        gBusy = false;
        if (gText) gText.textContent = '保存';
        if (gSpin) gSpin.hidden = true;
      }
    });
  })();

  /* ==================================================================
     侧栏分组拖拽排序（仅编辑态）。
     服务端 group_reorder 早已备好（一次提交整列 id、带 user_id 校验、
     全量对账失败就整批作废），这里补的是一直缺着的手势。

     刻意只在编辑态启用：侧栏项本质是 <a href="#gN">，平时点一下要跳到
     对应分组；若常驻拖拽，手一抖就会把「跳转」变成一次误排序。
     触摸沿用卡片那套「按住 260ms 才起拖」——滑屏手势必须让给浏览器，
     起拖后再用 passive:false 的 touchmove 拦；绝不在手势中改 touch-action。
     ================================================================== */
  (() => {
    const track = document.getElementById('gnav-track');
    if (!track) return;

    const THRESHOLD = 6;
    const HOLD_MS = 260;
    const HOLD_SLOP = 8;

    let el = null;            // 正在拖的那一项
    let started = false;
    let startX = 0, startY = 0;
    let holdTimer = 0;
    let suppressUntil = 0;    // 吞掉松手那一发 click，避免跳锚点
    let snap = [];            // 落点用起手/重排后的矩形快照，不实时读
    let beforeIds = [];       // 起手顺序，取消或存失败时还原

    const idleIds = () => $$('.gnav-item', track)
      .map(n => Number(n.dataset.group || 0)).filter(n => n > 0);
    const clearHold = () => { if (holdTimer) { clearTimeout(holdTimer); holdTimer = 0; } };

    /* 快照排除正在拖的项：它的位置是被我们改的那个，
       拿它当参照会自证式抖动（卡片拖拽踩过同一个坑）。 */
    const takeSnap = () => {
      snap = $$('.gnav-item', track).filter(n => n !== el).map(n => {
        const r = n.getBoundingClientRect();
        return { el: n, mid: r.top + r.height / 2 };
      });
    };

    const restore = () => {
      for (const id of beforeIds) {
        const n = track.querySelector(`.gnav-item[data-group="${id}"]`);
        if (n) track.appendChild(n);
      }
      snap = [];
    };

    /* capture 阶段拦在 track 上，先于 document 的冒泡监听生效。
       拖动结束紧跟的 click 若放行，<a> 就会跳到旧锚点，看着像排序没生效。 */
    track.addEventListener('click', e => {
      if (Date.now() < suppressUntil) { e.preventDefault(); e.stopPropagation(); }
    }, true);

    let blocked = false;
    const block = ev => { if (ev.cancelable) ev.preventDefault(); };
    const seize = on => {
      if (on && !blocked) {
        document.addEventListener('touchmove', block, { passive: false });
        blocked = true;
      } else if (!on && blocked) {
        document.removeEventListener('touchmove', block, { passive: false });
        blocked = false;
      }
    };

    /* 一维竖列表：第一个中线在指针下方的项就是插入点，没有就追加末尾。
       直接挪 DOM（不插占位线），位置参照系全程稳定。 */
    const place = (x, y) => {
      if (!snap.length) takeSnap();
      let target = null;
      for (const s of snap) {
        if (y < s.mid) { target = s.el; break; }
      }
      if (target) {
        if (el.nextElementSibling === target) return;
        target.before(el);
      } else {
        if (!el.nextElementSibling) return;
        track.appendChild(el);
      }
      takeSnap(); // DOM 真挪过了，重拍一次，后续落点才准
    };

    const begin = () => {
      started = true;
      el.classList.add('is-nav-dragging');
      document.body.classList.add('is-drag-nav');
      suppressUntil = Date.now() + 500;
      takeSnap();
      navigator.vibrate?.(12); // 不支持的设备静默跳过
    };

    const cleanup = () => {
      clearHold();
      seize(false);
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      document.removeEventListener('pointercancel', onCancel);
      document.body.classList.remove('is-drag-nav');
      el?.classList.remove('is-nav-dragging');
      el?.removeAttribute('draggable');
    };

    const onMove = ev => {
      if (!started) {
        const dist = Math.hypot(ev.clientX - startX, ev.clientY - startY);
        if (ev.pointerType === 'touch') {
          // 长按等待期间手指一滑，说明用户要滚页面，让给他
          if (dist > HOLD_SLOP) { clearHold(); cleanup(); el = null; return; }
          return;
        }
        if (dist < THRESHOLD) return;
        begin();
      }
      if (ev.cancelable) ev.preventDefault();
      place(ev.clientX, ev.clientY);
    };

    const onUp = async () => {
      cleanup();
      if (!started) { el = null; return; }
      const after = idleIds();
      if (after.join(',') === beforeIds.join(',')) { el = null; return; }

      toast('正在保存分组顺序…');
      try {
        await api({ action: 'group_reorder', ids: after });
        /* 侧栏顺序、主区分区顺序、#gN 锚点三者都由 PHP 按 sort 渲染，
           刷新一次就全部对齐 —— 局部拼装很容易漏掉其中一处。 */
        location.reload();
      } catch (err) {
        restore(); // 存失败就把界面还原，别留一个刷新后必跳回去的假象
        toast(err.message || '排序保存失败', 'bad');
        el = null;
      }
    };

    const onCancel = () => {
      cleanup();
      if (started) restore();
      el = null;
    };

    track.addEventListener('pointerdown', e => {
      if (!document.body.classList.contains('is-editing')) return;
      if (e.button !== 0 && e.pointerType === 'mouse') return;
      const item = e.target.closest('.gnav-item');
      if (!item || !track.contains(item)) return;

      /* 侧栏项是 <a href="#gN">。按住再移动会被浏览器当成原生链接拖拽，
         dragstart 一发 pointermove 就不再送达 —— 手势刚越过阈值就断，
         表现是「拖了但什么都没发生」。卡片拖拽里也专门兜过这一层。 */
      item.setAttribute('draggable', 'false');
      el = item;
      started = false;
      startX = e.clientX; startY = e.clientY;
      beforeIds = idleIds();
      snap = [];

      document.addEventListener('pointermove', onMove);
      document.addEventListener('pointerup', onUp);
      document.addEventListener('pointercancel', onCancel);

      if (e.pointerType === 'touch') {
        holdTimer = setTimeout(() => {
          holdTimer = 0;
          if (started || !el) return;
          begin();
          seize(true);
        }, HOLD_MS);
      }
    });
  })();

  /* 「这些链接是你自己的副本」提示：点知道了写 user 级 setting。
     写失败就保持提示可见，用户还能再点一次 —— 不要先藏起来再说。 */
  (() => {
    const note = document.getElementById('cloneNote');
    const btn  = document.getElementById('cloneNoteClose');
    if (!note || !btn) return;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        await api({ action: 'dismiss_clone_hint' });
        note.hidden = true;
      } catch (err) {
        btn.disabled = false;
        toast(err.message || '保存失败，请重试', 'bad');
      }
    });
  })();
})();