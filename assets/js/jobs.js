/* DevTech Staff Portal — job orders: list, detail with timeline, assign, complete with photos, verify */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, ago, isAdmin } = App;

  const JOB_STATUS = {
    requested: ['Requested', 'info'], open: ['Open', 'brand'], in_progress: ['In progress', 'out'], awaiting_check: ['Awaiting check', 'out'],
    returned: ['Sent back', 'bad'], done: ['Done', 'in'], cancelled: ['Cancelled', ''],
  };
  const PRIORITY = { urgent: ['Urgent', 'bad'], high: ['High', 'out'], normal: ['Normal', ''], low: ['Low', ''] };
  const jobPill = (s) => { const [l, t] = JOB_STATUS[s] || [s, '']; return `<span class="pill ${t}">${esc(l)}</span>`; };
  const prioPill = (p) => (p === 'normal' || p === 'low' ? '' : `<span class="pill ${PRIORITY[p]?.[1] || ''}"><i class="ti ti-flag"></i>${esc(PRIORITY[p]?.[0] || p)}</span>`);
  const KIND_ICON = { created: 'circle-plus', assign: 'users', status: 'arrows-exchange', complete: 'circle-check', comment: 'message', followup: 'message-forward', report: 'clipboard-text', edit: 'edit', priority: 'flag' };

  /** Finger/mouse signature pad. Returns { file: () => Promise<File|null>, clear, isEmpty }. */
  function signaturePad(canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let empty = true;
    const fit = () => {
      const r = canvas.getBoundingClientRect();
      canvas.width = Math.round(r.width * devicePixelRatio);
      canvas.height = Math.round(r.height * devicePixelRatio);
      ctx.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0);
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, r.width, r.height);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111';
      empty = true;
    };
    const pt = (e) => { const r = canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    canvas.addEventListener('pointerdown', (e) => { drawing = true; canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...pt(e)); e.preventDefault(); });
    canvas.addEventListener('pointermove', (e) => { if (!drawing) return; ctx.lineTo(...pt(e)); ctx.stroke(); empty = false; e.preventDefault(); });
    canvas.addEventListener('pointerup', () => { drawing = false; });
    setTimeout(fit, 30);
    return {
      clear: fit,
      isEmpty: () => empty,
      file: () => new Promise((resolve) => (empty ? resolve(null) : canvas.toBlob((b) => resolve(b ? new File([b], 'signature.png', { type: 'image/png' }) : null), 'image/png'))),
    };
  }
  App.jobPill = jobPill;

  let staffCache = null;
  let clientCache = null;
  const activeStaff = async () => {
    if (!staffCache) staffCache = (await api('users_list')).items.filter((u) => u.active);
    return staffCache;
  };

  const assigneeChips = (list) => (list.length ? list.map((a) => `<span class="chip-user"><span class="avatar xs">${esc(App.initials(a.name))}</span>${esc(a.name)}</span>`).join('')
    : '<span class="faint small">Not assigned</span>');

  const dueText = (j) => (j.due_date ? `<span class="${j.overdue ? 'bad' : ''}">${j.overdue ? '<i class="ti ti-alert-triangle"></i> ' : ''}Due ${esc(fmtDate(j.due_date, false))}</span>` : '');

  /* ----------------------------------------------------------------- list */
  const TABS_ADMIN = [['active', 'Active'], ['awaiting_check', 'To check'], ['requested', 'Requested'], ['done', 'Done'], ['cancelled', 'Cancelled'], ['', 'All']];
  const TABS_STAFF = [['active', 'Active'], ['awaiting_check', 'Waiting check'], ['done', 'Done'], ['', 'All']];
  const state = { status: 'active', q: '', user_id: '', overdue: '' };

  App.route('jobs', (ctx) => jobsList(ctx), { title: 'Job orders', live: true });
  App.route('jobs/:id', async (ctx) => { await jobsList(ctx); openJob(+ctx.params.id); }, { title: 'Job orders', nav: 'jobs', live: true });

  async function jobsList(ctx) {
    const admin = isAdmin();
    App.setTitle(admin ? 'Job orders' : 'My jobs');
    const tabs = admin ? TABS_ADMIN : TABS_STAFF;
    const staff = admin ? await activeStaff() : [];
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Job orders' : 'My jobs'}</h1><p class="muted">${admin ? 'Tasks collected from schools and companies.' : 'Jobs assigned to you, and the ones you logged.'}</p></div>
        <div class="actions">${admin ? '<a class="btn" id="jb-export"><i class="ti ti-download"></i>Export CSV</a>' : ''}
          <button class="btn primary" id="jb-new"><i class="ti ti-plus"></i>${admin ? 'New job' : 'Log a job'}</button></div></div>
      <div class="seg scroll" id="jb-tabs" style="margin-bottom:12px">${tabs.map(([k, l]) => `<button data-s="${k}" class="${k === state.status ? 'on' : ''}">${l}<span class="count" data-count="${k}"></span></button>`).join('')}</div>
      <div class="filters" id="jb-filters">
        <input type="search" name="q" placeholder="Search job, school, company, JO-0001…" value="${esc(state.q)}">
        ${admin ? `<select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), state.user_id, { placeholder: 'Anyone' })}</select>` : ''}
        <label class="opt"><input type="checkbox" name="overdue" ${state.overdue ? 'checked' : ''}><span>Overdue only</span></label>
      </div>
      <div class="card" id="jb-list">${App.loading()}</div>`;
    if (!tabs.some(([k]) => k === state.status)) state.status = 'active';
    const load = async () => {
      const r = await api('jobs_list', { query: state });
      const c = r.counts;
      const tabCount = { active: (c.open || 0) + (c.in_progress || 0) + (c.returned || 0) + (c.requested || 0) + (c.awaiting_check || 0) };
      $$('[data-count]', ctx.el).forEach((el) => {
        const n = el.dataset.count === 'active' ? tabCount.active : el.dataset.count ? c[el.dataset.count] || 0 : Object.values(c).reduce((a, b) => a + b, 0);
        el.textContent = n ? ` ${n}` : '';
      });
      const exp = $('#jb-export', ctx.el);
      if (exp) exp.href = 'api.php?action=jobs_export&' + new URLSearchParams(Object.entries(state).filter(([, v]) => v)).toString();
      const box = $('#jb-list', ctx.el);
      if (!r.items.length) {
        box.innerHTML = App.empty('clipboard-list', 'No jobs here', admin ? 'Create a job and assign it to staff.' : 'Jobs your admin assigns to you show up here.');
        return;
      }
      box.innerHTML = `<div class="table-wrap desktop-only"><table class="tbl"><thead><tr><th>Job</th><th>Client</th><th>Assigned to</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
        ${r.items.map((j) => `<tr class="click" data-job="${j.id}"><td><div style="font-weight:500">${esc(j.title)}</div><div class="faint small">${esc(j.ref)} ${prioPill(j.priority)}</div></td>
          <td>${esc(j.client_name)}<div class="faint small">${esc(j.location)}</div></td><td><div class="chips">${assigneeChips(j.assignees)}</div></td>
          <td class="small">${dueText(j) || '<span class="faint">—</span>'}</td><td>${jobPill(j.status)}</td>
          <td class="faint small">${j.photos ? `<i class="ti ti-photo"></i>${j.photos}` : ''}</td></tr>`).join('')}</tbody></table></div>
        <ul class="list mobile-only">${r.items.map((j) => `<li class="li" data-job="${j.id}">
          <div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
          <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.ref)} · ${esc(j.client_name)}${j.assignees.length && admin ? ' · ' + esc(j.assignees.map((a) => a.name.split(' ')[0]).join(', ')) : ''}</div>
          ${j.due_date ? `<div class="s">${dueText(j)}</div>` : ''}</div><div style="text-align:right">${jobPill(j.status)}${prioPill(j.priority) ? '<div style="margin-top:3px">' + prioPill(j.priority) + '</div>' : ''}</div></li>`).join('')}</ul>`;
      $$('[data-job]', box).forEach((row) => row.addEventListener('click', () => openJob(+row.dataset.job)));
    };
    $$('#jb-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => {
      state.status = b.dataset.s;
      $$('#jb-tabs button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      load().catch(App.fail);
    }));
    $('#jb-filters', ctx.el).addEventListener('input', App.debounce((e) => {
      if (!e.target.name) return;
      state[e.target.name] = e.target.type === 'checkbox' ? (e.target.checked ? 1 : '') : e.target.value;
      load().catch(App.fail);
    }, 300));
    $('#jb-new', ctx.el).addEventListener('click', () => editJob(null));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  /** "Signed off by …" with the client's signature, or a warning when the client didn't sign. */
  function signoffHtml(j) {
    if (!j.completed_at) return '';
    if (j.signoff_skipped) return `<div class="note-box warn" style="margin-top:12px"><i class="ti ti-signature-off"></i> <b>Client didn't sign:</b> ${esc(j.signoff_skipped)}</div>`;
    if (!j.signoff_name) return '';
    const sig = j.updates.flatMap((u) => u.files).filter((f) => f.name === 'signature.png').pop();
    return `<div class="sec-title">Client sign-off</div><div class="signoff">
      ${sig ? `<img src="file.php?id=${sig.id}" alt="Signature of ${esc(j.signoff_name)}">` : ''}
      <div><b>${esc(j.signoff_name)}</b>${j.signoff_phone ? ` · <a href="tel:${esc(j.signoff_phone)}">${esc(j.signoff_phone)}</a>` : ''}
      <div class="faint small">Signed ${esc(fmtDateTime(j.signoff_at))}</div></div></div>`;
  }

  /* ------------------------------------------------------------- detail */
  async function openJob(id) {
    const el = App.drawer('Job', App.loading());
    const render = async () => {
      let j;
      try { j = (await api('job_get', { query: { id } })).job; } catch (e) { App.closeTop(); App.fail(e); return; }
      const admin = isAdmin();
      const mine = j.is_assignee;
      const active = ['open', 'in_progress', 'returned'].includes(j.status);
      $('.drawer-head h2', el).textContent = `${j.ref} · ${j.title}`;
      $('.drawer-body', el).innerHTML = `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">${jobPill(j.status)}${prioPill(j.priority)}${j.overdue ? '<span class="pill bad"><i class="ti ti-alert-triangle"></i>Overdue</span>' : ''}</div>
        ${j.status === 'returned' && j.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>Sent back:</b> ${esc(j.review_note)}</div>` : ''}
        ${j.status === 'requested' ? `<div class="note-box warn" style="margin-bottom:12px">Logged by ${esc(j.created_by_name || 'staff')}. ${admin ? 'Assign someone to accept it.' : 'Waiting for an admin to accept and assign it.'}</div>` : ''}
        <dl class="kv"><dt>${j.client_type === 'company' ? 'Company' : j.client_type === 'school' ? 'School' : 'Client'}</dt><dd>${esc(j.client_name)}</dd>
          ${j.location ? `<dt>Location</dt><dd>${esc(j.location)}</dd>` : ''}
          ${j.contact_name || j.contact_phone ? `<dt>Contact</dt><dd>${esc(j.contact_name)}${j.contact_phone ? ` · <a href="tel:${esc(j.contact_phone)}">${esc(j.contact_phone)}</a>` : ''}</dd>` : ''}
          ${j.due_date ? `<dt>Due</dt><dd>${dueText(j)}</dd>` : ''}
          <dt>Logged</dt><dd>${esc(fmtDateTime(j.created_at))}${j.created_by_name ? ' by ' + esc(j.created_by_name) : ''}</dd>
          ${j.source_report_id ? `<dt>From report</dt><dd><a href="#/reports/${j.source_report_id}">View report</a></dd>` : ''}</dl>
        ${j.description ? `<div class="sec-title">What needs to be done</div><p style="white-space:pre-wrap">${esc(j.description)}</p>` : ''}
        ${signoffHtml(j)}
        <div class="sec-title" style="display:flex;align-items:center;justify-content:space-between">Assigned to
          ${admin && !['done', 'cancelled'].includes(j.status) ? '<button class="btn sm ghost" id="jd-assign"><i class="ti ti-user-plus"></i>Assign / reassign</button>' : ''}</div>
        <div class="chips">${assigneeChips(j.assignees)}</div>
        <div class="sec-title">Timeline</div>
        <ol class="timeline">${j.updates.map((u) => `<li class="tl-${esc(u.kind)}"><div class="tl-dot"><i class="ti ti-${KIND_ICON[u.kind] || 'point'}"></i></div>
          <div class="tl-body"><div class="small"><b>${esc(u.user_name || 'System')}</b> <span class="faint">· ${esc(ago(u.created_at))}</span></div>
          ${u.body ? `<div style="white-space:pre-wrap">${esc(u.body)}</div>` : ''}
          ${u.report_id ? `<a class="small" href="#/reports/${u.report_id}">Open report</a>` : ''}
          ${u.files.length ? `<div class="tl-files" style="margin-top:6px">${App.gallery(u.files)}</div>` : ''}</div></li>`).join('')}</ol>
        ${!['cancelled'].includes(j.status) ? `<form id="jd-comment" class="card card-pad" style="margin-top:8px;background:var(--surface-2)">
          <div class="field" style="margin-bottom:8px"><label for="jd-body">${admin ? 'Follow up' : 'Add an update'}</label>
            <textarea id="jd-body" name="body" rows="2" placeholder="${admin ? 'Ask for progress, give instructions…' : 'What happened, what\'s next, anything you need…'}"></textarea></div>
          <div id="jd-up"></div><button class="btn sm" type="submit" style="margin-top:8px"><i class="ti ti-send"></i>Post</button></form>` : ''}`;
      $$('.tl-files', el).forEach((g) => App.bindGallery(g));
      const up = $('#jd-up', el) ? App.uploader($('#jd-up', el), { label: 'Photo', capture: true }) : null;
      $('#jd-comment', el)?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = e.target.body.value.trim();
        if (!body && !up.count()) { toast('Write an update or add a photo.', true); return; }
        try { await App.busy(e.submitter, () => api('job_update', { data: { id: j.id, body }, files: up.files() })); toast('Update posted'); render(); } catch (err) { App.fail(err); }
      });
      $('#jd-assign', el)?.addEventListener('click', () => assignJob(j, render));

      let foot = $('.drawer-foot', el);
      if (!foot) { foot = document.createElement('div'); foot.className = 'drawer-foot'; el.appendChild(foot); }
      const btns = [];
      if (admin) {
        if (j.status !== 'done' && j.status !== 'cancelled') btns.push('<button class="btn ghost" data-a="cancel"><i class="ti ti-ban"></i>Cancel job</button>');
        if (['done', 'cancelled'].includes(j.status)) btns.push('<button class="btn" data-a="reopen"><i class="ti ti-arrow-back-up"></i>Reopen</button>');
        btns.push('<button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>');
        if (j.status === 'requested') btns.push('<button class="btn primary" data-a="accept"><i class="ti ti-user-check"></i>Accept & assign</button>');
        if (j.status === 'awaiting_check' || j.status === 'done') btns.push('<button class="btn danger" data-a="return"><i class="ti ti-arrow-back"></i>Send back</button>');
        if (j.status === 'awaiting_check') btns.push('<button class="btn success" data-a="approve"><i class="ti ti-check"></i>Approve</button>');
      }
      if (!admin && (mine || +j.created_by === App.user.id) && j.priority !== 'urgent' && !['done', 'cancelled', 'awaiting_check'].includes(j.status)) {
        btns.push('<button class="btn ghost" data-a="escalate"><i class="ti ti-flag"></i>Raise priority</button>');
      }
      if (mine && ['open', 'returned'].includes(j.status)) btns.push('<button class="btn" data-a="start"><i class="ti ti-player-play"></i>Start</button>');
      if (mine && active) btns.push('<button class="btn primary" data-a="complete"><i class="ti ti-camera-check"></i>Mark complete</button>');
      if (!admin && !mine && j.status === 'requested') btns.push('<button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>');
      foot.innerHTML = btns.join('');
      foot.classList.toggle('hide', !btns.length);
      $$('[data-a]', foot).forEach((b) => b.addEventListener('click', () => act(b, b.dataset.a, j)));
    };
    const act = async (btn, a, j) => {
      if (a === 'edit') { editJob(j, null, render); return; }
      if (a === 'accept') { assignJob(j, render); return; }
      if (a === 'complete') { completeJob(j, render); return; }
      if (a === 'escalate') { escalateJob(j, render); return; }
      let note = '';
      if (a === 'return') {
        note = await App.confirm('Send back to staff?', 'They\'ll be notified and the job goes back to their active list.', { ok: 'Send back', danger: true,
          input: { label: 'What still needs to be done?', required: true } });
        if (!note) return;
      } else if (a === 'cancel') {
        note = await App.confirm('Cancel this job?', 'Assigned staff will be notified.', { ok: 'Cancel job', danger: true, input: { label: 'Reason (optional)' } });
        if (note === null) return;
      } else if (a === 'approve') {
        note = await App.confirm('Approve and close?', 'Make sure the photos show the work was done.', { ok: 'Approve', input: { label: 'Note (optional)' } });
        if (note === null) return;
      }
      try {
        await App.busy(btn, () => api('job_status', { data: { id: j.id, action: a, note: note === true ? '' : note } }));
        toast({ start: 'Job started', approve: 'Job approved', return: 'Sent back', cancel: 'Job cancelled', reopen: 'Job reopened' }[a]);
        App.current?.refresh?.();
        render();
      } catch (err) { App.fail(err); }
    };
    await render();
  }
  App.openJob = openJob;

  /* --------------------------------------------------------- complete */
  function completeJob(j, done) {
    const el = App.modal(`Complete ${esc(j.ref)}`, `
      <form id="jc-form" novalidate>
        <p class="muted" style="margin-bottom:12px">Take photos of the finished work and get the client to sign. An admin will check before the job is closed.</p>
        <div class="field" data-field="files"><div class="label">Photos of the finished work <span class="req">*</span></div><div id="jc-up"></div></div>
        <div class="field"><label for="jc-note">What did you do? <span class="req">*</span></label><textarea id="jc-note" name="note" rows="3" placeholder="e.g. Installed and calibrated the interactive board in JSS2 classroom, tested with the teacher."></textarea></div>
        <div class="sec-title">Client sign-off</div>
        <div id="jc-sign">
          <div class="form-row"><div class="field"><label for="jc-sn">Client contact name <span class="req">*</span></label><input id="jc-sn" name="signoff_name" value="${esc(j.contact_name || '')}" placeholder="Who checked the work"></div>
            <div class="field"><label for="jc-sp">Their phone</label><input type="tel" id="jc-sp" name="signoff_phone" value="${esc(j.contact_phone || '')}"></div></div>
          <div class="field" data-field="signature"><div class="label" style="display:flex;justify-content:space-between;align-items:center"><span>Signature <span class="req">*</span></span>
            <button type="button" class="btn sm ghost" id="jc-clear"><i class="ti ti-eraser"></i>Clear</button></div>
            <canvas class="sigpad" id="jc-pad"></canvas><div class="help">Hand the phone to the client to sign with their finger.</div></div>
        </div>
        <label class="check-row"><input type="checkbox" name="signoff_unavailable" id="jc-na"><span>The client wasn't available to sign</span></label>
        <div class="field hide" id="jc-why"><label for="jc-sk">Why not? <span class="req">*</span></label><input id="jc-sk" name="signoff_skipped" placeholder="e.g. Director travelling; teacher saw the work"></div>
      </form>`, '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="jc-ok"><i class="ti ti-send"></i>Send for checking</button>', { wide: true });
    const up = App.uploader($('#jc-up', el), { label: 'Take photo', accept: 'image/*', capture: true });
    const pad = signaturePad($('#jc-pad', el));
    $('#jc-clear', el).addEventListener('click', () => pad.clear());
    $('#jc-na', el).addEventListener('change', (e) => {
      $('#jc-sign', el).classList.toggle('hide', e.target.checked);
      $('#jc-why', el).classList.toggle('hide', !e.target.checked);
    });
    $('#jc-ok', el).addEventListener('click', async (e) => {
      const form = $('#jc-form', el);
      const d = App.formData(form);
      const note = (d.note || '').trim();
      const errs = {};
      if (!up.count()) errs.files = 'Add at least one photo.';
      if (!note) errs.note = 'Say what was done.';
      if (d.signoff_unavailable) { if (!d.signoff_skipped.trim()) errs.signoff_skipped = 'Say why the client couldn\'t sign.'; }
      else {
        if (!d.signoff_name.trim()) errs.signoff_name = 'Enter the client contact\'s name.';
        if (pad.isEmpty()) errs.signature = 'Ask the client to sign.';
      }
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      const sig = d.signoff_unavailable ? null : await pad.file();
      const files = up.files().concat(sig ? [{ field: 'signature', file: sig, name: 'signature.png' }] : []);
      try {
        await App.busy(e.currentTarget, () => api('job_status', { data: { id: j.id, action: 'complete', note, signoff_name: d.signoff_name, signoff_phone: d.signoff_phone,
          signoff_unavailable: d.signoff_unavailable ? 1 : '', signoff_skipped: d.signoff_skipped }, files }));
        App.closeTop();
        toast('Sent for checking');
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /* --------------------------------------------------------- escalate */
  function escalateJob(j, done) {
    const el = App.modal(`Raise priority of ${esc(j.ref)}`, `<form id="jx-form" novalidate>
      <p class="muted" style="margin-bottom:12px">The office is emailed straight away so they can follow up with the client.</p>
      <div class="field" data-field="priority"><div class="label">New priority</div><div class="opts">
        ${j.priority !== 'high' ? '<label class="opt"><input type="radio" name="priority" value="high"><span><i class="ti ti-flag"></i>High</span></label>' : ''}
        <label class="opt"><input type="radio" name="priority" value="urgent"><span><i class="ti ti-urgent"></i>Urgent</span></label></div></div>
      <div class="field"><label for="jx-r">Why? <span class="req">*</span></label><textarea id="jx-r" name="reason" rows="3" placeholder="e.g. Exams start Monday and the lab has no power"></textarea></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn danger solid" id="jx-ok"><i class="ti ti-flag"></i>Raise priority</button>');
    $('#jx-ok', el).addEventListener('click', async (e) => {
      const form = $('#jx-form', el);
      const d = App.formData(form);
      const errs = {};
      if (!d.priority) errs.priority = 'Choose High or Urgent.';
      if (!d.reason.trim()) errs.reason = 'Say why.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(e.currentTarget, () => api('job_escalate', { data: { id: j.id, ...d } }));
        App.closeTop();
        toast('Priority raised. The office has been told.');
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.fail(err); }
    });
  }

  /* ----------------------------------------------------------- assign */
  async function assignJob(j, done) {
    const staff = await activeStaff();
    const current = new Set(j.assignees.map((a) => +a.id));
    const el = App.modal(`Assign ${esc(j.ref)}`, `
      <p class="muted" style="margin-bottom:10px">Tick everyone who should work on this. Untick someone to take them off it.</p>
      <input type="search" id="ja-q" placeholder="Search staff…" style="margin-bottom:10px">
      <div class="opts col" id="ja-list">${staff.map((s) => `<label class="opt" data-name="${esc(s.name.toLowerCase())}"><input type="checkbox" value="${s.id}" ${current.has(s.id) ? 'checked' : ''}>
        <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}${s.location ? ` <span class="faint small">· ${esc(s.location)}</span>` : ''}${s.role === 'admin' ? ' <span class="faint small">(admin)</span>' : ''}</span></label>`).join('')}</div>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ja-ok">Save</button>');
    $('#ja-q', el).addEventListener('input', (e) => {
      const q = e.target.value.trim().toLowerCase();
      $$('#ja-list .opt', el).forEach((o) => o.classList.toggle('hide', q && !o.dataset.name.includes(q)));
    });
    $('#ja-ok', el).addEventListener('click', async (e) => {
      const ids = $$('#ja-list input:checked', el).map((x) => +x.value);
      if (!ids.length) { toast('Choose at least one staff member.', true); return; }
      try {
        await App.busy(e.currentTarget, () => api('job_assign', { data: { id: j.id, user_ids: ids } }));
        App.closeTop();
        toast('Assignment saved');
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.fail(err); }
    });
  }

  /* ------------------------------------------------------- create / edit */
  async function editJob(j, prefill = null, done = null) {
    const admin = isAdmin();
    const isNew = !j;
    const v = { title: '', client_type: 'school', client_name: '', location: '', contact_name: '', contact_phone: '', description: '', priority: 'normal', due_date: '', ...(j || {}), ...(prefill || {}) };
    const staff = admin && isNew ? await activeStaff() : [];
    const preset = new Set((v.assignees || []).map(Number));
    const el = App.modal(isNew ? (admin ? 'New job order' : 'Log a job') : `Edit ${esc(j.ref)}`, `
      <form id="je-form" novalidate>
        ${!admin && isNew ? '<p class="muted" style="margin-bottom:12px">Picked up a task at a school or company? Log it here. An admin will accept and assign it.</p>' : ''}
        <div class="field"><label for="je-t">What needs to be done? <span class="req">*</span></label><input id="je-t" name="title" value="${esc(v.title)}" placeholder="e.g. Install 2 projectors in the ICT lab" autofocus></div>
        <div class="field"><div class="label">Client type</div><div class="opts">${[['school', 'School'], ['company', 'Company'], ['other', 'Other']].map(([k, l]) =>
          `<label class="opt"><input type="radio" name="client_type" value="${k}" ${v.client_type === k ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
        <div class="form-row"><div class="field"><label for="je-c">School / company name <span class="req">*</span></label><input id="je-c" name="client_name" list="je-locs" value="${esc(v.client_name)}">
          <datalist id="je-locs">${[...new Set((App.cfg.clients || []).concat(App.cfg.locations))].map((l) => `<option value="${esc(l)}">`).join('')}</datalist>
          <div class="help">Pick from the clients list, or type a new name to add it.</div></div>
          <div class="field"><label for="je-l">Location / address</label><input id="je-l" name="location" value="${esc(v.location)}" placeholder="Area, street or room"></div></div>
        <div class="form-row"><div class="field"><label for="je-cn">Contact person</label><input id="je-cn" name="contact_name" value="${esc(v.contact_name)}"></div>
          <div class="field"><label for="je-cp">Contact phone</label><input type="tel" id="je-cp" name="contact_phone" value="${esc(v.contact_phone)}"></div></div>
        <div class="field"><label for="je-d">Details</label><textarea id="je-d" name="description" rows="4" placeholder="What exactly is needed, quantities, faults reported, access notes…">${esc(v.description)}</textarea></div>
        <div class="form-row"><div class="field"><label for="je-p">Priority</label><select id="je-p" name="priority">${App.opts(Object.entries(PRIORITY).map(([k, [l]]) => [k, l]), v.priority)}</select></div>
          <div class="field"><label for="je-due">Due date</label><input type="date" id="je-due" name="due_date" value="${esc(v.due_date || '')}"></div></div>
        ${staff.length ? `<div class="field"><div class="label">Assign to</div><div class="opts">${staff.map((s) => `<label class="opt"><input type="checkbox" data-multi="1" name="assignees" value="${s.id}" ${preset.has(s.id) ? 'checked' : ''}>
          <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}</span></label>`).join('')}</div><div class="help">You can add more people or reassign later.</div></div>` : ''}
        ${isNew ? '<div class="field"><div class="label">Photos (optional)</div><div id="je-up"></div></div>' : ''}
      </form>`, `<button class="btn ghost" data-close>Cancel</button>${!isNew && admin ? '<button class="btn danger" id="je-del"><i class="ti ti-trash"></i>Delete</button>' : ''}
        <button class="btn primary" id="je-save">${isNew ? (admin ? 'Create job' : 'Send to admin') : 'Save'}</button>`, { wide: true });
    const up = isNew ? App.uploader($('#je-up', el), { label: 'Add photo' }) : null;
    if (admin) {
      // Fill in the client's address and contact when an existing client is picked.
      $('#je-c', el).addEventListener('change', async (e) => {
        const form = $('#je-form', el);
        try {
          clientCache = clientCache || (await api('clients_list')).items;
          const c = clientCache.find((x) => x.name.toLowerCase() === e.target.value.trim().toLowerCase());
          if (!c) return;
          const fill = (n, v) => { if (v && !form[n].value) form[n].value = v; };
          fill('location', [c.address, c.area].filter(Boolean).join(', '));
          fill('contact_name', c.contact_name);
          fill('contact_phone', c.contact_phone);
          const t = form.querySelector(`input[name=client_type][value="${c.type}"]`);
          if (t) t.checked = true;
        } catch { /* optional */ }
      });
    }
    $('#je-save', el).addEventListener('click', async (e) => {
      const form = $('#je-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = j.id;
      if (prefill?.source_report_id) d.source_report_id = prefill.source_report_id;
      if (staff.length) d.assignees = (d.assignees || []).map(Number);
      try {
        const r = await App.busy(e.currentTarget, () => api('job_save', { data: d, files: up ? up.files() : [] }));
        if (App.cfg.clients && !App.cfg.clients.includes(d.client_name.trim())) App.cfg.clients.push(d.client_name.trim());
        clientCache = null;
        App.closeTop();
        toast(isNew ? (admin ? 'Job created' : 'Job sent to admin') : 'Job saved');
        if (done) done();
        else if (isNew) App.go(`jobs/${r.id}`);
        App.current?.refresh?.();
      } catch (err) { App.showErrors($('#je-form', el), err.fields); App.fail(err); }
    });
    $('#je-del', el)?.addEventListener('click', async () => {
      if (!(await App.confirm('Delete this job?', 'The job, its timeline and photos will be permanently removed.', { ok: 'Delete job', danger: true }))) return;
      try { await api('job_delete', { data: { id: j.id } }); App.closeAll(); toast('Job deleted'); App.current?.refresh?.(); } catch (err) { App.fail(err); }
    });
  }
  App.editJob = editJob;

  /** Compact "My jobs" card for the staff home page. */
  App.renderMyJobs = async (box) => {
    if (!box) return;
    try {
      const r = await api('jobs_list', { query: { mine: 1, status: 'active' } });
      box.innerHTML = `<div class="card-head"><h3>My jobs</h3><a href="#/jobs" class="small">All jobs</a></div>
        ${r.items.length ? `<ul class="list">${r.items.slice(0, 6).map((j) => `<li class="li" data-job="${j.id}"><div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
          <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.client_name)}${j.due_date ? ' · ' : ''}${dueText(j)}</div></div>${jobPill(j.status)}</li>`).join('')}</ul>`
          : '<p class="faint small card-body">No active jobs right now.</p>'}`;
      $$('[data-job]', box).forEach((li) => li.addEventListener('click', () => App.go(`jobs/${li.dataset.job}`)));
    } catch { box.innerHTML = ''; }
  };
})();
