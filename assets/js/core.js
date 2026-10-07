/* DevTech Staff Portal — core: helpers, API, router, shell, overlays, shared components */
(function () {
  'use strict';

  const App = (window.App = {
    user: null, cfg: null, csrf: document.querySelector('meta[name=csrf]')?.content || '',
    views: {}, charts: [], lastChange: 0, unread: 0, badges: { review: 0, requests: 0 }, wallet: null,
    pollTimer: null, current: null,
  });

  /* ------------------------------------------------------------ helpers */
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const nf = new Intl.NumberFormat('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const nf0 = new Intl.NumberFormat('en-NG', { maximumFractionDigits: 0 });
  const money = (n, opts = {}) => {
    const v = Number(n) || 0;
    const cur = App.cfg?.currency || '₦';
    const s = (opts.short ? nf0 : nf).format(Math.abs(v));
    const sign = v < 0 ? '-' : opts.sign && v > 0 ? '+' : '';
    return `${sign}${cur}${s}`;
  };
  const moneyShort = (n) => {
    const v = Math.abs(Number(n) || 0), cur = App.cfg?.currency || '₦', sg = n < 0 ? '-' : '';
    if (v >= 1e6) return `${sg}${cur}${(v / 1e6).toFixed(v >= 1e7 ? 0 : 1)}m`;
    if (v >= 1e3) return `${sg}${cur}${(v / 1e3).toFixed(v >= 1e4 ? 0 : 1)}k`;
    return `${sg}${cur}${nf0.format(v)}`;
  };
  const parseDate = (d) => (d ? new Date(String(d).length <= 10 ? d + 'T00:00:00' : String(d).replace(' ', 'T')) : null);
  const fmtDate = (d, withYear = true) => {
    const x = parseDate(d);
    if (!x || isNaN(x)) return '';
    return x.toLocaleDateString('en-GB', withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' });
  };
  const fmtDateTime = (d) => {
    const x = parseDate(d);
    if (!x || isNaN(x)) return '';
    return x.toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
  };
  const ago = (d) => {
    const x = parseDate(d);
    if (!x) return '';
    const s = (Date.now() - x.getTime()) / 1000;
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    if (s < 604800) return `${Math.floor(s / 86400)}d ago`;
    return fmtDate(d);
  };
  const initials = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
  const isAdmin = () => App.user?.role === 'admin';
  const addDays = (iso, n) => { const d = parseDate(iso); d.setDate(d.getDate() + n); return iso8601(d); };
  const iso8601 = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const store = {
    get(k, d = null) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* storage unavailable */ } },
    del(k) { try { localStorage.removeItem(k); } catch { /* storage unavailable */ } },
  };

  const STATUS = {
    posted: ['Awaiting review', 'out'], approved: ['Approved', 'in'], queried: ['Queried', 'bad'], rejected: ['Rejected (refunded)', 'bad'],
    pending: ['Pending', 'out'], declined: ['Declined', 'bad'], cancelled: ['Cancelled', ''],
  };
  const statusPill = (s, kind) => {
    if (kind && kind !== 'debit') return kind === 'credit' ? '<span class="pill in">Received</span>' : '<span class="pill info">Adjustment</span>';
    const [label, tone] = STATUS[s] || [s, ''];
    return `<span class="pill ${tone}">${esc(label)}</span>`;
  };
  const CAT_ICONS = [[/transport/i, 'bus'], [/material|item|bought/i, 'tools'], [/logist|deliver/i, 'truck-delivery'], [/data|airtime/i, 'wifi'],
    [/feed|food/i, 'tools-kitchen-2'], [/repair|part/i, 'settings'], [/allowance/i, 'calendar-dollar'], [/budget/i, 'briefcase'], [/top/i, 'circle-plus'],
    [/adjust/i, 'adjustments'], [/import/i, 'file-import']];
  const catIcon = (c) => (CAT_ICONS.find(([re]) => re.test(c || '')) || [0, 'receipt'])[1];
  const txnTone = (t) => (t.kind === 'credit' ? 'tone-in' : t.kind === 'adjust' ? 'tone-info' : t.status === 'rejected' ? 'tone-bad' : 'tone-out');

  Object.assign(App, { $, $$, esc, money, moneyShort, fmtDate, fmtDateTime, ago, initials, isAdmin, addDays, iso8601, parseDate, debounce, store, statusPill, catIcon, txnTone, STATUS });

  /* ---------------------------------------------------------------- API */
  async function api(action, { data = null, files = null, query = null, method = null, retry = true } = {}) {
    const isPost = method === 'POST' || data !== null || files !== null;
    let url = `api.php?action=${encodeURIComponent(action)}`;
    if (query) {
      const q = new URLSearchParams();
      Object.entries(query).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) q.set(k, v); });
      const s = q.toString();
      if (s) url += '&' + s;
    }
    const opts = { method: isPost ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-CSRF-Token': App.csrf } };
    if (isPost) {
      if (files && files.length) {
        const fd = new FormData();
        fd.append('payload', JSON.stringify(data || {}));
        files.forEach((f) => fd.append(f.field || 'files[]', f.file || f, f.name || f.file?.name || 'file'));
        opts.body = fd;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data || {});
      }
    }
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      const err = new Error('You seem to be offline. Check your connection and try again.');
      err.offline = true;
      throw err;
    }
    let body = null;
    try { body = await res.json(); } catch { body = { ok: false, error: `Server returned ${res.status}.` }; }
    if (res.status === 419 && retry) {
      await refreshSession();
      return api(action, { data, files, query, method, retry: false });
    }
    if (res.status === 401 && action !== 'login') {
      App.user = null;
      renderLogin('Your session ended. Sign in again.');
      throw new Error('Signed out');
    }
    if (!res.ok || body.ok === false) {
      const err = new Error(body.error || 'Something went wrong.');
      err.fields = body.fields || null;
      err.status = res.status;
      throw err;
    }
    return body;
  }
  async function refreshSession() {
    const r = await fetch('api.php?action=session', { credentials: 'same-origin' }).then((x) => x.json());
    App.csrf = r.csrf;
    return r;
  }
  App.api = api;

  /* ------------------------------------------------------------- toasts */
  function toast(msg, isErr = false) {
    const el = document.createElement('div');
    el.className = 'toast' + (isErr ? ' err' : '');
    el.textContent = msg;
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), isErr ? 5000 : 2800);
  }
  App.toast = toast;
  App.fail = (e) => { if (e?.message !== 'Signed out') toast(e?.message || String(e), true); };

  /* ----------------------------------------------------------- overlays */
  const stack = [];
  function closeTop() {
    const top = stack.pop();
    if (!top) return;
    top.els.forEach((e) => e.remove());
    top.onClose && top.onClose();
    if (!stack.length) document.body.style.overflow = '';
  }
  function openLayer(className, html, { onClose } = {}) {
    const bd = document.createElement('div');
    bd.className = 'backdrop';
    const el = document.createElement('div');
    el.className = className;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = html;
    bd.addEventListener('click', closeTop);
    $('#overlay-root').append(bd, el);
    document.body.style.overflow = 'hidden';
    const layer = { els: [bd, el], onClose };
    stack.push(layer);
    $$('[data-close]', el).forEach((b) => b.addEventListener('click', closeTop));
    setTimeout(() => el.querySelector('[autofocus]')?.focus(), 50);
    return el;
  }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && stack.length) closeTop(); });

  App.drawer = (title, body, foot = '', opts = {}) => openLayer('drawer',
    `<div class="drawer-head"><h2>${title}</h2><button class="icon-btn" data-close aria-label="Close"><i class="ti ti-x"></i></button></div>
     <div class="drawer-body">${body}</div>${foot ? `<div class="drawer-foot">${foot}</div>` : ''}`, opts);
  App.modal = (title, body, foot = '', opts = {}) => openLayer('modal' + (opts.wide ? ' wide' : ''),
    `<div class="drawer-head"><h2>${title}</h2><button class="icon-btn" data-close aria-label="Close"><i class="ti ti-x"></i></button></div>
     <div class="drawer-body">${body}</div>${foot ? `<div class="drawer-foot">${foot}</div>` : ''}`, opts);
  App.closeTop = closeTop;
  App.closeAll = () => { while (stack.length) closeTop(); };
  App.hasOverlay = () => stack.length > 0;
  App.confirm = (title, message, { ok = 'Confirm', danger = false, input = null } = {}) => new Promise((resolve) => {
    let done = false;
    const el = App.modal(esc(title),
      `<p class="muted" style="margin-bottom:12px">${message}</p>${input ? `<div class="field"><label>${esc(input.label)}${input.required ? ' <span class="req">*</span>' : ''}</label><textarea id="cf-in" rows="3" placeholder="${esc(input.placeholder || '')}" autofocus></textarea><div class="err hide" id="cf-err">${esc(input.requiredMsg || 'This is required.')}</div></div>` : ''}`,
      `<button class="btn ghost" data-close>Cancel</button><button class="btn ${danger ? 'danger solid' : 'primary'}" id="cf-ok">${esc(ok)}</button>`,
      { onClose: () => { if (!done) resolve(null); } });
    $('#cf-ok', el).addEventListener('click', () => {
      const v = input ? $('#cf-in', el).value.trim() : true;
      if (input?.required && !v) { $('#cf-err', el).classList.remove('hide'); return; }
      done = true;
      closeTop();
      resolve(v);
    });
  });
  App.lightbox = (src) => {
    const lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.innerHTML = `<img src="${esc(src)}" alt=""><button class="btn"><i class="ti ti-x"></i> Close</button>`;
    lb.addEventListener('click', () => lb.remove());
    document.body.appendChild(lb);
  };

  /* -------------------------------------------------------- attachments */
  App.gallery = (files, { removable = false } = {}) => {
    if (!files || !files.length) return '<p class="faint small">No files attached.</p>';
    return `<div class="gallery">${files.map((f) => {
      const isImg = f.mime?.startsWith('image/');
      const inner = isImg && f.thumb ? `<img src="file.php?id=${f.id}&thumb=1" alt="${esc(f.name)}" loading="lazy">`
        : isImg ? `<img src="file.php?id=${f.id}" alt="${esc(f.name)}" loading="lazy">`
          : `<i class="ti ti-file-type-pdf" style="font-size:30px"></i>`;
      return `<div class="thumb" data-file="${f.id}" data-img="${isImg ? 1 : 0}" title="${esc(f.name)}">${inner}
        ${isImg ? '' : `<span class="fname">${esc(f.name)}</span>`}
        ${removable ? `<button class="x" data-del-file="${f.id}" aria-label="Remove ${esc(f.name)}"><i class="ti ti-x"></i></button>` : ''}</div>`;
    }).join('')}</div>`;
  };
  App.bindGallery = (root, onDeleted) => {
    $$('.thumb[data-file]', root).forEach((t) => t.addEventListener('click', (e) => {
      if (e.target.closest('[data-del-file]')) return;
      const id = t.dataset.file;
      if (t.dataset.img === '1') App.lightbox(`file.php?id=${id}`);
      else window.open(`file.php?id=${id}`, '_blank', 'noopener');
    }));
    $$('[data-del-file]', root).forEach((b) => b.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!(await App.confirm('Remove file?', 'This file will be deleted.', { ok: 'Remove', danger: true }))) return;
      try {
        await api('attachment_delete', { data: { id: +b.dataset.delFile } });
        b.closest('.thumb').remove();
        toast('File removed');
        onDeleted && onDeleted();
      } catch (err) { App.fail(err); }
    }));
  };

  /** Image compression keeps uploads small on mobile data. */
  async function compressImage(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 350 * 1024) return file;
    try {
      const bmp = await createImageBitmap(file);
      const max = 1600;
      const s = Math.min(1, max / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * s);
      c.height = Math.round(bmp.height * s);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.82));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch {
      return file;
    }
  }

  /** File picker with previews. Returns { files: () => File[] (compressed), count }. */
  App.uploader = (container, { existing = 0, label = 'Add photos', accept = 'image/*,application/pdf', capture = false } = {}) => {
    const max = App.cfg?.max_files || 6;
    const picked = [];
    container.innerHTML = `<div class="gallery"><label class="thumb add-file" title="${esc(label)}">
      <input type="file" accept="${accept}" multiple class="sr" ${capture ? 'capture="environment"' : ''}>
      <div style="text-align:center"><i class="ti ti-camera-plus" style="font-size:24px"></i><div class="small">${esc(label)}</div></div></label></div>
      <div class="help faint small" style="margin-top:6px">JPG, PNG, WEBP or PDF · up to ${max} files · ${App.cfg?.max_mb || 8}MB each</div>`;
    const gal = $('.gallery', container);
    const input = $('input', container);
    const addBtn = $('.add-file', container);
    input.addEventListener('change', async () => {
      for (const f of Array.from(input.files)) {
        if (picked.length + existing >= max) { toast(`You can attach up to ${max} files.`, true); break; }
        if (f.size > (App.cfg?.max_mb || 8) * 1024 * 1024 * 3) { toast(`${f.name} is too large.`, true); continue; }
        const small = await compressImage(f);
        if (small.size > (App.cfg?.max_mb || 8) * 1024 * 1024) { toast(`${f.name} is larger than ${App.cfg?.max_mb || 8}MB.`, true); continue; }
        picked.push(small);
        const t = document.createElement('div');
        t.className = 'thumb';
        t.innerHTML = small.type.startsWith('image/') ? `<img alt="">` : `<i class="ti ti-file-type-pdf" style="font-size:30px"></i><span class="fname">${esc(small.name)}</span>`;
        if (small.type.startsWith('image/')) t.querySelector('img').src = URL.createObjectURL(small);
        const x = document.createElement('button');
        x.className = 'x';
        x.type = 'button';
        x.setAttribute('aria-label', 'Remove');
        x.innerHTML = '<i class="ti ti-x"></i>';
        x.addEventListener('click', () => { picked.splice(picked.indexOf(small), 1); t.remove(); });
        t.appendChild(x);
        gal.insertBefore(t, addBtn);
      }
      input.value = '';
    });
    return { files: () => picked.slice(), count: () => picked.length, setExisting: (n) => { existing = n; } };
  };

  /* ------------------------------------------------------------- charts */
  const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  App.palette = () => [1, 2, 3, 4, 5, 6, 7, 8].map((i) => cssVar(`--chart-${i}`));
  App.cssVar = cssVar;
  App.chart = (canvas, config) => {
    if (!window.Chart || !canvas) return null;
    const text = cssVar('--text-2'), grid = cssVar('--border');
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = text;
    Chart.defaults.borderColor = grid;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.maintainAspectRatio = false;
    const c = new Chart(canvas, config);
    App.charts.push(c);
    return c;
  };
  function destroyCharts() { App.charts.forEach((c) => c.destroy()); App.charts = []; }
  App.destroyCharts = destroyCharts;

  /* --------------------------------------------------------------- form */
  App.formData = (form) => {
    const out = {};
    $$('[name]', form).forEach((el) => {
      if (el.type === 'file') return;
      if (el.type === 'checkbox') {
        if (el.dataset.multi) { out[el.name] = out[el.name] || []; if (el.checked) out[el.name].push(el.value); }
        else out[el.name] = el.checked;
      } else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; else if (!(el.name in out)) out[el.name] = ''; }
      else out[el.name] = el.value;
    });
    return out;
  };
  App.showErrors = (form, fields) => {
    $$('.field.invalid', form).forEach((f) => { f.classList.remove('invalid'); f.querySelector('.err')?.remove(); });
    if (!fields) return;
    let first = null;
    Object.entries(fields).forEach(([k, msg]) => {
      const el = form.querySelector(`[name="${k}"]`) || form.querySelector(`[data-field="${k}"]`);
      const f = el?.closest('.field');
      if (!f) return;
      f.classList.add('invalid');
      const e = document.createElement('div');
      e.className = 'err';
      e.textContent = msg;
      f.appendChild(e);
      first = first || f;
    });
    first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  App.busy = async (btn, fn) => {
    const html = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px"></span>';
    try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = html; }
  };
  App.opts = (list, selected, { placeholder = null } = {}) =>
    (placeholder !== null ? `<option value="">${esc(placeholder)}</option>` : '') +
    list.map((o) => {
      const [v, l] = Array.isArray(o) ? o : [o, o];
      return `<option value="${esc(v)}" ${String(v) === String(selected ?? '') ? 'selected' : ''}>${esc(l)}</option>`;
    }).join('');
  App.empty = (icon, title, text = '', action = '') =>
    `<div class="empty"><i class="ti ti-${icon}"></i><h3>${esc(title)}</h3>${text ? `<p>${esc(text)}</p>` : ''}${action ? `<div style="margin-top:12px">${action}</div>` : ''}</div>`;
  App.loading = () => '<div class="boot" style="min-height:240px"><div class="spinner"></div></div>';

  /* -------------------------------------------------------------- login */
  function renderLogin(msg = '') {
    stopPolling();
    App.closeAll();
    const name = esc(App.cfg?.app_name || 'Staff portal');
    $('#app').innerHTML = `
      <div class="login-wrap">
        <div class="login-art">
          <div><img src="assets/logo.png" alt="${esc(App.cfg?.company || 'DevTech')}" class="login-logo"><div class="brand-name" style="margin-top:10px">${esc(App.cfg?.company || 'DevTech')}</div><div style="opacity:.8;font-size:12px">${name}</div></div>
          <div><h1>Daily reports, allowances and expenses in one place.</h1>
            <div class="feats">
              <div class="feat"><i class="ti ti-wallet"></i> Live wallet balance as you spend</div>
              <div class="feat"><i class="ti ti-camera"></i> Snap receipts and work photos</div>
              <div class="feat"><i class="ti ti-clipboard-check"></i> Submit your daily report in minutes</div>
            </div></div>
          <div style="opacity:.8;font-size:12px;line-height:1.6"><i class="ti ti-map-pin" style="font-size:14px"></i> ${esc(App.cfg?.address || '')}<br>© ${new Date().getFullYear()} ${esc(App.cfg?.company || '')}${App.cfg?.domain ? ' · ' + esc(App.cfg.domain) : ''}</div>
        </div>
        <div class="login-form"><form class="login-card" id="login-form" novalidate>
          <h1 style="margin-bottom:4px">Sign in</h1><p class="muted" style="margin-bottom:20px">Use the email your admin set up for you.</p>
          ${msg ? `<div class="note-box warn" style="margin-bottom:14px">${esc(msg)}</div>` : ''}
          <div class="field"><label for="lg-email">Email</label><input type="email" id="lg-email" name="email" autocomplete="username" placeholder="name@devtech.ng" required autofocus></div>
          <div class="field"><label for="lg-pass">Password</label><input type="password" id="lg-pass" name="password" autocomplete="current-password" required></div>
          <div class="err small bad hide" id="lg-err" style="margin-bottom:10px"></div>
          <button class="btn primary block" type="submit" style="height:44px">Sign in</button>
          <p class="faint small" style="margin-top:14px">Forgot your password? Ask your admin to reset it.</p>
        </form></div>
      </div>`;
    $('#login-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button[type=submit]');
      const err = $('#lg-err');
      err.classList.add('hide');
      const data = App.formData(e.target);
      if (!data.email || !data.password) { err.textContent = 'Enter your email and password.'; err.classList.remove('hide'); return; }
      try {
        const r = await App.busy(btn, () => api('login', { data }));
        boot(r);
      } catch (ex) {
        err.textContent = ex.message;
        err.classList.remove('hide');
      }
    });
  }
  App.renderLogin = renderLogin;

  /* -------------------------------------------------------------- shell */
  function navItems() {
    if (isAdmin()) {
      return [
        { label: 'Admin' },
        { href: '', icon: 'layout-dashboard', text: 'Overview', mobile: true },
        { href: 'pay', icon: 'send', text: 'Send payment', mobile: true },
        { href: 'review', icon: 'receipt', text: 'Expense review', badge: 'review', mobile: true },
        { href: 'requests', icon: 'cash', text: 'Funding requests', badge: 'requests' },
        { href: 'balances', icon: 'wallet', text: 'Balances' },
        { href: 'ledger', icon: 'list-details', text: 'Ledger' },
        { label: 'Work' },
        { href: 'reports', icon: 'clipboard-text', text: 'Work reports', mobile: true },
        { href: 'issues', icon: 'layout-kanban', text: 'Issues board' },
        { label: 'Setup' },
        { href: 'staff', icon: 'users', text: 'Staff setup' },
        { href: 'import', icon: 'file-import', text: 'Import history' },
        { href: 'settings', icon: 'settings', text: 'Settings' },
        { href: 'profile', icon: 'user', text: 'Profile', mobileOnly: true },
      ];
    }
    return [
      { label: 'My work' },
      { href: '', icon: 'home', text: 'Home', mobile: true },
      { href: 'wallet', icon: 'wallet', text: 'Wallet', mobile: true },
      { href: 'report/new', icon: 'clipboard-plus', text: 'New report', mobile: true },
      { href: 'reports', icon: 'clipboard-text', text: 'My reports', mobile: true },
      { href: 'requests', icon: 'cash', text: 'Funding requests' },
      { href: 'profile', icon: 'user', text: 'Profile', mobile: true },
    ];
  }
  function renderShell() {
    const items = navItems();
    const u = App.user;
    $('#app').innerHTML = `
      <div class="shell">
        <aside class="sidebar">
          <div class="brand"><img src="assets/logo.png" class="brand-logo" alt="${esc(App.cfg.company)}"><div style="min-width:0"><div class="brand-name">${esc(App.cfg.company)}</div><div class="brand-sub">Staff portal</div></div></div>
          <nav class="nav">${items.filter((i) => !i.mobileOnly).map((i) => i.label ? `<div class="nav-label">${esc(i.label)}</div>`
            : `<a href="#/${i.href}" data-nav="${i.href}"><i class="ti ti-${i.icon}"></i>${esc(i.text)}${i.badge ? `<span class="badge hide" data-badge="${i.badge}"></span>` : ''}</a>`).join('')}</nav>
          <div class="sidebar-foot">
            <a href="#/profile" class="li" style="padding:8px;border:0;border-radius:10px;text-decoration:none;color:inherit">
              <div class="avatar sm">${esc(initials(u.name))}</div><div class="main-col"><div class="t">${esc(u.name)}</div><div class="s">${esc(u.role === 'admin' ? 'Administrator' : u.location || 'Staff')}</div></div></a>
          </div>
        </aside>
        <div class="main">
          <header class="topbar">
            <img src="assets/logo-mark.png" class="mobile-only" alt="" style="width:32px;height:32px;border-radius:50%">
            <div class="title" id="page-title"></div>
            <div class="spacer"></div>
            <span class="live" id="live-dot" title="Balances update automatically">Live</span>
            <button class="icon-btn" id="theme-btn" aria-label="Toggle dark mode"><i class="ti ti-moon"></i></button>
            <button class="icon-btn" id="bell-btn" aria-label="Notifications"><i class="ti ti-bell"></i><span class="dot hide" id="bell-dot"></span></button>
            <button class="icon-btn desktop-only" id="logout-btn" aria-label="Sign out" title="Sign out"><i class="ti ti-logout"></i></button>
          </header>
          <main class="content" id="view"></main>
        </div>
        <nav class="mobile-nav">${items.filter((i) => i.mobile || i.mobileOnly).slice(0, 5).map((i) =>
          `<a href="#/${i.href}" data-nav="${i.href}"><i class="ti ti-${i.icon}"></i>${esc(i.text.replace('Expense review', 'Review').replace('Send payment', 'Pay').replace('Work reports', 'Reports').replace('My reports', 'Reports').replace('New report', 'Report'))}${i.badge ? `<span class="badge hide" data-badge="${i.badge}"></span>` : ''}</a>`).join('')}</nav>
      </div>`;
    $('#logout-btn').addEventListener('click', logout);
    $('#bell-btn').addEventListener('click', openNotifications);
    $('#theme-btn').addEventListener('click', toggleTheme);
    syncThemeIcon();
  }
  async function logout() {
    try { const r = await api('logout', { data: {} }); App.csrf = r.csrf; } catch { /* ignore */ }
    App.user = null;
    location.hash = '';
    renderLogin();
  }
  App.logout = logout;
  function toggleTheme() {
    const dark = document.documentElement.dataset.theme === 'dark' ||
      (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'light' : 'dark';
    store.set('dt-theme', document.documentElement.dataset.theme);
    try { localStorage.setItem('dt-theme', document.documentElement.dataset.theme); } catch { /* ignore */ }
    syncThemeIcon();
    if (App.charts.length) route(true); // redraw charts in the new colours
  }
  function syncThemeIcon() {
    const dark = document.documentElement.dataset.theme === 'dark' ||
      (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
    const b = $('#theme-btn');
    if (b) b.innerHTML = `<i class="ti ti-${dark ? 'sun' : 'moon'}"></i>`;
  }
  App.toggleTheme = toggleTheme;

  async function openNotifications() {
    if ($('.popover')) { closeTop(); return; }
    const el = openLayer('popover', `<div class="card-head"><h3>Notifications</h3><button class="btn ghost sm" id="nf-read">Mark all read</button></div><div id="nf-list">${App.loading()}</div>`);
    el.previousElementSibling.style.background = 'transparent';
    try {
      const r = await api('notifications');
      $('#nf-list', el).innerHTML = r.items.length ? r.items.map((n) => `
        <div class="notif ${n.read_at ? '' : 'unread'}" data-link="${esc(n.link)}"><div class="t">${esc(n.title)}</div>
        ${n.body ? `<div class="b">${esc(n.body)}</div>` : ''}<div class="faint small">${esc(ago(n.created_at))}</div></div>`).join('')
        : App.empty('bell-off', "You're all caught up");
      $$('.notif', el).forEach((n) => n.addEventListener('click', () => { closeTop(); if (n.dataset.link) location.hash = '#/' + n.dataset.link; }));
      $('#nf-read', el).addEventListener('click', async () => {
        await api('notifications_read', { data: {} });
        $$('.notif.unread', el).forEach((n) => n.classList.remove('unread'));
        setBadges({ unread: 0 });
      });
    } catch (e) { App.fail(e); }
  }

  function setBadges(p) {
    if ('unread' in p) {
      App.unread = p.unread;
      const d = $('#bell-dot');
      if (d) { d.textContent = p.unread > 9 ? '9+' : p.unread; d.classList.toggle('hide', !p.unread); }
    }
    if ('pending_reviews' in p) App.badges.review = p.pending_reviews;
    if ('pending_requests' in p) App.badges.requests = p.pending_requests;
    $$('[data-badge]').forEach((b) => {
      const n = App.badges[b.dataset.badge] || 0;
      b.textContent = n;
      b.classList.toggle('hide', !n);
    });
  }

  /* ------------------------------------------------------------ polling */
  async function poll() {
    if (!App.user || document.hidden) return;
    try {
      const p = await api('pulse');
      $('#live-dot')?.classList.remove('off');
      setBadges(p);
      const prev = App.wallet?.balance;
      App.wallet = p.wallet;
      $$('[data-live-balance]').forEach((el) => {
        el.textContent = money(p.wallet.balance);
        el.classList.toggle('neg', p.wallet.balance < 0);
        if (prev !== undefined && prev !== p.wallet.balance) { el.classList.remove('bump'); void el.offsetWidth; el.classList.add('bump'); }
      });
      $$('[data-live]').forEach((el) => { const k = el.dataset.live; if (k in p.wallet) el.textContent = money(p.wallet[k]); });
      if (App.lastChange && p.change > App.lastChange && App.current?.live && !App.hasOverlay()) {
        App.current.refresh && App.current.refresh();
      }
      App.lastChange = p.change;
    } catch (e) {
      if (e.offline) $('#live-dot')?.classList.add('off');
    }
  }
  function startPolling() {
    stopPolling();
    App.pollTimer = setInterval(poll, Math.max(5, App.cfg.poll || 10) * 1000);
    poll();
  }
  function stopPolling() { if (App.pollTimer) clearInterval(App.pollTimer); App.pollTimer = null; }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && App.user) poll(); });
  App.poll = poll;

  /* ------------------------------------------------------------- router */
  const routes = [];
  App.route = (pattern, view, opts = {}) => routes.push({ re: new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$'), view, opts });

  async function route(soft = false) {
    if (!App.user) return;
    const path = location.hash.replace(/^#\/?/, '').split('?')[0].replace(/\/$/, '');
    let match = null;
    for (const r of routes) {
      if (r.opts.admin !== undefined && r.opts.admin !== isAdmin()) continue;
      const m = path.match(r.re);
      if (m) { match = { r, params: m.groups || {} }; break; }
    }
    if (!soft) App.closeAll();
    destroyCharts();
    const view = $('#view');
    if (!match) { view.innerHTML = App.empty('map-off', 'Page not found', '', '<a class="btn" href="#/">Go home</a>'); return; }
    const navKey = match.r.opts.nav ?? path.split('/')[0];
    $$('[data-nav]').forEach((a) => a.classList.toggle('on', a.dataset.nav === navKey));
    $('#page-title').textContent = match.r.opts.title || '';
    view.innerHTML = App.loading();
    window.scrollTo(0, 0);
    const ctx = { params: match.params, el: view, live: !!match.r.opts.live, refresh: null };
    App.current = ctx;
    try {
      await match.r.view(ctx);
    } catch (e) {
      if (App.current === ctx) view.innerHTML = App.empty('alert-triangle', 'Couldn\'t load this page', e.message, '<button class="btn" onclick="location.reload()">Reload</button>');
    }
  }
  App.go = (hash) => { if (location.hash === '#/' + hash) route(); else location.hash = '#/' + hash; };
  App.reroute = route;
  window.addEventListener('hashchange', () => route());

  App.setTitle = (t) => { const el = $('#page-title'); if (el) el.textContent = t; };

  /* --------------------------------------------------------------- boot */
  function boot(sess) {
    App.csrf = sess.csrf;
    App.cfg = sess.config;
    App.user = sess.user;
    document.title = App.cfg.app_name || 'Staff portal';
    if (!App.user) { renderLogin(); return; }
    renderShell();
    route();
    startPolling();
  }
  App.start = async () => {
    try {
      boot(await refreshSession());
    } catch (e) {
      $('#app').innerHTML = App.empty('wifi-off', 'Can\'t reach the server', 'Check your connection and reload.', '<button class="btn" onclick="location.reload()">Reload</button>');
    }
  };
})();
