/* DevTech Staff Portal — admin dashboard, staff setup, import, settings, profile */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, moneyShort, fmtDate, ago, isAdmin, statusPill, catIcon } = App;

  /* ------------------------------------------------------------ dashboard */
  const RANGES = [['today', 'Today'], ['7', '7 days'], ['30', '30 days'], ['month', 'This month'], ['year', 'This year']];
  function rangeDates(key) {
    const t = App.cfg.today;
    if (key === 'today') return [t, t];
    if (key === '7') return [App.addDays(t, -6), t];
    if (key === 'month') return [t.slice(0, 8) + '01', t];
    if (key === 'year') return [t.slice(0, 5) + '01-01', t];
    return [App.addDays(t, -29), t];
  }
  const bucketLabel = (b, byDay) => {
    if (byDay) return fmtDate(b, false);
    const [y, m] = b.split('-');
    return new Date(+y, +m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
  };
  function buckets(from, to, byDay) {
    const out = [];
    if (byDay) { for (let d = from; d <= to; d = App.addDays(d, 1)) out.push(d); return out; }
    let [y, m] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);
    while (y < ty || (y === ty && m <= tm)) { out.push(`${y}-${String(m).padStart(2, '0')}`); m++; if (m > 12) { m = 1; y++; } }
    return out;
  }

  App.route('', async (ctx) => {
    let range = App.store.get('dt-range', '30');
    const load = async () => {
      const [from, to] = rangeDates(range);
      const d = await api('dashboard', { query: { from, to } });
      const pal = App.palette();
      const m = d.money, rp = d.reports;
      const completion = rp.n ? Math.round((rp.done / rp.n) * 100) : 0;
      const kpi = (label, value, sub, icon, tone, extra = '') => `<div class="card kpi ${extra}"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div>
        <div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
      const maxBal = Math.max(1, ...d.balances.map((b) => Math.abs(b.balance)));
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Overview</h1><p class="muted">${esc(fmtDate(d.from))} – ${esc(fmtDate(d.to))}</p></div>
          <div class="actions"><div class="seg" id="db-range">${RANGES.map(([k, l]) => `<button data-r="${k}" class="${k === range ? 'on' : ''}">${l}</button>`).join('')}</div>
          <a class="btn primary" href="#/pay"><i class="ti ti-send"></i>Send payment</a></div></div>
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Total sent', money(m.sent), 'Allowances, budgets, top-ups', 'arrow-up-right', 'tone-in')}
          ${kpi('Total spent', money(m.spent), `${m.n_expenses} expenses · ${m.sent ? Math.round((m.spent / m.sent) * 100) : 0}% of sent`, 'receipt', 'tone-out')}
          ${kpi('Net adjustments', money(m.adjust, { sign: true }), 'Corrections in this period', 'adjustments', 'tone-info')}
          ${kpi('Available balance', money(m.available), 'Held by all staff right now', 'wallet', 'tone-brand', 'accent')}
        </div>
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Reports today', `${rp.today} <span class="faint" style="font-size:15px">of ${rp.staff}</span>`, `${d.missing_today.length} not in yet`, 'clipboard-check', 'tone-brand')}
          ${kpi('Tasks completed', `${completion}%`, `${rp.done} of ${rp.n} reports`, 'circle-check', 'tone-in')}
          ${kpi('Faulty laptops', rp.faulty, 'Latest report per school', 'device-laptop-off', rp.faulty ? 'tone-bad' : 'tone-in')}
          ${kpi('Waiting on you', m.pending_reviews + m.pending_requests, `${m.pending_reviews} expenses · ${m.pending_requests} requests`, 'bell-ringing', (m.pending_reviews + m.pending_requests) ? 'tone-out' : 'tone-in')}
        </div>
        ${d.missing_today.length ? `<div class="card card-pad" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span class="muted small"><i class="ti ti-clock-exclamation"></i> Not reported today:</span>
          ${d.missing_today.map((s) => `<a class="pill bad" href="#/wallet/${s.id}">${esc(s.name)}</a>`).join('')}</div>` : ''}
        <div class="grid g-main" style="margin-bottom:14px">
          <div class="card"><div class="card-head"><h3>Sent vs spent</h3><span class="faint small">${d.by_day ? 'by day' : 'by month'}</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="ch-money"></canvas></div></div></div>
          <div class="card"><div class="card-head"><h3>Spending by category</h3></div>
            <div class="card-body">${d.categories.length ? '<div class="chart-box"><canvas id="ch-cat"></canvas></div>' : App.empty('chart-donut', 'No spending yet')}</div></div>
        </div>
        <div class="grid g-main" style="margin-bottom:14px">
          <div class="card"><div class="card-head"><h3>Staff balance overview</h3><a href="#/balances" class="small">All balances</a></div>
            <div class="card-body">${d.balances.length ? `<div class="bars">${d.balances.filter((b) => +b.active).map((b) => `
              <a href="#/wallet/${b.id}" style="color:inherit;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(b.name)}</a>
              <div class="bar-track"><div class="bar-fill ${b.balance < 0 ? 'neg' : ''}" style="width:${Math.max(2, (Math.abs(b.balance) / maxBal) * 100)}%"></div></div>
              <span class="num ${b.balance < 0 ? 'bad' : ''}" style="font-weight:600;text-align:right">${money(b.balance)}</span>`).join('')}</div>` : App.empty('users', 'No staff yet', '', '<a class="btn sm" href="#/staff">Add staff</a>')}</div></div>
          <div class="card"><div class="card-head"><h3>Waiting on you</h3><a href="#/review" class="small">Review all</a></div>
            ${d.review.length || d.requests.length ? `<ul class="list">
              ${d.requests.map((x) => `<li class="li" onclick="location.hash='#/requests'"><div class="li-icon tone-in"><i class="ti ti-cash"></i></div>
                <div class="main-col"><div class="t">${esc(x.user_name)} · request</div><div class="s">${esc(x.purpose)}</div></div><span class="amt">${money(x.amount)}</span></li>`).join('')}
              ${d.review.map((t) => `<li class="li" data-txn="${t.id}"><div class="li-icon tone-out"><i class="ti ti-${catIcon(t.category)}"></i></div>
                <div class="main-col"><div class="t">${esc(t.user_name)} · ${esc(t.category)}</div><div class="s">${esc(t.description)}</div></div>
                <div style="text-align:right"><div class="amt out">${money(t.amount)}</div>${t.status === 'queried' ? statusPill('queried') : `<span style="white-space:nowrap">
                <button class="btn sm success" data-q="approved" aria-label="Approve"><i class="ti ti-check"></i></button><button class="btn sm danger" data-q="rejected" aria-label="Reject"><i class="ti ti-x"></i></button></span>`}</div></li>`).join('')}
            </ul>` : App.empty('checks', 'All caught up')}</div>
        </div>
        <div class="grid g3" style="margin-bottom:14px">
          <div class="card"><div class="card-head"><h3>Top 10 expenses</h3></div>
            ${d.top_expenses.length ? `<ul class="list">${d.top_expenses.map((t) => `<li class="li" data-txn="${t.id}"><div class="main-col"><div class="t">${esc(t.description || t.category)}</div>
              <div class="s">${esc(t.user_name)} · ${esc(fmtDate(t.txn_date, false))}</div></div><span class="amt out">${money(t.amount, { short: true })}</span></li>`).join('')}</ul>` : App.empty('receipt-off', 'No expenses')}</div>
          <div class="card"><div class="card-head"><h3>Top spenders</h3></div><div class="card-body">${d.top_spenders.length ? '<div class="chart-box sm"><canvas id="ch-spend"></canvas></div>' : App.empty('users', 'No spending')}</div></div>
          <div class="card"><div class="card-head"><h3>Work type</h3></div><div class="card-body">${d.by_work_type.length ? '<div class="chart-box sm"><canvas id="ch-work"></canvas></div>' : App.empty('chart-pie', 'No reports')}</div></div>
        </div>
        <div class="grid g-main">
          <div class="card"><div class="card-head"><h3>Reports by task status</h3><a href="#/reports" class="small">All reports</a></div>
            <div class="card-body">${rp.n ? '<div class="chart-box"><canvas id="ch-rep"></canvas></div>' : App.empty('clipboard', 'No reports in this period')}</div></div>
          <div class="card"><div class="card-head"><h3>Reports by school</h3><a href="#/issues" class="small">Issues board</a></div>
            <div class="card-body">${d.by_location.length ? '<div class="chart-box"><canvas id="ch-loc"></canvas></div>' : App.empty('school', 'No reports')}
            ${d.faulty_by_location.some((f) => +f.laptops_faulty) ? `<div class="sec-title">Faulty laptops (latest)</div><div class="chips">${d.faulty_by_location.filter((f) => +f.laptops_faulty).map((f) =>
              `<span class="pill bad">${esc(f.location)}: ${f.laptops_faulty}/${f.laptops_total || '?'}</span>`).join('')}</div>` : ''}</div></div>
        </div>`;

      $$('#db-range button', ctx.el).forEach((b) => b.addEventListener('click', () => { range = b.dataset.r; App.store.set('dt-range', range); ctx.refresh(); }));
      $$('[data-txn]', ctx.el).forEach((r) => r.addEventListener('click', () => App.openTxn(+r.dataset.txn)));
      $$('[data-q]', ctx.el).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); App.review([+b.closest('[data-txn]').dataset.txn], b.dataset.q); }));

      // Money chart
      const keys = buckets(d.from, d.to, d.by_day);
      const byKey = Object.fromEntries(d.series.map((s) => [s.b, s]));
      App.chart($('#ch-money', ctx.el), {
        data: {
          labels: keys.map((k) => bucketLabel(k, d.by_day)),
          datasets: [
            { type: 'bar', label: 'Sent', data: keys.map((k) => +(byKey[k]?.sent || 0)), backgroundColor: pal[1], borderRadius: 4, order: 2 },
            { type: 'bar', label: 'Spent', data: keys.map((k) => +(byKey[k]?.spent || 0)), backgroundColor: pal[2], borderRadius: 4, order: 2 },
            { type: 'line', label: 'Net', data: keys.map((k) => +(byKey[k]?.sent || 0) - +(byKey[k]?.spent || 0) + +(byKey[k]?.adjust || 0)), borderColor: pal[0], backgroundColor: pal[0], tension: 0.3, pointRadius: keys.length > 31 ? 0 : 2, order: 1 },
          ],
        },
        options: { interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${money(c.parsed.y)}` } } },
          scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { ticks: { callback: (v) => moneyShort(v) } } } },
      });
      if (d.categories.length) {
        App.chart($('#ch-cat', ctx.el), {
          type: 'doughnut',
          data: { labels: d.categories.map((c) => c.label), datasets: [{ data: d.categories.map((c) => +c.value), backgroundColor: pal, borderWidth: 2, borderColor: App.cssVar('--surface') }] },
          options: { cutout: '64%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => `${c.label}: ${money(c.parsed)}` } } } },
        });
      }
      if (d.top_spenders.length) {
        App.chart($('#ch-spend', ctx.el), {
          type: 'bar',
          data: { labels: d.top_spenders.map((s) => s.label), datasets: [{ data: d.top_spenders.map((s) => +s.value), backgroundColor: pal[2], borderRadius: 4 }] },
          options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => money(c.parsed.x) } } }, scales: { x: { ticks: { callback: (v) => moneyShort(v) } }, y: { grid: { display: false } } } },
        });
      }
      if (d.by_work_type.length) {
        App.chart($('#ch-work', ctx.el), {
          type: 'doughnut',
          data: { labels: d.by_work_type.map((c) => c.label || 'Not set'), datasets: [{ data: d.by_work_type.map((c) => +c.value), backgroundColor: pal, borderWidth: 2, borderColor: App.cssVar('--surface') }] },
          options: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } },
        });
      }
      if (rp.n) {
        const statusKeys = [['Yes – completed', 'Completed', pal[1]], ['Partially completed', 'Partial', pal[2]], ['No – not completed', 'Not completed', App.cssVar('--bad')]];
        App.chart($('#ch-rep', ctx.el), {
          type: 'bar',
          data: {
            labels: keys.map((k) => bucketLabel(k, d.by_day)),
            datasets: statusKeys.map(([s, l, c]) => ({ label: l, backgroundColor: c, borderRadius: 3, data: keys.map((k) => +(d.rep_series.find((x) => x.b === k && x.finished === s)?.n || 0)) })),
          },
          options: { interaction: { mode: 'index', intersect: false }, scales: { x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { stacked: true, ticks: { precision: 0 } } } },
        });
      }
      if (d.by_location.length) {
        App.chart($('#ch-loc', ctx.el), {
          type: 'bar',
          data: { labels: d.by_location.map((x) => x.label || 'Not set'), datasets: [{ data: d.by_location.map((x) => +x.value), backgroundColor: pal[0], borderRadius: 4 }] },
          options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { precision: 0 } }, y: { grid: { display: false } } } },
        });
      }
    };
    ctx.refresh = () => { App.destroyCharts(); load().catch(() => {}); };
    await load();
  }, { title: 'Overview', admin: true, live: true });

  /* ---------------------------------------------------------- staff setup */
  const genPass = () => {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const r = crypto.getRandomValues(new Uint32Array(10));
    return Array.from(r, (n) => a[n % a.length]).join('');
  };

  App.route('staff', async (ctx) => {
    const load = async () => {
      const items = (await api('users_list')).items;
      const noPass = items.filter((u) => !u.has_password && u.active);
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>Staff setup</h1><p class="muted">${items.filter((u) => u.active).length} active accounts</p></div>
          <div class="actions"><button class="btn primary" id="st-add"><i class="ti ti-user-plus"></i>Add staff</button></div></div>
        ${noPass.length ? `<div class="banner" style="margin-bottom:14px"><i class="ti ti-key"></i>${noPass.length} account${noPass.length > 1 ? 's have' : ' has'} no password yet (created by import). Set one so they can sign in.</div>` : ''}
        <div class="card"><div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th class="hide-sm">Email</th><th class="hide-sm">Phone</th><th>School</th><th class="r">Balance</th><th class="hide-sm">Last report</th><th>Status</th><th></th></tr></thead><tbody>
          ${items.map((u) => `<tr class="click" data-u="${u.id}" style="${u.active ? '' : 'opacity:.55'}">
            <td><div style="display:flex;gap:8px;align-items:center"><div class="avatar sm">${esc(App.initials(u.name))}</div><div><div style="font-weight:500">${esc(u.name)}</div><div class="faint small mobile-only">${esc(u.email)}</div></div></div></td>
            <td class="hide-sm">${esc(u.email)}</td><td class="hide-sm">${esc(u.phone)}</td><td>${esc(u.location)}</td>
            <td class="r num ${u.balance < 0 ? 'bad' : ''}">${money(u.balance)}</td>
            <td class="hide-sm">${u.last_report ? esc(fmtDate(u.last_report)) : '<span class="faint">—</span>'}</td>
            <td>${u.role === 'admin' ? '<span class="pill brand">Admin</span>' : ''} ${!u.active ? '<span class="pill">Inactive</span>' : !u.has_password ? '<span class="pill out">No password</span>' : u.last_login ? `<span class="faint small">${esc(ago(u.last_login))}</span>` : '<span class="pill info">Never signed in</span>'}</td>
            <td><i class="ti ti-chevron-right faint"></i></td></tr>`).join('')}</tbody></table></div></div>`;
      $('#st-add', ctx.el).addEventListener('click', () => editUser(null));
      $$('[data-u]', ctx.el).forEach((r) => r.addEventListener('click', () => editUser(items.find((u) => u.id === +r.dataset.u))));
    };
    function editUser(u) {
      const isNew = !u;
      u = u || { name: '', email: '', phone: '', location: '', role: 'staff', active: 1 };
      const el = App.modal(isNew ? 'Add staff' : `Edit ${esc(u.name)}`, `
        <form id="us-form" novalidate>
          <div class="form-row"><div class="field"><label for="us-n">Full name <span class="req">*</span></label><input id="us-n" name="name" value="${esc(u.name)}" autofocus></div>
            <div class="field"><label for="us-e">Email <span class="req">*</span></label><input type="email" id="us-e" name="email" value="${esc(u.email)}" placeholder="name@devtech.ng"></div></div>
          <div class="form-row"><div class="field"><label for="us-p">Phone</label><input type="tel" id="us-p" name="phone" value="${esc(u.phone)}" placeholder="0803 000 0000"></div>
            <div class="field"><label for="us-l">Assigned school / location</label><select id="us-l" name="location">${App.opts(App.cfg.locations.concat(u.location && !App.cfg.locations.includes(u.location) ? [u.location] : []), u.location, { placeholder: 'None' })}</select></div></div>
          <div class="form-row"><div class="field" data-field="role"><div class="label">Role</div><div class="opts">
            <label class="opt"><input type="radio" name="role" value="staff" ${u.role !== 'admin' ? 'checked' : ''}><span>Staff</span></label>
            <label class="opt"><input type="radio" name="role" value="admin" ${u.role === 'admin' ? 'checked' : ''}><span>Admin</span></label></div></div>
            <div class="field"><div class="label">Status</div><label class="check-row" style="padding:9px 12px"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}><span>Active (can sign in)</span></label></div></div>
          <div class="field"><label for="us-pw">${isNew ? 'Starting password' : 'Set a new password'} ${isNew ? '<span class="req">*</span>' : ''}</label>
            <div style="display:flex;gap:8px"><input type="text" id="us-pw" name="password" placeholder="${isNew ? 'At least 8 characters' : 'Leave blank to keep the current one'}" autocomplete="new-password">
            <button type="button" class="btn" id="us-gen" title="Generate"><i class="ti ti-wand"></i></button></div>
            <div class="help">Share it with them privately. They can change it under Profile.</div></div>
          <label class="check-row"><input type="checkbox" name="send_welcome" ${isNew ? 'checked' : ''}><span>Email them their sign-in details (when a password is set and email is configured)</span></label>
        </form>`, `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="us-save">${isNew ? 'Create account' : 'Save'}</button>`);
      const form = $('#us-form', el);
      $('#us-gen', el).addEventListener('click', () => { form.password.value = genPass(); });
      $('#us-save', el).addEventListener('click', async (ev) => {
        const d = App.formData(form);
        if (!isNew) d.id = u.id;
        try {
          await App.busy(ev.currentTarget, () => api('user_save', { data: d }));
          App.closeAll();
          toast(isNew ? 'Account created' : 'Saved');
          if (d.password) App.confirm('Password set', `Share these with ${esc(d.name)}:<br><br><b>Email:</b> ${esc(d.email)}<br><b>Password:</b> <code>${esc(d.password)}</code>`, { ok: 'Done' });
          load();
        } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
      });
    }
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Staff setup', admin: true });

  /* --------------------------------------------------------------- import */
  App.route('import', async (ctx) => {
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Import history</h1><p class="muted">Bring in your Google Sheets data. Re-importing the same file skips rows that are already in.</p></div></div>
      <div class="grid g2">
        <div class="card"><div class="card-head"><h3><i class="ti ti-clipboard-text"></i> Daily work reports</h3></div><div class="card-body">
          <ol class="muted small" style="padding-left:18px;margin:0 0 12px">
            <li>Open the "Daily Staff Work Progress Report" sheet.</li>
            <li>Go to the <b>Form responses 1</b> tab, then File → Download → Comma-separated values (.csv).</li>
            <li>Upload it here. Staff accounts are created from the email column (set their passwords afterwards in Staff setup).</li></ol>
          <form id="im-rep"><input type="file" name="csv" accept=".csv,text/csv,.tsv" required>
          <button class="btn primary" style="margin-top:12px" type="submit"><i class="ti ti-upload"></i>Import reports</button></form>
          <div id="im-rep-out" style="margin-top:12px"></div></div></div>
        <div class="card"><div class="card-head"><h3><i class="ti ti-wallet"></i> Expense console history</h3></div><div class="card-body">
          <ol class="muted small" style="padding-left:18px;margin:0 0 12px">
            <li>Open your expense console sheet and download each tab (payments, expenses, adjustments) as CSV.</li>
            <li>Upload one file, match its columns, check the preview, then import.</li>
            <li>Add staff accounts first so names can be matched.</li></ol>
          <form id="im-exp"><input type="file" name="csv" accept=".csv,text/csv,.tsv" required>
          <button class="btn primary" style="margin-top:12px" type="submit"><i class="ti ti-table-import"></i>Preview file</button></form>
          <div id="im-exp-out" style="margin-top:12px"></div></div></div>
      </div>`;
    const summary = (r, extra = '') => `<div class="note-box" style="white-space:normal"><b class="in"><i class="ti ti-circle-check"></i> ${r.added} added</b> · ${r.skipped} skipped${extra}
      ${r.users_created?.length ? `<div style="margin-top:8px"><b>${r.users_created.length} staff accounts created</b> (no password yet): ${r.users_created.map(esc).join(', ')}. <a href="#/staff">Set passwords</a></div>` : ''}
      ${r.unknown_staff?.length ? `<div style="margin-top:8px" class="bad"><b>Unmatched staff names</b> (rows skipped): ${r.unknown_staff.map(esc).join(', ')}. Add them in <a href="#/staff">Staff setup</a> and import again.</div>` : ''}
      ${r.unmatched_columns?.length ? `<div style="margin-top:8px" class="faint small">Columns kept as extra answers: ${r.unmatched_columns.map(esc).join('; ')}</div>` : ''}
      ${r.problems?.length ? `<div style="margin-top:8px" class="small bad">${r.problems.map(esc).join('<br>')}</div>` : ''}</div>`;

    $('#im-rep', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target.csv.files[0];
      if (!f) { toast('Choose a CSV file first.', true); return; }
      try {
        const r = await App.busy(e.submitter, () => api('import_reports', { data: {}, files: [{ field: 'csv', file: f }] }));
        $('#im-rep-out', ctx.el).innerHTML = summary(r);
        toast(`${r.added} reports imported`);
      } catch (err) { App.fail(err); }
    });

    $('#im-exp', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const f = e.target.csv.files[0];
      if (!f) { toast('Choose a CSV file first.', true); return; }
      let p;
      try { p = await App.busy(e.submitter, () => api('import_expenses_preview', { data: {}, files: [{ field: 'csv', file: f }] })); } catch (err) { App.fail(err); return; }
      const cols = p.header.map((h, i) => [i, h || `Column ${i + 1}`]);
      const sel = (k, label, req = false) => `<div class="field"><label>${label}${req ? ' <span class="req">*</span>' : ''}</label><select name="${k}">${App.opts(cols, p.guess[k] ?? '', { placeholder: '— not in file —' })}</select></div>`;
      const out = $('#im-exp-out', ctx.el);
      out.innerHTML = `<form id="im-map" class="note-box" style="white-space:normal"><b>${p.total} rows found.</b> Match the columns:
        <div class="form-row" style="margin-top:10px">${sel('date', 'Date', true)}${sel('amount', 'Amount', true)}</div>
        <div class="form-row">${sel('staff', 'Staff name')}${sel('email', 'Staff email')}</div>
        <div class="form-row">${sel('kind', 'Type (sent / spent / adjustment)')}${sel('category', 'Category')}</div>
        <div class="form-row">${sel('description', 'Purpose / description')}${sel('receipt', 'Receipt link')}</div>
        <div class="form-row"><div class="field"><label>If there's no type column, every row is…</label><select name="fixed_kind">${App.opts([['credit', 'Money sent to staff'], ['debit', 'Staff expense'], ['adjust', 'Adjustment (+/-)']], '', { placeholder: 'Use the type column' })}</select></div>
          <div class="field"><label>Dates are written</label><select name="date_order">${App.opts([['dmy', 'Day first (13/01/2026)'], ['mdy', 'Month first (01/13/2026)']], 'dmy')}</select></div></div>
        <div class="table-wrap" style="max-height:240px;margin:6px 0 12px;background:var(--surface);border-radius:8px"><table class="tbl"><thead><tr>${p.header.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>
          <tbody>${p.rows.map((r) => `<tr>${p.header.map((_, i) => `<td class="ellipsis">${esc(r[i] ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>
        <button class="btn primary" type="submit"><i class="ti ti-database-import"></i>Import ${p.total} rows</button></form>`;
      $('#im-map', out).addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const d = App.formData(ev.target);
        const map = {};
        ['date', 'amount', 'staff', 'email', 'kind', 'category', 'description', 'receipt'].forEach((k) => { if (d[k] !== '') map[k] = +d[k]; });
        try {
          const r = await App.busy(ev.submitter, () => api('import_expenses_commit', { data: { token: p.token, map, fixed_kind: d.fixed_kind, date_order: d.date_order } }));
          out.innerHTML = summary(r);
          toast(`${r.added} transactions imported`);
        } catch (err) { App.fail(err); }
      });
    });
  }, { title: 'Import history', admin: true });

  /* ------------------------------------------------------------- settings */
  App.route('settings', async (ctx) => {
    const s = (await api('settings_get')).settings;
    const list = (k, label, help) => `<div class="field"><label for="se-${k}">${label}</label><textarea id="se-${k}" name="${k}" rows="6">${esc((s[k] || []).join('\n'))}</textarea><div class="help">${help}</div></div>`;
    const sm = s.smtp || {};
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Settings</h1></div></div>
      <form class="card card-pad" id="se-co" style="margin-bottom:14px"><div style="display:flex;gap:14px;align-items:center;margin-bottom:14px">
        <img src="assets/logo.png" alt="" class="login-logo" style="height:48px;border:1px solid var(--border)"><div><h3>Company</h3><p class="muted small">Shown on the sign-in page, emails and the sidebar.</p></div></div>
        <div class="form-row"><div class="field"><label for="se-cn">Company name</label><input id="se-cn" name="company_name" value="${esc(s.company_name || '')}"></div>
          <div class="field"><label for="se-ca">Address</label><input id="se-ca" name="company_address" value="${esc(s.company_address || '')}"></div></div>
        <button class="btn primary" type="submit">Save company details</button></form>
      <div class="grid g2">
        <form class="card card-pad" id="se-lists"><h3 style="margin-bottom:14px">Lists</h3>
          ${list('locations', 'Schools / locations', 'One per line. Used in reports and staff profiles.')}
          ${list('expense_categories', 'Expense categories', 'One per line. Staff pick from these when logging expenses.')}
          ${list('credit_types', 'Payment types', 'One per line, e.g. Daily allowance, Work budget, Top-up.')}
          <div class="field"><label for="se-al">Default daily allowance (${esc(App.cfg.currency)})</label><input type="number" id="se-al" name="daily_allowance_default" value="${esc(s.daily_allowance_default || '')}" min="0" step="0.01"><div class="help">Pre-fills the amount on Send payment.</div></div>
          <button class="btn primary" type="submit">Save lists</button></form>
        <form class="card card-pad" id="se-smtp"><h3 style="margin-bottom:4px">Email notifications</h3>
          <p class="muted small" style="margin-bottom:14px">Use an email account from cPanel → Email Accounts → Connect Devices for these details.</p>
          <label class="check-row" style="margin-bottom:14px"><input type="checkbox" name="enabled" ${sm.enabled ? 'checked' : ''}><span>Send email notifications</span></label>
          <div class="form-row"><div class="field"><label>SMTP host</label><input name="host" value="${esc(sm.host || '')}" placeholder="mail.devtech.ng"></div>
            <div class="field"><label>Port</label><input type="number" name="port" value="${esc(sm.port || 465)}"></div></div>
          <div class="form-row"><div class="field"><label>Security</label><select name="secure">${App.opts([['ssl', 'SSL (port 465)'], ['tls', 'STARTTLS (port 587)'], ['none', 'None']], sm.secure || 'ssl')}</select></div>
            <div class="field"><label>Username</label><input name="user" value="${esc(sm.user || '')}" autocomplete="off"></div></div>
          <div class="form-row"><div class="field"><label>Password</label><input type="password" name="pass" value="${esc(sm.pass || '')}" autocomplete="new-password"></div>
            <div class="field"><label>From email</label><input type="email" name="from_email" value="${esc(sm.from_email || '')}" placeholder="portal@devtech.ng"></div></div>
          <div class="field"><label>From name</label><input name="from_name" value="${esc(sm.from_name || '')}"></div>
          <div class="actions"><button class="btn primary" type="submit">Save email settings</button><button class="btn" type="button" id="se-test"><i class="ti ti-mail-forward"></i>Send test email</button></div></form>
      </div>`;
    $('#se-co', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = App.formData(e.target);
      try {
        await App.busy(e.submitter, () => api('settings_save', { data: d }));
        App.cfg = (await api('session')).config;
        const bn = $('.sidebar .brand-name');
        if (bn) bn.textContent = App.cfg.company;
        toast('Company details saved');
      } catch (err) { App.fail(err); }
    });
    $('#se-lists', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = App.formData(e.target);
      const split = (v) => v.split('\n').map((x) => x.trim()).filter(Boolean);
      try {
        await App.busy(e.submitter, () => api('settings_save', { data: { locations: split(d.locations), expense_categories: split(d.expense_categories), credit_types: split(d.credit_types), daily_allowance_default: d.daily_allowance_default } }));
        Object.assign(App.cfg, { locations: split(d.locations), categories: split(d.expense_categories), credit_types: split(d.credit_types), allowance: +d.daily_allowance_default || 0 });
        const sess = await api('session');
        App.cfg = sess.config;
        toast('Settings saved');
      } catch (err) { App.fail(err); }
    });
    $('#se-smtp', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await App.busy(e.submitter, () => api('settings_save', { data: { smtp: App.formData(e.target) } })); toast('Email settings saved'); } catch (err) { App.fail(err); }
    });
    $('#se-test', ctx.el).addEventListener('click', async (e) => {
      try { const r = await App.busy(e.currentTarget, () => api('smtp_test', { data: {} })); toast(r.message); } catch (err) { App.fail(err); }
    });
  }, { title: 'Settings', admin: true });

  /* -------------------------------------------------------------- profile */
  App.route('profile', async (ctx) => {
    const u = App.user;
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Profile</h1></div><div class="actions"><button class="btn" id="pf-out"><i class="ti ti-logout"></i>Sign out</button></div></div>
      <div class="grid g2">
        <form class="card card-pad" id="pf-form"><div style="display:flex;gap:12px;align-items:center;margin-bottom:16px"><div class="avatar" style="width:48px;height:48px;font-size:17px">${esc(App.initials(u.name))}</div>
          <div><div style="font-weight:600;font-size:16px">${esc(u.name)}</div><div class="muted">${esc(u.email)}</div></div></div>
          <div class="field"><label for="pf-p">Phone</label><input type="tel" id="pf-p" name="phone" value="${esc(u.phone || '')}"></div>
          <div class="field"><label for="pf-l">My school / location</label><select id="pf-l" name="location">${App.opts(App.cfg.locations, u.location, { placeholder: 'None' })}</select><div class="help">Pre-fills your daily report.</div></div>
          <button class="btn primary" type="submit">Save</button></form>
        <form class="card card-pad" id="pw-form" novalidate><h3 style="margin-bottom:14px">Change password</h3>
          <div class="field"><label for="pw-c">Current password</label><input type="password" id="pw-c" name="current" autocomplete="current-password"></div>
          <div class="field"><label for="pw-n">New password</label><input type="password" id="pw-n" name="new" autocomplete="new-password" placeholder="At least 8 characters"></div>
          <div class="field"><label for="pw-n2">Repeat new password</label><input type="password" id="pw-n2" name="new2" autocomplete="new-password"></div>
          <button class="btn primary" type="submit">Change password</button></form>
        <div class="card card-pad"><h3 style="margin-bottom:10px">Appearance</h3><button class="btn" id="pf-theme"><i class="ti ti-moon"></i>Toggle dark mode</button></div>
      </div>`;
    $('#pf-out', ctx.el).addEventListener('click', App.logout);
    $('#pf-theme', ctx.el).addEventListener('click', App.toggleTheme);
    $('#pf-form', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      try { const r = await App.busy(e.submitter, () => api('profile_save', { data: App.formData(e.target) })); App.user = { ...App.user, ...r.user }; toast('Profile saved'); } catch (err) { App.fail(err); }
    });
    $('#pw-form', ctx.el).addEventListener('submit', async (e) => {
      e.preventDefault();
      const d = App.formData(e.target);
      const errs = {};
      if (!d.current) errs.current = 'Enter your current password.';
      if ((d.new || '').length < 8) errs.new = 'Use at least 8 characters.';
      if (d.new !== d.new2) errs.new2 = 'The passwords don\'t match.';
      if (Object.keys(errs).length) { App.showErrors(e.target, errs); return; }
      try { await App.busy(e.submitter, () => api('password_change', { data: d })); e.target.reset(); App.showErrors(e.target, null); toast('Password changed'); } catch (err) { App.fail(err); }
    });
  }, { title: 'Profile' });

  App.start();
})();
