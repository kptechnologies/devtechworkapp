/* DevTech Staff Portal — deductions statement, weekly performance report, and what staff may see of them */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, isAdmin } = App;
  const monthLabel = (m) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  const weekLabel = (from) => {
    const a = new Date(from + 'T00:00:00');
    const b = new Date(a.getTime() + 6 * 86400000);
    const f = (d) => d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    return `${f(a)} – ${f(b)}`;
  };
  const tone = (s) => (s >= 80 ? 'in' : s >= 60 ? 'out' : 'bad');
  const grade = (s) => (s >= 80 ? 'Good' : s >= 60 ? 'Needs work' : 'Poor');
  const scoreOf = (r) => (r.measured === false ? '–' : r.score);
  const gradeOf = (r) => (r.measured === false ? 'No work recorded' : grade(r.score));
  const kpi = (label, value, sub, icon, t) => `<div class="card kpi"><div class="kpi-icon ${t}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;

  /* ------------------------------------------------------- weekly report */

  function weeklyHtml(r) {
    const a = r.attendance;
    const w = r.work;
    const rp = r.reports;
    const diff = r.prev_score === null || r.prev_score === undefined ? '' : r.score - r.prev_score;
    const diffTxt = diff === '' ? 'First report' : diff === 0 ? 'Same as the week before' : `${diff > 0 ? '▲' : '▼'} ${Math.abs(diff)} from the week before`;
    const bars = Object.values(r.parts || {}).map((p) => `<div style="margin-bottom:8px"><div class="small" style="display:flex;justify-content:space-between"><span>${esc(p.label)}</span><span class="num">${p.good}/${p.of}</span></div>
      <div style="height:8px;border-radius:4px;background:var(--border);overflow:hidden"><div style="height:100%;width:${p.pct}%;background:var(--${tone(p.pct)})"></div></div></div>`).join('');
    const stat = (label, val, bad) => `<div class="card card-pad" style="padding:10px"><div class="muted small">${label}</div><div style="font-size:20px;font-weight:600" class="${bad ? 'bad' : ''}">${val}</div></div>`;
    const lines = (r.lines || []).map((l) => `<li class="li" style="display:block"><div style="display:flex;justify-content:space-between;gap:10px">
        <span><b>${esc(l.label)}</b> <span class="faint small">${esc(fmtDate(l.date, false))}</span>${l.waived ? ' <span class="pill in">Waived</span>' : ''}</span>
        ${'amount' in l ? `<span class="num ${l.waived ? 'faint' : 'bad'}">${l.waived ? `<s>${money(-l.amount)}</s>` : money(-l.amount)}</span>` : ''}</div>
        ${l.reason ? `<div class="faint small">${esc(l.reason)}</div>` : ''}</li>`).join('');
    return `
      <div class="card card-pad" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
        <div class="${r.measured === false ? '' : 'tone-' + tone(r.score)}" style="flex:none;${r.measured === false ? 'background:var(--border);' : ''}width:84px;height:84px;border-radius:50%;font-size:30px;font-weight:700;display:flex;align-items:center;justify-content:center">${scoreOf(r)}</div>
        <div style="flex:1;min-width:160px"><div class="muted small">Week of ${esc(weekLabel(r.from))}</div><h2 style="margin:2px 0">${gradeOf(r)}</h2><div class="small muted">${esc(diffTxt)}</div></div>
        ${r.deductions !== null && r.deductions !== undefined ? `<div style="text-align:right"><div class="muted small">Deductions this week</div><div class="num ${r.deductions ? 'bad' : 'in'}" style="font-size:22px;font-weight:600">${money(-r.deductions)}</div></div>`
          : `<div style="text-align:right"><div class="muted small">Deductions this week</div><div style="font-size:22px;font-weight:600" class="${r.charged ? 'bad' : 'in'}">${r.charged}</div></div>`}
      </div>
      ${r.tips && r.tips.length ? `<div class="note-box ${r.score >= 80 ? '' : 'warn'}" style="margin-bottom:14px"><b>Do this next week</b><ul style="margin:6px 0 0;padding-left:18px">${r.tips.map((t) => `<li>${esc(t)}</li>`).join('')}</ul></div>` : ''}
      <div class="grid g4" style="margin-bottom:14px">
        ${stat('Days at work', `${a.present}/${a.working_days}`, a.absent)}
        ${stat('Late days', a.late, a.late)}
        ${stat('Missed clock-outs', a.missed_out, a.missed_out)}
        ${stat('Without GPS', a.no_gps, a.no_gps)}
        ${stat('Reports on time', `${rp.on_time}/${rp.required}`, rp.on_time < rp.required)}
        ${stat('Tasks done', w.tasks_done, false)}
        ${stat('Items left overdue', w.tasks_overdue + (w.still_overdue ? ` <span class="small muted">(${w.still_overdue} now)</span>` : ''), w.tasks_overdue || w.still_overdue)}
        ${stat('Jobs completed', `${w.jobs_done}${w.jobs_done ? ` <span class="small muted">· ${w.signed} signed</span>` : ''}`, w.signoff_skipped)}
      </div>
      ${w.returned ? `<div class="banner" style="background:var(--bad-soft);color:var(--bad);margin-bottom:14px"><i class="ti ti-arrow-back-up"></i>${w.returned} job${w.returned === 1 ? ' was' : 's were'} sent back for rework.</div>` : ''}
      ${bars ? `<div class="card card-pad" style="margin-bottom:14px"><h3 style="margin-bottom:10px">How the score is made</h3>${bars}</div>` : ''}
      <h3 style="margin:0 0 8px">Deductions</h3>
      <div class="card">${lines ? `<ul class="list">${lines}</ul>` : App.empty('mood-smile', 'No deductions this week', 'Keep it that way.')}</div>`;
  }

  const shown = new Set();
  /** Full-screen report; the staff member confirms they read it. */
  App.showWeekly = (r) => {
    shown.add(r.id);
    const el = App.modal('Your weekly report', weeklyHtml(r), r.seen_at ? '<button class="btn" data-close>Close</button>' : '<button class="btn primary" id="wk-read"><i class="ti ti-check"></i>I\'ve read this</button>', { wide: true });
    $('#wk-read', el)?.addEventListener('click', async () => {
      try { await api('weekly_report_seen', { data: { id: r.id } }); } catch (e) { /* still close */ }
      r.seen_at = new Date().toISOString();
      App.closeTop();
    });
  };

  /** Pops up last week's report once per app session until it's marked read (home page and first clock-in). */
  App.maybeShowWeekly = async (known) => {
    try {
      const r = known || (await api('weekly_report_mine')).report;
      if (r && !r.seen_at && !shown.has(r.id)) App.showWeekly(r);
    } catch (e) { /* not important enough to show an error */ }
  };

  /** Home page card for this week's report, plus the pop-up if it hasn't been read. */
  App.renderWeeklyCard = async (el) => {
    if (!el) return;
    let res;
    try { res = await api('weekly_report_mine'); } catch (e) { return; }
    const r = res.report;
    if (!r) { el.innerHTML = ''; return; }
    const recent = (Date.now() - new Date(r.week_start + 'T00:00:00').getTime()) < 14 * 86400000;
    if (!recent) { el.innerHTML = ''; return; }
    el.innerHTML = `<div class="card card-pad" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
      <div class="${r.measured === false ? '' : 'tone-' + tone(r.score)}" style="flex:none;${r.measured === false ? 'background:var(--border);' : ''}width:52px;height:52px;border-radius:50%;font-weight:700;font-size:18px;display:flex;align-items:center;justify-content:center">${scoreOf(r)}</div>
      <div style="flex:1;min-width:160px"><div class="t" style="font-weight:600">Last week: ${gradeOf(r)}</div>
        <div class="small muted">${[`${r.attendance.present}/${r.attendance.working_days} days`, r.attendance.missed_out && `${r.attendance.missed_out} missed clock-out${r.attendance.missed_out === 1 ? '' : 's'}`,
          r.deductions ? `${money(r.deductions)} deducted` : r.charged ? `${r.charged} deduction${r.charged === 1 ? '' : 's'}` : 'no deductions'].filter(Boolean).join(' · ')}</div></div>
      <button class="btn ${r.seen_at ? '' : 'primary'}" id="wk-open">${r.seen_at ? 'View' : 'Read your report'}</button></div>`;
    $('#wk-open', el).addEventListener('click', () => App.showWeekly(r));
    App.maybeShowWeekly(r);
  };

  App.route('my-week', async (ctx) => {
    let id = null;
    ctx.el.innerHTML = `<div class="page-head"><div><h1>My weekly report</h1><p class="muted">How you kept the rules each week: attendance, clock-out, GPS, reports, tasks and sign-off.</p></div></div>
      <div class="filters" id="mw-f"></div><div id="mw-body">${App.loading()}</div>`;
    const load = async () => {
      const res = await api('weekly_report_mine', { query: id ? { id } : {} });
      const r = res.report;
      $('#mw-f', ctx.el).innerHTML = res.history.length > 1 ? `<label class="small muted" style="display:flex;gap:6px;align-items:center">Week <select id="mw-week" style="width:auto">${res.history.map((h) =>
        `<option value="${h.id}" ${r && h.id === r.id ? 'selected' : ''}>${esc(weekLabel(h.week_start))} · ${h.score}</option>`).join('')}</select></label>` : '';
      $('#mw-body', ctx.el).innerHTML = r ? weeklyHtml(r) + (r.seen_at ? '' : '<div class="actions" style="margin-top:14px"><button class="btn primary" id="mw-read"><i class="ti ti-check"></i>I\'ve read this</button></div>')
        : App.empty('chart-line', 'No weekly report yet', 'Your first report arrives on Monday morning.');
      $('#mw-week', ctx.el)?.addEventListener('change', (e) => { id = +e.target.value; load().catch(App.fail); });
      $('#mw-read', ctx.el)?.addEventListener('click', async (e) => {
        try { await App.busy(e.currentTarget, () => api('weekly_report_seen', { data: { id: r.id } })); toast('Thanks'); await load(); } catch (err) { App.fail(err); }
      });
    };
    await load();
  }, { title: 'My weekly report' });

  /* ---------------------------------------------------- team weekly view */

  App.route('weekly', async (ctx) => {
    let week = null;
    ctx.el.innerHTML = `<div class="page-head"><div><h1>Weekly reports</h1><p class="muted">Every staff member's score for the week, and whether they've read their report. Sent each Monday (time set in Settings).</p></div>
      <div class="actions" id="wr-actions"></div></div><div class="filters" id="wr-f"></div><div id="wr-body">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('weekly_reports', { query: week ? { week } : {} });
      week = r.week;
      $('#wr-actions', ctx.el).innerHTML = r.can_generate ? '<button class="btn" id="wr-gen"><i class="ti ti-refresh"></i>Build last week\'s reports now</button>' : '';
      $('#wr-f', ctx.el).innerHTML = r.weeks.length ? `<label class="small muted" style="display:flex;gap:6px;align-items:center">Week <select id="wr-week" style="width:auto">${r.weeks.map((w) =>
        `<option value="${w}" ${w === week ? 'selected' : ''}>${esc(weekLabel(w))}</option>`).join('')}</select></label>` : '';
      const rows = r.rows;
      const scored = rows.filter((x) => x.measured !== false);
      const avg = scored.length ? Math.round(scored.reduce((s, x) => s + x.score, 0) / scored.length) : 0;
      const sum = (f) => rows.reduce((s, x) => s + f(x), 0);
      $('#wr-body', ctx.el).innerHTML = !rows.length ? App.empty('chart-bar', 'No weekly reports yet', 'They are built every Monday morning, or press the button above.') : `
        <div class="grid g4" style="margin-bottom:14px">
          ${kpi('Average score', avg, `${rows.length} staff`, 'gauge', `tone-${tone(avg)}`)}
          ${kpi('Missed clock-outs', sum((x) => x.attendance.missed_out), 'Across the team', 'clock-off', 'tone-bad')}
          ${kpi('Without GPS', sum((x) => x.attendance.no_gps), 'Days clocked without location', 'map-pin-off', 'tone-out')}
          ${kpi('Read their report', `${rows.filter((x) => x.seen_at).length}/${rows.length}`, 'Marked as read', 'eye-check', 'tone-info')}</div>
        <div class="card"><div class="table-wrap"><table class="tbl"><thead><tr><th>Staff</th><th class="r">Score</th><th class="r">Days</th><th class="r hide-sm">Late</th><th class="r">No clock-out</th><th class="r hide-sm">No GPS</th>
          <th class="r hide-sm">Reports</th><th class="r hide-sm">Overdue</th><th class="r hide-sm">Returned</th><th class="r">Deductions</th><th>Read</th></tr></thead><tbody>
          ${rows.map((x, i) => `<tr class="click" data-i="${i}"><td>${esc(x.name)}</td><td class="r"><span class="pill ${x.measured === false ? '' : tone(x.score)}">${scoreOf(x)}</span></td>
            <td class="r num">${x.attendance.present}/${x.attendance.working_days}</td><td class="r num hide-sm">${x.attendance.late}</td><td class="r num ${x.attendance.missed_out ? 'bad' : ''}">${x.attendance.missed_out}</td>
            <td class="r num hide-sm ${x.attendance.no_gps ? 'bad' : ''}">${x.attendance.no_gps}</td><td class="r num hide-sm">${x.reports.on_time}/${x.reports.required}</td>
            <td class="r num hide-sm">${x.work.tasks_overdue}</td><td class="r num hide-sm">${x.work.returned}</td><td class="r num ${x.deductions ? 'bad' : ''}">${money(-(x.deductions || 0))}</td>
            <td>${x.seen_at ? `<i class="ti ti-check in" title="Read ${esc(fmtDate(x.seen_at))}"></i>` : '<span class="faint small">Not yet</span>'}</td></tr>`).join('')}</tbody></table></div></div>`;
      $$('[data-i]', ctx.el).forEach((tr) => tr.addEventListener('click', () => {
        const x = rows[+tr.dataset.i];
        App.modal(esc(x.name), weeklyHtml(x) + (isAdmin() ? `<div class="actions" style="margin-top:14px"><a class="btn" href="#/staff/${x.user_id}">Profile</a><a class="btn" href="#/payroll">Payroll</a></div>` : ''), '', { wide: true });
      }));
      $('#wr-week', ctx.el)?.addEventListener('change', (e) => { week = e.target.value; load().catch(App.fail); });
      $('#wr-gen', ctx.el)?.addEventListener('click', async (e) => {
        if (!(await App.confirm('Build weekly reports', 'Build last week\'s report for every staff member now? Anyone who hasn\'t had theirs yet is alerted on their phone.', { ok: 'Build and send' }))) return;
        try { const res = await App.busy(e.currentTarget, () => api('weekly_generate', { data: {} })); toast(`Reports built · ${res.sent} sent`); week = null; await load(); } catch (err) { App.fail(err); }
      });
    };
    ctx.refresh = () => { if (!App.hasOverlay()) load().catch(() => {}); };
    await load();
  }, { title: 'Weekly reports', manager: true, live: true });

  /* ------------------------------------------------- statement of account */

  App.route('my-statement', async (ctx) => {
    let months = 6;
    ctx.el.innerHTML = `<div class="page-head"><div><h1>My deductions</h1><p class="muted">Every deduction from your pay, with the reason. Talk to your admin if one is wrong.</p></div>
      <div class="actions"><button class="btn" id="st-csv"><i class="ti ti-download"></i>Download</button><button class="btn" id="st-print"><i class="ti ti-printer"></i>Print</button></div></div>
      <div class="filters" id="st-f"><label class="small muted" style="display:flex;gap:6px;align-items:center">Show <select name="months" style="width:auto">
        ${[3, 6, 12].map((n) => `<option value="${n}" ${n === months ? 'selected' : ''}>Last ${n} months</option>`).join('')}</select></label>
        <label class="small muted" style="display:flex;gap:6px;align-items:center">Kind <select name="cat" style="width:auto"><option value="">All</option></select></label></div>
      <div id="st-body">${App.loading()}</div>`;
    let data = null;
    let cat = '';
    const draw = () => {
      const r = data;
      const body = $('#st-body', ctx.el);
      if (r.hidden) { body.innerHTML = App.empty('eye-off', 'Not available', 'Your admin hasn\'t turned on the deductions statement.'); return; }
      const sel = $('[name=cat]', ctx.el);
      if (sel.options.length === 1) sel.innerHTML += Object.entries(r.categories).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('');
      const cur = r.months[0];
      const last = r.months[1];
      const tot = (m) => (m && m.show_amounts ? money(m.total) : `${m ? m.count : 0} item${m && m.count === 1 ? '' : 's'}`);
      const months = r.months.map((m) => {
        const lines = m.lines.filter((l) => !cat || l.category === cat);
        return `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3>${esc(monthLabel(m.month))}${m.published ? ' <span class="pill in">Final</span>' : m.locked ? ' <span class="pill">Locked</span>' : ' <span class="pill info">So far</span>'}</h3>
          <b class="num ${m.count ? 'bad' : ''}">${m.show_amounts ? money(-m.total) : `${m.count} deduction${m.count === 1 ? '' : 's'}`}</b></div>
          ${lines.length ? `<ul class="list">${lines.map((l) => `<li class="li" style="display:block"><div style="display:flex;justify-content:space-between;gap:10px">
            <span><b>${esc(l.label)}</b> <span class="faint small">${esc(fmtDate(l.date, false))} · ${esc(l.category_label)}</span>${l.waived ? ` <span class="pill in" title="${esc(l.waived)}">Waived</span>` : ''}</span>
            ${'amount' in l ? `<span class="num ${l.waived ? 'faint' : 'bad'}" style="white-space:nowrap">${l.waived ? `<s>${money(-l.amount)}</s>` : money(-l.amount)}</span>` : ''}</div>
            ${l.reason ? `<div class="faint small">${esc(l.reason)}</div>` : ''}${l.waived ? `<div class="small in">${esc(l.waived)}</div>` : ''}</li>`).join('')}</ul>`
            : '<p class="muted small card-pad">No deductions.</p>'}
          ${!m.show_amounts && r.amounts ? '<div class="faint small card-pad" style="padding-top:0">Amounts show once this month is finalised.</div>' : ''}</div>`;
      }).join('');
      body.innerHTML = `<div class="grid g4" style="margin-bottom:14px">
          ${kpi('This month', tot(cur), cur && cur.count ? `${cur.count} deduction${cur.count === 1 ? '' : 's'}` : 'Nothing yet', 'calendar', cur && cur.count ? 'tone-bad' : 'tone-in')}
          ${kpi('Last month', tot(last), last && last.published ? 'Final' : 'Not final yet', 'calendar-minus', last && last.count ? 'tone-out' : 'tone-in')}
          ${kpi('Most common', r.top ? esc(r.top.label) : 'None', r.top ? `${r.top.count} time${r.top.count === 1 ? '' : 's'}` : 'A clean record', 'alert-triangle', r.top ? 'tone-bad' : 'tone-in')}
          ${kpi('Total shown', r.amounts ? money(r.total) : '—', `Last ${r.months.length} months`, 'sum', 'tone-info')}</div>${months}`;
    };
    const load = async () => { data = await api('my_statement', { query: { months } }); draw(); };
    $('#st-f', ctx.el).addEventListener('change', (e) => {
      if (e.target.name === 'months') { months = +e.target.value; load().catch(App.fail); }
      if (e.target.name === 'cat') { cat = e.target.value; draw(); }
    });
    $('#st-print', ctx.el).addEventListener('click', () => window.print());
    $('#st-csv', ctx.el).addEventListener('click', () => {
      if (!data || data.hidden) return;
      const q = (v) => `"${String(v ?? '').replace(/"/g, '""').replace(/^([=+\-@])/, "'$1")}"`;
      const rows = [['Month', 'Date', 'Kind', 'What', 'Reason', 'Amount', 'Waived']];
      data.months.forEach((m) => m.lines.forEach((l) => rows.push([m.month, l.date, l.category_label, l.label, l.reason, 'amount' in l ? l.amount : '', l.waived || ''])));
      const blob = new Blob(['﻿' + rows.map((r) => r.map(q).join(',')).join('\n')], { type: 'text/csv' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'my-deductions.csv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'My deductions', live: true });

  /* ------------------------------------- per-staff visibility (admin only) */

  App.renderStaffVisibility = async (el, uid) => {
    if (!el || !App.visFields) return;
    let r;
    try { r = await api('staff_visibility', { query: { user_id: uid } }); } catch (e) { return; }
    const v = r.visibility;
    el.innerHTML = `<details class="card card-pad"${v.custom ? ' open' : ''}><summary style="cursor:pointer;font-weight:500"><i class="ti ti-eye"></i> Portal visibility
        <span class="pill ${v.custom ? 'brand' : ''}">${v.custom ? 'Custom for this person' : 'Company setting'}</span></summary>
      <form id="sv-form" style="margin-top:12px">
        <label class="check-row" style="margin-bottom:12px"><input type="checkbox" name="custom" ${v.custom ? 'checked' : ''}><span>Give this person their own settings (otherwise <a href="#/settings">Settings</a> applies)</span></label>
        <div id="sv-fields" style="${v.custom ? '' : 'opacity:.5;pointer-events:none'}">${App.visFields(v, r.categories)}</div>
        <div class="actions" style="margin-top:10px"><button class="btn primary" type="submit">Save visibility</button></div></form></details>`;
    const form = $('#sv-form', el);
    form.custom.addEventListener('change', () => { $('#sv-fields', el).style.cssText = form.custom.checked ? '' : 'opacity:.5;pointer-events:none'; });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await App.busy(e.submitter, () => api('staff_visibility_save', { data: { user_id: uid, custom: form.custom.checked, visibility: App.visRead(form) } }));
        toast('Visibility saved');
        App.renderStaffVisibility(el, uid);
      } catch (err) { App.fail(err); }
    });
  };
})();
