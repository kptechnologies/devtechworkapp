/* DevTech Staff Portal — wallet: home, expenses, payments, review, requests, balances, ledger */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, fmtDateTime, ago, isAdmin, statusPill, catIcon, txnTone } = App;

  const txnRow = (t, { showUser = false } = {}) => {
    const sign = t.kind === 'credit' ? 1 : t.kind === 'adjust' ? Math.sign(t.effect) : -1;
    const cls = t.status === 'rejected' ? 'faint' : sign > 0 ? 'in' : 'out';
    const title = t.kind === 'credit' ? t.category : t.kind === 'adjust' ? 'Balance adjustment' : (t.category || 'Expense');
    const sub = [showUser ? t.user_name : '', t.description, t.route].filter(Boolean).join(' · ');
    return `<li class="li" data-txn="${t.id}">
      <div class="li-icon ${txnTone(t)}"><i class="ti ti-${catIcon(t.category)}"></i></div>
      <div class="main-col"><div class="t">${esc(title)}</div><div class="s">${esc(fmtDate(t.txn_date))}${sub ? ' · ' + esc(sub) : ''}</div></div>
      <div style="text-align:right"><div class="amt ${cls}" style="${t.status === 'rejected' ? 'text-decoration:line-through' : ''}">${money(Math.abs(t.amount) * (sign || -1), { sign: true })}</div>
      <div style="margin-top:2px">${t.kind === 'debit' ? statusPill(t.status) : ''}${t.files ? ` <i class="ti ti-paperclip faint" style="font-size:14px"></i>` : ''}</div></div></li>`;
  };
  App.txnRow = txnRow;
  const bindTxns = (root) => $$('[data-txn]', root).forEach((r) => r.addEventListener('click', () => openTxn(+r.dataset.txn)));

  const heroHtml = (w, { name = null } = {}) => `
    <div class="hero"><div class="top"><span>${name ? esc(name) + ' · ' : ''}Wallet balance</span><span class="live">Live</span></div>
      <div class="bal ${w.balance < 0 ? 'neg' : ''}" ${name ? '' : 'data-live-balance'}>${money(w.balance)}</div>
      <div class="row"><span>In today <b ${name ? '' : 'data-live="in_today"'}>${money(w.in_today)}</b></span>
        <span>Spent today <b ${name ? '' : 'data-live="spent_today"'}>${money(w.spent_today)}</b></span>
        <span>Spent this month <b${name ? '' : 'data-live="spent_month"'}>${money(w.spent_month)}</b></span></div></div>`;

  /* ---------------------------------------------------------- staff home */
  App.route('', async (ctx) => {
    const load = async () => {
      const r = await api('home');
      App.wallet = r.wallet;
      const hour = new Date().getHours();
      const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
      ctx.el.innerHTML = `
        <div class="page-head"><div><p class="muted">${greet}</p><h1>${esc(App.user.name.split(' ')[0])}</h1></div></div>
        <div class="grid g-main">
          <div class="stack">
            ${heroHtml(r.wallet)}
            <div class="quick">
              <button data-act="expense"><i class="ti ti-receipt out"></i>Add expense</button>
              <button data-act="report"><i class="ti ti-clipboard-plus" style="color:var(--brand)"></i>${r.today_report ? 'Another report' : 'Daily report'}</button>
              <button data-act="request"><i class="ti ti-cash in"></i>Request funds</button>
            </div>
            ${r.today_report ? `<div class="banner ok"><i class="ti ti-circle-check"></i>Today's report is in.<a class="btn sm" href="#/reports/${r.today_report}">View</a></div>`
              : `<div class="banner"><i class="ti ti-alert-circle"></i>Today's report isn't in yet.<a class="btn sm" href="#/report/new">Submit now</a></div>`}
            ${r.wallet.queried ? `<div class="banner" style="background:var(--bad-soft);color:var(--bad)"><i class="ti ti-help-circle"></i>${r.wallet.queried} expense${r.wallet.queried > 1 ? 's need' : ' needs'} your reply.<a class="btn sm" href="#/wallet">Answer</a></div>` : ''}
            <div class="card"><div class="card-head"><h3>Recent activity</h3><a href="#/wallet" class="small">See all</a></div>
              ${r.txns.length ? `<ul class="list">${r.txns.map((t) => txnRow(t)).join('')}</ul>` : App.empty('wallet', 'No transactions yet', 'Money your admin sends and expenses you log show up here.')}</div>
          </div>
          <div class="stack">
            <div class="card"><div class="card-head"><h3>My recent reports</h3><a href="#/reports" class="small">All reports</a></div>
              ${r.reports.length ? `<ul class="list">${r.reports.map((x) => `<li class="li" onclick="location.hash='#/reports/${x.id}'">
                <div class="main-col"><div class="t">${esc(fmtDate(x.report_date))}</div><div class="s">${esc(x.location)} · ${esc(x.work_type)}</div></div>${App.finishedPill(x.finished)}</li>`).join('')}</ul>`
                : App.empty('clipboard', 'No reports yet', '', '<a class="btn primary sm" href="#/report/new">Submit your first report</a>')}</div>
            <div class="card"><div class="card-head"><h3>Funding requests</h3><a href="#/requests" class="small">All requests</a></div>
              ${r.requests.length ? `<ul class="list">${r.requests.map((x) => `<li class="li" onclick="location.hash='#/requests'">
                <div class="main-col"><div class="t">${money(x.amount)}</div><div class="s">${esc(x.purpose)}</div></div>${statusPill(x.status)}</li>`).join('')}</ul>`
                : '<p class="faint small card-body">No requests yet.</p>'}</div>
          </div>
        </div>`;
      bindTxns(ctx.el);
      $$('[data-act]', ctx.el).forEach((b) => b.addEventListener('click', () => {
        const a = b.dataset.act;
        if (a === 'expense') openExpense();
        else if (a === 'request') openRequest();
        else App.go('report/new');
      }));
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Home', admin: false, live: true });

  /* -------------------------------------------------------------- wallet */
  App.route('wallet', (ctx) => walletView(ctx, null), { title: 'Wallet', live: true, admin: false });
  App.route('wallet/:id', (ctx) => walletView(ctx, +ctx.params.id), { title: 'Staff wallet', nav: 'balances', live: true, admin: true });
  App.route('txn/:id', async (ctx) => {
    if (isAdmin()) await ledgerView(ctx); else await walletView(ctx, null);
    openTxn(+ctx.params.id);
  }, { title: 'Wallet', nav: 'wallet', live: true });

  async function walletView(ctx, userId) {
    let kind = '';
    const load = async () => {
      const r = await api('wallet', { query: { user_id: userId || '', kind } });
      const admin = isAdmin();
      if (!userId) App.wallet = r.wallet;
      ctx.el.innerHTML = `
        <div class="page-head"><div>${admin ? '<a href="#/balances" class="small"><i class="ti ti-arrow-left"></i> All balances</a>' : ''}
          <h1>${admin ? esc(r.user.name) : 'My wallet'}</h1>${admin ? `<p class="muted">${esc(r.user.email)}${r.user.location ? ' · ' + esc(r.user.location) : ''}</p>` : ''}</div>
          <div class="actions">${admin
            ? `<button class="btn" id="wl-adjust"><i class="ti ti-adjustments"></i>Adjust</button><button class="btn primary" id="wl-pay"><i class="ti ti-send"></i>Send money</button>`
            : `<button class="btn" id="wl-req"><i class="ti ti-cash"></i>Request funds</button><button class="btn primary" id="wl-exp"><i class="ti ti-plus"></i>Add expense</button>`}</div></div>
        <div class="grid g-main">
          <div class="stack">
            ${heroHtml(r.wallet, { name: admin ? r.user.name : null })}
            <div class="card"><div class="card-head"><h3>Transactions</h3>
              <div class="seg" id="wl-kind">${[['', 'All'], ['credit', 'Money in'], ['debit', 'Expenses'], ['adjust', 'Adjustments']].map(([k, l]) =>
                `<button data-k="${k}" class="${k === kind ? 'on' : ''}">${l}</button>`).join('')}</div></div>
              ${r.txns.length ? `<ul class="list">${r.txns.map((t) => txnRow(t)).join('')}</ul>` : App.empty('receipt-off', 'Nothing here yet')}</div>
          </div>
          <div class="stack">
            <div class="grid g2" style="grid-template-columns:repeat(2,minmax(0,1fr))">
              <div class="card kpi"><div class="label">Received this month</div><div class="value in" style="font-size:18px">${money(r.wallet.in_month)}</div></div>
              <div class="card kpi"><div class="label">Spent this month</div><div class="value out" style="font-size:18px">${money(r.wallet.spent_month)}</div></div>
              <div class="card kpi"><div class="label">Awaiting review</div><div class="value" style="font-size:18px">${r.wallet.pending}</div></div>
              <div class="card kpi"><div class="label">Queried</div><div class="value ${r.wallet.queried ? 'bad' : ''}" style="font-size:18px">${r.wallet.queried}</div></div>
            </div>
            <div class="card"><div class="card-head"><h3>Funding requests</h3></div>
              ${r.requests.length ? `<ul class="list">${r.requests.map((x) => `<li class="li" onclick="location.hash='#/requests'"><div class="main-col"><div class="t">${money(x.amount)}</div>
                <div class="s">${esc(x.purpose)}</div></div>${statusPill(x.status)}</li>`).join('')}</ul>` : '<p class="faint small card-body">No requests.</p>'}</div>
          </div>
        </div>`;
      bindTxns(ctx.el);
      $$('#wl-kind button', ctx.el).forEach((b) => b.addEventListener('click', () => { kind = b.dataset.k; load().catch(App.fail); }));
      $('#wl-exp', ctx.el)?.addEventListener('click', () => openExpense());
      $('#wl-req', ctx.el)?.addEventListener('click', () => openRequest());
      $('#wl-pay', ctx.el)?.addEventListener('click', () => App.go(`pay?to=${userId}`));
      $('#wl-adjust', ctx.el)?.addEventListener('click', () => openAdjust(r.user));
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  /* ---------------------------------------------------------- txn drawer */
  async function openTxn(id) {
    const el = App.drawer('Transaction', App.loading());
    let t;
    try { t = (await api('txn_get', { query: { id } })).txn; } catch (e) { App.closeTop(); App.fail(e); return; }
    const admin = isAdmin();
    const mine = t.user_id == App.user.id;
    const editable = t.kind === 'debit' && (admin || (mine && ['posted', 'queried'].includes(t.status)));
    const sign = t.kind === 'credit' ? 1 : t.kind === 'adjust' ? Math.sign(t.effect) : -1;
    const title = t.kind === 'credit' ? t.category : t.kind === 'adjust' ? 'Balance adjustment' : (t.category || 'Expense');
    $('.drawer-head h2', el).textContent = title;
    const hist = (t.history || []).map((h) => {
      let d = {};
      try { d = JSON.parse(h.details || '{}'); } catch { /* ignore */ }
      const what = { expense_create: 'Logged', expense_update: 'Edited', expense_reply: 'Replied', review_approved: 'Approved', review_queried: 'Queried',
        review_rejected: 'Rejected', review_posted: 'Moved back to pending', credit_send: 'Sent', adjust: 'Adjusted', attachment_delete: 'Removed a file' }[h.action] || h.action;
      return `<div class="qa"><div class="q">${esc(fmtDateTime(h.created_at))} · ${esc(h.name || 'System')}</div><div class="a">${esc(what)}${d.note ? ': ' + esc(d.note) : ''}${d.reply ? ': ' + esc(d.reply) : ''}</div></div>`;
    }).join('');
    $('.drawer-body', el).innerHTML = `
      <div style="text-align:center;padding:6px 0 16px">
        <div class="li-icon ${txnTone(t)}" style="margin:0 auto 8px;width:48px;height:48px"><i class="ti ti-${catIcon(t.category)}" style="font-size:24px"></i></div>
        <div style="font-size:30px;font-weight:600;${t.status === 'rejected' ? 'text-decoration:line-through' : ''}" class="${sign > 0 ? 'in' : 'out'} num">${money(Math.abs(t.amount) * (sign || -1), { sign: true })}</div>
        <div style="margin-top:6px">${statusPill(t.status, t.kind)}</div></div>
      ${t.status === 'queried' && t.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>Question from ${esc(t.reviewed_by_name || 'admin')}:</b>\n${esc(t.review_note)}</div>` : ''}
      ${t.status === 'rejected' && t.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>Rejected:</b> ${esc(t.review_note)}\nThe amount was returned to the balance.</div>` : ''}
      ${t.staff_reply ? `<div class="note-box" style="margin-bottom:12px"><b>Staff reply:</b>\n${esc(t.staff_reply)}</div>` : ''}
      <dl class="kv">
        <dt>Staff</dt><dd>${esc(t.user_name)}</dd><dt>Date</dt><dd>${esc(fmtDate(t.txn_date))}</dd>
        ${t.description ? `<dt>${t.kind === 'debit' ? 'What for' : 'Note'}</dt><dd>${esc(t.description)}</dd>` : ''}
        ${t.route ? `<dt>Route</dt><dd>${esc(t.route)}</dd>` : ''}
        ${t.report ? `<dt>Work report</dt><dd><a href="#/reports/${t.report.id}">${esc(fmtDate(t.report.report_date))} · ${esc(t.report.location)}</a></dd>` : ''}
        ${t.request ? `<dt>Funding request</dt><dd>${esc(t.request.purpose)}</dd>` : ''}
        <dt>Entered by</dt><dd>${esc(t.created_by_name || '—')} · ${esc(ago(t.created_at))}</dd>
        ${t.reviewed_by_name && t.status !== 'queried' ? `<dt>Reviewed by</dt><dd>${esc(t.reviewed_by_name)} · ${esc(fmtDateTime(t.reviewed_at))}${t.review_note && t.status === 'approved' ? '<br>' + esc(t.review_note) : ''}</dd>` : ''}
        ${t.source === 'import' ? '<dt>Source</dt><dd>Imported history</dd>' : ''}
      </dl>
      <div class="sec-title">${t.kind === 'debit' ? 'Receipts and photos' : 'Attachments'}</div>
      <div id="tx-files">${App.gallery(t.attachments, { removable: editable })}</div>
      ${t.request_attachments?.length ? `<div class="sec-title">Request attachments</div>${App.gallery(t.request_attachments)}` : ''}
      ${mine && t.status === 'queried' ? `<div class="sec-title">Reply to the query</div><form id="tx-reply"><div class="field"><textarea name="reply" rows="3" placeholder="Explain, or add the missing receipt"></textarea></div>
        <div class="field" id="tx-reply-files"></div><button class="btn primary" type="submit"><i class="ti ti-send"></i>Send reply</button></form>` : ''}
      ${hist ? `<div class="sec-title">History</div>${hist}` : ''}`;
    App.bindGallery($('#tx-files', el));

    let replyUp = null;
    if ($('#tx-reply', el)) {
      replyUp = App.uploader($('#tx-reply-files', el), { existing: t.attachments.length, label: 'Add receipt' });
      $('#tx-reply', el).addEventListener('submit', async (e) => {
        e.preventDefault();
        const reply = e.target.reply.value.trim();
        if (!reply) { toast('Write a reply first.', true); return; }
        try {
          await App.busy(e.submitter || e.target.querySelector('button'), () => api('expense_reply', { data: { id: t.id, reply }, files: replyUp.files() }));
          App.closeAll(); toast('Reply sent'); App.current?.refresh?.();
        } catch (err) { App.fail(err); }
      });
    }

    const foot = document.createElement('div');
    foot.className = 'drawer-foot';
    const btns = [];
    if (admin && t.kind === 'debit') {
      btns.push('<button class="btn danger" data-rv="rejected"><i class="ti ti-x"></i>Reject</button>');
      btns.push('<button class="btn" data-rv="queried"><i class="ti ti-help"></i>Query</button>');
      if (t.status !== 'approved') btns.push('<button class="btn primary" data-rv="approved"><i class="ti ti-check"></i>Approve</button>');
      else btns.push('<button class="btn" data-rv="posted"><i class="ti ti-arrow-back-up"></i>Undo approval</button>');
    }
    if (editable) btns.unshift('<button class="btn" id="tx-edit"><i class="ti ti-edit"></i>Edit</button>');
    if (admin || (mine && editable)) btns.unshift(`<button class="btn ghost danger" id="tx-del" title="Delete"><i class="ti ti-trash"></i>${admin ? '' : 'Delete'}</button>`);
    if (btns.length) { foot.innerHTML = btns.join(''); el.appendChild(foot); }
    $$('[data-rv]', el).forEach((b) => b.addEventListener('click', () => review([t.id], b.dataset.rv)));
    $('#tx-edit', el)?.addEventListener('click', () => { App.closeTop(); openExpense(t); });
    $('#tx-del', el)?.addEventListener('click', async () => {
      const what = t.kind === 'debit' ? 'expense' : 'transaction';
      if (!(await App.confirm(`Delete this ${what}?`, `${money(t.amount)} will be removed and the balance recalculated. This can't be undone.`, { ok: 'Delete', danger: true }))) return;
      try { await api(admin ? 'txn_delete' : 'expense_delete', { data: { id: t.id } }); App.closeAll(); toast('Deleted'); App.poll(); App.current?.refresh?.(); } catch (e) { App.fail(e); }
    });
  }
  App.openTxn = openTxn;

  async function review(ids, status) {
    let note = '';
    if (status === 'queried' || status === 'rejected') {
      note = await App.confirm(status === 'queried' ? 'Query expense' : 'Reject expense',
        status === 'queried' ? 'Ask the staff member for more details or a receipt. The amount stays deducted until you decide.'
          : 'The amount goes back into the staff member\'s balance. They\'ll be notified with your reason.',
        { ok: status === 'queried' ? 'Send question' : 'Reject', danger: status === 'rejected', input: { label: status === 'queried' ? 'Your question' : 'Reason', required: true } });
      if (!note) return false;
    }
    try {
      const r = await api('txn_review', { data: { ids, status, note } });
      App.closeAll();
      toast(`${r.count} expense${r.count === 1 ? '' : 's'} ${{ approved: 'approved', queried: 'queried', rejected: 'rejected', posted: 'moved back to pending' }[status]}`);
      App.poll();
      App.current?.refresh?.();
      return true;
    } catch (e) { App.fail(e); return false; }
  }
  App.review = review;

  /* --------------------------------------------------------- expense form */
  async function openExpense(existing = null) {
    const cats = App.cfg.categories;
    const e = existing || {};
    const w = App.wallet || { balance: 0 };
    const startBal = Number(w.balance) + (existing ? Number(existing.amount) : 0);
    let reports = [];
    try { reports = (await api('reports_list', { query: { limit: 10, user_id: existing ? existing.user_id : '' } })).items; } catch { /* optional */ }
    const el = App.modal(existing ? 'Edit expense' : 'Add expense', `
      <form id="ex-form" novalidate>
        <div class="field"><div class="label">Category <span class="req">*</span></div><div class="opts" data-field="category">
          ${cats.concat(e.category && !cats.includes(e.category) ? [e.category] : []).map((c, i) => `<label class="opt"><input type="radio" name="category" value="${esc(c)}" ${(e.category ? e.category === c : i === 0) ? 'checked' : ''}><span><i class="ti ti-${catIcon(c)}" style="font-size:15px"></i>${esc(c)}</span></label>`).join('')}</div></div>
        <div class="form-row"><div class="field"><label for="ex-amt">Amount (${esc(App.cfg.currency)}) <span class="req">*</span></label>
          <input type="number" id="ex-amt" name="amount" class="amount-input" inputmode="decimal" min="0" step="0.01" value="${esc(e.amount || '')}" placeholder="0.00" autofocus></div>
          <div class="field"><label for="ex-date">Date <span class="req">*</span></label><input type="date" id="ex-date" name="txn_date" value="${esc(e.txn_date || App.cfg.today)}" max="${App.addDays(App.cfg.today, 1)}"></div></div>
        <div class="field" id="ex-route-f"><label for="ex-route">Route (from → to)</label><input type="text" id="ex-route" name="route" value="${esc(e.route || '')}" placeholder="Office → Mayday School"></div>
        <div class="field"><label for="ex-desc">What was it for? <span class="req">*</span></label><textarea id="ex-desc" name="description" rows="2" placeholder="Keke to deliver robotics kits">${esc(e.description || '')}</textarea></div>
        ${reports.length ? `<div class="field"><label for="ex-rep">Link to a work report (optional)</label><select id="ex-rep" name="report_id">${App.opts(reports.map((x) => [x.id, `${fmtDate(x.report_date)} · ${x.location}`]), e.report_id, { placeholder: 'No link' })}</select></div>` : ''}
        <div class="field"><div class="label">Receipts and photos</div>${existing?.attachments?.length ? `<p class="faint small" style="margin-bottom:6px">${existing.attachments.length} file(s) already attached.</p>` : ''}<div id="ex-files"></div></div>
        <div class="card-pad" style="background:var(--surface-2);border-radius:10px;display:flex;justify-content:space-between;align-items:center">
          <span class="muted">Balance after</span><span id="ex-after" style="font-weight:600;font-size:16px" class="num"></span></div>
        <p class="small bad hide" id="ex-neg" style="margin-top:6px">This takes your balance below zero. You can still save it, and your admin will see it.</p>
      </form>`,
      `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ex-save"><i class="ti ti-check"></i>${existing ? 'Save changes' : 'Save expense'}</button>`);
    const form = $('#ex-form', el);
    const up = App.uploader($('#ex-files', el), { existing: existing?.attachments?.length || 0, label: 'Add receipt', capture: false });
    const update = () => {
      const amt = Number(form.amount.value) || 0;
      const after = startBal - amt;
      $('#ex-after', el).textContent = money(after);
      $('#ex-after', el).className = 'num ' + (after < 0 ? 'bad' : '');
      $('#ex-neg', el).classList.toggle('hide', !(after < 0 && amt > 0));
      const cat = App.formData(form).category || '';
      $('#ex-route-f', el).classList.toggle('hide', !/transport|logist|deliver/i.test(cat) && !form.route.value);
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
    $('#ex-save', el).addEventListener('click', async (ev) => {
      const data = App.formData(form);
      const errs = {};
      if (!(Number(data.amount) > 0)) errs.amount = 'Enter an amount above 0.';
      if (!data.description.trim()) errs.description = 'Say what the money was for.';
      if (!data.category) errs.category = 'Pick a category.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      if (existing) data.id = existing.id;
      try {
        const r = await App.busy(ev.currentTarget, () => api('expense_save', { data, files: up.files() }));
        if (!existing || existing.user_id == App.user.id) App.wallet = r.wallet;
        App.closeAll();
        toast(existing ? 'Expense updated' : `Expense saved · balance ${money(r.wallet.balance)}`);
        App.poll();
        App.current?.refresh?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.openExpense = openExpense;

  /* --------------------------------------------------------- request form */
  function openRequest() {
    const el = App.modal('Request funds', `
      <form id="rq-form" novalidate>
        <div class="field"><label for="rq-amt">Amount (${esc(App.cfg.currency)}) <span class="req">*</span></label><input type="number" id="rq-amt" name="amount" class="amount-input" inputmode="decimal" min="0" step="0.01" placeholder="0.00" autofocus></div>
        <div class="field"><label for="rq-p">What's it for? <span class="req">*</span></label><textarea id="rq-p" name="purpose" rows="3" placeholder="Projector bracket and screws for Soar High installation"></textarea></div>
        <div class="field"><label for="rq-d">Needed by</label><input type="date" id="rq-d" name="needed_by" min="${App.cfg.today}"></div>
        <div class="field"><div class="label">Quote or photo (optional)</div><div id="rq-files"></div></div>
      </form>`, `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="rq-save"><i class="ti ti-send"></i>Send request</button>`);
    const form = $('#rq-form', el);
    const up = App.uploader($('#rq-files', el), { label: 'Add file' });
    $('#rq-save', el).addEventListener('click', async (ev) => {
      const data = App.formData(form);
      const errs = {};
      if (!(Number(data.amount) > 0)) errs.amount = 'Enter an amount above 0.';
      if (!data.purpose.trim()) errs.purpose = 'Say what the money is for.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(ev.currentTarget, () => api('request_save', { data, files: up.files() }));
        App.closeAll(); toast('Request sent to your admin'); App.current?.refresh?.();
      } catch (err) { App.fail(err); }
    });
  }
  App.openRequest = openRequest;

  /* ---------------------------------------------------- admin: adjustment */
  function openAdjust(user, staff = null) {
    const el = App.modal('Adjust balance', `
      <form id="adj-form" novalidate>
        ${user ? `<p class="muted" style="margin-bottom:12px">${esc(user.name)}</p><input type="hidden" name="user_id" value="${user.id}">`
          : `<div class="field"><label for="adj-u">Staff member <span class="req">*</span></label><select id="adj-u" name="user_id">${App.opts(staff.map((s) => [s.id, `${s.name} · ${money(s.balance)}`]), '', { placeholder: 'Choose…' })}</select></div>`}
        <div class="field"><div class="label">Direction</div><div class="opts">
          <label class="opt"><input type="radio" name="dir" value="1" checked><span><i class="ti ti-plus"></i>Add to balance</span></label>
          <label class="opt"><input type="radio" name="dir" value="-1"><span><i class="ti ti-minus"></i>Deduct from balance</span></label></div></div>
        <div class="form-row"><div class="field"><label for="adj-a">Amount <span class="req">*</span></label><input type="number" id="adj-a" name="amount" min="0" step="0.01" class="amount-input" placeholder="0.00"></div>
          <div class="field"><label for="adj-d">Date</label><input type="date" id="adj-d" name="txn_date" value="${App.cfg.today}"></div></div>
        <div class="field"><label for="adj-r">Reason <span class="req">*</span></label><textarea id="adj-r" name="description" rows="2" placeholder="Opening balance carried over from the old sheet"></textarea></div>
      </form>`, `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="adj-save">Save adjustment</button>`);
    const form = $('#adj-form', el);
    $('#adj-save', el).addEventListener('click', async (ev) => {
      const d = App.formData(form);
      const errs = {};
      if (!d.user_id) errs.user_id = 'Choose a staff member.';
      if (!(Number(d.amount) > 0)) errs.amount = 'Enter an amount above 0.';
      if (!d.description.trim()) errs.description = 'Give a reason.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(ev.currentTarget, () => api('adjust', { data: { user_id: +d.user_id, amount: Number(d.amount) * Number(d.dir), description: d.description, txn_date: d.txn_date } }));
        App.closeAll(); toast('Balance adjusted'); App.current?.refresh?.();
      } catch (err) { App.fail(err); }
    });
  }
  App.openAdjust = openAdjust;

  /* -------------------------------------------------- admin: send payment */
  App.route('pay', async (ctx) => {
    const preselect = new URLSearchParams(location.hash.split('?')[1] || '').get('to');
    const staff = (await api('balances')).items.filter((s) => +s.active === 1);
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Send payment</h1><p class="muted">Daily allowance, work budget or top-up. Each person gets a notification and an email.</p></div>
        <div class="actions"><button class="btn" id="py-adjust"><i class="ti ti-adjustments"></i>Adjust a balance</button></div></div>
      <div class="grid g-main"><form class="card card-pad" id="py-form" novalidate>
        <div class="field" data-field="user_ids"><div class="label" style="display:flex;justify-content:space-between">Recipients <span class="req">*</span>
          <label class="small muted" style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" id="py-all"> All active staff</label></div>
          ${staff.length ? `<div class="opts">${staff.map((s) => `<label class="opt"><input type="checkbox" data-multi="1" name="user_ids" value="${s.id}" ${String(s.id) === preselect ? 'checked' : ''}><span>
            <span class="avatar sm" style="width:22px;height:22px;font-size:10px">${esc(App.initials(s.name))}</span>${esc(s.name)} <span class="faint small num">${money(s.balance, { short: true })}</span></span></label>`).join('')}</div>`
            : '<p class="faint">No staff yet. <a href="#/staff">Add staff</a> first.</p>'}</div>
        <div class="field"><div class="label">Type</div><div class="opts">${App.cfg.credit_types.map((c, i) => `<label class="opt"><input type="radio" name="category" value="${esc(c)}" ${i === 0 ? 'checked' : ''}><span><i class="ti ti-${catIcon(c)}" style="font-size:15px"></i>${esc(c)}</span></label>`).join('')}</div></div>
        <div class="form-row"><div class="field"><label for="py-amt">Amount per person (${esc(App.cfg.currency)}) <span class="req">*</span></label>
          <input type="number" id="py-amt" name="amount" class="amount-input" min="0" step="0.01" value="${App.cfg.allowance || ''}" placeholder="0.00"></div>
          <div class="field"><label for="py-date">Payment date</label><input type="date" id="py-date" name="txn_date" value="${App.cfg.today}"></div></div>
        <div class="field"><label for="py-desc">Purpose / description</label><textarea id="py-desc" name="description" rows="2" placeholder="Transport allowance for Tuesday"></textarea></div>
        <div class="field"><div class="label">Transfer receipt (optional)</div><div id="py-files"></div></div>
        <button class="btn primary block" id="py-send" style="height:46px"><i class="ti ti-send"></i>Send payment</button>
      </form>
      <div class="stack"><div class="card card-pad"><div class="muted small">Summary</div><div id="py-sum" style="font-size:22px;font-weight:600;margin:6px 0" class="num"></div><div class="faint small" id="py-sum2"></div></div>
        <div class="card"><div class="card-head"><h3>Current balances</h3><a href="#/balances" class="small">All</a></div>
          <ul class="list">${staff.map((s) => `<li class="li" onclick="location.hash='#/wallet/${s.id}'"><div class="avatar sm">${esc(App.initials(s.name))}</div><div class="main-col"><div class="t">${esc(s.name)}</div>
          <div class="s">Spent today ${money(s.spent_today)}</div></div><span class="amt ${s.balance < 0 ? 'bad' : ''}">${money(s.balance)}</span></li>`).join('')}</ul></div></div></div>`;
    const form = $('#py-form', ctx.el);
    const up = App.uploader($('#py-files', ctx.el), { label: 'Add receipt' });
    const sum = () => {
      const d = App.formData(form);
      const n = (d.user_ids || []).length;
      const amt = Number(d.amount) || 0;
      $('#py-sum', ctx.el).textContent = money(n * amt);
      $('#py-sum2', ctx.el).textContent = `${n} recipient${n === 1 ? '' : 's'} × ${money(amt)} · ${d.category || ''}`;
    };
    $('#py-all', ctx.el).addEventListener('change', (e) => { $$('input[name=user_ids]', form).forEach((c) => { c.checked = e.target.checked; }); sum(); });
    form.addEventListener('input', sum);
    form.addEventListener('change', sum);
    sum();
    $('#py-adjust', ctx.el).addEventListener('click', () => openAdjust(null, staff));
    $('#py-send', ctx.el).addEventListener('click', async (ev) => {
      ev.preventDefault();
      const btn = ev.currentTarget; // currentTarget is cleared once the confirm dialog is awaited
      const d = App.formData(form);
      const errs = {};
      if (!d.user_ids?.length) errs.user_ids = 'Pick at least one staff member.';
      if (!(Number(d.amount) > 0)) errs.amount = 'Enter an amount above 0.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      const total = d.user_ids.length * Number(d.amount);
      if (!(await App.confirm('Send payment?', `${money(total)} in total to ${d.user_ids.length} staff (${money(d.amount)} each) as ${esc(d.category)}.`, { ok: 'Send now' }))) return;
      try {
        const r = await App.busy(btn, () => api('credit_send', { data: { ...d, user_ids: d.user_ids.map(Number) }, files: up.files() }));
        toast(`Sent to ${r.count} staff`);
        App.reroute();
      } catch (err) { App.fail(err); }
    });
  }, { title: 'Send payment', admin: true });

  /* ------------------------------------------------------ admin: review */
  App.route('review', async (ctx) => {
    let status = 'review';
    const load = async () => {
      const r = await api('ledger', { query: { kind: 'debit', status } });
      const items = r.items;
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Expense review</h1><p class="muted">Expenses come off balances straight away. Approve, query, or reject them here. Rejecting refunds the amount.</p></div></div>
        <div class="filters"><div class="seg" id="rv-seg">${[['review', 'Needs review'], ['queried', 'Queried'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['', 'All']].map(([k, l]) =>
          `<button data-s="${k}" class="${k === status ? 'on' : ''}">${l}</button>`).join('')}</div>
          <span class="spacer" style="flex:1"></span>
          <span class="muted small" id="rv-selcount"></span>
          <button class="btn sm success hide" id="rv-approve"><i class="ti ti-checks"></i>Approve selected</button>
          <button class="btn sm danger hide" id="rv-reject"><i class="ti ti-x"></i>Reject selected</button></div>
        <div class="card">${items.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th style="width:32px"><input type="checkbox" id="rv-all" aria-label="Select all"></th>
          <th>Date</th><th>Staff</th><th>Category</th><th class="hide-sm">What for</th><th class="r">Amount</th><th>Status</th><th class="hide-sm"></th><th></th></tr></thead><tbody>
          ${items.map((t) => `<tr class="click" data-txn="${t.id}"><td><input type="checkbox" class="rv-c" value="${t.id}" aria-label="Select"></td>
            <td class="num">${esc(fmtDate(t.txn_date, false))}</td><td>${esc(t.user_name)}</td><td><i class="ti ti-${catIcon(t.category)} faint"></i> ${esc(t.category)}</td>
            <td class="ellipsis hide-sm">${esc(t.description)}${t.route ? ` <span class="faint">· ${esc(t.route)}</span>` : ''}</td>
            <td class="r num out" style="font-weight:600">${money(t.amount)}</td><td>${statusPill(t.status)}</td>
            <td class="faint hide-sm">${t.files ? `<i class="ti ti-paperclip"></i>${t.files}` : '<span class="small bad">No receipt</span>'}</td>
            <td style="white-space:nowrap">${['posted', 'queried'].includes(t.status) ? `<button class="btn sm success" data-q="approved" title="Approve"><i class="ti ti-check"></i></button>
              <button class="btn sm danger" data-q="rejected" title="Reject"><i class="ti ti-x"></i></button>` : ''}</td></tr>`).join('')}
          </tbody></table></div>` : App.empty('checks', status === 'review' ? 'All caught up' : 'Nothing here', status === 'review' ? 'No expenses are waiting for review.' : '')}</div>`;
      $$('#rv-seg button', ctx.el).forEach((b) => b.addEventListener('click', () => { status = b.dataset.s; load().catch(App.fail); }));
      const sel = () => $$('.rv-c:checked', ctx.el).map((c) => +c.value);
      const syncSel = () => {
        const n = sel().length;
        $('#rv-selcount', ctx.el).textContent = n ? `${n} selected` : '';
        $('#rv-approve', ctx.el).classList.toggle('hide', !n);
        $('#rv-reject', ctx.el).classList.toggle('hide', !n);
      };
      $('#rv-all', ctx.el)?.addEventListener('change', (e) => { $$('.rv-c', ctx.el).forEach((c) => { c.checked = e.target.checked; }); syncSel(); });
      $$('.rv-c', ctx.el).forEach((c) => { c.addEventListener('click', (e) => e.stopPropagation()); c.addEventListener('change', syncSel); });
      $$('[data-q]', ctx.el).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); review([+b.closest('tr').dataset.txn], b.dataset.q); }));
      $$('tr[data-txn]', ctx.el).forEach((r) => r.addEventListener('click', () => openTxn(+r.dataset.txn)));
      $('#rv-approve', ctx.el).addEventListener('click', () => review(sel(), 'approved'));
      $('#rv-reject', ctx.el).addEventListener('click', () => review(sel(), 'rejected'));
    };
    ctx.refresh = () => { if (!App.$$('.rv-c:checked').length) load().catch(() => {}); };
    await load();
  }, { title: 'Expense review', admin: true, live: true });

  /* --------------------------------------------------- funding requests */
  App.route('requests', async (ctx) => {
    const admin = isAdmin();
    const load = async () => {
      const r = await api('requests_list');
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Funding requests</h1><p class="muted">${admin ? 'Approving a request sends the money to the staff member\'s wallet.' : 'Ask for money ahead of a job. Approved requests are added to your wallet.'}</p></div>
          ${admin ? '' : '<div class="actions"><button class="btn primary" id="rq-new"><i class="ti ti-plus"></i>New request</button></div>'}</div>
        <div class="card">${r.items.length ? `<ul class="list">${r.items.map((x) => `<li class="li" style="cursor:default;align-items:flex-start">
          <div class="li-icon ${x.status === 'approved' ? 'tone-in' : x.status === 'pending' ? 'tone-out' : 'tone-bad'}"><i class="ti ti-cash"></i></div>
          <div class="main-col"><div class="t">${money(x.amount)}${admin ? ` · ${esc(x.user_name)}` : ''}</div>
            <div style="white-space:pre-wrap;font-size:13px">${esc(x.purpose)}</div>
            <div class="s">Asked ${esc(ago(x.created_at))}${x.needed_by ? ` · needed by ${esc(fmtDate(x.needed_by))}` : ''}${x.decided_by_name ? ` · ${esc(x.status)} by ${esc(x.decided_by_name)}` : ''}</div>
            ${x.admin_note ? `<div class="note-box" style="margin-top:6px">${esc(x.admin_note)}</div>` : ''}
            ${x.attachments.length ? `<div style="margin-top:8px" class="rq-g">${App.gallery(x.attachments)}</div>` : ''}</div>
          <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">${statusPill(x.status)}
            ${x.status === 'pending' && admin ? `<button class="btn sm primary" data-ap="${x.id}" data-amt="${x.amount}"><i class="ti ti-check"></i>Approve</button><button class="btn sm danger" data-dc="${x.id}">Decline</button>` : ''}
            ${x.status === 'pending' && !admin ? `<button class="btn sm ghost" data-cn="${x.id}">Cancel</button>` : ''}
            ${x.txn_id ? `<a class="small" href="#/txn/${x.txn_id}">View payment</a>` : ''}</div></li>`).join('')}</ul>`
          : App.empty('cash-off', 'No funding requests', admin ? 'Requests from staff will show up here.' : 'Need money for a job? Send a request.')}</div>`;
      $$('.rq-g', ctx.el).forEach((g) => App.bindGallery(g));
      $('#rq-new', ctx.el)?.addEventListener('click', openRequest);
      $$('[data-cn]', ctx.el).forEach((b) => b.addEventListener('click', async () => {
        if (!(await App.confirm('Cancel request?', 'Your admin will no longer see it as pending.', { ok: 'Cancel request', danger: true }))) return;
        try { await api('request_cancel', { data: { id: +b.dataset.cn } }); load(); } catch (e) { App.fail(e); }
      }));
      $$('[data-dc]', ctx.el).forEach((b) => b.addEventListener('click', async () => {
        const note = await App.confirm('Decline request', 'The staff member will see your reason.', { ok: 'Decline', danger: true, input: { label: 'Reason', required: true } });
        if (!note) return;
        try { await api('request_decide', { data: { id: +b.dataset.dc, approve: false, note } }); toast('Request declined'); load(); App.poll(); } catch (e) { App.fail(e); }
      }));
      $$('[data-ap]', ctx.el).forEach((b) => b.addEventListener('click', () => {
        const el = App.modal('Approve request', `<form id="ap-form">
          <div class="field"><label for="ap-a">Amount to send</label><input type="number" id="ap-a" name="amount" class="amount-input" value="${esc(b.dataset.amt)}" min="0" step="0.01"></div>
          <div class="field"><label for="ap-c">Record as</label><select id="ap-c" name="category">${App.opts(App.cfg.credit_types, App.cfg.credit_types.includes('Work budget') ? 'Work budget' : App.cfg.credit_types[0])}</select></div>
          <div class="field"><label for="ap-n">Note to staff (optional)</label><textarea id="ap-n" name="note" rows="2"></textarea></div></form>`,
          `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ap-go"><i class="ti ti-send"></i>Approve and send</button>`);
        $('#ap-go', el).addEventListener('click', async (ev) => {
          const d = App.formData($('#ap-form', el));
          try { await App.busy(ev.currentTarget, () => api('request_decide', { data: { id: +b.dataset.ap, approve: true, ...d } })); App.closeAll(); toast('Approved and sent'); load(); App.poll(); } catch (e) { App.fail(e); }
        });
      }));
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Funding requests', live: true });

  /* ---------------------------------------------------- admin: balances */
  App.route('balances', async (ctx) => {
    const load = async () => {
      const items = (await api('balances')).items;
      const total = items.reduce((a, s) => a + s.balance, 0);
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Balances</h1><p class="muted">${items.length} staff · total held <b class="num">${money(total)}</b></p></div>
          <div class="actions"><a class="btn primary" href="#/pay"><i class="ti ti-send"></i>Send payment</a></div></div>
        ${items.length ? `<div class="grid g4">${items.map((s) => `<a class="card card-pad" href="#/wallet/${s.id}" style="text-decoration:none;color:inherit;${+s.active ? '' : 'opacity:.55'}">
          <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px"><div class="avatar">${esc(App.initials(s.name))}</div>
            <div style="min-width:0"><div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(s.name)}</div><div class="faint small">${esc(s.location || (+s.active ? 'Staff' : 'Inactive'))}</div></div></div>
          <div class="faint small">Balance</div><div style="font-size:22px;font-weight:600" class="num ${s.balance < 0 ? 'bad' : ''}">${money(s.balance)}</div>
          <div style="display:flex;justify-content:space-between;margin-top:10px;font-size:12px" class="muted"><span>Today <b class="out">${money(s.spent_today, { short: true })}</b></span>
            <span>Month <b>${money(s.spent_month, { short: true })}</b></span>${s.pending ? `<span class="badge">${s.pending}</span>` : ''}</div></a>`).join('')}</div>`
          : App.empty('users', 'No staff yet', '', '<a class="btn primary" href="#/staff">Add staff</a>')}`;
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Balances', admin: true, live: true });

  /* ------------------------------------------------------ admin: ledger */
  const lf = { user_id: '', kind: '', category: '', status: '', from: '', to: '', q: '' };
  async function ledgerView(ctx) {
    const staff = (await api('users_list')).items;
    const cats = [...new Set(App.cfg.categories.concat(App.cfg.credit_types, ['Adjustment']))];
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Ledger</h1><p class="muted">Every payment, expense and adjustment.</p></div>
        <div class="actions"><a class="btn" id="lg-export"><i class="ti ti-download"></i>Export CSV</a><a class="btn primary" href="#/pay"><i class="ti ti-send"></i>Send payment</a></div></div>
      <div class="filters" id="lg-f">
        <input type="search" name="q" placeholder="Search description, route, staff…" value="${esc(lf.q)}">
        <select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), lf.user_id, { placeholder: 'All staff' })}</select>
        <select name="kind">${App.opts([['credit', 'Money sent'], ['debit', 'Expenses'], ['adjust', 'Adjustments']], lf.kind, { placeholder: 'All types' })}</select>
        <select name="category">${App.opts(cats, lf.category, { placeholder: 'All categories' })}</select>
        <select name="status">${App.opts([['review', 'Needs review'], ['posted', 'Awaiting review'], ['queried', 'Queried'], ['approved', 'Approved'], ['rejected', 'Rejected']], lf.status, { placeholder: 'Any status' })}</select>
        <input type="date" name="from" value="${esc(lf.from)}" aria-label="From"><input type="date" name="to" value="${esc(lf.to)}" aria-label="To">
      </div>
      <div class="grid g4" id="lg-tot" style="margin-bottom:14px"></div>
      <div class="card" id="lg-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('ledger', { query: lf });
      $('#lg-export', ctx.el).href = 'api.php?action=ledger_export&' + new URLSearchParams(Object.entries(lf).filter(([, v]) => v)).toString();
      $('#lg-tot', ctx.el).innerHTML = [['Sent', r.totals.sent, 'in'], ['Spent', r.totals.spent, 'out'], ['Adjustments', r.totals.adjust, ''], ['Net', r.totals.sent - r.totals.spent + r.totals.adjust, '']]
        .map(([l, v, c]) => `<div class="card kpi"><div class="label">${l}</div><div class="value ${c}" style="font-size:19px">${money(v)}</div></div>`).join('');
      $('#lg-list', ctx.el).innerHTML = r.items.length ? `<div class="table-wrap desktop-only"><table class="tbl"><thead><tr><th>Date</th><th>Staff</th><th>Type</th><th>Category</th><th>Description</th><th class="r">Amount</th><th>Status</th><th></th></tr></thead><tbody>
        ${r.items.map((t) => { const sg = t.kind === 'credit' ? 1 : t.kind === 'adjust' ? Math.sign(t.effect) : -1; return `<tr class="click" data-txn="${t.id}"><td class="num">${esc(fmtDate(t.txn_date))}</td><td>${esc(t.user_name)}</td>
          <td>${t.kind === 'credit' ? '<span class="pill in">Sent</span>' : t.kind === 'adjust' ? '<span class="pill info">Adjust</span>' : '<span class="pill out">Expense</span>'}</td>
          <td>${esc(t.category)}</td><td class="ellipsis">${esc(t.description)}</td>
          <td class="r num ${t.status === 'rejected' ? 'faint' : sg > 0 ? 'in' : 'out'}" style="font-weight:600">${money(Math.abs(t.amount) * sg, { sign: true })}</td>
          <td>${t.kind === 'debit' ? statusPill(t.status) : ''}</td><td class="faint">${t.files ? `<i class="ti ti-paperclip"></i>${t.files}` : ''}</td></tr>`; }).join('')}</tbody></table></div>
        <ul class="list mobile-only">${r.items.map((t) => txnRow(t, { showUser: true })).join('')}</ul>
        ${r.items.length >= 2000 ? '<p class="faint small card-body">Showing the latest 2,000. Narrow the filters or export to see more.</p>' : ''}`
        : App.empty('list-search', 'No transactions match', 'Try changing the filters.');
      bindTxns($('#lg-list', ctx.el));
    };
    $('#lg-f', ctx.el).addEventListener('input', App.debounce((e) => { if (e.target.name) lf[e.target.name] = e.target.value; load().catch(App.fail); }, 350));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }
  App.route('ledger', ledgerView, { title: 'Ledger', admin: true, live: true });
})();
