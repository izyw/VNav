/* 后台交互 */
(() => {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';
  /* 当前在编辑哪一份库（site = 网站默认 / mine = 我的）。
     由 PHP 注入到 <meta name="adm-lib">。切换库是整页跳转，
     所以这里读一次就够，不需要监听变化。
     每个写请求都会自动带上它 —— 不这样做的话，任何一个新增的
     api() 调用点都可能漏带，导致「在 A 库里点按钮，改到 B 库」。 */
  const LIB = document.querySelector('meta[name="adm-lib"]')?.content || 'site';
  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const toastEl = $('#toast');

  function toast(msg, bad = false) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-bad', bad);
    toastEl.hidden = false;
    requestAnimationFrame(() => toastEl.classList.add('show'));
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
      toastEl.classList.remove('show');
      setTimeout(() => { toastEl.hidden = true; }, 260);
    }, bad ? 4200 : 2400);
  }

  async function api(body) {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF },
      // lib 打头，body 里的同名键（不该有）会覆盖它，属于调用方显式覆盖
      body: JSON.stringify({ lib: LIB, ...body }),
      credentials: 'same-origin',
    });
    let data;
    try { data = await res.json(); }
    catch { throw new Error('服务器返回异常'); }
    if (!res.ok || !data.ok) throw new Error(data.error || '操作失败');
    return data;
  }

  function busy(btn, on, textOn, textOff) {
    const sp = btn.querySelector('.spinner');
    const tx = btn.querySelector('.btn-text') || btn;
    btn.disabled = on;
    if (sp) sp.hidden = !on;
    if (tx && textOff) tx.textContent = on ? textOn : textOff;
  }

  function showErr(el, msg) { el.textContent = msg; el.hidden = false; }
  function clearErr(el) { el.hidden = true; el.textContent = ''; }

  /* ================= 链接编辑 ================= */
  const editor = $('#linkEditor');
  const form = $('#linkForm');

  if (form) {
    const fId = $('#fId'), fUrl = $('#fUrl'), fTitle = $('#fTitle');
    const fGroup = $('#fGroup'), fDescr = $('#fDescr'), fErr = $('#linkErr');
    const fHidden = $('#fHidden'), fHideDesc = $('#fHideDesc');
    const saveBtn = $('#saveLink'), titleEl = $('#editorTitle');

    // 滑块下方文案要跟着状态变：光有一个开关，用户分不清"开"到底是什么意思
    const syncHideHint = () => {
      if (!fHidden || !fHideDesc) return;
      fHideDesc.textContent = fHidden.checked
        ? '开启后只有登录的你能看到这条'
        : '关闭时所有访客可见';
    };
    fHidden?.addEventListener('change', syncHideHint);
    syncHideHint();

    const openEditor = (row) => {
      editor.hidden = false;
      clearErr(fErr);
      titleEl.textContent = row ? '编辑链接' : '新增链接';
      fId.value = row ? row.dataset.id : '';
      fUrl.value = row ? row.dataset.url : '';
      fTitle.value = row ? row.dataset.title : '';
      fGroup.value = row ? (row.dataset.group || '0') : '0';
      fDescr.value = row ? row.dataset.descr : '';
      if (fHidden) { fHidden.checked = row ? row.dataset.hidden === '1' : false; syncHideHint(); }
      editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      (row ? fTitle : fUrl).focus();
    };

    $('#newLinkBtn')?.addEventListener('click', () => openEditor(null));

    /* 「我的链接库」为空时的一键打底：把网站默认那份整库搬过来。
       会写入几百行且不可撤销（覆盖由后端拦，目标库非空就拒绝），
       所以先让用户确认一次。 */
    $('#cloneLibBtn')?.addEventListener('click', async e => {
      const btn = e.currentTarget;
      if (!confirm('把「网站默认」里的全部分组和链接复制到「我的链接库」？\n\n'
        + '复制之后两个库各自独立：改一边不会影响另一边，访客始终只看「网站默认」。')) return;
      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = '复制中…';
      try {
        const r = await api({ action: 'library_clone' });
        toast('已复制 ' + (r.links ?? 0) + ' 条链接、' + (r.groups ?? 0) + ' 个分组');
        setTimeout(() => location.reload(), 700);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = label;
      }
    });
    // 空态里的「直接新增」复用页头那颗按钮，不再写一套编辑器打开逻辑
    $('#newLinkBtn2')?.addEventListener('click', () => $('#newLinkBtn')?.click());
    $('#cancelEdit')?.addEventListener('click', () => { editor.hidden = true; });

    // 行内按钮：编辑 / 删除 / 排序 / 隐藏开关
    $('#admList')?.addEventListener('click', async e => {
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const row = btn.closest('.adm-row');
      if (!row) return;
      const id = Number(row.dataset.id);

      if (btn.dataset.act === 'edit') { openEditor(row); return; }

      // 隐藏开关：复选框的 change 与 click 会各触发一次，用 dataset 记状态防重入
      if (btn.dataset.act === 'hide') {
        if (btn.dataset.running === '1') return;
        const want = btn.checked;
        // 立即改视觉，不等网络。失败再退回去 —— 乐观更新。
        btn.dataset.running = '1';
        row.classList.toggle('is-priv', want);
        row.dataset.hidden = want ? '1' : '0';
        try {
          await api({ action: 'set_hidden', id, hidden: want ? 1 : 0 });
          toast(want ? '已隐藏，仅登录后可见' : '已公开');
        } catch (err) {
          btn.checked = !want;
          row.classList.toggle('is-priv', !want);
          row.dataset.hidden = want ? '0' : '1';
          toast(err.message, true);
        }
        btn.dataset.running = '0';
        return;
      }

      if (btn.dataset.act === 'del') {
        if (!confirm(`删除「${row.dataset.title}」？此操作不可撤销。`)) return;
        try {
          await api({ action: 'delete', id });
          row.style.transition = 'opacity .2s, transform .2s';
          row.style.opacity = '0';
          row.style.transform = 'translateX(-12px)';
          setTimeout(() => row.remove(), 200);
          toast('已删除');
        } catch (err) { toast(err.message, true); }
        return;
      }

      // 上移 / 下移：先在 DOM 里换位，再整体提交顺序
      const dir = btn.dataset.act === 'up' ? 'prev' : 'next';
      const sib = row[dir === 'up' ? 'previousElementSibling' : 'nextElementSibling'];
      if (!sib || !sib.classList.contains('adm-row')) return;
      if (dir === 'up') row.parentNode.insertBefore(row, sib);
      else row.parentNode.insertBefore(sib, row);

      const ids = $$('#admList .adm-row').map(r => Number(r.dataset.id));
      try { await api({ action: 'reorder', ids }); }
      catch (err) { toast(err.message, true); location.reload(); }
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      clearErr(fErr);
      const url = fUrl.value.trim();
      const title = fTitle.value.trim();
      if (!url) { fUrl.setAttribute('aria-invalid', 'true'); fUrl.focus(); return showErr(fErr, '请填写网址'); }
      fUrl.removeAttribute('aria-invalid');
      if (!title) { fTitle.setAttribute('aria-invalid', 'true'); fTitle.focus(); return showErr(fErr, '请填写名称'); }
      fTitle.removeAttribute('aria-invalid');

      const id = fId.value;
      busy(saveBtn, true, '保存中', '保存');
      try {
        await api({
          action: id ? 'update' : 'add',
          id: id ? Number(id) : 0,
          url, title,
          group_id: Number(fGroup.value || 0),
          descr: fDescr.value.trim(),
          icon: '',
          hidden: fHidden?.checked ? 1 : 0,
        });
        toast(id ? '已保存' : '已添加');
        location.reload(); // 图标可能被后端抓取，重载最稳
      } catch (err) {
        showErr(fErr, err.message);
        busy(saveBtn, false, '保存中', '保存');
      }
    });

    // 抓取名称与图标
    $('#identifyBtn')?.addEventListener('click', async e => {
      const url = fUrl.value.trim();
      if (!url) { showErr(fErr, '先填网址'); return; }
      const btn = e.currentTarget;
      btn.disabled = true;
      const old = btn.textContent;
      btn.textContent = '抓取中…';
      try {
        const data = await api({ action: 'identify', url });
        const m = data.meta || {};
        if (m.title) fTitle.value = m.title.slice(0, 60);
        if (m.desc && !fDescr.value.trim()) fDescr.value = m.desc.slice(0, 120);
        toast(m.title ? '已识别：' + m.title : '没抓到标题，可手动填写');
      } catch (err) { toast(err.message, true); }
      btn.disabled = false;
      btn.textContent = old;
    });

    if (new URLSearchParams(location.search).get('focus')) {
      const row = document.querySelector(`.adm-row[data-id="${CSS.escape(new URLSearchParams(location.search).get('focus'))}"]`);
      if (row) setTimeout(() => { openEditor(row); }, 120);
    }
  }

  /* ================= 分组 ================= */
  const groupForm = $('#groupForm');
  if (groupForm) {
    const gErr = $('#groupErr');
    const gName = $('#gName');

    groupForm.addEventListener('submit', async e => {
      e.preventDefault();
      clearErr(gErr);
      const name = gName.value.trim();
      if (!name) { gName.focus(); return showErr(gErr, '请输入分组名'); }
      try {
        await api({ action: 'group_add', name });
        location.reload();
      } catch (err) { showErr(gErr, err.message); }
    });

    $('#groupList')?.addEventListener('click', async e => {
      const btn = e.target.closest('[data-gact]');
      if (!btn) return;
      const row = btn.closest('.adm-group-row');
      const id = Number(row.dataset.id);
      const input = row.querySelector('.f-input');

      if (btn.dataset.gact === 'save') {
        const name = input.value.trim();
        if (!name) { input.focus(); return toast('分组名不能为空', true); }
        try { await api({ action: 'group_rename', id, name }); toast('已重命名'); }
        catch (err) { toast(err.message, true); }
        return;
      }
      // 图标按钮必须在这里拦下。放在删除逻辑之后会被当成「删除」走掉
      // —— 那个分支下面就是 confirm + group_delete，误触即丢分组。
      if (btn.dataset.gact === 'icon') { openGroupIcon(row, btn); return; }
      if (btn.dataset.gact === 'up' || btn.dataset.gact === 'down') {
        moveGroupRow(row, btn.dataset.gact === 'up' ? -1 : 1);
        return;
      }
      if (!confirm('删除这个分组？组内链接会回到未分组。')) return;
      try {
        await api({ action: 'group_delete', id });
        row.remove();
        toast('已删除分组');
      } catch (err) { toast(err.message, true); }
    });

    /* 上移 / 下移一格。
       顺序是「先提交、成功了再动 DOM」—— 反过来（先动 DOM 再提交、失败回滚）
       要维护一套反向插入逻辑，而这里一次点击只挪一行，几百毫秒的等待
       换来的是「看得见的一定是存下的」。连点用 sortBusy 挡掉，
       否则两次请求会基于同一个旧顺序算出两个互相覆盖的结果。 */
    let sortBusy = false;
    async function moveGroupRow(row, dir) {
      if (sortBusy) return;
      const list = $('#groupList');
      if (!list) return;
      const rows = [...list.querySelectorAll('.adm-group-row')];
      const i = rows.indexOf(row);
      const j = i + dir;
      if (j < 0 || j >= rows.length) {
        toast(dir < 0 ? '已经是第一个了' : '已经是最后一个了');
        return;
      }

      const next = rows.slice();
      next.splice(i, 1);
      next.splice(j, 0, row);

      sortBusy = true;
      try {
        await api({ action: 'group_reorder', ids: next.map(r => Number(r.dataset.id)) });
        // dir<0：插到 rows[j] 之前；dir>0：插到 rows[j] 之后
        if (dir < 0) list.insertBefore(row, rows[j]);
        else list.insertBefore(row, rows[j].nextSibling);
        row.classList.add('is-flash');
        setTimeout(() => row.classList.remove('is-flash'), 620);
      } catch (err) {
        toast(err.message, true);
      } finally {
        sortBusy = false;
      }
    }
  }

  /* ---------- 分组图标选择浮层 ----------
     图标库与前台共用 inc/groupnav.php，这里只管交互：
     点图标按钮开浮层，点某个图标调 group_icon，成功后原地换掉按钮里的 svg
     （不刷新页面，否则刚输到一半的分组名会丢）。 */
  const giconModal = $('#giconModal');
  let giconRow = null, giconBtn = null;

  function openGroupIcon(row, btn) {
    if (!giconModal) return;
    giconRow = row;
    giconBtn = btn;
    const cur = btn.dataset.icon || '';
    giconModal.querySelectorAll('[data-pick]').forEach(o => {
      const on = (o.dataset.pick || '') === cur;
      o.classList.toggle('is-on', on);
      o.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    giconModal.hidden = false;
    giconModal.querySelector('[data-pick].is-on')?.focus();
  }

  giconModal?.addEventListener('click', async e => {
    if (e.target.closest('[data-gicon-close]')) {
      giconModal.hidden = true;
      giconBtn?.focus();
      return;
    }
    const pick = e.target.closest('[data-pick]');
    if (!pick || !giconRow) return;
    const icon = pick.dataset.pick || '';
    try {
      await api({ action: 'group_icon', id: Number(giconRow.dataset.id), icon });
      giconBtn.dataset.icon = icon;
      giconBtn.classList.toggle('is-auto', icon === '');
      giconBtn.querySelector('svg').innerHTML = pick.querySelector('svg').innerHTML;
      giconModal.hidden = true;
      giconBtn.focus();
      toast(icon === '' ? '已恢复自动匹配' : '图标已更新');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && giconModal && !giconModal.hidden) {
      giconModal.hidden = true;
      giconBtn?.focus();
    }
  });

  /* ================= 外观 ================= */
  const lookForm = $('#lookForm');
  if (lookForm) {
    const lookErr = $('#lookErr');
    const saveBtn = $('#saveLook');

    // 选中态视觉同步
    const syncLayouts = () => $$('.layout-radio').forEach(l => {
      l.classList.toggle('is-on', l.querySelector('input').checked);
    });
    $$('input[name="layout"], input[name="groupnav"]').forEach(i => i.addEventListener('change', syncLayouts));
    syncLayouts();

    // 皮肤同样要同步选中态，并即时预览（不保存，点保存才落库）
    const syncSkins = () => $$('.skin-radio').forEach(s => {
      s.classList.toggle('is-on', s.querySelector('input').checked);
    });
    $$('input[name="theme"]').forEach(i => i.addEventListener('change', () => {
      document.documentElement.dataset.theme = i.value;
      syncSkins();
    }));
    syncSkins();

    // 换强调色即时预览（不保存，保存才落库）
    $$('input[name="accent"]').forEach(i => i.addEventListener('change', () => {
      document.documentElement.dataset.accent = i.value;
    }));

    lookForm.addEventListener('submit', async e => {
      e.preventDefault();
      clearErr(lookErr);
      const fd = new FormData(lookForm);
      const payload = {
        action: 'save_settings',
        site_title: fd.get('site_title') ?? '',
        footer_note: fd.get('footer_note') ?? '',
        // 页脚版权署名与备案号：留空是合法值（会退回作者名/站点名，备案则不显示）
        copyright_name: fd.get('copyright_name') ?? '',
        icp_beian: fd.get('icp_beian') ?? '',
        /* groupnav 已不是可选项（只剩左侧栏），界面上没有这个控件。
           这里必须整行删掉而不是留空串 —— 提交空值会把库里的 side 冲成 ''。
           save_settings 只遍历「payload 里存在的键」，不传就不动。 */
        layout: fd.get('layout'),
        theme: fd.get('theme'),
        accent: fd.get('accent'),
        search_engine: fd.get('search_engine'),
        show_search: fd.get('show_search') ? '1' : '0',
        show_desc: fd.get('show_desc') ? '1' : '0',
        open_new_tab: fd.get('open_new_tab') ? '1' : '0',
        stats_on: fd.get('stats_on') ? '1' : '0',
        stats_keep: Number(fd.get('stats_keep') || 90),
      };
      busy(saveBtn, true, '保存中', '保存外观');
      try {
        await api(payload);
        toast('外观已保存');
      } catch (err) { showErr(lookErr, err.message); }
      busy(saveBtn, false, '保存中', '保存外观');
    });
  }

  /* ================= 统计 ================= */
  if ($('#statsClear') || $('#statsPrune') || $('#geoBackfill')) {
    $('#geoBackfill')?.addEventListener('click', async e => {
      const btn = e.currentTarget;
      btn.disabled = true;
      btn.textContent = '回填中…';
      try {
        const d = await api({ action: 'geo_backfill' });
        toast(d.fixed > 0
          ? `已回填 ${d.fixed} 条${d.left > 0 ? `，还剩 ${d.left} 条，可再点一次` : ''}`
          : '没有能识别的历史记录（可能地理库没导入）');
        setTimeout(() => location.reload(), 900);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = '现在就回填';
      }
    });

    $('#statsPrune')?.addEventListener('click', async e => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        const d = await api({ action: 'stats_prune' });
        toast(d.deleted > 0 ? `已清理 ${d.deleted} 条过期明细` : '没有需要清理的明细');
        setTimeout(() => location.reload(), 700);
      } catch (err) { toast(err.message, true); btn.disabled = false; }
    });

    $('#statsClear')?.addEventListener('click', async e => {
      if (!confirm('清空全部访问与点击明细，并把所有链接的点击次数归零。不可撤销，先导出备份？')) return;
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await api({ action: 'stats_clear', what: 'all' });
        toast('统计已清空');
        setTimeout(() => location.reload(), 600);
      } catch (err) { toast(err.message, true); btn.disabled = false; }
    });
  }

  /* ================= 访问控制 ================= */
  const aclMode = $('#aclMode');
  if (aclMode) {
    const modeDesc = $('#aclModeDesc');
    const grid = $('#aclGrid');

    const syncAclMode = () => {
      const on = aclMode.checked;
      if (modeDesc) modeDesc.textContent = on
        ? '已启用：除管理员外，所有访问都按下面的规则判定'
        : '关闭时任何人都能访问';
      if (grid) grid.classList.toggle('is-off', !on);
    };
    aclMode.addEventListener('change', syncAclMode);
    syncAclMode();

    const saveAclSettings = async () => {
      try {
        await api({
          action: 'save_settings',
          acl_mode: aclMode.checked ? 'on' : 'off',
          acl_list_type: $('#aclListType')?.value || 'deny',
          acl_unknown: $('#aclUnknown')?.value || 'allow',
        });
        toast('访问控制设置已保存');
      } catch (err) { toast(err.message, true); }
    };
    aclMode.addEventListener('change', saveAclSettings);
    $('#aclListType')?.addEventListener('change', saveAclSettings);
    $('#aclUnknown')?.addEventListener('change', saveAclSettings);

    // 加入名单
    $('#aclAdd')?.addEventListener('click', async e => {
      const cc = $('#aclPick')?.value || '';
      const mode = $('#aclRole')?.value || 'deny';
      if (!cc) { toast('先选一个国家或地区', true); return; }
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await api({ action: 'acl_set', cc, mode });
        location.reload();
      } catch (err) { toast(err.message, true); btn.disabled = false; }
    });

    // 移除单条（事件委托，规则列表是服务端渲染的）
    document.addEventListener('click', async e => {
      const btn = e.target.closest('[data-acl-del]');
      if (!btn) return;
      const cc = btn.dataset.aclDel;
      btn.disabled = true;
      try {
        await api({ action: 'acl_set', cc, mode: '' });
        btn.closest('.acl-item')?.remove();
        toast('已从名单移除');
      } catch (err) { toast(err.message, true); btn.disabled = false; }
    });

    // 地理库导入
    const geoLog = $('#geoLog');
    const runImport = async (scope, btn) => {
      if (scope === 'full' && !confirm('完整库约 90 MB，导入要一两分钟，期间请勿关闭页面。继续？')) return;
      const tips = { country: '正在下载并导入国家库…', full: '正在下载并导入完整库…' };
      busy(btn, true, scope === 'country' ? '导入中…' : '导入中，请等待…', btn.querySelector('.btn-text')?.textContent || '');
      if (geoLog) {
        geoLog.hidden = false;
        geoLog.textContent = tips[scope];
      }
      try {
        const d = await api({ action: 'geo_import', scope });
        const r = d.result || {};
        toast(scope === 'country'
          ? `国家库已就绪：${(r.seg || 0).toLocaleString()} 段`
          : `完整库已就绪：${(r.seg || 0).toLocaleString()} 段 / ${(r.places || 0).toLocaleString()} 个地点`);
        setTimeout(() => location.reload(), 900);
      } catch (err) {
        if (geoLog) geoLog.textContent = '导入失败：' + err.message;
        toast(err.message, true);
        busy(btn, false, '', btn.querySelector('.btn-text')?.textContent || '');
      }
    };
    $('#geoImportFast')?.addEventListener('click', e => runImport('country', e.currentTarget));
    $('#geoImportFull')?.addEventListener('click', e => runImport('full', e.currentTarget));

    $('#geoClear')?.addEventListener('click', async e => {
      if (!confirm('清空地理库？已记录的归属地不会消失，但新访问会全部变成「未知地区」。')) return;
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await api({ action: 'geo_clear' });
        toast('地理库已清空');
        setTimeout(() => location.reload(), 600);
      } catch (err) { toast(err.message, true); btn.disabled = false; }
    });
  }

  /* ================= 改密码 ================= */
  const passForm = $('#passForm');
  if (passForm) {
    const pErr = $('#passErr');
    const btn = $('#savePass');
    passForm.addEventListener('submit', async e => {
      e.preventDefault();
      clearErr(pErr);
      const old = $('#pOld').value;
      const n1 = $('#pNew').value;
      const n2 = $('#pNew2').value;
      if (n1.length < 8) return showErr(pErr, '新密码至少 8 位');
      if (n1 !== n2) return showErr(pErr, '两次输入的新密码不一致');
      busy(btn, true, '更新中', '更新密码');
      try {
        await api({ action: 'user_pass', old, new: n1 });
        toast('密码已更新');
        passForm.reset();
      } catch (err) { showErr(pErr, err.message); }
      busy(btn, false, '更新中', '更新密码');
    });
  }

  /* 后台也支持 Ctrl+V 快速添加（跳到链接页并打开编辑器） */
  if (!lookForm && !passForm) {
    document.addEventListener('paste', e => {
      const tag = document.activeElement?.tagName;
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) return;
      const text = (e.clipboardData || window.clipboardData)?.getData('text') || '';
      const m = text.match(/\bhttps?:\/\/[^\s<>"']+/i)
             || (!/\s/.test(text.trim()) ? text.trim().match(/^(?:[a-z0-9-]+\.)+[a-z]{2,24}(?:\/[^\s]*)?$/i) : null);
      if (!m) return;
      let url = m[0].replace(/[.,;!?]+$/, '');
      if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
      const fUrl = $('#fUrl');
      if (!fUrl) return;
      e.preventDefault();
      $('#newLinkBtn')?.click();
      fUrl.value = url;
      setTimeout(() => $('#identifyBtn')?.click(), 80);
    });
  }

  /* ================= 登录防护 ================= */
  const capToggle = $('#loginCaptcha');
  if (capToggle) {
    const desc = $('#capModeDesc');
    const syncDesc = () => {
      if (desc) {
        desc.textContent = capToggle.checked
          ? '已开启：第一次输错密码后出现验证码'
          : '已关闭：输错多少次都只提示密码错误';
      }
    };
    // 先动 UI 再发请求：开关是本地状态，服务端失败再翻回来并提示。
    // 反过来会有一个「点完没反应」的空档，比失败更难懂。
    capToggle.addEventListener('change', async () => {
      syncDesc();
      const on = capToggle.checked;
      try {
        await api({ action: 'set_captcha', on });
        toast(on ? '验证码已开启' : '验证码已关闭');
      } catch (err) {
        capToggle.checked = !on;
        syncDesc();
        toast(err.message, true);
      }
    });

    $('#unlockAll')?.addEventListener('click', async e => {
      const btn = e.currentTarget;
      btn.disabled = true;
      try {
        await api({ action: 'unlock_all' });
        toast('已解除全部锁定');
        setTimeout(() => location.reload(), 500);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
      }
    });
  }

  /* ================= 一键刷新元信息（标题 / 描述 / 图标） =================
     分批协议：后端一次只处理 6 条（逐条访问外站，一条能拖几百毫秒），
     返回 cursor 与 done。前端循环往下推，直到 done 为 true。
     用 cursor 而不是 offset —— 刷新过程中如果有链接被删，offset 会错位跳条。 */
  if ($('#metaRefreshAll')) {
    const panel    = $('#metaRefreshPanel');
    const progWrap = $('#mrProgress');
    const barFill  = $('#mrBarFill');
    const statusEl = $('#mrStatus');
    const btnAll   = $('#metaRefreshAll');
    const btnMiss  = $('#metaRefreshMissing');

    const setBusy = (busy, which) => {
      [btnAll, btnMiss].forEach(b => { if (b) b.disabled = busy; });
      const btn = which === 'missing' ? btnMiss : btnAll;
      const sp = btn?.querySelector('.spinner');
      if (sp) sp.hidden = !busy;
      if (busy) {
        // 两个按钮的文字都别动：只给正在跑的那个转圈，
        // 另一个保持原文案，用户中断后能一眼找到再点哪颗
      }
    };

    const runRefresh = async (overwrite, which) => {
      if (!overwrite && !confirm('只补缺失的：已填写的名称和描述不会改动。继续？')) return;

      const total = Number(panel?.querySelector('.mr-stat b')?.textContent || 0) || 0;
      let cursor = 0;
      let doneCount = 0;
      let okSum = 0, badSum = 0, changedSum = 0;
      const errLines = [];

      progWrap.hidden = false;
      barFill.style.width = '0%';
      setBusy(true, which);

      try {
        for (let round = 1; round <= 200; round++) {
          const r = await api({
            action: 'meta_refresh',
            /* 后端是并发的：耗时由「最慢那个站点」决定，不由条数决定。
               所以一次尽量多带，46 条能一批抓完 —— 原来 limit=6 时要 8 轮，
               每轮都是串行等两个请求。 */
            limit: 48,
            overwrite: overwrite ? 1 : 0,
            cursor,
          });
          const x = r.result || {};

          okSum      += x.ok || 0;
          badSum     += x.bad || 0;
          changedSum += x.changed || 0;
          cursor = x.cursor || cursor;

          if (x.errors && typeof x.errors === 'object') {
            for (const k of Object.keys(x.errors)) errLines.push(x.errors[k]);
          }

          /* 进度按「已处理的条数 / 总数」算。
             总数拿不到（0）时不显示百分比，只显示已处理几条，
             免得除零算出 NaN% 或者一直卡在 0% 看着像死掉了。 */
          const left = x.left || 0;
          const processed = Math.max(1, total - left);
          doneCount = processed;
          const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
          barFill.style.width = (total > 0 ? pct : 100) + '%';
          statusEl.textContent = total > 0
            ? `已处理 ${processed} / ${total}（成功 ${okSum}，失败 ${badSum}）`
            : `已处理 ${okSum + badSum} 条`;

          if (x.done || left <= 0 || (!x.ok && !x.bad)) break;
        }

        barFill.style.width = '100%';
        statusEl.textContent = `完成：成功 ${okSum} 条（其中 ${changedSum} 条有更新），失败 ${badSum} 条`;
        toast(`刷新完成：${changedSum} 条有更新${badSum ? '，' + badSum + ' 条失败' : ''}`);

        if (errLines.length) {
          statusEl.textContent += `；${errLines.slice(0, 3).join('、')}${errLines.length > 3 ? ' 等' : ''}`;
        }

        // 刷完要让列表里的标题/图标跟着变，直接重载最省事
        setTimeout(() => location.reload(), 900);
      } catch (err) {
        /* 出错时不清进度条、不改已处理的数字 ——
           用户能看到「跑到第几条挂的」，而不是一片空白。 */
        statusEl.textContent = `中断：${err.message}（已成功 ${okSum} 条）`;
        toast(err.message, true);
        setBusy(false, which);
      }
    };

    btnAll?.addEventListener('click', () => runRefresh(true, 'all'));
    btnMiss?.addEventListener('click', () => runRefresh(false, 'missing'));
  }

  /* ================= 图标缓存 ================= */
  if ($('#iconSync')) {
    const runIcon = async (btn, action, payload, okText) => {
      const sp = btn.querySelector('.spinner');
      const tx = btn.querySelector('.btn-text');
      btn.disabled = true;
      if (sp) sp.hidden = false;
      const orig = tx ? tx.textContent : '';
      if (tx) tx.textContent = '处理中';
      try {
        const r = await api({ action, ...(payload || {}) });
        toast(typeof okText === 'function' ? okText(r) : okText);
        setTimeout(() => location.reload(), 700);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        if (sp) sp.hidden = true;
        if (tx) tx.textContent = orig;
      }
    };

    $('#iconSync').addEventListener('click', e =>
      runIcon(e.currentTarget, 'icon_sync', {}, r => {
        const x = r.result || {};
        return `对账完成：新增 ${x.added || 0}，移除 ${x.removed || 0}`;
      })
    );

    $('#iconRefill').addEventListener('click', e =>
      runIcon(e.currentTarget, 'icon_refill', { limit: 12 }, r => {
        const x = r.result || {};
        return `抓取完成：成功 ${x.ok || 0}，失败 ${x.bad || 0}${x.left ? '，还剩 ' + x.left + ' 条' : ''}`;
      })
    );

    $('#iconGc').addEventListener('click', async e => {
      if (!confirm('删除所有没有被任何链接引用的图标文件？此操作不可撤销。')) return;
      runIcon(e.currentTarget, 'icon_gc', {}, r => {
        const x = r.result || {};
        // 字段名是 deleted（hp_icon_collect_garbage 的返回）。
        // sync 返回的才是 removed —— 两者别弄混，混了提示会永远显示 0。
        return `清理完成：删除 ${x.deleted || 0} 个文件，释放 ${((x.bytes || 0) / 1024).toFixed(1)} KB`;
      });
    });

    $('#iconPurge').addEventListener('click', async e => {
      if (!confirm('清空全部图标缓存？链接本身不会丢，但下次打开主页要重新抓一遍。')) return;
      runIcon(e.currentTarget, 'icon_purge', { days: 0 }, r => {
        const x = r.result || {};
        return `已清空 ${x.deleted || 0} 个文件${x.relinked ? '，' + x.relinked + ' 条链接已重置为自动识别' : ''}`;
      });
    });
  }

  /* ================= 升级 ================= */
  const upDrop = $('#upDrop');
  if (upDrop) {
    const upFile   = $('#upFile');
    const upName   = $('#upFileName');
    const upStart  = $('#upStart');
    const upCancel = $('#upCancel');
    const upBox    = $('#upProgress');
    const upBar    = $('#upBar');
    const upLog    = $('#upLog');

    let picked = null;
    let xhr = null;

    const resetPick = () => {
      picked = null;
      upFile.value = '';
      upStart.disabled = true;
      if (upName) upName.textContent = '建议先点下面「下载 JSON 备份」留一份数据';
      upDrop.classList.remove('has-file');
    };

    const setPick = f => {
      if (!f) return;
      if (!/\.zip$/i.test(f.name)) {
        toast('只接受 .zip 文件', true);
        return;
      }
      picked = f;
      upStart.disabled = false;
      upDrop.classList.add('has-file');
      if (upName) upName.textContent = `${f.name} · ${(f.size / 1048576).toFixed(1)} MB`;
    };

    upDrop.addEventListener('click', () => upFile.click());
    upDrop.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); upFile.click(); }
    });
    upFile.addEventListener('change', () => setPick(upFile.files?.[0]));

    ['dragenter', 'dragover'].forEach(ev =>
      upDrop.addEventListener(ev, e => { e.preventDefault(); upDrop.classList.add('is-over'); })
    );
    ['dragleave', 'drop'].forEach(ev =>
      upDrop.addEventListener(ev, e => { e.preventDefault(); upDrop.classList.remove('is-over'); })
    );
    upDrop.addEventListener('drop', e => setPick(e.dataTransfer?.files?.[0]));

    upStart.addEventListener('click', () => {
      if (!picked) return;
      if (!confirm(
        '开始升级：\n\n' +
        '· 会覆盖站点里的程序文件\n' +
        '· 不会动 inc/config.php 与 data/ 里的数据\n' +
        '· 升级前会自动做一份快照\n\n' +
        '确认继续？'
      )) return;

      upBox.hidden = false;
      upLog.textContent = '校验压缩包…';
      upBar.style.width = '4%';
      upStart.disabled = true;
      upCancel.hidden = false;

      const fd = new FormData();
      fd.append('pkg', picked);

      xhr = new XMLHttpRequest();
      xhr.open('POST', 'api.php?action=upgrade_apply', true);
      xhr.setRequestHeader('X-CSRF', CSRF);
      xhr.withCredentials = true;

      // 只有上传阶段有真实进度；服务端解包那段没法报进度，
      // 所以过 60% 就切文案，别让进度条卡在同一个数字上装作在动
      xhr.upload.addEventListener('progress', ev => {
        if (!ev.lengthComputable) return;
        const p = Math.min(60, Math.round(ev.loaded / ev.total * 60));
        upBar.style.width = p + '%';
        if (p >= 60) upLog.textContent = '上传完成，正在解包与迁移…';
      });

      xhr.addEventListener('load', () => {
        let d = null;
        try { d = JSON.parse(xhr.responseText); } catch { /* 下面统一报错 */ }
        upCancel.hidden = true;
        if (d && d.ok) {
          upBar.style.width = '100%';
          upLog.textContent = `升级完成：${d.from} → ${d.to}，覆盖 ${d.files} 个文件。正在刷新…`;
          toast('升级完成');
          setTimeout(() => { location.href = '?tab=upgrade'; }, 1200);
        } else {
          upBar.style.width = '100%';
          upBar.classList.add('is-bad');
          upLog.textContent = (d && d.error) || '升级失败（服务器返回异常）';
          toast((d && d.error) || '升级失败', true);
          upStart.disabled = false;
        }
      });

      xhr.addEventListener('error', () => {
        upCancel.hidden = true;
        upLog.textContent = '网络中断，升级未完成。请刷新页面确认当前版本，必要时用下方快照回滚。';
        toast('网络中断', true);
        upStart.disabled = false;
      });

      xhr.addEventListener('abort', () => {
        upCancel.hidden = true;
        upLog.textContent = '已取消（已上传的部分不会生效）。';
        upStart.disabled = false;
      });

      xhr.send(fd);
    });

    upCancel.addEventListener('click', () => {
      xhr?.abort();
      xhr = null;
    });

    // 回滚 / 删快照
    document.querySelectorAll('[data-rollback]').forEach(b => {
      b.addEventListener('click', async () => {
        const snap = b.dataset.rollback;
        if (!confirm(
          `回滚到快照 ${snap}？\n\n` +
          '这会用快照里的数据库覆盖当前数据库，当前的改动会丢失。\n' +
          '代码不会回退——要退代码请再上传旧版本包。'
        )) return;
        b.disabled = true;
        try {
          await api({ action: 'upgrade_rollback', snap });
          toast('已回滚，正在刷新');
          setTimeout(() => location.reload(), 800);
        } catch (err) {
          toast(err.message, true);
          b.disabled = false;
        }
      });
    });

    document.querySelectorAll('[data-dropsnap]').forEach(b => {
      b.addEventListener('click', async () => {
        if (!confirm('删除这个快照？删了就没法再回滚到它。')) return;
        b.disabled = true;
        try {
          await api({ action: 'upgrade_drop_snapshot', snap: b.dataset.dropsnap });
          toast('快照已删除');
          setTimeout(() => location.reload(), 500);
        } catch (err) {
          toast(err.message, true);
          b.disabled = false;
        }
      });
    });
  }

  /* ---------- 链接列表筛选 ----------
     300 多条平铺在一个页面上，找一条冷门链接要一路滚。先筛再操作：
     搜索匹配标题与网址，分组下拉按 data-group 收窄。
     纯前端隐藏，不打接口 —— 数据本来就已经在页面上了。 */
  (function initLinkFilter() {
    const input = $('#linkFilter');
    const sel   = $('#filterGroup');
    const box   = $('#admList');
    if (!input || !sel || !box) return;
    const countEl = $('#filterCount');

    function apply() {
      const q   = input.value.trim().toLowerCase();
      const gid = sel.value;
      const filtering = q !== '' || gid !== '';
      let shown = 0, total = 0;

      $$('.adm-list-group', box).forEach(block => {
        const label = $('.adm-list-label', block);
        const bg = label ? (label.dataset.group ?? '') : '';
        let inBlock = 0;
        $$('.adm-row', block).forEach(row => {
          total++;
          const okQ = !q
            || (row.dataset.title || '').toLowerCase().includes(q)
            || (row.dataset.url || '').toLowerCase().includes(q);
          const okG = gid === '' || bg === gid;
          const ok  = okQ && okG;
          row.hidden = !ok;
          if (ok) { shown++; inBlock++; }
        });
        // 整块都被筛掉就连组标题一起收起来，避免一长串空标题
        block.hidden = inBlock === 0;
      });

      if (countEl) {
        countEl.textContent = filtering ? '显示 ' + shown + ' / ' + total + ' 条' : '';
      }
      // 让批量条重算「全选」状态（筛选变化后可见集合变了）
      document.dispatchEvent(new CustomEvent('vnav:listfilter'));
    }

    input.addEventListener('input', apply);
    sel.addEventListener('change', apply);
    const reset = $('#filterReset');
    if (reset) {
      reset.addEventListener('click', () => {
        input.value = '';
        sel.value = '';
        apply();
      });
    }
  })();

  /* ---------- 批量管理 ----------
     勾选一批 → 一个动作。列表按分组分块渲染，选择状态统一用 data-bulk-id
     收集，跟分组无关。

     筛选改造后的两条约定：
       1. 「全选」只作用于**当前可见**的行 —— 筛出「设计」再点全选，
          选中的应该就是设计那 22 条，而不是页面上全部 300 条。
       2. 被筛掉的已选条目不会自动取消（切个筛选就丢选择太反直觉），
          但计数里会显式说明「其中 N 条在当前筛选外」，避免误删。 */
  (function initBulk() {
    const bar = $('#bulkBar');
    if (!bar) return;
    const allBox   = $('#bulkAll');
    const countEl  = $('#bulkCount');
    const groupSel = $('#bulkGroup');
    const all     = () => $$('input[data-bulk-id]');
    const isShown = b => {
      const row = b.closest('.adm-row');
      return !!row && !row.hidden;
    };
    const visible = () => all().filter(isShown);
    const picked  = () => all().filter(b => b.checked);

    function sync() {
      const vis = visible();
      const sel = picked();
      const hiddenSel = sel.filter(b => !isShown(b)).length;

      bar.hidden = sel.length === 0;
      countEl.textContent = hiddenSel > 0
        ? '已选 ' + sel.length + ' 条（其中 ' + hiddenSel + ' 条在当前筛选外）'
        : '已选 ' + sel.length + ' 条';

      const visOn = vis.filter(b => b.checked).length;
      allBox.checked = vis.length > 0 && visOn === vis.length;
      allBox.indeterminate = visOn > 0 && visOn < vis.length;

      all().forEach(b => {
        const row = b.closest('.adm-row');
        if (row) row.classList.toggle('is-picked', b.checked);
      });
    }

    document.addEventListener('change', e => {
      if (e.target === allBox) {
        const on = allBox.checked;
        visible().forEach(b => { b.checked = on; });
        sync();
        return;
      }
      if (e.target.matches && e.target.matches('input[data-bulk-id]')) sync();
    });

    /* 「选中本组」：一个分组头一颗按钮，点了就把这一块里可见的行全勾上
       （或全取消）。已有筛选时只作用在可见行上，不会波及筛掉的。 */
    document.addEventListener('click', e => {
      const btn = e.target.closest('[data-pick-group]');
      if (!btn) return;
      const block = btn.closest('.adm-list-group');
      if (!block) return;
      const boxes = $$('.adm-row', block)
        .filter(r => !r.hidden)
        .map(r => $('input[data-bulk-id]', r))
        .filter(Boolean);
      if (!boxes.length) return;
      const allOn = boxes.every(x => x.checked);
      boxes.forEach(x => { x.checked = !allOn; });
      sync();
    });

    // 筛选变化后重算状态
    document.addEventListener('vnav:listfilter', sync);

    bar.addEventListener('click', async e => {
      const btn = e.target.closest('[data-bulk]');
      if (!btn) return;
      const op  = btn.dataset.bulk;
      const ids = picked().map(b => Number(b.dataset.bulkId)).filter(Boolean);
      if (!ids.length) { toast('先勾选要处理的链接', true); return; }

      const payload = { action: 'bulk', op, ids };
      if (op === 'move') {
        const gid = Number(groupSel.value);
        if (!gid) { toast('先在上面选一个目标分组', true); return; }
        payload.group_id = gid;
      }
      if (op === 'delete') {
        const hiddenSel = picked().filter(b => !isShown(b)).length;
        const extra = hiddenSel > 0 ? '\n\n注意：其中 ' + hiddenSel + ' 条不在当前筛选结果里。' : '';
        if (!confirm('确定删除选中的 ' + ids.length + ' 条链接？删了无法恢复。' + extra)) return;
      }

      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = '处理中…';
      try {
        const r = await api(payload);
        toast('已处理 ' + (r.affected ?? ids.length) + ' 条');
        setTimeout(() => location.reload(), 500);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = label;
      }
    });
  })();

  /* ---------- 分组批量 ----------
     分组多了之后最想要的是「把这几个合成一个」：合并会把选中组的全部
     链接划到目标组，再删掉空壳；删除则让链接回到「未分组」。
     两个动作都不可撤销，所以都要二次确认，并把「会影响多少条链接」
     说清楚（行上的 .adm-group-n 就是那个数字）。 */
  (function initGroupBulk() {
    const bar = $('#gBulkBar');
    if (!bar) return;
    const allBox  = $('#gBulkAll');
    const countEl = $('#gBulkCount');
    const targetSel = $('#gMergeTarget');
    const rows = () => $$('.adm-group-row', $('#groupList') || document);
    const boxes = () => $$('input[data-gbulk-id]');
    const picked = () => boxes().filter(b => b.checked);

    function sync() {
      const sel = picked();
      bar.hidden = sel.length === 0;
      const links = sel.reduce((n, b) => {
        const row = b.closest('.adm-group-row');
        return n + (row ? Number(row.dataset.count || 0) : 0);
      }, 0);
      countEl.textContent = '已选 ' + sel.length + ' 个分组（共 ' + links + ' 条链接）';
      allBox.checked = sel.length > 0 && sel.length === boxes().length;
      allBox.indeterminate = sel.length > 0 && sel.length < boxes().length;
      rows().forEach(r => {
        const cb = $('input[data-gbulk-id]', r);
        r.classList.toggle('is-picked', !!(cb && cb.checked));
      });
    }

    document.addEventListener('change', e => {
      if (e.target === allBox) {
        const on = allBox.checked;
        boxes().forEach(b => { b.checked = on; });
        sync();
        return;
      }
      if (e.target.matches && e.target.matches('input[data-gbulk-id]')) sync();
    });

    bar.addEventListener('click', async e => {
      const btn = e.target.closest('[data-gbulk]');
      if (!btn) return;
      const op  = btn.dataset.gbulk;
      const ids = picked().map(b => Number(b.dataset.gbulkId)).filter(Boolean);
      if (!ids.length) { toast('先勾选分组', true); return; }

      const payload = { action: 'groups_bulk', op, ids };
      if (op === 'merge') {
        const t = Number(targetSel.value);
        if (!t) { toast('先在右边选一个「合并到」的目标分组', true); return; }
        payload.target_id = t;
        const tName = targetSel.options[targetSel.selectedIndex].textContent;
        if (!confirm('把选中的 ' + ids.length + ' 个分组合并到「' + tName + '」？\n'
          + '这些分组里的链接会全部划过去，然后分组本身被删除。此操作不可撤销。')) return;
      }
      if (op === 'delete') {
        const links = picked().reduce((n, b) => {
          const row = b.closest('.adm-group-row');
          return n + (row ? Number(row.dataset.count || 0) : 0);
        }, 0);
        if (!confirm('确定删除选中的 ' + ids.length + ' 个分组？\n'
          + '其中 ' + links + ' 条链接不会被删除，会回到「未分组」。')) return;
      }

      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = '处理中…';
      try {
        const r = await api(payload);
        toast(op === 'merge'
          ? '已合并 ' + (r.moved ?? 0) + ' 条链接，消掉 ' + (r.affected ?? 0) + ' 个分组'
          : '已删除 ' + (r.affected ?? 0) + ' 个分组');
        setTimeout(() => location.reload(), 600);
      } catch (err) {
        toast(err.message, true);
        btn.disabled = false;
        btn.textContent = label;
      }
    });
  })();
})();