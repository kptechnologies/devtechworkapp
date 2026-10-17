/* DevTech Staff Portal — general notices, phone alerts (web push) and the Google Tasks link */
(() => {
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, ago, isManagement } = App;

  const AUDIENCE = { all_staff: 'All staff', everyone: 'Everyone, management too' };

  /* ------------------------------------------------------------ banners */
  /** Unacknowledged notices as banners at the top of the home page. */
  App.renderNotices = async (el) => {
    if (!el) return;
    let items = [];
    try { items = (await api('notices_active')).items; } catch { return; }
    el.innerHTML = items.map((n) => `<div class="notice-banner ${n.urgent ? 'urgent' : ''}" data-notice="${n.id}">
      <i class="ti ti-${n.urgent ? 'alert-octagon' : 'speakerphone'}"></i>
      <div class="main-col"><div class="t">${n.urgent ? 'Urgent: ' : ''}${esc(n.title)}</div>
        <div class="b">${esc(n.body.length > 220 ? n.body.slice(0, 220) + '…' : n.body)}</div>
        <div class="s">${esc(n.sender_name)} · ${esc(ago(n.created_at))}</div></div>
      <div class="acts"><a class="btn sm ghost" href="#/notices/${n.id}">Open</a><button class="btn sm" data-ack="${n.id}"><i class="ti ti-check"></i>Got it</button></div></div>`).join('');
    el.classList.toggle('stack', !!items.length);
    $$('[data-ack]', el).forEach((b) => b.addEventListener('click', async () => {
      try {
        await App.busy(b, () => api('notice_ack', { data: { id: +b.dataset.ack } }));
        b.closest('.notice-banner').remove();
        App.poll();
      } catch (e) { App.fail(e); }
    }));
  };

  /* -------------------------------------------------------------- list */
  async function noticesView(ctx) {
    const mgmt = isManagement();
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Notices</h1><p class="muted">${mgmt ? 'Post a notice to every staff member. It stays on their home page until they press Got it, and alerts their phones.' : 'Notices from management.'}</p></div>
        ${mgmt ? '<div class="actions"><button class="btn primary" id="nt-new"><i class="ti ti-speakerphone"></i>Post notice</button></div>' : ''}</div>
      <div class="card" id="nt-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('notices_list');
      const el = $('#nt-list', ctx.el);
      if (!r.items.length) { el.innerHTML = App.empty('speakerphone', 'No notices yet', mgmt ? 'Post one and every staff member sees it on their home page.' : ''); return; }
      el.innerHTML = `<ul class="list">${r.items.map((n) => `<li class="li" data-n="${n.id}" style="${!mgmt && !n.my_ack && n.live ? 'font-weight:600' : ''}">
        <div class="li-icon tone-${n.urgent ? 'bad' : 'brand'}"><i class="ti ti-${n.urgent ? 'alert-octagon' : 'speakerphone'}"></i></div>
        <div class="main-col"><div class="t">${esc(n.title)}</div>
          <div class="s">${esc(n.ref)} · ${esc(n.sender_name)} · ${esc(ago(n.created_at))}${n.expires_at ? ' · until ' + esc(fmtDate(n.expires_at.slice(0, 10))) : ''}</div></div>
        ${mgmt ? `<span class="pill ${+n.acked === +n.recipients ? 'in' : ''}">${+n.acked}/${+n.recipients} got it</span>` : n.my_ack ? '<span class="pill in">Got it</span>' : ''}
        ${n.live ? '' : '<span class="pill">Ended</span>'}</li>`).join('')}</ul>`;
      $$('[data-n]', el).forEach((li) => li.addEventListener('click', () => App.go(`notices/${li.dataset.n}`)));
    };
    $('#nt-new', ctx.el)?.addEventListener('click', compose);
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  function compose() {
    const el = App.modal('Post a notice', `<form id="nc-form" novalidate>
      <div class="field"><label for="nc-title">Title <span class="req">*</span></label><input id="nc-title" name="title" autofocus placeholder="e.g. Staff meeting on Friday at 4pm"></div>
      <div class="field"><label for="nc-body">Notice <span class="req">*</span></label><textarea id="nc-body" name="body" rows="7"></textarea></div>
      <div class="field" data-field="audience"><div class="label">Who sees it</div><div class="opts">
        <label class="opt"><input type="radio" name="audience" value="all_staff" checked><span><i class="ti ti-users"></i>All staff</span></label>
        <label class="opt"><input type="radio" name="audience" value="everyone"><span><i class="ti ti-building"></i>Everyone, management too</span></label></div></div>
      <div class="form-row"><div class="field"><label for="nc-exp">Show until</label><input type="date" id="nc-exp" name="expires" min="${esc(App.cfg.today)}">
        <div class="help">Leave empty to keep it up until everyone has pressed Got it.</div></div>
        <div class="field"><div class="label">&nbsp;</div><label class="check-row"><input type="checkbox" name="urgent"><span><b>Urgent</b><br><span class="muted small">Shown in red at the top.</span></span></label></div></div>
      <p class="muted small">Staff get it on their home page, by email, as a phone alert if they turned alerts on, and in Google Tasks if they linked it.</p></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="nc-ok"><i class="ti ti-send"></i>Post notice</button>');
    const form = $('#nc-form', el);
    $('#nc-ok', el).addEventListener('click', async (e) => {
      const d = App.formData(form);
      try {
        const r = await App.busy(e.currentTarget, () => api('notice_post', { data: d }));
        App.closeTop();
        toast('Notice posted');
        App.go(`notices/${r.id}`);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  async function openNotice(id) {
    const el = App.drawer('Notice', App.loading());
    const render = async () => {
      let n;
      try { n = (await api('notice_get', { query: { id } })).notice; } catch (e) { App.closeTop(); App.fail(e); return; }
      App.poll();
      const body = $('.drawer-body', el) || el;
      const acked = n.recipient_list.filter((r) => r.ack_at).length;
      body.innerHTML = `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">${n.urgent ? '<span class="pill bad"><i class="ti ti-alert-octagon"></i>Urgent</span>' : '<span class="pill brand"><i class="ti ti-speakerphone"></i>Notice</span>'}
          ${n.live ? '' : '<span class="pill">Ended</span>'}</div>
        <h2 style="margin-bottom:4px">${esc(n.title)}</h2>
        <div class="muted small" style="margin-bottom:12px">${esc(n.ref)} · from <b>${esc(n.sender_name)}</b> to ${esc(AUDIENCE[n.audience] || n.audience)} · ${esc(fmtDateTime(n.created_at))}${n.expires_at ? ` · shown until ${esc(fmtDate(n.expires_at.slice(0, 10)))}` : ''}</div>
        <div class="note-box" style="white-space:pre-wrap;margin-bottom:12px">${esc(n.body)}</div>
        ${n.recipient_list.length ? `<div class="sec-title">${acked} of ${n.recipient_list.length} pressed Got it</div>
          <div class="chips" style="margin-top:6px">${n.recipient_list.map((r) => `<span class="pill ${r.ack_at ? 'in' : r.read_at ? 'info' : ''}" title="${r.ack_at ? 'Got it ' + esc(fmtDateTime(r.ack_at)) : r.read_at ? 'Opened, not acknowledged' : 'Not seen yet'}">${esc(r.name)}</span>`).join('')}</div>
          <p class="faint small" style="margin-top:6px">Green: got it · blue: opened · grey: not seen yet.</p>` : ''}`;
      $('.drawer-foot', el)?.remove();
      const foot = document.createElement('div');
      foot.className = 'drawer-foot';
      if (n.is_recipient && !n.my_ack && n.live) foot.innerHTML += '<button class="btn primary" id="nd-ack"><i class="ti ti-check"></i>Got it</button>';
      else if (n.my_ack) foot.innerHTML += `<span class="muted small">You pressed Got it ${esc(ago(n.my_ack))}.</span>`;
      if (isManagement()) foot.innerHTML += n.archived_at ? '<button class="btn" id="nd-arch" data-restore="1"><i class="ti ti-arrow-back-up"></i>Put back up</button>'
        : '<button class="btn ghost" id="nd-arch"><i class="ti ti-archive"></i>Take down</button>';
      if (foot.innerHTML) el.appendChild(foot);
      $('#nd-ack', foot)?.addEventListener('click', async (e) => {
        try { await App.busy(e.currentTarget, () => api('notice_ack', { data: { id: n.id } })); toast('Thanks'); App.current?.refresh?.(); render(); } catch (err) { App.fail(err); }
      });
      $('#nd-arch', foot)?.addEventListener('click', async (e) => {
        const restore = !!e.currentTarget.dataset.restore;
        if (!restore && await App.confirm('Take this notice down?', 'It stops showing on home pages. You can put it back up later.', { ok: 'Take down' }) === null) return;
        try {
          await api('notice_archive', { data: { id: n.id, restore } });
          toast(restore ? 'Notice is back up' : 'Notice taken down');
          App.current?.refresh?.();
          render();
        } catch (err) { App.fail(err); }
      });
    };
    await render();
  }

  App.route('notices', noticesView, { title: 'Notices', live: true });
  App.route('notices/:id', async (ctx) => { await noticesView(ctx); openNotice(+ctx.params.id); }, { title: 'Notices', nav: 'notices', live: true });

  /* -------------------------------------------------------- phone alerts */
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const installed = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const keyBytes = (b64) => {
    const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64.length % 4)) % 4));
    return Uint8Array.from(s, (c) => c.charCodeAt(0));
  };

  let swReg = null;
  const registration = async () => {
    if (!swReg) swReg = await navigator.serviceWorker.register('sw.js');
    await navigator.serviceWorker.ready;
    return swReg;
  };
  if (pushSupported) {
    // Keep the worker fresh and send this phone's subscription again in case it was renewed.
    registration().then(async (reg) => {
      const sub = await reg.pushManager.getSubscription();
      if (sub && Notification.permission === 'granted') {
        const wait = () => (App.user ? api('push_subscribe', { data: sub.toJSON() }).catch(() => {}) : setTimeout(wait, 3000));
        wait();
      }
    }).catch(() => {});
    navigator.serviceWorker.addEventListener('message', (e) => { if (typeof e.data?.go === 'string') App.go(e.data.go); });
  }

  /** 'on' | 'off' | 'blocked' | 'install' (iPhone, not added to the home screen) | 'unsupported' */
  App.pushState = async () => {
    if (ios && !installed) return 'install';
    if (!pushSupported) return 'unsupported';
    if (Notification.permission === 'denied') return 'blocked';
    try { return (await (await registration()).pushManager.getSubscription()) ? 'on' : 'off'; } catch { return 'unsupported'; }
  };

  App.enablePush = async () => {
    if (await Notification.requestPermission() !== 'granted') throw new Error('Alerts were not allowed. Allow notifications for this site in your browser settings.');
    const { key } = await api('push_key');
    const reg = await registration();
    let sub = await reg.pushManager.getSubscription();
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) });
    await api('push_subscribe', { data: sub.toJSON() });
  };

  App.disablePush = async () => {
    const sub = await (await registration()).pushManager.getSubscription();
    if (!sub) return;
    await api('push_unsubscribe', { data: { endpoint: sub.endpoint } }).catch(() => {});
    await sub.unsubscribe();
  };

  /** A one-time nudge on the home page to turn alerts on for this phone. */
  App.renderPushPrompt = async (el) => {
    if (!el) return;
    const state = await App.pushState();
    if (App.store.get('dt-push-nudge') === 'no' || !['off', 'install'].includes(state)) { el.innerHTML = ''; return; }
    el.innerHTML = `<div class="banner" style="background:var(--brand-soft);color:var(--brand-text)"><i class="ti ti-device-mobile-message"></i>
      <span>${state === 'install' ? 'To get alerts on this iPhone, tap <b>Share</b> then <b>Add to Home Screen</b>, and open the portal from there.' : 'Get notices and new jobs as alerts on this phone.'}</span>
      ${state === 'off' ? '<button class="btn sm primary" data-push="on">Turn on</button>' : ''}<button class="btn sm ghost" data-push="no" aria-label="Not now">Not now</button></div>`;
    $('[data-push="on"]', el)?.addEventListener('click', async (e) => {
      try { await App.busy(e.currentTarget, App.enablePush); toast('Phone alerts are on'); el.innerHTML = ''; } catch (err) { App.fail(err); }
    });
    $('[data-push="no"]', el).addEventListener('click', () => { App.store.set('dt-push-nudge', 'no'); el.innerHTML = ''; });
  };

  /** Profile card: phone alerts for this device and the Google Tasks link. */
  App.renderAlertsCard = async (el) => {
    if (!el) return;
    const [state, st] = await Promise.all([App.pushState(), api('alerts_status').catch(() => null)]);
    const g = st?.google || {};
    const pushText = {
      on: 'Alerts are on for this phone.',
      off: 'Get a phone alert the moment a notice, job or memo comes in.',
      blocked: 'Alerts are blocked for this site. Allow notifications in your browser settings, then reload.',
      install: 'On iPhone, tap Share → Add to Home Screen first, then open the portal from the home screen icon.',
      unsupported: 'This browser cannot show alerts. Try Chrome on Android, or Safari on iPhone 16.4 or newer.',
    }[state];
    el.innerHTML = `<div class="card card-pad alerts-card" style="margin-bottom:14px"><h3 style="margin-bottom:10px">Alerts on your phone</h3>
      <div class="li" style="padding:0 0 12px;border:0"><div class="li-icon tone-${state === 'on' ? 'in' : 'brand'}"><i class="ti ti-device-mobile-message"></i></div>
        <div class="main-col"><div class="t">Phone alerts</div><div class="s">${esc(pushText)}</div></div>
        <div class="actions">${state === 'on' ? '<button class="btn sm" data-a="test">Test</button><button class="btn sm ghost" data-a="off">Turn off</button>' : state === 'off' ? '<button class="btn sm primary" data-a="on">Turn on</button>' : ''}</div></div>
      ${g.ready ? `<div class="li" style="padding:12px 0 0;border:0;border-top:1px solid var(--border)"><div class="li-icon tone-${g.linked && !g.broken ? 'in' : 'info'}"><i class="ti ti-checklist"></i></div>
        <div class="main-col"><div class="t">Google Tasks</div><div class="s">${g.linked ? (g.broken ? 'The link stopped working. Link it again.' : `Linked to ${esc(g.email || 'your Google account')}. Notices and new jobs are added to your task list.`)
          : 'Add notices and new jobs to Google Tasks, which reminds you on your phone on the due date.'}</div></div>
        <div class="actions">${g.linked && !g.broken ? '<button class="btn sm" data-a="gtest">Test</button><button class="btn sm ghost" data-a="gunlink">Unlink</button>' : '<a class="btn sm primary" href="google.php?start=1"><i class="ti ti-brand-google"></i>Link</a>'}</div></div>` : ''}</div>`;
    const redraw = () => App.renderAlertsCard(el);
    $$('[data-a]', el).forEach((b) => b.addEventListener('click', async () => {
      try {
        const a = b.dataset.a;
        if (a === 'on') { await App.busy(b, App.enablePush); toast('Phone alerts are on'); }
        if (a === 'off') { await App.busy(b, App.disablePush); toast('Phone alerts are off for this phone'); }
        if (a === 'test') toast((await App.busy(b, () => api('push_test', { data: {} }))).message);
        if (a === 'gtest') toast((await App.busy(b, () => api('google_test', { data: {} }))).message);
        if (a === 'gunlink') {
          if (await App.confirm('Unlink Google Tasks?', 'New notices and jobs stop going to your Google Tasks. Tasks already there stay.', { ok: 'Unlink' }) === null) return;
          await api('google_unlink', { data: {} });
          toast('Google Tasks unlinked');
        }
        redraw();
      } catch (err) { App.fail(err); }
    }));
    const msg = { linked: 'Google Tasks linked', cancelled: 'Google Tasks was not linked', failed: 'Linking Google Tasks failed. Try again.', noscope: 'Tick the Google Tasks box on the Google screen to link it.', off: 'Google Tasks is not set up yet. Ask your admin.' };
    const res = new URLSearchParams(location.hash.split('?')[1] || '').get('google');
    if (res && msg[res]) {
      toast(msg[res], res !== 'linked');
      history.replaceState(null, '', '#/profile');
    }
  };
})();
