(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const API = 'api.php'; // relative, so the folder can have any name
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const store = {
    get(k, d) { try { const v = localStorage.getItem('xl-' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('xl-' + k, JSON.stringify(v)); } catch (e) {} },
  };

  const toast = (msg, fail = false) => {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast show' + (fail ? ' fail' : '');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => (t.className = 'toast'), fail ? 7000 : 3500);
  };

  async function api(action, body) {
    const opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': XF.csrf }, body: JSON.stringify(body) } : {};
    const res = await fetch(`${API}?action=${action}`, opts);
    const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  /* ---------------- theme & tabs ---------------- */
  $('#theme').onclick = () => {
    const dark = getComputedStyle(document.documentElement).colorScheme.includes('dark');
    const next = dark ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('xl-theme', next); } catch (e) {}
  };

  function showTab(name) {
    $$('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
    $$('.panel').forEach((p) => (p.hidden = p.id !== 'panel-' + name));
    if (location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
    if (name === 'doctor' && !doctorLoaded && XF.local) diagnose();
  }
  $$('.tab').forEach((t) => (t.onclick = () => showTab(t.dataset.tab)));

  /* ---------------- status ---------------- */
  async function loadStatus() {
    try {
      const s = await api('status');
      $('#st-php').textContent = 'PHP ' + s.php;
      $('#st-mysql').innerHTML = `<i class="dot ${s.mysql.up ? 'ok' : 'fail'}"></i>MySQL ${s.mysql.up ? ':' + s.mysql.port : 'stopped'}`;
    } catch (e) {}
  }

  /* ---------------- projects ---------------- */
  let projects = [], archives = [];
  const PAGE = 12;
  let shown = PAGE;
  let filter = store.get('filter', 'All');
  let view = store.get('view', 'grid');
  let pins = new Set(store.get('pins', []));
  $('#sort').value = store.get('sort', 'recent');
  $('#show-system').checked = store.get('system', false);

  const ago = (ts) => {
    const s = Date.now() / 1000 - ts;
    if (s < 3600) return Math.max(1, Math.round(s / 60)) + 'm ago';
    if (s < 86400) return Math.round(s / 3600) + 'h ago';
    if (s < 86400 * 30) return Math.round(s / 86400) + 'd ago';
    if (s < 86400 * 365) return Math.round(s / 86400 / 30) + 'mo ago';
    return (s / 86400 / 365).toFixed(1).replace('.0', '') + 'y ago';
  };
  const size = (b) => (b > 1 << 30 ? (b / (1 << 30)).toFixed(1) + ' GB' : b > 1 << 20 ? (b / (1 << 20)).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');

  function card(p) {
    const href = p.entry || p.url;
    const tags = [
      `<span class="tag type">${esc(p.type)}</span>`,
      ...p.tags.map((t) => `<span class="tag">${esc(t)}</span>`),
      p.branch ? `<span class="tag" title="git branch">git:${esc(p.branch)}</span>` : '',
      p.noIndex ? `<span class="tag alert" title="No index file — Apache will list the folder">no index</span>` : '',
    ].join('');
    return `<article class="proj" data-name="${esc(p.name)}">
      <div class="proj-head">
        <div style="min-width:0">
          <a class="proj-name" href="${esc(href)}" target="_blank" rel="noopener" title="${esc(p.name)}">${esc(p.name)}</a>
          <div class="proj-sub">Changed ${ago(p.modified)}</div>
        </div>
      </div>
      <div class="proj-meta">${tags}</div>
      <div class="proj-actions">
        ${p.entry ? `<a href="${esc(p.url)}" target="_blank" rel="noopener" title="Folder root">root</a>` : ''}
        <button data-copy="${esc(p.path)}" title="Copy folder path">Copy path</button>
      </div>
      <button class="pin ${pins.has(p.name) ? 'on' : ''}" data-pin="${esc(p.name)}" title="Pin">${pins.has(p.name) ? 'Unpin' : 'Pin'}</button>
    </article>`;
  }

  function renderProjects() {
    const q = $('#q').value.trim().toLowerCase();
    const sort = $('#sort').value;
    const sys = $('#show-system').checked;
    const visible = projects.filter((p) => sys || !p.system);

    // chips
    const counts = visible.reduce((m, p) => ((m[p.type] = (m[p.type] || 0) + 1), m), {});
    if (filter !== 'All' && !counts[filter]) filter = 'All';
    const types = Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
    $('#chips').innerHTML = ['All', ...types].map((t) =>
      `<button class="chip ${t === filter ? 'on' : ''}" data-type="${esc(t)}">${esc(t)}<b>${t === 'All' ? visible.length : counts[t]}</b></button>`).join('');

    let list = visible.filter((p) => (filter === 'All' || p.type === filter) &&
      (!q || p.name.toLowerCase().includes(q) || p.type.toLowerCase().includes(q) || p.tags.join(' ').toLowerCase().includes(q)));
    const cmp = { recent: (a, b) => b.modified - a.modified, name: (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true }),
      type: (a, b) => a.type.localeCompare(b.type) || a.name.localeCompare(b.name) }[sort];
    list.sort(cmp);

    const pinned = list.filter((p) => pins.has(p.name));
    $('#pinned-wrap').hidden = !pinned.length;
    $('#pinned').innerHTML = pinned.map(card).join('');
    $('#grid').innerHTML = list.slice(0, shown).map(card).join('') || `<div class="empty">No projects match “${esc(q)}”.</div>`;
    const rest = list.length - shown;
    $('#more').hidden = rest <= 0;
    $('#more-count').textContent = `Showing ${shown} of ${list.length}`;
    $('#more-btn').textContent = `Show ${Math.min(PAGE, rest)} more`;
    $('#all-title').textContent = q || filter !== 'All' ? `${list.length} result${list.length === 1 ? '' : 's'}` : 'All projects';
    $('#tab-count').textContent = visible.length;
    $$('#grid, #pinned').forEach((g) => g.classList.toggle('list', view === 'list'));
    $$('#view button').forEach((b) => b.classList.toggle('on', b.dataset.view === view));

    $('#archives-wrap').hidden = !archives.length;
    $('#archives-count').textContent = archives.length;
    $('#archives').innerHTML = archives.map((a) => `<li><span>${esc(a.name)}</span><span class="muted">${size(a.size)}</span></li>`).join('');
  }

  async function loadProjects() {
    try {
      const data = await api('projects');
      projects = data.projects; archives = data.archives;
      renderProjects();
    } catch (e) {
      $('#grid').innerHTML = `<div class="empty">Could not load projects: ${esc(e.message)}</div>`;
    }
  }

  // Any change to what's listed starts again from the first page.
  const rerender = () => { shown = PAGE; renderProjects(); };
  $('#more-btn').onclick = () => { shown += PAGE; renderProjects(); };
  $('#more-all').onclick = () => { shown = Infinity; renderProjects(); };
  $('#q').oninput = rerender;
  $('#sort').onchange = () => { store.set('sort', $('#sort').value); rerender(); };
  $('#show-system').onchange = () => { store.set('system', $('#show-system').checked); rerender(); };
  $('#view').onclick = (e) => { const b = e.target.closest('button'); if (!b) return; view = b.dataset.view; store.set('view', view); renderProjects(); };
  $('#chips').onclick = (e) => { const c = e.target.closest('.chip'); if (!c) return; filter = c.dataset.type; store.set('filter', filter); rerender(); };
  $('#panel-projects').addEventListener('click', (e) => {
    const pin = e.target.closest('[data-pin]');
    if (pin) {
      const n = pin.dataset.pin;
      pins.has(n) ? pins.delete(n) : pins.add(n);
      store.set('pins', [...pins]); renderProjects();
      return;
    }
    const cp = e.target.closest('[data-copy]');
    if (cp) navigator.clipboard?.writeText(cp.dataset.copy).then(() => toast('Copied ' + cp.dataset.copy), () => toast(cp.dataset.copy));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); showTab('projects'); $('#q').focus(); }
    if (e.key === 'Escape' && document.activeElement === $('#q')) { $('#q').value = ''; renderProjects(); }
    if (e.key === 'Enter' && document.activeElement === $('#q')) { const first = $('#grid .proj-name'); if (first) window.open(first.href, '_blank'); }
  });

  /* ---------------- doctor ---------------- */
  let doctorLoaded = false;
  const ICON = { ok: '✓', warn: '!', fail: '✕', info: 'i' };

  async function diagnose() {
    if (!XF.local) return;
    doctorLoaded = true;
    const btn = $('#run');
    btn.disabled = true; btn.textContent = 'Checking…';
    try {
      const d = await api('diagnose');
      renderDoctor(d);
    } catch (e) {
      $('#checks').innerHTML = `<li class="check"><span class="ico fail">✕</span><div class="check-body"><strong>Diagnostics failed</strong><p>${esc(e.message)}</p></div></li>`;
    } finally {
      btn.disabled = false; btn.textContent = 'Run checks again';
    }
  }

  function renderDoctor(d) {
    const problems = d.checks.filter((c) => c.status === 'fail').length;
    const warns = d.checks.filter((c) => c.status === 'warn').length;
    const sum = $('#summary');
    sum.className = 'summary ' + (problems ? 'fail' : 'ok');
    sum.innerHTML = problems
      ? `${problems} problem${problems > 1 ? 's' : ''} found${warns ? `, ${warns} warning${warns > 1 ? 's' : ''}` : ''}. Use the buttons below to fix them.`
      : `phpMyAdmin should work${warns ? ` (${warns} warning${warns > 1 ? 's' : ''})` : ''}.`;
    $('#tab-issues').hidden = !problems;
    $('#tab-issues').textContent = problems;

    $('#checks').innerHTML = d.checks.map((c) => `<li class="check">
      <span class="ico ${c.status}">${ICON[c.status]}</span>
      <div class="check-body"><strong>${esc(c.label)}</strong>${c.detail ? `<p>${esc(c.detail)}</p>` : ''}</div>
      ${c.fix ? `<button class="btn ${c.fix.danger ? 'danger' : c.status === 'fail' ? 'primary' : ''}" data-fix="${esc(c.fix.action)}"
        ${c.fix.params ? `data-params='${esc(JSON.stringify(c.fix.params))}'` : ''}
>${esc(c.fix.label)}</button>` : ''}
    </li>`).join('');

    // Prefill connection form with current config (never the password).
    const f = $('#cred-form');
    if (!f.dataset.touched) {
      f.user.value = d.server.user; f.host.value = d.server.host; f.port.value = d.server.port;
      f.password.placeholder = d.server.hasPassword ? '(saved password, retype to change)' : '(empty)';
      setAuth(d.server.auth_type === 'config' ? 'config' : 'cookie');
    }

    $('#log').innerHTML = d.log.length
      ? d.log.map((l) => `<span class="${/\[ERROR\]/.test(l) ? 'err' : /\[Warning\]/.test(l) ? 'wrn' : ''}">${esc(l)}</span>`).join('\n')
      : 'Log is empty or not found.';
    if (d.logIssue && !d.mysql.running) $('#log-wrap').open = true;

    $('#backups-tool').hidden = !d.backups.length;
    $('#backups').innerHTML = d.backups.map((b) => `<option value="${esc(b.file)}">${new Date(b.modified * 1000).toLocaleString()}</option>`).join('');
    loadStatus();
  }

  const AUTH_HELP = {
    config: 'phpMyAdmin logs in automatically with these credentials (XAMPP default).',
    cookie: 'phpMyAdmin shows a login page and you type the user and password each time. Use this if you don’t want the password stored in a file.',
  };
  function setAuth(a) {
    $('#cred-form').dataset.auth = a;
    $$('#auth-seg button').forEach((b) => b.classList.toggle('on', b.dataset.auth === a));
    $('#auth-help').textContent = AUTH_HELP[a];
    $('.pw-field').style.opacity = a === 'cookie' ? 0.5 : 1;
  }

  /* What each write action does, shown in the warning modal before it runs. */
  const P = XF.paths || {};
  const CFG_BACKUP = 'A copy of the current file is saved as config.inc.php.xl-<date> first, and can be restored under Other tools.';
  const WARNINGS = {
    save_connection: (p) => ({
      title: 'Overwrite phpMyAdmin connection settings?',
      lead: 'phpMyAdmin will use these settings for every page load. If they are wrong it will stop connecting.',
      points: [
        `Login mode: ${p.auth_type === 'cookie' ? 'login screen' : 'automatic login'}`,
        `Server: ${p.user || 'root'} @ ${p.host || '127.0.0.1'}:${p.port || 3306}`,
        p.auth_type === 'cookie' ? 'Any saved password is removed from the file.' : 'The password is stored in plain text in config.inc.php.',
        CFG_BACKUP,
      ],
      files: [P.pmaConfig],
      ok: 'Save settings',
    }),
    start_mysql: () => ({
      title: 'Start the MySQL server?',
      lead: 'Launches mysqld.exe in the background from Apache.',
      points: [
        'The XAMPP Control Panel may not show it as started, but it still detects the open port.',
        'If MySQL was not shut down cleanly before, starting it can trigger crash recovery on your data files.',
      ],
      files: [P.mysqld],
      ok: 'Start MySQL',
    }),
    reset_config: () => ({
      title: 'Reset phpMyAdmin config to XAMPP default?',
      lead: 'Replaces the whole config.inc.php with the stock XAMPP version.',
      points: ['Login becomes root with no password (automatic login).', 'Any custom settings in the file are lost from the live config.', CFG_BACKUP],
      files: [P.pmaConfig],
      ok: 'Reset config',
    }),
    restore_mysql_data: () => ({
      title: 'Repair MySQL from backup?',
      lead: 'Rebuilds the MySQL data folder. Only use this when MySQL refuses to start.',
      points: [
        `The current data folder is renamed to data_old_<date>. Nothing is deleted.`,
        'System tables are copied fresh from the backup folder, then your databases and ibdata1 are copied back.',
        'All MySQL user accounts and passwords reset to XAMPP defaults (root with no password).',
        'MySQL must be stopped. This can take several minutes for large databases.',
      ],
      files: [P.mysqlData, P.mysqlBackup],
      ok: 'Repair MySQL',
      typed: 'REPAIR',
    }),
    restore_config: (p) => ({
      title: 'Restore config from backup?',
      lead: `Replaces config.inc.php with ${p.file}.`,
      points: ['Changes made after that backup are undone in the live config.', CFG_BACKUP],
      files: [P.pmaConfig],
      ok: 'Restore backup',
    }),
    fix_blowfish: () => ({
      title: 'Generate a new blowfish_secret?',
      lead: 'Writes a new random 32-character cookie secret.',
      points: ['Anyone logged in to phpMyAdmin through the login screen will be logged out.', CFG_BACKUP],
      files: [P.pmaConfig],
      ok: 'Generate secret',
    }),
    disable_controluser: () => ({
      title: 'Disable the phpMyAdmin control user?',
      lead: 'Comments out the controluser and controlpass lines.',
      points: ['Removes the "controluser failed" warning.', 'Advanced features (bookmarks, history, designer) stop working until it is set up again.', CFG_BACKUP],
      files: [P.pmaConfig],
      ok: 'Disable control user',
    }),
    set_port: (p) => ({
      title: `Change phpMyAdmin port to ${p.port}?`,
      lead: 'phpMyAdmin will connect to MySQL on this port.',
      points: [CFG_BACKUP],
      files: [P.pmaConfig],
      ok: 'Change port',
    }),
    fix_tmp: () => ({
      title: 'Create the phpMyAdmin tmp folder?',
      lead: 'Creates a folder phpMyAdmin uses for its template cache.',
      points: [],
      files: [P.pmaConfig && P.pmaConfig.replace(/config\.inc\.php$/, 'tmp')],
      ok: 'Create folder',
    }),
  };

  function confirmCritical(w) {
    const d = $('#warn');
    $('#warn-title').textContent = w.title;
    $('#warn-lead').textContent = w.lead;
    $('#warn-points').innerHTML = w.points.map((x) => `<li>${esc(x)}</li>`).join('');
    const files = w.files.filter(Boolean);
    $('#warn-files').innerHTML = files.length ? `<span>Affects</span>${files.map((f) => `<code>${esc(f)}</code>`).join('')}` : '';
    $('#warn-ok').textContent = w.ok || 'Continue';
    const input = $('#warn-input');
    $('#warn-typed').hidden = !w.typed;
    $('#warn-word').textContent = w.typed || '';
    input.value = '';
    $('#warn-ok').disabled = !!w.typed;
    input.oninput = () => ($('#warn-ok').disabled = input.value.trim() !== w.typed);
    d.returnValue = 'cancel';
    d.showModal();
    (w.typed ? input : $('#warn-cancel')).focus();
    return new Promise((resolve) => {
      d.onclick = (e) => { if (e.target === d) d.close('cancel'); }; // click on backdrop
      d.onclose = () => resolve(d.returnValue === 'ok');
    });
  }

  /** Runs a fix after the warning modal. Resolves to null if the user cancels. */
  async function runFix(btn, fix, params = {}) {
    if (WARNINGS[fix] && !(await confirmCritical(WARNINGS[fix](params)))) return null;
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Working…';
    try {
      const r = await api('fix', { fix, params });
      toast(r.message || 'Done');
      return r;
    } catch (e) {
      toast(e.message, true);
      throw e;
    } finally {
      btn.disabled = false; btn.textContent = label;
    }
  }

  if (XF.local) {
    $('#run').onclick = diagnose;

    $('#panel-doctor').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-fix]');
      if (!b) return;
      const fix = b.dataset.fix;
      if (fix === 'open_credentials') { $('#cred-form').scrollIntoView({ behavior: 'smooth', block: 'center' }); $('#cred-form').password.focus(); return; }
      try { if (!(await runFix(b, fix, b.dataset.params ? JSON.parse(b.dataset.params) : {}))) return; } catch (err) {}
      diagnose();
    });

    $('#restore-backup').onclick = async (e) => {
      const file = $('#backups').value;
      if (!file) return;
      try { if (!(await runFix(e.target, 'restore_config', { file }))) return; } catch (err) {}
      diagnose();
    };

    const f = $('#cred-form');
    $('#auth-seg').onclick = (e) => { const b = e.target.closest('button'); if (b) { setAuth(b.dataset.auth); f.dataset.touched = 1; } };
    f.oninput = () => (f.dataset.touched = 1);
    const params = () => ({ auth_type: f.dataset.auth || 'config', user: f.user.value, password: f.password.value, host: f.host.value, port: f.port.value });
    const msg = (text, ok) => { const m = $('#cred-msg'); m.textContent = text; m.className = 'form-msg ' + (ok ? 'ok' : 'fail'); };

    $('#test').onclick = async (e) => {
      try { const r = await runFix(e.target, 'test_connection', params()); msg(r.message, true); } catch (err) { msg(err.message, false); }
    };
    f.onsubmit = async (e) => {
      e.preventDefault();
      const btn = f.querySelector('[type=submit]');
      try {
        const r = await runFix(btn, 'save_connection', params());
        if (!r) return;
        msg('Saved. ' + r.message, true);
        delete f.dataset.touched; f.password.value = '';
      } catch (err) { msg(err.message, false); }
      diagnose();
    };
  }

  /* ---------------- boot ---------------- */
  loadStatus();
  loadProjects();
  showTab(['#doctor', '#about'].includes(location.hash) ? location.hash.slice(1) : 'projects');
})();
