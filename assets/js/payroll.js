/* DevTech Staff Portal — payroll: salary less attendance charges, plus approved overtime and adjustments */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, isAdmin } = App;
  const timeOf = (dt) => (dt ? new Date(dt.replace(' ', 'T')).toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }) : '');
  const monthLabel = (m) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  const kpi = (label, value, sub, icon, tone) => `<div class="card kpi"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
  const monthPicker = (m) => `<label class="small muted" style="display:flex;gap:6px;align-items:center">Month <input type="month" name="month" value="${esc(m)}" style="width:auto"></label>`;
  const OT_PILL = { approved: ['Overtime approved', 'in'], rejected: ['Overtime rejected', ''], pending: ['Overtime awaiting approval', 'info'] };

  /** Advances given in the month; admins can remove them while the month is open. */
  function advancesHtml(list, editable) {
    if (!list.length) return '<p class="muted small card-pad">None this month.</p>';
    return `<ul class="list">${list.map((a) => `<li class="li"><div class="main-col"><div class="t">${esc(fmtDate(a.given_on, false))}</div><div class="s">${esc(a.note || '')}${a.by ? `${a.note ? ' · ' : ''}by ${esc(a.by)}` : ''}</div></div>
      <span class="num bad">${money(-a.amount)}</span>${editable ? `<button class="btn sm ghost" data-adv-del="${a.id}" aria-label="Remove"><i class="ti ti-trash"></i></button>` : ''}</li>`).join('')}</ul>`;
  }

  /** Day-by-day charges and overtime; admins get buttons to excuse or approve. */
  function breakdownHtml(c, admin) {
    const editable = admin && !c.locked;
    const btn = (kind, on, label, icon) => `<button class="btn sm ${on ? 'primary' : 'ghost'}" data-mark="${kind}" data-on="${on ? 0 : 1}"><i class="ti ti-${icon}"></i>${label}</button>`;
    const days = c.days.map((d) => {
      const has = (k) => (d.marks || []).includes(k);
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
          <span>Salary advances (${c.advances.length})</span><span class="${c.advances_total ? 'bad' : ''}">${money(-c.advances_total)}</span>
          <span style="border-top:1px solid var(--border);padding-top:6px;font-weight:600">Net pay</span><b style="border-top:1px solid var(--border);padding-top:6px;font-size:18px" class="${c.net < 0 ? 'bad' : ''}">${money(c.net)}</b>
        </div>
        ${c.locked ? `<div class="note-box small" style="margin-top:10px"><i class="ti ti-lock"></i> Locked on ${esc(fmtDate(c.locked_at))}. These figures are final.</div>` : ''}
        ${c.rules_from > c.month + '-01' ? `<div class="faint small" style="margin-top:8px">Charges apply from ${esc(fmtDate(c.rules_from, false))}.</div>` : ''}
      </div>
      <h3 style="margin:0 0 8px">Day by day</h3>
      <div class="card" style="margin-bottom:14px">${days ? `<ul class="list">${days}</ul>` : App.empty('mood-smile', 'No charges or overtime', 'Every working day so far was on time with a report.')}</div>
      <h3 style="margin:0 0 8px">Adjustments</h3>
      <div class="card" style="margin-bottom:14px">${adj ? `<ul class="list">${adj}</ul>` : '<p class="muted small card-pad">None this month.</p>'}</div>
      <h3 style="margin:0 0 8px">Salary advances</h3>
      <div class="card" style="margin-bottom:14px">${advancesHtml(c.advances, editable)}</div>
      ${editable ? `<form class="card card-pad" id="pr-adv" novalidate style="margin-bottom:14px"><h3 style="margin-bottom:10px">Give salary advance</h3>
        <div class="form-row"><div class="field"><label>Amount (${esc(App.cfg.currency)})</label><input type="number" name="amount" min="0" step="0.01" placeholder="20000"></div>
          <div class="field"><label>Date given</label><input type="date" name="given_on" value="${esc(App.cfg.today)}"></div></div>
        <div class="field"><label>Note</label><input name="note" placeholder="e.g. Transport, emergency"></div>
        <div class="help" style="margin-bottom:10px">Comes off ${esc(monthLabel(c.month))} pay. The staff member can see it and their expected balance.</div>
        <button class="btn primary" type="submit">Record advance</button></form>` : ''}
      ${editable ? `<form class="card card-pad" id="pr-adj" novalidate><h3 style="margin-bottom:10px">Add a charge or bonus</h3>
        <button type="button" class="btn sm" data-preset="job" style="margin-bottom:10px"><i class="ti ti-clipboard-x"></i>Job order failure</button>
        <div class="form-row"><div class="field"><label>What for</label><input name="label" placeholder="e.g. Job order failure"></div>
          <div class="field"><label>Amount (${esc(App.cfg.currency)})</label><input type="number" name="amount" step="0.01" placeholder="-3000"><div class="help">Negative to deduct, positive to add.</div></div></div>
        <div class="field"><label>Note</label><input name="note" placeholder="Which job, what happened"></div>
        <button class="btn primary" type="submit">Add</button></form>` : ''}`;
  }

  /** Staff view of their own month: what was charged and why, with no amounts (those are admin-only). */
  function recordHtml(c) {
    const n = c.counts;
    const plural = (k, one, many) => `${k} ${k === 1 ? one : many}`;
    const days = c.days.map((d) => {
      const items = d.items.map((i) => `<div>${esc(i.label)}${i.waived ? ` <span class="pill in" title="${esc(i.waived)}">Excused</span><div class="faint small">${esc(i.waived)}</div>` : ''}${i.note ? `<div class="faint small">${esc(i.note)}</div>` : ''}</div>`).join('');
      const ot = d.overtime ? `<div>Worked late, out ${esc(timeOf(d.overtime.out_at))} <span class="pill ${OT_PILL[d.overtime.state][1]}">${OT_PILL[d.overtime.state][0]}</span></div>` : '';
      return `<li class="li" style="display:block"><div class="t" style="margin-bottom:4px">${esc(fmtDate(d.date))}${d.in ? ` <span class="faint small">· in ${esc(timeOf(d.in))}${d.out ? `, out ${esc(timeOf(d.out))}` : ''}</span>` : ''}</div>
        <div class="small" style="display:grid;gap:4px">${items}${ot}</div></li>`;
    }).join('');
    return `
      <div class="grid g4" style="margin-bottom:14px">
        ${kpi('Late', n.late, plural(n.late, 'day', 'days'), 'clock-exclamation', n.late ? 'tone-bad' : 'tone-in')}
        ${kpi('Absent', n.absence, 'Without approval', 'calendar-x', n.absence ? 'tone-bad' : 'tone-in')}
        ${kpi('Daily reports', n.report, 'Missed or late', 'file-alert', n.report ? 'tone-bad' : 'tone-in')}
        ${kpi('Overtime', n.overtime, n.overtime_pending ? `${n.overtime_pending} awaiting approval` : 'Approved days', 'clock-plus', 'tone-info')}</div>
      ${c.locked ? `<div class="note-box small" style="margin-bottom:14px"><i class="ti ti-lock"></i> Finalised on ${esc(fmtDate(c.locked_at))}.</div>` : ''}
      ${c.rules_from > c.month + '-01' ? `<div class="faint small" style="margin-bottom:10px">Counted from ${esc(fmtDate(c.rules_from, false))}.</div>` : ''}
      <h3 style="margin:0 0 8px">Day by day</h3>
      <div class="card" style="margin-bottom:14px">${days ? `<ul class="list">${days}</ul>` : App.empty('mood-smile', 'A clean record', 'Every working day so far was on time with a report.')}</div>`;
  }

  /** Staff view before the month is published: salary less advances, with charges still to be confirmed. */
  function payCardHtml(c) {
    const adv = c.advances.map((a) => `<span>Advance · ${esc(fmtDate(a.given_on, false))}${a.note ? ` <span class="faint small">${esc(a.note)}</span>` : ''}</span><span class="bad">${money(-a.amount)}</span>`).join('');
    return `<div class="card card-pad" style="margin-bottom:14px">
        <div style="display:grid;grid-template-columns:1fr auto;gap:6px 14px" class="num">
          <span>Basic salary</span><b>${money(c.salary)}</b>
          ${adv || '<span class="muted">No salary advance this month</span><span></span>'}
          <span style="border-top:1px solid var(--border);padding-top:6px;font-weight:600">Expected balance</span><b style="border-top:1px solid var(--border);padding-top:6px;font-size:18px" class="${c.expected < 0 ? 'bad' : ''}">${money(c.expected)}</b>
        </div>
        <div class="faint small" style="margin-top:8px">Final pay is confirmed after the month-end review.</div>
      </div>`;
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
        + (c.published_at ? `<div class="note-box small" style="margin-top:10px"><i class="ti ti-eye"></i> Published to the staff member on ${esc(fmtDate(c.published_at))}.</div>` : '')
        + `<div class="actions" style="margin-top:14px;flex-wrap:wrap"><button class="btn" id="pr-lock1"><i class="ti ti-${c.locked ? 'lock-open' : 'lock'}"></i>${c.locked ? 'Unlock' : 'Lock'} this staff member's month</button>
          <button class="btn ${c.published_at ? '' : 'primary'}" id="pr-pub1"><i class="ti ti-${c.published_at ? 'eye-off' : 'eye'}"></i>${c.published_at ? 'Unpublish' : 'Publish to staff'}</button>
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
      $$('[data-adv-del]', body).forEach((b) => b.addEventListener('click', async () => {
        if (!(await App.confirm('Remove advance', 'Remove this salary advance?', { ok: 'Remove', danger: true }))) return;
        try { await api('payroll_advance_delete', { data: { id: +b.dataset.advDel } }); await changed(); } catch (err) { App.fail(err); }
      }));
      const adv = $('#pr-adv', body);
      adv?.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await App.busy(e.submitter, () => api('payroll_advance', { data: { ...App.formData(adv), user_id: uid, month } })); toast('Advance recorded'); await changed(); } catch (err) { App.showErrors(adv, err.fields); App.fail(err); }
      });
      $('#pr-pub1', body).addEventListener('click', async (e) => {
        const publish = !c.published_at;
        if (!(await App.confirm(publish ? 'Publish to staff' : 'Unpublish', publish
          ? `Lock ${esc(monthLabel(month))} and show ${esc(c.user.name)} their full breakdown and net pay?`
          : `Hide the breakdown and net pay from ${esc(c.user.name)}? The month stays locked.`, { ok: publish ? 'Publish' : 'Unpublish' }))) return;
        try { await App.busy(e.currentTarget, () => api('payroll_publish', { data: { user_id: uid, month, publish } })); toast(publish ? 'Published' : 'Unpublished'); await changed(); } catch (err) { App.fail(err); }
      });
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
        <div class="actions"><a class="btn" id="pr-export"><i class="ti ti-download"></i>Export CSV</a><button class="btn" id="pr-pub"><i class="ti ti-eye"></i>Publish month</button><button class="btn primary" id="pr-lock"><i class="ti ti-lock"></i>Lock month</button></div></div>
      <div class="filters" id="pr-filters">${monthPicker(month)}</div>
      <div id="pr-body">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('payroll_list', { query: { month } });
      $('#pr-export', ctx.el).href = `api.php?action=payroll_export&month=${month}`;
      const allLocked = r.rows.length && r.rows.every((x) => x.locked);
      const lockBtn = $('#pr-lock', ctx.el);
      lockBtn.innerHTML = `<i class="ti ti-${allLocked ? 'lock-open' : 'lock'}"></i>${allLocked ? 'Unlock month' : 'Lock month'}`;
      lockBtn.dataset.lock = allLocked ? '0' : '1';
      const allPublished = r.rows.length && r.rows.every((x) => x.published);
      const pubBtn = $('#pr-pub', ctx.el);
      pubBtn.innerHTML = `<i class="ti ti-${allPublished ? 'eye-off' : 'eye'}"></i>${allPublished ? 'Unpublish month' : 'Publish month'}`;
      pubBtn.dataset.publish = allPublished ? '0' : '1';
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
          ${kpi('Deductions', money(sum('deductions'), { short: true }), sum('advances') ? `+ ${money(sum('advances'), { short: true })} in advances` : 'Lateness, absence, reports', 'arrow-down-right', 'tone-bad')}
          ${kpi('Overtime', money(sum('overtime'), { short: true }), `${r.pending.length} awaiting approval`, 'clock-plus', 'tone-in')}
          ${kpi('Net payroll', money(sum('net'), { short: true }), monthLabel(month), 'cash', 'tone-info')}</div>
        <div class="card">${r.rows.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Staff</th><th class="r">Salary</th><th class="r">Deductions</th><th class="r hide-sm">Overtime</th><th class="r hide-sm">Adjustments</th><th class="r hide-sm">Advances</th><th class="r">Net pay</th><th></th></tr></thead><tbody>
          ${r.rows.map((x) => `<tr class="click" data-u="${x.id}"><td><div style="font-weight:500">${esc(x.name)}</div>
              <div class="faint small">${[x.counts.late && `${x.counts.late} late`, x.counts.absence && `${x.counts.absence} absent`, x.counts.report && `${x.counts.report} report`].filter(Boolean).join(' · ') || 'No charges'}</div></td>
            <td class="r num">${x.salary ? money(x.salary) : '<span class="pill out">Not set</span>'}</td><td class="r num ${x.deductions ? 'bad' : ''}">${money(-x.deductions)}</td>
            <td class="r num hide-sm">${money(x.overtime, { sign: true })}${x.counts.overtime_pending ? ` <span class="pill info" title="Awaiting approval">+${x.counts.overtime_pending}</span>` : ''}</td>
            <td class="r num hide-sm">${money(x.adjustments, { sign: true })}</td><td class="r num hide-sm ${x.advances ? 'bad' : ''}">${money(-x.advances)}</td><td class="r num"><b class="${x.net < 0 ? 'bad' : ''}">${money(x.net)}</b></td>
            <td style="white-space:nowrap">${x.locked ? '<i class="ti ti-lock faint" title="Locked"></i>' : ''}${x.published ? ' <i class="ti ti-eye faint" title="Published to staff"></i>' : ''}</td></tr>`).join('')}</tbody></table></div>`
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
    $('#pr-pub', ctx.el).addEventListener('click', async (e) => {
      const publish = e.currentTarget.dataset.publish === '1';
      if (!(await App.confirm(publish ? 'Publish month' : 'Unpublish month', publish
        ? `Lock ${esc(monthLabel(month))} and show every staff member their full breakdown, deductions and net pay?`
        : `Hide ${esc(monthLabel(month))} breakdowns and net pay from staff? The month stays locked.`, { ok: publish ? 'Publish' : 'Unpublish' }))) return;
      try { await App.busy(e.currentTarget, () => api('payroll_publish', { data: { month, publish } })); toast(publish ? 'Month published' : 'Month unpublished'); await load(); } catch (err) { App.fail(err); }
    });
    ctx.refresh = () => { if (!App.hasOverlay()) load().catch(() => {}); };
    await load();
  }, { title: 'Payroll', admin: true, live: true });

  App.route('my-pay', async (ctx) => {
    let month = App.cfg.today.slice(0, 7);
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>My pay</h1><p class="muted">Your salary, advances and attendance record for the month.</p></div></div>
      <div class="filters" id="mp-filters">${monthPicker(month)}</div>
      <div id="mp-body">${App.loading()}</div>`;
    const load = async () => {
      const c = await api('payroll_detail', { query: { month } });
      const r = c.rules;
      const pay = c.published
        ? `<div class="note-box small" style="margin-bottom:14px"><i class="ti ti-circle-check"></i> Your ${esc(monthLabel(c.month))} pay was published on ${esc(fmtDate(c.published_at))}. These figures are final.</div>` + breakdownHtml(c, false)
        : (c.salary || c.advances.length ? payCardHtml(c) : '') + recordHtml(c);
      $('#mp-body', ctx.el).innerHTML = pay + `<details class="card card-pad"><summary style="cursor:pointer;font-weight:500">What counts</summary>
        <ul class="small muted" style="margin:10px 0 0;padding-left:18px;line-height:1.7">
          <li>Late: clocking in after ${esc(App.cfg.work_start)}; more than ${r.very_late_min} minutes counts as very late.</li>
          <li>Absent: a working day with no clock-in and no approval.</li>
          <li>Daily report: send it by ${esc(r.report_deadline)} each working day.</li>
          <li>Overtime: clocking out ${r.overtime_min}+ minutes after ${esc(App.cfg.work_end)}, once your admin approves it.</li>
          <li>Get permission in advance to report late or be absent; approved days are excused.</li></ul></details>`;
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
