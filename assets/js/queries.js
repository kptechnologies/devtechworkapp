/* DevTech Staff Portal — staff queries: written notes on non-performance, staff replies, outcomes and fines */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, fmtDateTime, ago, isAdmin, isManager } = App;

  const QSTATUS = {
    open: ['Awaiting reply', 'out'], replied: ['Replied', 'info'], resolved: ['Resolved', 'in'], warning: ['Warning issued', 'bad'], withdrawn: ['Withdrawn', ''],
  };
  const qPill = (x) => `<span class="pill ${QSTATUS[x.status]?.[1] || ''}">${esc(QSTATUS[x.status]?.[0] || x.status)}</span>${x.overdue ? ' <span class="pill bad">Overdue</span>' : ''}`;
  const qTone = (s) => ({ open: 'tone-out', replied: 'tone-info', resolved: 'tone-in', warning: 'tone-bad' }[s] || '');
  const cats = () => App.cfg.query_categories || {};

  App.queryList = (items, { showPerson = false } = {}) => items.length
    ? `<ul class="list">${items.map((x) => `<li class="li" data-query="${x.id}"><div class="li-icon ${qTone(x.status)}"><i class="ti ti-alert-triangle"></i></div>
        <div class="main-col"><div class="t">${showPerson ? `${esc(x.person)}${x.person_type === 'contractor' ? ' <span class="faint small">(contract)</span>' : ''} · ` : ''}${esc(x.subject)}</div>
        <div class="s">${esc(x.ref)} · ${esc(x.category_label)} · ${esc(fmtDate(x.query_date, false))}${x.fine ? ` · fine ${money(x.fine, { short: true })}` : ''}</div></div>${qPill(x)}</li>`).join('')}</ul>`
    : App.empty('mood-happy', 'No queries', 'Queries on non-performance show up here.');
  App.bindQueryList = (root) => $$('[data-query]', root).forEach((li) => li.addEventListener('click', () => openQuery(+li.dataset.query)));

  /** Raise or edit a query. `preset` may hold user_id or contractor_id (with name) to skip the person picker. */
  async function queryForm(preset = {}, q = null) {
    let staff = [], contractors = [];
    const fixed = q || preset.user_id || preset.contractor_id;
    if (!fixed) {
      try {
        [staff, contractors] = await Promise.all([api('users_list').then((r) => r.items.filter((u) => +u.active && u.role !== 'admin' && u.id !== App.user.id)),
          isAdmin() ? api('contractors_list').then((r) => r.items) : []]);
      } catch (e) { App.fail(e); return; }
    }
    const v = q || { query_date: App.cfg.today, category: 'other', reply_by: App.addDays(App.cfg.today, 2) };
    const who = q ? q.person : preset.name;
    const el = App.modal(q ? `Edit ${esc(q.ref)}` : `Raise a query${who ? ' on ' + esc(who) : ''}`, `<form id="qf-form" novalidate>
      ${fixed ? '' : `<div class="field"><label for="qf-who">Who is it for? <span class="req">*</span></label><select id="qf-who" name="person">
        <option value="">Choose…</option>${staff.length ? `<optgroup label="Staff">${staff.map((u) => `<option value="u:${u.id}">${esc(u.name)}</option>`).join('')}</optgroup>` : ''}
        ${contractors.length ? `<optgroup label="Contract staff">${contractors.map((c) => `<option value="c:${c.id}">${esc(c.name)}${c.trade ? ' · ' + esc(c.trade) : ''}</option>`).join('')}</optgroup>` : ''}</select></div>`}
      <div class="field"><label for="qf-sub">Subject <span class="req">*</span></label><input id="qf-sub" name="subject" value="${esc(v.subject || '')}" placeholder="Failure to complete CCTV installation at Greenfield" autofocus></div>
      <div class="form-row"><div class="field"><label for="qf-cat">Type</label><select id="qf-cat" name="category">${App.opts(Object.entries(cats()), v.category)}</select></div>
        <div class="field"><label for="qf-date">Date</label><input type="date" id="qf-date" name="query_date" value="${esc(v.query_date)}"></div></div>
      <div class="field"><label for="qf-det">What happened? <span class="req">*</span></label><textarea id="qf-det" name="details" rows="5" placeholder="Describe the non-performance and ask them to explain in writing why disciplinary action should not be taken.">${esc(v.details || '')}</textarea></div>
      <div class="form-row"><div class="field"><label for="qf-rb">Reply by</label><input type="date" id="qf-rb" name="reply_by" value="${esc(v.reply_by || '')}"></div>
        ${isAdmin() ? `<div class="field"><label for="qf-fine">Fine (${esc(App.cfg.currency)})</label><input type="number" id="qf-fine" name="fine" min="0" step="0.01" value="${esc(+v.fine ? v.fine : '')}" placeholder="0">
          <div class="help">Optional. For staff, it is deducted from payroll when you close the query, unless you withdraw it.</div></div>` : '<div></div>'}</div>
      <div class="field"><div class="label">Evidence (photos, documents)</div><div id="qf-files"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="qf-ok"><i class="ti ti-send"></i>${q ? 'Save' : 'Issue query'}</button>`);
    const up = App.uploader($('#qf-files', el), { existing: q?.attachments?.length || 0, label: 'Add evidence' });
    $('#qf-ok', el).addEventListener('click', async (e) => {
      const form = $('#qf-form', el);
      const d = { ...App.formData(form), id: q?.id || 0 };
      if (!q) {
        if (preset.user_id) d.user_id = preset.user_id;
        if (preset.contractor_id) d.contractor_id = preset.contractor_id;
        const [t, pid] = String(d.person || '').split(':');
        if (t === 'u') d.user_id = +pid;
        if (t === 'c') d.contractor_id = +pid;
      }
      try {
        const r = await App.busy(e.currentTarget, () => api('query_save', { data: d, files: up.files() }));
        App.closeAll();
        toast(q ? 'Query updated' : `Query issued${d.user_id ? ' and sent to them' : ''}`);
        App.current?.refresh?.();
        if (!q) openQuery(r.id);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.queryForm = queryForm;

  async function openQuery(id) {
    const el = App.drawer('Query', App.loading());
    let x;
    try { x = (await api('query_get', { query: { id } })).query; } catch (e) { App.closeTop(); App.fail(e); return; }
    const admin = isManager();
    const live = ['open', 'replied'].includes(x.status);
    const own = x.user_id == App.user.id;
    const canReply = live && (own || (isAdmin() && x.person_type === 'contractor'));
    $('.drawer-head h2', el).textContent = `${x.ref} · ${x.category_label}`;
    const hist = x.history.map((h) => {
      let d = {};
      try { d = JSON.parse(h.details || '{}'); } catch { /* ignore */ }
      const what = { query_create: 'Issued', query_update: 'Edited', query_reply: 'Replied', query_resolved: 'Closed as resolved', query_warning: 'Warning issued',
        query_withdrawn: 'Withdrawn', attachment_delete: 'Removed a file' }[h.action] || h.action;
      return `<div class="qa"><div class="q">${esc(fmtDateTime(h.created_at))} · ${esc(h.name || 'System')}</div><div class="a">${esc(what)}${d.fine ? ` · fine ${money(d.fine)}` : ''}</div></div>`;
    }).join('');
    $('.drawer-body', el).innerHTML = `
      <div style="margin-bottom:12px"><h3 style="margin-bottom:6px">${esc(x.subject)}</h3>${qPill(x)}</div>
      <dl class="kv">
        <dt>${x.person_type === 'contractor' ? 'Contract staff' : 'Staff'}</dt><dd>${admin ? `<a href="#/${x.person_type === 'contractor' ? 'contractors/' + x.contractor_id : 'staff/' + x.user_id}">${esc(x.person)}</a>` : esc(x.person)}</dd>
        <dt>Date</dt><dd>${esc(fmtDate(x.query_date))}</dd>
        ${x.reply_by ? `<dt>Reply by</dt><dd>${esc(fmtDate(x.reply_by))}</dd>` : ''}
        ${x.job_id ? `<dt>Job order</dt><dd><a href="#/jobs/${x.job_id}">${esc(x.job_title || 'Job')}</a></dd>` : ''}
        ${x.fine ? `<dt>Fine</dt><dd class="bad">${money(x.fine)}${x.pay_adjustment_id ? ' · deducted in payroll' : x.person_type === 'staff' && live ? ' · deducted when closed' : ''}</dd>` : ''}
        <dt>Issued by</dt><dd>${esc(x.created_by_name || '—')} · ${esc(ago(x.created_at))}</dd>
        ${admin ? `<dt>Record</dt><dd>${+x.counts.total} quer${+x.counts.total === 1 ? 'y' : 'ies'} · ${+x.counts.warnings || 0} warning${+x.counts.warnings === 1 ? '' : 's'}</dd>` : ''}
      </dl>
      <div class="sec-title">Query</div><div class="note-box">${esc(x.details)}</div>
      ${x.reply ? `<div class="sec-title">Reply</div><div class="note-box">${esc(x.reply)}</div>
        <div class="faint small" style="margin-top:4px">${x.replied_by_name && x.person_type === 'contractor' ? `Recorded by ${esc(x.replied_by_name)} · ` : ''}${esc(ago(x.replied_at))}</div>` : ''}
      ${x.closed_at ? `<div class="sec-title">Outcome</div><div class="note-box ${x.status === 'warning' ? 'bad' : ''}"><b>${esc(QSTATUS[x.status][0])}</b> by ${esc(x.closed_by_name || 'admin')}, ${esc(fmtDate(x.closed_at))}${x.outcome_note ? '\n' + esc(x.outcome_note) : ''}</div>` : ''}
      <div class="sec-title">Files</div><div id="qd-gal">${x.attachments.length ? App.gallery(x.attachments, { removable: live }) : '<p class="faint small">No files.</p>'}</div>
      ${canReply ? `<div class="sec-title">${own ? (x.reply ? 'Update your reply' : 'Your reply') : 'Record their reply'}</div><form id="qd-reply">
        <div class="field"><textarea name="reply" rows="4" placeholder="${own ? 'Explain what happened.' : 'What they said, in their words.'}">${esc(x.reply || '')}</textarea></div>
        <div class="field" id="qd-files"></div><button class="btn primary" type="submit"><i class="ti ti-send"></i>${own ? 'Send reply' : 'Save reply'}</button></form>` : ''}
      ${hist ? `<div class="sec-title">History</div>${hist}` : ''}`;
    App.bindGallery($('#qd-gal', el));

    if ($('#qd-reply', el)) {
      const up = App.uploader($('#qd-files', el), { existing: x.attachments.length, label: 'Add file' });
      $('#qd-reply', el).addEventListener('submit', async (e) => {
        e.preventDefault();
        const reply = e.target.reply.value.trim();
        if (!reply) { toast('Write a reply first.', true); return; }
        try {
          await App.busy(e.submitter || e.target.querySelector('button'), () => api('query_reply', { data: { id: x.id, reply }, files: up.files() }));
          App.closeAll(); toast('Reply saved'); App.current?.refresh?.(); App.poll();
        } catch (err) { App.fail(err); }
      });
    }
    if (!admin || !live) return;
    const foot = document.createElement('div');
    foot.className = 'drawer-foot';
    foot.innerHTML = '<button class="btn" id="qd-edit"><i class="ti ti-edit"></i>Edit</button><button class="btn primary" id="qd-close"><i class="ti ti-gavel"></i>Close query</button>';
    el.appendChild(foot);
    $('#qd-edit', el).addEventListener('click', () => { App.closeTop(); queryForm({}, x); });
    $('#qd-close', el).addEventListener('click', () => closeQuery(x));
  }
  App.openQuery = openQuery;

  function closeQuery(x) {
    const staff = x.person_type === 'staff';
    const el = App.modal(`Close ${esc(x.ref)}`, `<form id="qc-form">
      ${x.status === 'open' ? '<div class="note-box warn" style="margin-bottom:12px">No reply yet. You can still close it if they didn\'t respond in time.</div>' : ''}
      <div class="field"><div class="label">Outcome</div><div class="opts">
        <label class="opt"><input type="radio" name="outcome" value="resolved" checked><span><i class="ti ti-circle-check"></i>Resolved: reply accepted</span></label>
        <label class="opt"><input type="radio" name="outcome" value="warning"><span><i class="ti ti-alert-octagon"></i>Issue a warning</span></label>
        <label class="opt"><input type="radio" name="outcome" value="withdrawn"><span><i class="ti ti-arrow-back-up"></i>Withdraw the query</span></label></div></div>
      <div class="field"><label for="qc-note">Note</label><textarea id="qc-note" name="note" rows="3" placeholder="Required for a warning"></textarea></div>
      <div class="form-row ${isAdmin() ? '' : 'hide'}" id="qc-fine-row"><div class="field"><label for="qc-fine">Fine (${esc(App.cfg.currency)})</label><input type="number" id="qc-fine" name="fine" min="0" step="0.01" value="${esc(x.fine || '')}" placeholder="0"></div>
        ${staff ? `<div class="field"><label for="qc-month">Deduct from pay for</label><input type="month" id="qc-month" name="month" value="${esc(App.cfg.today.slice(0, 7))}"></div>` : '<div></div>'}</div>
      <div class="help ${isAdmin() ? '' : 'hide'}" id="qc-help">${staff ? 'A fine is added to Payroll as a deduction for that month.' : 'Contract staff aren\'t on payroll, so the fine is only recorded here. Deduct it when you next pay them.'}</div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="qc-ok">Close query</button>');
    const sync = () => { $('#qc-fine-row', el).classList.toggle('hide', !isAdmin() || App.formData($('#qc-form', el)).outcome === 'withdrawn'); };
    $('#qc-form', el).addEventListener('change', sync);
    $('#qc-ok', el).addEventListener('click', async (e) => {
      const d = App.formData($('#qc-form', el));
      if (d.outcome === 'warning' && !d.note.trim()) { toast('Write the warning so it is on record.', true); return; }
      try {
        const r = await App.busy(e.currentTarget, () => api('query_close', { data: { ...d, id: x.id } }));
        App.closeAll();
        toast(r.adjustment_id ? 'Query closed and fine added to payroll' : 'Query closed');
        App.current?.refresh?.();
        App.poll();
      } catch (err) { App.fail(err); }
    });
  }

  /* --------------------------------------------------------------- pages */
  const qf = { status: 'pending', person_type: '', category: '' };
  async function queriesView(ctx) {
    const admin = isManager();
    const load = async () => {
      const r = await api('queries_list', { query: admin ? qf : {} });
      const items = r.items;
      ctx.el.innerHTML = admin ? `
        <div class="page-head"><div><h1>Queries</h1><p class="muted">Written queries on non-performance by staff and contract staff. Staff reply in the portal. You record replies for contract staff.</p></div>
          <div class="actions"><button class="btn primary" id="qs-new"><i class="ti ti-plus"></i>Raise a query</button></div></div>
        <div class="filters" id="qs-f">
          <div class="seg">${[['pending', 'Open'], ['replied', 'Replied'], ['warning', 'Warnings'], ['', 'All']].map(([k, l]) => `<button type="button" data-s="${k}" class="${k === qf.status ? 'on' : ''}">${l}</button>`).join('')}</div>
          <select name="person_type">${App.opts([['staff', 'Staff'], ['contractor', 'Contract staff']], qf.person_type, { placeholder: 'Everyone' })}</select>
          <select name="category">${App.opts(Object.entries(r.categories), qf.category, { placeholder: 'All types' })}</select></div>
        <div class="card">${App.queryList(items, { showPerson: true })}</div>`
        : `<div class="page-head"><div><h1>My queries</h1><p class="muted">Queries from management about your work. Reply to each one before the date shown.</p></div></div>
          ${items.some((x) => x.status === 'open') ? `<div class="note-box warn" style="margin-bottom:14px">You have ${items.filter((x) => x.status === 'open').length} quer${items.filter((x) => x.status === 'open').length === 1 ? 'y' : 'ies'} waiting for your reply.</div>` : ''}
          <div class="card">${App.queryList(items)}</div>`;
      App.bindQueryList(ctx.el);
      $('#qs-new', ctx.el)?.addEventListener('click', () => queryForm());
      $$('#qs-f [data-s]', ctx.el).forEach((b) => b.addEventListener('click', () => { qf.status = b.dataset.s; load().catch(App.fail); }));
      $('#qs-f', ctx.el)?.addEventListener('change', (e) => { if (e.target.name) { qf[e.target.name] = e.target.value; load().catch(App.fail); } });
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }
  App.route('queries', queriesView, { title: 'Queries', live: true });
  App.route('queries/:id', async (ctx) => { await queriesView(ctx); openQuery(+ctx.params.id); }, { title: 'Queries', nav: 'queries', live: true });
})();
