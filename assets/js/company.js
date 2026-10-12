/* DevTech Staff Portal — company wallet: top-ups, contract expenses, client income, projects and contract staff */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, fmtDateTime, ago, catIcon } = App;

  const KINDS = {
    topup: ['Top-up', 'in', 'circle-plus', 'tone-in'],
    expense: ['Expense', 'out', 'receipt', 'tone-out'],
    income: ['Client payment', 'in', 'cash', 'tone-brand'],
    adjust: ['Adjustment', 'info', 'adjustments', 'tone-info'],
  };
  const PSTATUS = { active: ['Active', 'brand'], completed: ['Completed', 'in'], cancelled: ['Cancelled', ''] };
  const kindPill = (k) => `<span class="pill ${KINDS[k][1]}">${KINDS[k][0]}</span>`;
  const projectPill = (s) => `<span class="pill ${PSTATUS[s]?.[1] || ''}">${esc(PSTATUS[s]?.[0] || s)}</span>`;
  const signed = (t) => (t.kind === 'expense' ? -t.amount : t.kind === 'adjust' ? +t.effect : +t.amount);
  const kpi = (label, value, sub, icon, tone, extra = '') => `<div class="card kpi ${extra}"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div>
    <div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;

  /* ------------------------------------------------------- transactions */
  function ctxnTable(items, { showProject = true } = {}) {
    if (!items.length) return App.empty('receipt-off', 'No entries yet');
    return `<div class="table-wrap desktop-only"><table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Category</th>${showProject ? '<th>Project</th>' : ''}<th>Details</th><th class="r">Amount</th><th></th></tr></thead><tbody>
      ${items.map((t) => `<tr class="click" data-ctxn="${t.id}" style="${t.voided_at ? 'opacity:.5' : ''}"><td class="num">${esc(fmtDate(t.txn_date))}</td><td>${kindPill(t.kind)}</td>
        <td>${esc(t.category)}</td>${showProject ? `<td>${esc(t.project_name || '')}</td>` : ''}
        <td class="ellipsis">${esc([t.description, t.contractor_name || t.payee].filter(Boolean).join(' · '))}</td>
        <td class="r num ${signed(t) >= 0 ? 'in' : 'out'}" style="font-weight:600;${t.voided_at ? 'text-decoration:line-through' : ''}">${money(signed(t), { sign: true })}</td>
        <td class="faint">${t.voided_at ? '<span class="pill">Voided</span>' : t.files ? `<i class="ti ti-paperclip"></i>${t.files}` : ''}</td></tr>`).join('')}</tbody></table></div>
      <ul class="list mobile-only">${items.map((t) => `<li class="li" data-ctxn="${t.id}" style="${t.voided_at ? 'opacity:.5' : ''}">
        <div class="li-icon ${KINDS[t.kind][3]}"><i class="ti ti-${t.kind === 'expense' ? catIcon(t.category) : KINDS[t.kind][2]}"></i></div>
        <div class="main-col"><div class="t">${esc(t.kind === 'expense' ? t.category : KINDS[t.kind][0])}${t.project_name ? ` · ${esc(t.project_name)}` : ''}</div>
        <div class="s">${esc(fmtDate(t.txn_date, false))}${t.description ? ' · ' + esc(t.description) : ''}</div></div>
        <span class="amt ${signed(t) >= 0 ? 'in' : 'out'}" style="${t.voided_at ? 'text-decoration:line-through' : ''}">${money(signed(t), { sign: true })}</span></li>`).join('')}</ul>`;
  }
  const bindCtxns = (root) => $$('[data-ctxn]', root).forEach((r) => r.addEventListener('click', () => openCtxn(+r.dataset.ctxn)));

  /** Lists for the pickers: active projects, contract staff, and jobs that aren't closed. */
  async function pickers() {
    const [p, c, j] = await Promise.all([api('projects_list', { query: { status: 'active' } }), api('contractors_list'), api('jobs_list', { query: { status: 'active' } })]);
    return { projects: p.items, contractors: c.items, jobs: j.items };
  }

  /** Add or edit a company transaction. `preset` pre-fills project/contractor when opened from those pages. */
  async function ctxnForm(kind, t = null, preset = {}) {
    let lists;
    try { lists = await pickers(); } catch (e) { App.fail(e); return; }
    const v = t || { txn_date: App.cfg.today, ...preset };
    if (t?.project_id && !lists.projects.some((p) => p.id === +t.project_id)) lists.projects.push({ id: +t.project_id, name: t.project_name });
    const exp = kind === 'expense';
    const title = t ? `Edit ${KINDS[kind][0].toLowerCase()}` : { topup: 'Top up company wallet', expense: 'Add company expense', income: 'Record client payment', adjust: 'Adjust company balance' }[kind];
    const el = App.modal(esc(title), `<form id="cx-form" novalidate>
      <div class="form-row"><div class="field"><label for="cx-amt">Amount (${esc(App.cfg.currency)}) <span class="req">*</span></label>
        <input type="number" id="cx-amt" name="amount" class="amount-input" step="0.01" ${kind === 'adjust' ? '' : 'min="0"'} value="${esc(t ? (kind === 'adjust' ? t.effect : t.amount) : '')}" placeholder="${kind === 'adjust' ? '-5000 or 5000' : '0.00'}" autofocus></div>
        <div class="field"><label for="cx-date">Date</label><input type="date" id="cx-date" name="txn_date" value="${esc(v.txn_date)}"></div></div>
      ${exp ? `<div class="field"><label for="cx-cat">Category <span class="req">*</span></label><select id="cx-cat" name="category">${App.opts(App.cfg.company_categories, v.category, { placeholder: 'Choose…' })}</select></div>` : ''}
      ${kind !== 'topup' && kind !== 'adjust' ? `<div class="field"><label for="cx-proj">Project</label><select id="cx-proj" name="project_id">${App.opts(lists.projects.map((p) => [p.id, p.name]), v.project_id, { placeholder: 'Not for a project' })}</select>
        <div class="help">Tag it to a contract to see what each job cost. <a href="#/projects" data-close>Add projects</a>.</div></div>` : ''}
      ${exp ? `<div class="form-row"><div class="field"><label for="cx-con">Paid to contract staff</label><select id="cx-con" name="contractor_id">${App.opts(lists.contractors.map((c) => [c.id, c.name + (c.trade ? ' · ' + c.trade : '')]), v.contractor_id, { placeholder: 'No one / a vendor' })}</select></div>
        <div class="field"><label for="cx-payee">Payee / vendor</label><input id="cx-payee" name="payee" value="${esc(v.payee || '')}" placeholder="e.g. Alaba market supplier"></div></div>` : ''}
      ${kind === 'income' ? `<div class="field"><label for="cx-payee">Paid by</label><input id="cx-payee" name="payee" value="${esc(v.payee || '')}" placeholder="Client name"></div>` : ''}
      ${kind !== 'adjust' ? `<div class="form-row"><div class="field"><label for="cx-meth">Method</label><select id="cx-meth" name="method">${App.opts(App.cfg.payment_methods, v.method, { placeholder: '—' })}</select></div>
        ${exp ? `<div class="field"><label for="cx-job">Job order</label><select id="cx-job" name="job_id">${App.opts(lists.jobs.map((j) => [j.id, `${j.ref} · ${j.title}`]), v.job_id, { placeholder: 'None' })}</select></div>` : '<div></div>'}</div>` : ''}
      <div class="field"><label for="cx-desc">${exp ? 'What was it for?' : 'Note'}${kind === 'topup' ? '' : ' <span class="req">*</span>'}</label><textarea id="cx-desc" name="description" rows="2" placeholder="${exp ? '2 x 200Ah batteries for Greenfield inverter' : ''}">${esc(v.description || '')}</textarea></div>
      <div class="field"><div class="label">Receipts / invoices</div><div id="cx-files"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="cx-ok"><i class="ti ti-check"></i>Save</button>`);
    const up = App.uploader($('#cx-files', el), { existing: t?.attachments?.length || 0, label: 'Add receipt' });
    $('#cx-ok', el).addEventListener('click', async (e) => {
      const form = $('#cx-form', el);
      const d = { ...App.formData(form), kind, id: t?.id || 0 };
      try {
        await App.busy(e.currentTarget, () => api('company_txn_save', { data: d, files: up.files() }));
        App.closeAll();
        toast('Saved');
        App.current?.refresh?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.companyTxnForm = ctxnForm;

  async function openCtxn(id) {
    const el = App.drawer('Entry', App.loading());
    let t;
    try { t = (await api('company_txn_get', { query: { id } })).txn; } catch (e) { App.closeTop(); App.fail(e); return; }
    const s = signed(t);
    $('.drawer-head h2', el).textContent = t.kind === 'expense' ? (t.category || 'Expense') : KINDS[t.kind][0];
    const hist = t.history.map((h) => {
      let d = {};
      try { d = JSON.parse(h.details || '{}'); } catch { /* ignore */ }
      const what = { ctxn_create: 'Entered', ctxn_update: 'Edited', ctxn_void: 'Voided', attachment_delete: 'Removed a file' }[h.action] || h.action;
      return `<div class="qa"><div class="q">${esc(fmtDateTime(h.created_at))} · ${esc(h.name || 'System')}</div><div class="a">${esc(what)}${d.note ? ': ' + esc(d.note) : ''}</div></div>`;
    }).join('');
    $('.drawer-body', el).innerHTML = `
      <div style="text-align:center;padding:6px 0 16px">
        <div class="li-icon ${KINDS[t.kind][3]}" style="margin:0 auto 8px;width:48px;height:48px"><i class="ti ti-${t.kind === 'expense' ? catIcon(t.category) : KINDS[t.kind][2]}" style="font-size:24px"></i></div>
        <div style="font-size:30px;font-weight:600;${t.voided_at ? 'text-decoration:line-through' : ''}" class="${s >= 0 ? 'in' : 'out'} num">${money(s, { sign: true })}</div>
        <div style="margin-top:6px">${kindPill(t.kind)}</div></div>
      ${t.voided_at ? `<div class="note-box bad" style="margin-bottom:12px"><b>Voided by ${esc(t.voided_by_name || 'admin')} ${esc(ago(t.voided_at))}:</b> ${esc(t.void_reason)}\nIt no longer counts towards the balance.</div>` : ''}
      <dl class="kv">
        <dt>Date</dt><dd>${esc(fmtDate(t.txn_date))}</dd>
        ${t.description ? `<dt>${t.kind === 'expense' ? 'What for' : 'Note'}</dt><dd>${esc(t.description)}</dd>` : ''}
        ${t.project_name ? `<dt>Project</dt><dd><a href="#/projects/${t.project_id}">${esc(t.project_name)}</a></dd>` : ''}
        ${t.job_id ? `<dt>Job order</dt><dd><a href="#/jobs/${t.job_id}">${esc(t.job_title || 'Job')}</a></dd>` : ''}
        ${t.contractor_name ? `<dt>Contract staff</dt><dd><a href="#/contractors/${t.contractor_id}">${esc(t.contractor_name)}</a></dd>` : ''}
        ${t.payee ? `<dt>${t.kind === 'income' ? 'Paid by' : 'Payee'}</dt><dd>${esc(t.payee)}</dd>` : ''}
        ${t.method ? `<dt>Method</dt><dd>${esc(t.method)}</dd>` : ''}
        <dt>Entered by</dt><dd>${esc(t.created_by_name || '—')} · ${esc(ago(t.created_at))}</dd>
      </dl>
      <div class="sec-title">Receipts</div>
      <div id="cx-gal">${t.attachments.length ? App.gallery(t.attachments, { removable: !t.voided_at }) : '<p class="faint small">No files.</p>'}</div>
      ${hist ? `<div class="sec-title">History</div>${hist}` : ''}`;
    App.bindGallery($('#cx-gal', el));
    if (t.voided_at) return;
    const foot = document.createElement('div');
    foot.className = 'drawer-foot';
    foot.innerHTML = '<button class="btn ghost danger" id="cx-void"><i class="ti ti-ban"></i>Void</button><button class="btn" id="cx-edit"><i class="ti ti-edit"></i>Edit</button>';
    el.appendChild(foot);
    $('#cx-edit', el).addEventListener('click', () => { App.closeTop(); ctxnForm(t.kind, t); });
    $('#cx-void', el).addEventListener('click', async () => {
      const reason = await App.confirm('Void this entry?', `${money(Math.abs(s))} will stop counting towards the balance. The entry stays in the history.`,
        { ok: 'Void', danger: true, input: { label: 'Reason', required: true, placeholder: 'Entered twice' } });
      if (!reason) return;
      try { await api('company_txn_void', { data: { id: t.id, reason } }); App.closeAll(); toast('Entry voided'); App.current?.refresh?.(); } catch (err) { App.fail(err); }
    });
  }
  App.openCompanyTxn = openCtxn;

  /* ------------------------------------------------------ company wallet */
  const cf = { kind: '', category: '', project_id: '', from: '', to: '', q: '' };
  App.route('company', async (ctx) => {
    let projects = (await api('projects_list')).items;
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Company wallet</h1><p class="muted">Company money for contract jobs, procurement and day-to-day running costs.</p></div>
        <div class="actions"><button class="btn" data-new="topup"><i class="ti ti-circle-plus"></i>Top up</button><button class="btn" data-new="income"><i class="ti ti-cash"></i>Client payment</button>
          <button class="btn primary" data-new="expense"><i class="ti ti-plus"></i>Add expense</button></div></div>
      <div class="grid g4" id="cw-kpi" style="margin-bottom:14px"></div>
      <div class="filters" id="cw-f">
        <input type="search" name="q" placeholder="Search description, payee, project…" value="${esc(cf.q)}">
        <select name="kind">${App.opts(Object.entries(KINDS).map(([k, v]) => [k, v[0]]), cf.kind, { placeholder: 'All types' })}</select>
        <select name="category">${App.opts(App.cfg.company_categories, cf.category, { placeholder: 'All categories' })}</select>
        <select name="project_id">${App.opts(projects.map((p) => [p.id, p.name]), cf.project_id, { placeholder: 'All projects' })}</select>
        <input type="date" name="from" value="${esc(cf.from)}" aria-label="From"><input type="date" name="to" value="${esc(cf.to)}" aria-label="To">
        <a class="btn" id="cw-export"><i class="ti ti-download"></i>Export</a><button class="btn ghost" id="cw-adjust" type="button"><i class="ti ti-adjustments"></i>Adjust</button>
      </div>
      <div class="grid g-main"><div class="card" id="cw-list">${App.loading()}</div><div class="stack" id="cw-side"></div></div>`;
    const load = async () => {
      let r;
      [r, projects] = await Promise.all([api('company_wallet', { query: cf }), api('projects_list').then((x) => x.items)]);
      const st = r.stats;
      $('#cw-export', ctx.el).href = 'api.php?action=company_export&' + new URLSearchParams(Object.entries(cf).filter(([, v]) => v)).toString();
      $('#cw-kpi', ctx.el).innerHTML = kpi('Balance', `<span class="${st.balance < 0 ? 'bad' : ''}">${money(st.balance)}</span>`, 'Top-ups + client payments − expenses', 'building-bank', 'tone-brand', 'accent')
        + kpi('Spent today', money(st.spent_today), `${money(st.spent_week)} this week`, 'receipt', 'tone-out')
        + kpi('Spent this month', money(st.spent_month), `${money(st.topup_month)} topped up`, 'calendar-dollar', 'tone-out')
        + kpi('Client payments', money(st.income_month), 'Received this month', 'cash', 'tone-in');
      $('#cw-list', ctx.el).innerHTML = `<div class="card-head"><h3>Entries</h3><span class="faint small">In ${money(r.totals.topup + r.totals.income)} · out ${money(r.totals.spent)}</span></div>${ctxnTable(r.items)}
        ${r.items.length >= 2000 ? '<p class="faint small card-body">Showing the latest 2,000. Narrow the filters or export to see more.</p>' : ''}`;
      const maxCat = Math.max(1, ...r.month_categories.map((c) => +c.total));
      const active = projects.filter((p) => p.status === 'active').slice(0, 6);
      $('#cw-side', ctx.el).innerHTML = `
        <div class="card"><div class="card-head"><h3>This month by category</h3></div><div class="card-body">${r.month_categories.length ? `<div class="bars">${r.month_categories.map((c) => `
          <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.category || 'Uncategorised')}</span>
          <div class="bar-track"><div class="bar-fill" style="width:${Math.max(2, (+c.total / maxCat) * 100)}%"></div></div>
          <span class="num" style="font-weight:600;text-align:right">${money(+c.total, { short: true })}</span>`).join('')}</div>` : '<p class="faint small">No spending this month.</p>'}</div></div>
        <div class="card"><div class="card-head"><h3>Active projects</h3><a class="small" href="#/projects">All</a></div>
          ${active.length ? `<ul class="list">${active.map((p) => `<li class="li" onclick="location.hash='#/projects/${p.id}'"><div class="li-icon tone-brand"><i class="ti ti-briefcase"></i></div>
            <div class="main-col"><div class="t">${esc(p.name)}</div><div class="s">${esc(p.category || '')}${p.client_name ? ' · ' + esc(p.client_name) : ''}</div></div>
            <span class="amt out">${money(p.spent, { short: true })}</span></li>`).join('')}</ul>` : `<div class="card-body">${App.empty('briefcase', 'No active projects', '', '<a class="btn sm" href="#/projects">Add a project</a>')}</div>`}</div>`;
      bindCtxns(ctx.el);
    };
    $$('[data-new]', ctx.el).forEach((b) => b.addEventListener('click', () => ctxnForm(b.dataset.new)));
    $('#cw-adjust', ctx.el).addEventListener('click', () => ctxnForm('adjust'));
    $('#cw-f', ctx.el).addEventListener('input', App.debounce((e) => { if (e.target.name) cf[e.target.name] = e.target.value; load().catch(App.fail); }, 350));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Company wallet', admin: true, live: true });

  /* ------------------------------------------------------------ projects */
  function budgetBar(p) {
    if (p.budget_used === null) return '';
    return `<div class="bar-track" style="margin-top:8px" title="${p.budget_used}% of budget"><div class="bar-fill ${p.budget_used > 100 ? 'neg' : ''}" style="width:${Math.min(100, Math.max(2, p.budget_used))}%"></div></div>
      <div class="faint small" style="margin-top:4px">${p.budget_used}% of ${money(p.budget, { short: true })} budget</div>`;
  }

  async function projectForm(p = null, done) {
    let clients, jobs;
    try { [clients, jobs] = await Promise.all([api('clients_list').then((r) => r.items), api('jobs_list').then((r) => r.items)]); } catch (e) { App.fail(e); return; }
    const v = p || { status: 'active', start_date: App.cfg.today };
    const el = App.modal(p ? 'Edit project' : 'New project', `<form id="pj-form" novalidate>
      <div class="field"><label for="pj-name">Project name <span class="req">*</span></label><input id="pj-name" name="name" value="${esc(v.name || '')}" placeholder="CCTV installation – Greenfield School" autofocus></div>
      <div class="form-row"><div class="field"><label for="pj-cat">Type of work</label><select id="pj-cat" name="category">${App.opts(App.cfg.project_categories, v.category, { placeholder: 'Choose…' })}</select></div>
        <div class="field"><label for="pj-st">Status</label><select id="pj-st" name="status">${App.opts(Object.entries(PSTATUS).map(([k, x]) => [k, x[0]]), v.status)}</select></div></div>
      <div class="form-row"><div class="field"><label for="pj-cl">Client</label><select id="pj-cl" name="client_id">${App.opts(clients.map((c) => [c.id, c.name]), v.client_id, { placeholder: 'Choose…' })}</select></div>
        <div class="field"><label for="pj-job">Job order</label><select id="pj-job" name="job_id">${App.opts(jobs.map((j) => [j.id, `${j.ref} · ${j.title}`]), v.job_id, { placeholder: 'None' })}</select></div></div>
      <div class="form-row"><div class="field"><label for="pj-cv">Contract value (${esc(App.cfg.currency)})</label><input type="number" id="pj-cv" name="contract_value" min="0" step="0.01" value="${esc(+v.contract_value ? v.contract_value : '')}"><div class="help">What the client is paying.</div></div>
        <div class="field"><label for="pj-bd">Budget (${esc(App.cfg.currency)})</label><input type="number" id="pj-bd" name="budget" min="0" step="0.01" value="${esc(+v.budget ? v.budget : '')}"><div class="help">What you plan to spend.</div></div></div>
      <div class="form-row"><div class="field"><label for="pj-sd">Start date</label><input type="date" id="pj-sd" name="start_date" value="${esc(v.start_date || '')}"></div>
        <div class="field"><label for="pj-ed">End date</label><input type="date" id="pj-ed" name="end_date" value="${esc(v.end_date || '')}"></div></div>
      <div class="field"><label for="pj-notes">Notes / scope</label><textarea id="pj-notes" name="notes" rows="3">${esc(v.notes || '')}</textarea></div>
      <div class="field"><div class="label">Contract, quotation or invoice</div><div id="pj-files"></div></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="pj-ok"><i class="ti ti-check"></i>Save project</button>');
    const up = App.uploader($('#pj-files', el), { existing: p?.attachments?.length || 0, label: 'Add file' });
    $('#pj-ok', el).addEventListener('click', async (e) => {
      const form = $('#pj-form', el);
      try {
        const r = await App.busy(e.currentTarget, () => api('project_save', { data: { ...App.formData(form), id: p?.id || 0 }, files: up.files() }));
        App.closeTop();
        toast('Project saved');
        done ? done(r.id) : App.go('projects/' + r.id);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  let pStatus = 'active';
  App.route('projects', async (ctx) => {
    const load = async () => {
      const items = (await api('projects_list', { query: { status: pStatus } })).items;
      const sum = (k) => items.reduce((a, p) => a + p[k], 0);
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Projects</h1><p class="muted">Contract jobs such as inverter, CCTV and interactive board installations, and procurement. See what each one costs and earns.</p></div>
          <div class="actions"><div class="seg" id="pj-tabs">${[['active', 'Active'], ['completed', 'Completed'], ['', 'All']].map(([k, l]) => `<button data-s="${k}" class="${k === pStatus ? 'on' : ''}">${l}</button>`).join('')}</div>
          <button class="btn primary" id="pj-new"><i class="ti ti-plus"></i>New project</button></div></div>
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Contract value', money(sum('contract_value')), `${items.length} project${items.length === 1 ? '' : 's'}`, 'file-certificate', 'tone-brand')}
          ${kpi('Spent', money(sum('spent')), 'Expenses tagged to these projects', 'receipt', 'tone-out')}
          ${kpi('Received', money(sum('income')), `${money(sum('outstanding'))} still to collect`, 'cash', 'tone-in')}
          ${kpi('Expected profit', money(sum('profit')), 'Contract value − spent', 'trending-up', sum('profit') < 0 ? 'tone-bad' : 'tone-in')}
        </div>
        ${items.length ? `<div class="grid g3">${items.map((p) => `<a class="card card-pad" href="#/projects/${p.id}" style="text-decoration:none;color:inherit">
          <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin-bottom:6px"><div style="font-weight:600;min-width:0">${esc(p.name)}</div>${projectPill(p.status)}</div>
          <div class="faint small" style="margin-bottom:10px">${[p.category, p.client_name].filter(Boolean).map(esc).join(' · ') || '&nbsp;'}</div>
          <div style="display:flex;justify-content:space-between;font-size:13px"><span class="muted">Contract</span><b class="num">${money(p.contract_value)}</b></div>
          <div style="display:flex;justify-content:space-between;font-size:13px"><span class="muted">Spent</span><b class="num out">${money(p.spent)}</b></div>
          <div style="display:flex;justify-content:space-between;font-size:13px"><span class="muted">Profit</span><b class="num ${p.profit < 0 ? 'bad' : 'in'}">${money(p.profit)}</b></div>
          ${budgetBar(p)}</a>`).join('')}</div>`
          : App.empty('briefcase', 'No projects here', 'Create a project for each contract, then tag company expenses to it.', '<button class="btn primary" id="pj-new2"><i class="ti ti-plus"></i>New project</button>')}`;
      $$('#pj-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { pStatus = b.dataset.s; load().catch(App.fail); }));
      $('#pj-new', ctx.el).addEventListener('click', () => projectForm());
      $('#pj-new2', ctx.el)?.addEventListener('click', () => projectForm());
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Projects', admin: true, live: true });

  App.route('projects/:id', async (ctx) => {
    const id = +ctx.params.id;
    const load = async () => {
      const r = await api('project_get', { query: { id } });
      const p = r.project;
      const maxCat = Math.max(1, ...r.categories.map((c) => +c.total));
      ctx.el.innerHTML = `
        <div class="page-head"><div><a href="#/projects" class="small"><i class="ti ti-arrow-left"></i> All projects</a><h1>${esc(p.name)}</h1>
          <p class="muted">${[p.category, p.client_name].filter(Boolean).map(esc).join(' · ')} ${projectPill(p.status)}</p></div>
          <div class="actions"><button class="btn" id="pd-edit"><i class="ti ti-edit"></i>Edit</button><button class="btn" id="pd-inc"><i class="ti ti-cash"></i>Client payment</button>
            <button class="btn primary" id="pd-exp"><i class="ti ti-plus"></i>Add expense</button></div></div>
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Contract value', money(p.contract_value), p.outstanding ? `${money(p.outstanding)} still to collect` : 'Fully paid', 'file-certificate', 'tone-brand')}
          ${kpi('Spent', money(p.spent), p.budget_used !== null ? `${p.budget_used}% of ${money(p.budget, { short: true })} budget` : 'No budget set', 'receipt', p.budget_used > 100 ? 'tone-bad' : 'tone-out')}
          ${kpi('Received', money(p.income), 'Client payments', 'cash', 'tone-in')}
          ${kpi('Expected profit', `<span class="${p.profit < 0 ? 'bad' : ''}">${money(p.profit)}</span>`, p.contract_value ? 'Contract value − spent' : 'Received − spent', 'trending-up', p.profit < 0 ? 'tone-bad' : 'tone-in', 'accent')}
        </div>
        <div class="grid g-main"><div class="card"><div class="card-head"><h3>Entries</h3><span class="faint small">${r.txns.length}</span></div>${ctxnTable(r.txns, { showProject: false })}</div>
          <div class="stack">
            <div class="card"><div class="card-head"><h3>Spending by category</h3></div><div class="card-body">${r.categories.length ? `<div class="bars">${r.categories.map((c) => `
              <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.category || 'Uncategorised')}</span>
              <div class="bar-track"><div class="bar-fill" style="width:${Math.max(2, (+c.total / maxCat) * 100)}%"></div></div>
              <span class="num" style="font-weight:600;text-align:right">${money(+c.total, { short: true })}</span>`).join('')}</div>` : '<p class="faint small">No expenses yet.</p>'}</div></div>
            <div class="card card-pad"><dl class="kv">
              ${p.client_name ? `<dt>Client</dt><dd><a href="#/clients/${p.client_id}">${esc(p.client_name)}</a></dd>` : ''}
              ${p.job_id ? `<dt>Job order</dt><dd><a href="#/jobs/${p.job_id}">${esc(p.job_title || 'Job')}</a></dd>` : ''}
              ${p.start_date ? `<dt>Dates</dt><dd>${esc(fmtDate(p.start_date))}${p.end_date ? ' – ' + esc(fmtDate(p.end_date)) : ''}</dd>` : ''}
              ${p.notes ? `<dt>Notes</dt><dd>${esc(p.notes)}</dd>` : ''}</dl>
              ${p.attachments.length ? `<div class="sec-title">Documents</div><div id="pd-gal">${App.gallery(p.attachments, { removable: true })}</div>` : ''}</div>
          </div></div>`;
      bindCtxns(ctx.el);
      if ($('#pd-gal', ctx.el)) App.bindGallery($('#pd-gal', ctx.el), load);
      $('#pd-edit', ctx.el).addEventListener('click', () => projectForm(p, () => load().catch(App.fail)));
      $('#pd-exp', ctx.el).addEventListener('click', () => ctxnForm('expense', null, { project_id: p.id }));
      $('#pd-inc', ctx.el).addEventListener('click', () => ctxnForm('income', null, { project_id: p.id, payee: p.client_name || '' }));
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Project', nav: 'projects', admin: true, live: true });

  /* ------------------------------------------------------- contract staff */
  function contractorForm(c = null, done) {
    const v = c || { active: 1 };
    const f = (name, label, attrs = '') => `<div class="field"><label for="ct-${name}">${label}</label><input id="ct-${name}" name="${name}" value="${esc(v[name] || '')}" ${attrs}></div>`;
    const el = App.modal(c ? 'Edit contract staff' : 'Add contract staff', `<form id="ct-form" novalidate>
      ${f('name', 'Full name <span class="req">*</span>', 'autofocus')}
      <div class="form-row">${f('phone', 'Phone', 'type="tel"')}${f('trade', 'Trade / skill', 'placeholder="e.g. Inverter technician"')}</div>
      <div class="form-row">${f('bank_name', 'Bank')}${f('bank_account', 'Account number', 'inputmode="numeric"')}</div>
      ${f('rate_note', 'Rate', 'placeholder="e.g. ₦15,000 per installation"')}
      <div class="field"><label for="ct-notes">Notes</label><textarea id="ct-notes" name="notes" rows="2">${esc(v.notes || '')}</textarea></div>
      ${c ? `<label class="check-row"><input type="checkbox" name="active" ${+v.active ? 'checked' : ''}><span>Active (still working with us)</span></label>` : ''}</form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ct-ok"><i class="ti ti-check"></i>Save</button>');
    $('#ct-ok', el).addEventListener('click', async (e) => {
      const form = $('#ct-form', el);
      const d = { ...App.formData(form), id: c?.id || 0 };
      if (!c) d.active = true;
      try {
        const r = await App.busy(e.currentTarget, () => api('contractor_save', { data: d }));
        App.closeTop();
        toast('Saved');
        done ? done(r.id) : App.go('contractors/' + r.id);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  App.route('contractors', async (ctx) => {
    let all = false;
    const load = async () => {
      const items = (await api('contractors_list', { query: { all: all ? 1 : '' } })).items;
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Contract staff</h1><p class="muted">People you hire per job. They don't sign in. Track what you pay them and any queries on their work.</p></div>
          <div class="actions"><label class="small muted" style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="ct-all" ${all ? 'checked' : ''}> Show inactive</label>
            <button class="btn primary" id="ct-new"><i class="ti ti-plus"></i>Add contract staff</button></div></div>
        <div class="card">${items.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>Trade</th><th class="hide-sm">Phone</th><th class="r">Paid</th><th class="r">Queries</th></tr></thead><tbody>
          ${items.map((c) => `<tr class="click" data-ct="${c.id}" style="${+c.active ? '' : 'opacity:.55'}"><td><b>${esc(c.name)}</b></td><td>${esc(c.trade)}</td><td class="hide-sm">${esc(c.phone)}</td>
            <td class="r num">${money(c.paid)}</td><td class="r">${c.open_queries ? `<span class="pill bad">${c.open_queries} open</span> ` : ''}${c.queries || ''}</td></tr>`).join('')}</tbody></table></div>`
          : App.empty('user-cog', 'No contract staff yet', 'Add the technicians and installers you hire per job.')}</div>`;
      $$('[data-ct]', ctx.el).forEach((r) => r.addEventListener('click', () => App.go('contractors/' + r.dataset.ct)));
      $('#ct-new', ctx.el).addEventListener('click', () => contractorForm());
      $('#ct-all', ctx.el).addEventListener('change', (e) => { all = e.target.checked; load().catch(App.fail); });
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Contract staff', admin: true, live: true });

  App.route('contractors/:id', async (ctx) => {
    const id = +ctx.params.id;
    const load = async () => {
      const r = await api('contractor_get', { query: { id } });
      const c = r.contractor;
      const open = r.queries.filter((x) => ['open', 'replied'].includes(x.status)).length;
      const warnings = r.queries.filter((x) => x.status === 'warning').length;
      ctx.el.innerHTML = `
        <div class="card card-pad profile-head" style="margin-bottom:14px">
          <div class="avatar xl">${esc(App.initials(c.name))}</div>
          <div class="main-col" style="min-width:0;flex:1"><a href="#/contractors" class="small"><i class="ti ti-arrow-left"></i> Contract staff</a><h1 style="margin-bottom:2px">${esc(c.name)}</h1>
            <div class="muted">${[c.trade, 'Contract staff'].filter(Boolean).map(esc).join(' · ')}</div>
            <div class="small muted" style="margin-top:4px">${c.phone ? `<a href="tel:${esc(c.phone)}">${esc(c.phone)}</a>` : ''}${c.bank_account ? ` · ${esc(c.bank_name)} ${esc(c.bank_account)}` : ''}${c.rate_note ? ` · ${esc(c.rate_note)}` : ''}</div></div>
          <div class="actions"><button class="btn" id="cd-edit"><i class="ti ti-edit"></i>Edit</button><button class="btn" id="cd-query"><i class="ti ti-alert-triangle"></i>Raise query</button>
            <button class="btn primary" id="cd-pay"><i class="ti ti-send"></i>Record payment</button>${+c.active ? '' : '<span class="pill">Inactive</span>'}</div>
        </div>
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Total paid', money(r.paid), `${r.txns.filter((t) => !t.voided_at).length} payments`, 'cash', 'tone-out')}
          ${kpi('Queries', r.queries.length, `${open} open`, 'alert-triangle', open ? 'tone-bad' : 'tone-info')}
          ${kpi('Warnings', warnings, 'From closed queries', 'alert-octagon', warnings ? 'tone-bad' : 'tone-in')}
          ${kpi('Since', esc(fmtDate(c.created_at, true)), 'Added to the portal', 'calendar', 'tone-brand')}
        </div>
        <div class="grid g-main"><div class="card"><div class="card-head"><h3>Payments</h3></div>${ctxnTable(r.txns)}</div>
          <div class="card"><div class="card-head"><h3>Queries</h3></div>${App.queryList ? App.queryList(r.queries) : ''}</div></div>
        ${c.notes ? `<div class="card card-pad" style="margin-top:14px"><div class="sec-title">Notes</div><div style="white-space:pre-wrap">${esc(c.notes)}</div></div>` : ''}`;
      bindCtxns(ctx.el);
      App.bindQueryList?.(ctx.el);
      $('#cd-edit', ctx.el).addEventListener('click', () => contractorForm(c, () => load().catch(App.fail)));
      $('#cd-pay', ctx.el).addEventListener('click', () => ctxnForm('expense', null, { contractor_id: c.id, category: App.cfg.company_categories.find((x) => /labour/i.test(x)) || '' }));
      $('#cd-query', ctx.el).addEventListener('click', () => App.queryForm({ contractor_id: c.id, name: c.name }));
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Contract staff', nav: 'contractors', admin: true, live: true });
})();
