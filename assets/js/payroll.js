/* DevTech Staff Portal — payroll: salary less attendance charges, plus approved overtime and adjustments */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, isAdmin } = App;
  const timeOf = (dt) => (dt ? new Date(dt.replace(' ', 'T')).toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }) : '');
  const monthLabel = (m) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  const kpi = (label, value, sub, icon, tone) => `<div class="card kpi"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
  const monthPicker = (m) => `<label class="small muted" style="display:flex;gap:6px;align-items:center">Month <input type="month" name="month" value="${esc(m)}" style="width:auto"></label>`;
  const OT_PILL = { approved: ['Overtime approved', 'in'], rejected: ['Overtime rejected', ''], pending: ['Overtime awaiting approval', 'info'] };

  /** Day-by-day charges and overtime; admins get buttons to excuse or approve. */
  function breakdownHtml(c, admin) {
    const editable = admin && !c.locked;
    const btn = (kind, on, label, icon) => `<button class="btn sm ${on ? 'primary' : 'ghost'}" data-mark="${kind}" data-on="${on ? 0 : 1}"><i class="ti ti-${icon}"></i>${label}</button>`;
    const days = c.days.map((d) => {
      const has = (k) => d.marks.includes(k);
      const items = d.items.map((i) => `<div style="display:flex;justify-content:space-between;gap:10px">
        <span>${esc(i.label)}${i.waived ? ` <span class="pill in" title="${esc(i.waived)}">Waived</span><div class="faint small">${esc(i.waived)}</div>` : ''}${i.note ? `<div class="faint small">${esc(i.note)}</div>` : ''}</span>
        <span class="num ${i.amount < 0 ? 'bad' : 'faint'}" style="white-space:nowrap">${i.waived ? `<s>${money(-i.charge)}</s>` : money(i.amount)}</span></div>`).join('');
      const ot = d.overtime ? `<div style="display:flex;justify-content:space-between;gap:10px"><span>Worked late, out ${esc(timeOf(d.overtime.out_at))} <span class="pill ${OT_PILL[d.overtime.state][1]}">${OT_PILL[d.overtime.state][0]}</span></span>
        <span class="num ${d.overtime.amount ? 'in' : 'faint'}">${money(d.overtime.amount, { sign: true })}</span></div>` : '';
      const codes = d.items.map((i) => i.code);
      const actions = !editable ? '' : [
        d.status === 'absent' ? btn('absence_approved', has('absence_approved'), 'Approved absence', 'calendar-check') + btn('absence_notified', has('absence_notified'), 'Notified / forgot clock-in', 'message') : '',
        codes.includes('late') || codes.includes('very_late') ? btn('late_approved', has('late_approved'), 'Late permission', 'clock-check') : '',
        codes.includes('report') ? btn('report_waived', has('report_waived'), 'Waive report charge', 'file-check') : '',
        d.overtime ? btn('overtime_approved', has('overtime_approved'), 'Approve overtime', 'check') + btn('overtime_rejected', has('overtime_rejected'), 'Reject overtime', 'x') : '',
      ].join('');
      return `<li class="li" style="display:block" data-day="${d.date}"><div class="t" style="margin-bottom:4px">${esc(fmtDate(d.date))}${d.in ? ` <span class="faint small">· in ${esc(timeOf(d.in))}${d.out ? `, out ${esc(timeOf(d.out))}` : ''}</span>` : ''}</div>
        <div class="small" style="display:grid;gap:4px">${items}${ot}</div>${actions ? `<div class="actions" style="margin-top:8px;flex-wrap:wrap">${actions}</div>` : ''}</li>`;
    }).join('');
    const adj = c.adjustments.map((a) => `<li class="li"><div class="main-col"><div class="t">${esc(a.label)}</div><div class="s">${esc(a.note || '')}${a.by ? `${a.note ? ' · ' : ''}by ${esc(a.by)}` : ''}</div></div>
      <span class="num ${a.amount < 0 ? 'bad' : 'in'}">${money(a.amount, { sign: true })}</span>${editable ? `<button class="btn sm ghost" data-adj-del="${a.id}" aria-label="Remove"><i class="ti ti-trash"></i></button>` : ''}</li>`).join('');
    return `
      <div class="card card-pad" style="margin-bottom:14px">
        <div style="display:grid;grid-template-columns:1fr auto;gap:6px 14px" class="num">
          <span>Basic salary</span><b>${money(c.salary)}</b>
          <span>Lateness (${c.counts.late} day${c.counts.late === 1 ? '' : 's'})</span><span class="bad">${money(-c.totals.late)}</span>
          <span>Absences (${c.counts.absence})</span><span class="bad">${money(-c.totals.absence)}</span>
          <span>Missed / late daily reports (${c.counts.report})</span><span class="bad">${money(-c.totals.report)}</span>
          <span>Overtime (${c.counts.overtime} day${c.counts.overtime === 1 ? '' : 's'})${c.counts.overtime_pending ? ` <span class="pill info">${c.counts.overtime_pending} awaiting approval</span>` : ''}</span><span class="in">${money(c.overtime, { sign: true })}</span>
          <span>Adjustments</span><span class="${c.adjustments_total < 0 ? 'bad' : ''}">${money(c.adjustments_total, { sign: true })}</span>
          <span style="border-top:1px solid var(--border);padding-top:6px;font-weight:600">Net pay</span><b style="border-top:1px solid var(--border);padding-top:6px;font-size:18px" class="${c.net < 0 ? 'bad' : ''}">${money(c.net)}</b>
        </div>
        ${c.locked ? `<div class="note-box small" style="margin-top:10px"><i class="ti ti-lock"></i> Locked on ${esc(fmtDate(c.locked_at))}. These figures are final.</div>` : ''}
        ${c.rules_from > c.month + '-01' ? `<div class="faint small" style="margin-top:8px">Charges apply from ${esc(fmtDate(c.rules_from, false))}.</div>` : ''}
      </div>
      <h3 style="margin:0 0 8px">Day by day</h3>
      <div class="card" style="margin-bottom:14px">${days ? `<ul class="list">${days}</ul>` : App.empty('mood-smile', 'No charges or overtime', 'Every working day so far was on time with a report.')}</div>
      <h3 style="margin:0 0 8px">Adjustments</h3>
      <div class="card" style="margin-bottom:14px">${adj ? `<ul class="list">${adj}</ul>` : '<p class="muted small card-pad">None this month.</p>'}</div>
      ${editable ? `<form class="card card-pad" id="pr-adj" novalidate><h3 style="margin-bottom:10px">Add a charge or bonus</h3>
        <button type="button" class="btn sm" data-preset="job" style="margin-bottom:10px"><i class="ti ti-clipboard-x"></i>Job order failure</button>
        <div class="form-row"><div class="field"><label>What for</label><input name="label" placeholder="e.g. Job order failure"></div>
          <div class="field"><label>Amount (${esc(App.cfg.currency)})</label><input type="number" name="amount" step="0.01" placeholder="-3000"><div class="help">Negative to deduct, positive to add.</div></div></div>
        <div class="field"><label>Note</label><input name="note" placeholder="Which job, what happened"></div>
        <button class="btn primary" type="submit">Add</button></form>` : ''}`;
  }

  /** Admin drawer for one staff member's month. */
  async function payDrawer(uid, month, onChange) {
    const el = App.drawer('Payroll', App.loading());
    const load = async () => {
      const c = await api('payroll_detail', { query: { user_id: uid, month } });
      $('.drawer-head h2', el).textContent = `${c.user.name} · ${monthLabel(month)}`;
      const body = $('.drawer-body', el);
      body.innerHTML = (c.locked ? '' : `<form class="card card-pad" id="pr-sal" style="margin-bottom:14px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <div class="field" style="margin:0;flex:1;min-width:160px"><label>Monthly salary (${esc(App.cfg.currency)})</label><input type="number" name="salary" min="0" step="0.01" value="${esc(c.salary || '')}"></div>
          <button class="btn" type="submit">Save salary</button></form>`) + breakdownHtml(c, true)
        + `<div class="actions" style="margin-top:14px"><button class="btn" id="pr-lock1"><i class="ti ti-${c.locked ? 'lock-open' : 'lock'}"></i>${c.locked ? 'Unlock' : 'Lock'} this staff member's month</button>
          <a class="btn ghost" href="#/staff/${uid}"><i class="ti ti-user"></i>Profile</a></div>`;
      const changed = async () => { await load(); onChange && onChange(); };
      $('#pr-sal', body)?.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await App.busy(e.submitter, () => api('payroll_salary', { data: { user_id: uid, salary: App.formData(e.target).salary } })); toast('Salary saved'); await changed(); } catch (err) { App.fail(err); }
      });
      $$('[data-mark]', body).forEach((b) => b.addEventListener('click', async () => {
        const date = b.closest('[data-day]').dataset.day;
        const on = b.dataset.on === '1';
        const kind = b.dataset.mark;
        let note = '';
        if (on && !kind.startsWith('overtime')) {
          note = await App.confirm(b.textContent.trim(), `For ${esc(fmtDate(date))}.`, { ok: 'Save', input: { label: 'Note', required: true, placeholder: 'e.g. Called in sick at 7am', requiredMsg: 'Say why.' } });
          if (note === null) return;
        }
        try { await App.busy(b, () => api('payroll_mark', { data: { user_id: uid, date, kind, on, note } })); await changed(); } catch (err) { App.fail(err); }
      }));
      $$('[data-adj-del]', body).forEach((b) => b.addEventListener('click', async () => {
        if (!(await App.confirm('Remove adjustment', 'Remove this charge or bonus?', { ok: 'Remove', danger: true }))) return;
        try { await api('payroll_adjust_delete', { data: { id: +b.dataset.adjDel } }); await changed(); } catch (err) { App.fail(err); }
      }));
      const form = $('#pr-adj', body);
      if (form) {
        $('[data-preset=job]', form).addEventListener('click', () => {
          form.label.value = 'Job order failure';
          form.amount.value = -(c.rules.job_failure || 3000);
          form.note.focus();
        });
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          try { await App.busy(e.submitter, () => api('payroll_adjust', { data: { ...App.formData(form), user_id: uid, month } })); toast('Added'); await changed(); } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
        });
      }
      $('#pr-lock1', body).addEventListener('click', async (e) => {
        try { await App.busy(e.currentTarget, () => api('payroll_lock', { data: { user_id: uid, month, lock: !c.locked } })); await changed(); } catch (err) { App.fail(err); }
      });
    };
    try { await load(); } catch (e) { App.closeTop(); App.fail(e); }
  }

  App.route('payroll', async (ctx) => {
    let month = App.store.get('dt-paymonth', App.cfg.today.slice(0, 7));
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Payroll</h1><p class="muted">Salary less attendance charges, plus approved overtime. Rules are set in <a href="#/settings">Settings → Pay rules</a>.</p></div>
        <div class="actions"><a class="btn" id="pr-export"><i class="ti ti-download"></i>Export CSV</a><button class="btn primary" id="pr-lock"><i class="ti ti-lock"></i>Lock month</button></div></div>
      <div class="filters" id="pr-filters">${monthPicker(month)}</div>
      <div id="pr-body">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('payroll_list', { query: { month } });
      $('#pr-export', ctx.el).href = `api.php?action=payroll_export&month=${month}`;
      const allLocked = r.rows.length && r.rows.every((x) => x.locked);
      const lockBtn = $('#pr-lock', ctx.el);
      lockBtn.innerHTML = `<i class="ti ti-${allLocked ? 'lock-open' : 'lock'}"></i>${allLocked ? 'Unlock month' : 'Lock month'}`;
      lockBtn.dataset.lock = allLocked ? '0' : '1';
      const sum = (k) => r.rows.reduce((a, x) => a + x[k], 0);
      const noSalary = r.rows.filter((x) => !x.salary).length;
      $('#pr-body', ctx.el).innerHTML = `
        ${r.pending.length ? `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3><i class="ti ti-clock-plus"></i> Overtime awaiting approval (${r.pending.length})</h3>
          <div class="actions"><button class="btn sm" data-ot="reject">Reject selected</button><button class="btn sm primary" data-ot="approve">Approve selected</button></div></div>
          <div class="table-wrap"><table class="tbl"><thead><tr><th><input type="checkbox" id="ot-all" checked aria-label="Select all"></th><th>Staff</th><th>Date</th><th>Clocked out</th><th class="r">Pay</th></tr></thead><tbody>
          ${r.pending.map((p, i) => `<tr><td><input type="checkbox" data-ot-i="${i}" checked></td><td>${esc(p.name)}</td><td class="num">${esc(fmtDate(p.date, false))}</td>
            <td class="num">${esc(timeOf(p.out_at))}</td><td class="r num">${money(r.rules.overtime)}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Total salaries', money(sum('salary'), { short: true }), noSalary ? `${noSalary} staff with no salary set` : `${r.rows.length} staff`, 'users', noSalary ? 'tone-out' : 'tone-brand')}
          ${kpi('Deductions', money(sum('deductions'), { short: true }), 'Lateness, absence, reports', 'arrow-down-right', 'tone-bad')}
          ${kpi('Overtime', money(sum('overtime'), { short: true }), `${r.pending.length} awaiting approval`, 'clock-plus', 'tone-in')}
          ${kpi('Net payroll', money(sum('net'), { short: true }), monthLabel(month), 'cash', 'tone-info')}</div>
        <div class="card">${r.rows.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Staff</th><th class="r">Salary</th><th class="r">Deductions</th><th class="r hide-sm">Overtime</th><th class="r hide-sm">Adjustments</th><th class="r">Net pay</th><th></th></tr></thead><tbody>
          ${r.rows.map((x) => `<tr class="click" data-u="${x.id}"><td><div style="font-weight:500">${esc(x.name)}</div>
              <div class="faint small">${[x.counts.late && `${x.counts.late} late`, x.counts.absence && `${x.counts.absence} absent`, x.counts.report && `${x.counts.report} report`].filter(Boolean).join(' · ') || 'No charges'}</div></td>
            <td class="r num">${x.salary ? money(x.salary) : '<span class="pill out">Not set</span>'}</td><td class="r num ${x.deductions ? 'bad' : ''}">${money(-x.deductions)}</td>
            <td class="r num hide-sm">${money(x.overtime, { sign: true })}${x.counts.overtime_pending ? ` <span class="pill info" title="Awaiting approval">+${x.counts.overtime_pending}</span>` : ''}</td>
            <td class="r num hide-sm">${money(x.adjustments, { sign: true })}</td><td class="r num"><b class="${x.net < 0 ? 'bad' : ''}">${money(x.net)}</b></td>
            <td>${x.locked ? '<i class="ti ti-lock faint" title="Locked"></i>' : ''}</td></tr>`).join('')}</tbody></table></div>`
          : App.empty('users', 'No staff on the payroll')}</div>`;
      const body = $('#pr-body', ctx.el);
      $$('[data-u]', body).forEach((row) => row.addEventListener('click', () => payDrawer(+row.dataset.u, month, () => load().catch(() => {}))));
      $('#ot-all', body)?.addEventListener('change', (e) => $$('[data-ot-i]', body).forEach((c) => { c.checked = e.target.checked; }));
      $$('[data-ot]', body).forEach((b) => b.addEventListener('click', async () => {
        const items = $$('[data-ot-i]:checked', body).map((c) => r.pending[+c.dataset.otI]).map((p) => ({ user_id: p.user_id, date: p.date }));
        if (!items.length) { toast('Tick at least one day.', true); return; }
        try {
          const res = await App.busy(b, () => api('payroll_overtime', { data: { decision: b.dataset.ot, items } }));
          toast(`${res.count} day${res.count === 1 ? '' : 's'} ${b.dataset.ot === 'reject' ? 'rejected' : 'approved'}`);
          App.poll();
          await load();
        } catch (err) { App.fail(err); }
      }));
    };
    $('#pr-filters', ctx.el).addEventListener('change', (e) => {
      if (e.target.name !== 'month' || !e.target.value) return;
      month = e.target.value;
      App.store.set('dt-paymonth', month);
      load().catch(App.fail);
    });
    $('#pr-lock', ctx.el).addEventListener('click', async (e) => {
      const lock = e.currentTarget.dataset.lock === '1';
      if (!(await App.confirm(lock ? 'Lock month' : 'Unlock month', lock
        ? `Freeze ${esc(monthLabel(month))} pay for everyone? Later attendance edits, approvals and adjustments won't change it until you unlock.`
        : `Unlock ${esc(monthLabel(month))}? Figures will be recalculated from current records.`, { ok: lock ? 'Lock' : 'Unlock' }))) return;
      try { await App.busy(e.currentTarget, () => api('payroll_lock', { data: { month, lock } })); toast(lock ? 'Month locked' : 'Month unlocked'); await load(); } catch (err) { App.fail(err); }
    });
    ctx.refresh = () => { if (!App.hasOverlay()) load().catch(() => {}); };
    await load();
  }, { title: 'Payroll', admin: true, live: true });

  App.route('my-pay', async (ctx) => {
    let month = App.cfg.today.slice(0, 7);
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>My pay</h1><p class="muted">Your salary for the month after attendance charges and approved overtime. Final figures are confirmed at month end.</p></div></div>
      <div class="filters" id="mp-filters">${monthPicker(month)}</div>
      <div id="mp-body">${App.loading()}</div>`;
    const load = async () => {
      const c = await api('payroll_detail', { query: { month } });
      const r = c.rules;
      $('#mp-body', ctx.el).innerHTML = breakdownHtml(c, false) + `<details class="card card-pad"><summary style="cursor:pointer;font-weight:500">How it's worked out</summary>
        <ul class="small muted" style="margin:10px 0 0;padding-left:18px;line-height:1.7">
          <li>Late: ${money(r.late, { short: true })} a day; more than ${r.very_late_min} minutes late: ${money(r.very_late, { short: true })} instead.</li>
          <li>Absent without approval: ${money(r.absent, { short: true })}. Failed to clock in but notified: ${money(r.absent_notified, { short: true })}.</li>
          <li>Daily report not sent by ${esc(r.report_deadline)}: ${money(r.report_missing, { short: true })}.</li>
          <li>Job order failures: ${money(r.job_failure, { short: true })}, added by your admin.</li>
          <li>Clocking out ${r.overtime_min}+ minutes after ${esc(App.cfg.work_end)}: ${money(r.overtime, { short: true })} once approved.</li>
          <li>Get permission in advance to report late or be absent; approved days aren't charged.</li></ul></details>`;
    };
    $('#mp-filters', ctx.el).addEventListener('change', (e) => {
      if (e.target.name !== 'month' || !e.target.value) return;
      month = e.target.value;
      load().catch(App.fail);
    });
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'My pay', live: true });
})();
