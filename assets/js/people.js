/* DevTech Staff Portal — staff profiles, work tools register, attendance (clock in/out with GPS + selfie) */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, money, fmtDate, fmtDateTime, ago, isAdmin } = App;

  const timeOf = (dt) => (dt ? new Date(dt.replace(' ', 'T')).toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true }) : '');
  const hrs = (min) => (min === null || min === undefined ? '' : `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}m`);
  const mapLink = (lat, lng, label) => (lat !== null && lat !== undefined ? `<a href="https://maps.google.com/?q=${encodeURIComponent(lat + ',' + lng)}" target="_blank" rel="noopener">${label}</a>` : '');
  const where = (site, dist, lat, lng) => {
    if (lat === null || lat === undefined) return '<span class="pill out">No location</span>';
    const txt = site ? `${esc(site)}${dist !== null ? ` · ${dist < 1000 ? dist + 'm' : (dist / 1000).toFixed(1) + 'km'}` : ''}` : 'Map';
    const far = site && dist > 300;
    return `${mapLink(lat, lng, `<i class="ti ti-map-pin"></i>${txt}`)}${far ? ' <span class="pill out" title="More than 300m from the nearest school">Far</span>' : ''}`;
  };
  App.avatar = (u, cls = '') => (u.photo_id
    ? `<img class="avatar ${cls}" src="file.php?id=${u.photo_id}&thumb=1" alt="">`
    : `<div class="avatar ${cls}">${esc(App.initials(u.name))}</div>`);

  /* ------------------------------------------------------- clock in/out */
  /** A fresh GPS fix (never a cached one): resolves to {lat, lng, acc, ts} or {error}. */
  function getPosition() {
    return new Promise((resolve) => {
      if (!navigator.geolocation) { resolve({ error: 'This phone or browser can\'t share location.' }); return; }
      navigator.geolocation.getCurrentPosition(
        (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy), ts: p.timestamp }),
        (e) => resolve({ error: e.code === 1 ? 'Location permission is blocked. Allow location for this site in your browser settings.' : 'Couldn\'t get your location. Move outside or near a window and try again.' }),
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
  }
  const todayAt = (hm) => { const [h, m] = hm.split(':').map(Number); const d = new Date(); d.setHours(h, m, 0, 0); return d; };

  /**
   * Strict clock in/out: a selfie taken now, a GPS fix taken when the button is pressed (its timestamp is checked
   * against the press time on the server), and a reason when late, leaving early or without location.
   */
  function clockDialog(action, info, onDone) {
    const isIn = action === 'in';
    const cfg = App.cfg;
    const late = isIn && Date.now() >= todayAt(cfg.work_start).getTime() + ((cfg.late_grace || 0) + 1) * 60000;
    const early = !isIn && info.open?.work_date === cfg.today && Date.now() < todayAt(cfg.work_end).getTime();
    let photo = null;
    let selfieTs = null;
    let noGps = false;
    const reasonLabel = late ? `You're late (work starts at ${cfg.work_start}). Why?` : early ? `Work ends at ${cfg.work_end}. Why are you leaving early?` : 'Note (optional)';
    const el = App.modal(isIn ? 'Clock in' : 'Clock out', `
      ${!window.isSecureContext ? '<div class="note-box bad" style="margin-bottom:12px">This page isn\'t on HTTPS, so the phone won\'t share location or open the camera. Ask your admin to turn on HTTPS.</div>' : ''}
      <form id="ck-form" novalidate>
      <div class="field" data-field="selfie"><div class="label">Selfie <span class="req">*</span></div>
        <label class="selfie" id="ck-selfie"><input type="file" accept="image/*" capture="user" class="sr">
          <span><i class="ti ti-camera" style="font-size:28px"></i><br>Tap to take a selfie now</span></label></div>
      <div class="field"><div class="label">Location</div><div id="ck-loc" class="note-box"><i class="ti ti-map-pin"></i> Your location is read when you press the button, so it's fresh.</div></div>
      <div class="field" data-field="reason"><label for="ck-reason">${esc(reasonLabel)} ${late || early ? '<span class="req">*</span>' : ''}</label>
        <input id="ck-reason" name="reason" placeholder="${isIn ? 'e.g. Traffic at Garriki; went to Mayday first' : 'e.g. Going to a site visit at Soar High'}"></div>
      </form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ck-ok"><i class="ti ti-${isIn ? 'login' : 'logout'}"></i>${isIn ? 'Clock in now' : 'Clock out now'}</button>`);
    const form = $('#ck-form', el);
    const locBox = $('#ck-loc', el);
    const input = $('#ck-selfie input', el);
    // Ask for location permission early so the press itself is quick; this reading isn't used.
    getPosition().then((p) => {
      if (p.error && locBox.isConnected) { noGps = true; locBox.className = 'note-box warn'; locBox.textContent = p.error + ' You can still continue if you say where you are below.'; }
    });
    input.addEventListener('change', async () => {
      const f = input.files[0];
      if (!f) return;
      selfieTs = f.lastModified || Date.now();
      if (Date.now() - selfieTs > 10 * 60000) { toast('That photo is old. Take a new selfie now.', true); input.value = ''; return; }
      photo = await App.compressImage(f);
      const sp = $('#ck-selfie span', el);
      sp.innerHTML = '<img alt="">';
      sp.querySelector('img').src = URL.createObjectURL(photo);
    });
    const send = async (btn, attempt) => {
      const reason = form.reason.value.trim();
      locBox.className = 'note-box';
      locBox.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-width:2px;display:inline-block;vertical-align:-2px"></span> Getting a fresh location…';
      const pos = await getPosition();
      const data = { action, reason, client_ts: Date.now(), selfie_ts: selfieTs };
      if (pos.error) {
        noGps = true;
        locBox.className = 'note-box warn';
        locBox.textContent = pos.error;
        if (!reason) { App.showErrors(form, { reason: 'Your location couldn\'t be read. Say where you are and why.' }); return; }
      } else {
        Object.assign(data, { lat: pos.lat, lng: pos.lng, acc: pos.acc, gps_ts: pos.ts });
        locBox.innerHTML = `<i class="ti ti-map-pin in"></i> Location found (±${pos.acc}m). ${mapLink(pos.lat, pos.lng, 'Check on map')}`;
      }
      try {
        const r = await api('clock', { data, files: [photo] });
        App.closeTop();
        toast(isIn ? `Clocked in at ${timeOf(r.record.in_at)}` : `Clocked out at ${timeOf(r.record.out_at)}`);
        onDone && onDone();
      } catch (err) {
        if (err.code === 'stale_gps' && attempt < 2) return send(btn, attempt + 1); // cached fix: try once more
        if (err.code === 'late_reason' || err.code === 'early_reason' || err.code === 'no_gps') App.showErrors(form, { reason: err.message });
        else if (err.code === 'selfie' || err.code === 'old_selfie') App.showErrors(form, { selfie: err.message });
        App.fail(err);
      }
    };
    $('#ck-ok', el).addEventListener('click', async (e) => {
      App.showErrors(form, null);
      if (!photo) { App.showErrors(form, { selfie: 'Take a selfie now.' }); return; }
      if ((late || early) && !form.reason.value.trim()) { App.showErrors(form, { reason: late ? 'Say why you\'re late.' : 'Say why you\'re leaving early.' }); return; }
      await App.busy(e.currentTarget, () => send(e.currentTarget, 1));
    });
  }

  /** Clock card on the staff home page. */
  App.renderClockCard = async (box) => {
    if (!box) return;
    let r;
    try { r = await api('attendance_today'); } catch { box.innerHTML = ''; return; }
    const open = r.open;
    const today = r.record;
    let tick = null;
    const draw = () => {
      if (!box.isConnected) { clearInterval(tick); return; }
      if (open) {
        const mins = Math.max(0, Math.round((Date.now() - new Date(open.in_at.replace(' ', 'T')).getTime()) / 60000));
        box.innerHTML = `<div class="clock-card on"><div><div class="small muted">Clocked in at ${esc(timeOf(open.in_at))}${open.late ? ' <span class="pill out">Late</span>' : ''}</div>
          <div class="clock-time">${hrs(mins)}</div><div class="small muted">${open.in_site ? 'Near ' + esc(open.in_site) : 'On duty'} · work ends ${esc(r.work_end)}</div></div>
          <button class="btn danger solid" data-clock="out"><i class="ti ti-logout"></i>Clock out</button></div>`;
      } else if (today?.out_at) {
        box.innerHTML = `<div class="clock-card done"><div><div class="small muted">Today</div><div class="clock-time" style="font-size:18px">${esc(timeOf(today.in_at))} – ${esc(timeOf(today.out_at))}</div>
          <div class="small muted">${hrs(today.minutes)} worked</div></div><button class="btn sm" data-clock="in"><i class="ti ti-login"></i>Clock in again</button></div>`;
      } else {
        box.innerHTML = `<div class="clock-card"><div><div style="font-weight:600">You haven't clocked in today</div><div class="small muted">Work starts at ${esc(r.work_start)}. Take a selfie at your location.</div></div>
          <button class="btn primary" data-clock="in"><i class="ti ti-login"></i>Clock in</button></div>`;
      }
      $('[data-clock]', box)?.addEventListener('click', (e) => clockDialog(e.currentTarget.dataset.clock, r, () => App.renderClockCard(box)));
    };
    draw();
    if (open) tick = setInterval(draw, 60000);
  };

  /* ------------------------------------------------------- attendance */
  const FLAG_TEXT = { no_gps: 'No GPS', admin_edit: 'Edited by admin' };
  const flagPills = (flags) => String(flags || '').split(',').filter(Boolean).map((f) => `<span class="pill out" title="${esc(FLAG_TEXT[f] || f)}">${esc(FLAG_TEXT[f] || f)}</span>`).join(' ');
  const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);

  App.route('attendance', async (ctx) => {
    const admin = isAdmin();
    const staff = admin ? (await api('users_list')).items.filter((u) => u.active) : [];
    const t = App.cfg.today;
    let tab = App.store.get('dt-attab', admin ? 'register' : 'report');
    const st = { from: admin ? t : t.slice(0, 8) + '01', to: t, user_id: '' };
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Attendance' : 'My attendance'}</h1>
        <p class="muted">Work hours ${esc(App.cfg.work_start)}–${esc(App.cfg.work_end)}${App.cfg.late_grace ? ` · ${App.cfg.late_grace} min grace` : ''}. Clock-ins after the start time are marked late.</p></div>
        <div class="actions">${admin ? '<a class="btn" id="at-export"><i class="ti ti-download"></i>Export CSV</a><button class="btn primary" id="at-add"><i class="ti ti-plus"></i>Add manually</button>' : ''}</div></div>
      <div class="seg" id="at-tabs" style="margin-bottom:12px"><button data-t="register"><i class="ti ti-list"></i> Clock-ins</button><button data-t="report"><i class="ti ti-chart-bar"></i> Report</button></div>
      <div class="filters" id="at-filters">
        <label class="small muted" style="display:flex;gap:6px;align-items:center">From <input type="date" name="from" value="${st.from}" style="width:auto"></label>
        <label class="small muted" style="display:flex;gap:6px;align-items:center">To <input type="date" name="to" value="${st.to}" style="width:auto"></label>
        ${admin ? `<select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), '', { placeholder: 'All staff' })}</select>` : ''}
        <div class="seg"><button type="button" data-q="today">Today</button><button type="button" data-q="week">7 days</button><button type="button" data-q="month">This month</button><button type="button" data-q="last">Last month</button></div>
      </div>
      <div id="at-body">${App.loading()}</div>`;
    const kpi = (label, value, sub, icon, tone) => `<div class="card kpi"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
    const setExport = () => {
      const exp = $('#at-export', ctx.el);
      if (exp) exp.href = `api.php?action=${tab === 'report' ? 'attendance_report_export' : 'attendance_export'}&` + new URLSearchParams(Object.entries(st).filter(([, v]) => v)).toString();
    };

    const loadRegister = async () => {
      const r = await api('attendance_list', { query: st });
      const single = r.from === r.to;
      const firstIns = {};
      r.items.forEach((x) => { if (!firstIns[x.user_id + x.work_date]) firstIns[x.user_id + x.work_date] = x; });
      const firsts = Object.values(firstIns);
      const lateN = firsts.filter((x) => x.late).length;
      const onSite = r.items.filter((x) => !x.out_at && !x.missed_out).length;
      const totalMin = r.items.reduce((a, x) => a + (x.minutes || 0), 0);
      $('#at-body', ctx.el).innerHTML = `
        <div class="grid g4" style="margin-bottom:14px">
          ${admin && single ? kpi('Clocked in', `${firsts.length} <span class="faint" style="font-size:15px">of ${firsts.length + r.absent.length}</span>`, `${r.absent.length} not in`, 'login', 'tone-brand')
            : kpi('Days present', new Set(firsts.map((x) => x.user_id + x.work_date)).size, `${fmtDate(r.from, false)} – ${fmtDate(r.to, false)}`, 'calendar-check', 'tone-brand')}
          ${kpi('Late arrivals', lateN, `After ${esc(r.work_start)}`, 'clock-exclamation', lateN ? 'tone-out' : 'tone-in')}
          ${kpi('On site now', onSite, 'Not clocked out yet', 'map-pin', 'tone-info')}
          ${kpi('Hours logged', Math.round(totalMin / 6) / 10, 'Completed clock-outs', 'hourglass', 'tone-in')}
        </div>
        ${admin && single && r.absent.length ? `<div class="card card-pad" style="margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span class="muted small"><i class="ti ti-user-off"></i> Not clocked in:</span>${r.absent.map((s) => `<button class="pill bad" data-add="${s.id}" title="Add manually">${esc(s.name)}</button>`).join('')}</div>` : ''}
        <div class="card">${r.items.length ? `<div class="table-wrap"><table class="tbl"><thead><tr>${single ? '' : '<th>Date</th>'}${admin ? '<th>Staff</th>' : ''}<th>Arrived</th><th>Left</th><th class="r">Hours</th><th>Where / why</th><th>Selfies</th>${admin ? '<th></th>' : ''}</tr></thead><tbody>
          ${r.items.map((x) => `<tr>${single ? '' : `<td class="num">${esc(fmtDate(x.work_date, false))}</td>`}${admin ? `<td><a href="#/staff/${x.user_id}">${esc(x.user_name)}</a></td>` : ''}
            <td class="num">${esc(timeOf(x.in_at))} ${x.late && firstIns[x.user_id + x.work_date] === x ? `<span class="pill out">Late ${x.late_min}m</span>` : ''}</td>
            <td class="num">${x.out_at ? `${esc(timeOf(x.out_at))}${x.early ? ' <span class="pill out">Early</span>' : ''}` : x.missed_out ? '<span class="pill bad">No clock-out</span>' : '<span class="pill info">On site</span>'}</td>
            <td class="r num">${hrs(x.minutes)}</td>
            <td class="small">${x.in_lat === null && x.source === 'admin' ? '' : where(x.in_site, x.in_dist, x.in_lat, x.in_lng)} ${flagPills(x.flags)}
              ${x.in_reason ? `<div class="small"><b>In:</b> ${esc(x.in_reason)}</div>` : ''}${x.out_reason ? `<div class="small"><b>Out:</b> ${esc(x.out_reason)}</div>` : ''}
              ${x.note ? `<div class="faint small" style="white-space:pre-wrap">${esc(x.note)}</div>` : ''}</td>
            <td><div class="gallery sm">${x.in_photo ? `<div class="thumb" data-file="${x.in_photo}" data-img="1" title="Clock-in selfie"><img src="file.php?id=${x.in_photo}&thumb=1" alt="Clock-in selfie" loading="lazy"></div>` : ''}${x.out_photo ? `<div class="thumb" data-file="${x.out_photo}" data-img="1" title="Clock-out selfie"><img src="file.php?id=${x.out_photo}&thumb=1" alt="Clock-out selfie" loading="lazy"></div>` : ''}</div></td>
            ${admin ? `<td><button class="btn sm ghost" data-edit="${x.id}" aria-label="Edit"><i class="ti ti-edit"></i></button></td>` : ''}</tr>`).join('')}</tbody></table></div>`
          : App.empty('clock-off', 'No clock-ins', single ? 'Nobody has clocked in for this day yet.' : 'No records in this period.')}</div>`;
      App.bindGallery($('#at-body', ctx.el));
      $$('[data-edit]', ctx.el).forEach((b) => b.addEventListener('click', () => editAttendance(r.items.find((x) => x.id === +b.dataset.edit), staff, load)));
      $$('[data-add]', ctx.el).forEach((b) => b.addEventListener('click', () => editAttendance({ user_id: +b.dataset.add, work_date: r.from }, staff, load)));
    };

    const loadReport = async () => {
      const r = await api('attendance_report', { query: st });
      const body = $('#at-body', ctx.el);
      if (!admin || st.user_id) {
        const x = r.rows[0];
        if (!x) { body.innerHTML = App.empty('calendar-off', 'No data'); return; }
        body.innerHTML = reportKpis(x, kpi) + `<div class="card card-pad">${calendarHtml(r.calendar)}</div>`;
        return;
      }
      const tot = r.rows.reduce((a, x) => ({ late: a.late + x.late, absent: a.absent + x.absent, missed: a.missed + x.missed_out, present: a.present + x.present, wd: a.wd + x.working_days }), { late: 0, absent: 0, missed: 0, present: 0, wd: 0 });
      body.innerHTML = `<div class="grid g4" style="margin-bottom:14px">
          ${kpi('Attendance', pct(tot.wd ? Math.round(tot.present / tot.wd * 100) : null), `${tot.present} of ${tot.wd} staff-days`, 'calendar-check', 'tone-brand')}
          ${kpi('Late arrivals', tot.late, 'Across all staff', 'clock-exclamation', tot.late ? 'tone-out' : 'tone-in')}
          ${kpi('Absences', tot.absent, 'Working days with no clock-in', 'user-off', tot.absent ? 'tone-bad' : 'tone-in')}
          ${kpi('Missed clock-outs', tot.missed, 'Forgot to clock out', 'logout-2', tot.missed ? 'tone-out' : 'tone-in')}</div>
        <div class="card"><div class="table-wrap"><table class="tbl"><thead><tr><th>Staff</th><th class="r">Present</th><th class="r">Absent</th><th class="r">Late</th><th class="r hide-sm">Min late</th>
          <th class="hide-sm">Avg arrival</th><th class="r hide-sm">Left early</th><th class="r hide-sm">No clock-out</th><th class="r">Hours</th><th class="r">Punctual</th></tr></thead><tbody>
          ${r.rows.map((x) => `<tr class="click" data-u="${x.id}"><td><div style="font-weight:500">${esc(x.name)}</div><div class="faint small">${pct(x.attendance_pct)} attendance</div></td>
            <td class="r num">${x.present}<span class="faint">/${x.working_days}</span></td><td class="r num ${x.absent ? 'bad' : ''}">${x.absent}</td>
            <td class="r num ${x.late ? 'out' : ''}">${x.late}</td><td class="r num hide-sm">${x.late_min}</td><td class="hide-sm num">${x.avg_arrival || '—'}</td>
            <td class="r num hide-sm">${x.early}</td><td class="r num hide-sm ${x.missed_out ? 'bad' : ''}">${x.missed_out}</td><td class="r num">${x.hours}</td>
            <td class="r"><span class="pill ${x.punctuality_pct === null ? '' : x.punctuality_pct >= 90 ? 'in' : x.punctuality_pct >= 70 ? 'out' : 'bad'}">${pct(x.punctuality_pct)}</span></td></tr>`).join('')}
          </tbody></table></div></div>`;
      $$('[data-u]', body).forEach((row) => row.addEventListener('click', () => staffCalendar(+row.dataset.u, st)));
    };

    const load = async () => {
      $$('#at-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.t === tab));
      setExport();
      const ex = $('#at-export', ctx.el);
      if (ex) ex.classList.toggle('hide', false);
      return tab === 'report' ? loadReport() : loadRegister();
    };
    $$('#at-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { tab = b.dataset.t; App.store.set('dt-attab', tab); load().catch(App.fail); }));
    $('#at-filters', ctx.el).addEventListener('change', (e) => {
      if (!e.target.name) return;
      st[e.target.name] = e.target.value;
      load().catch(App.fail);
    });
    $$('[data-q]', ctx.el).forEach((b) => b.addEventListener('click', () => {
      const d = new Date(t + 'T00:00:00');
      st.to = t;
      if (b.dataset.q === 'today') st.from = t;
      else if (b.dataset.q === 'week') st.from = App.addDays(t, -6);
      else if (b.dataset.q === 'month') st.from = t.slice(0, 8) + '01';
      else {
        const first = new Date(d.getFullYear(), d.getMonth() - 1, 1);
        st.from = App.iso8601(first);
        st.to = App.iso8601(new Date(d.getFullYear(), d.getMonth(), 0));
      }
      $('[name=from]', ctx.el).value = st.from;
      $('[name=to]', ctx.el).value = st.to;
      load().catch(App.fail);
    }));
    $('#at-add', ctx.el)?.addEventListener('click', () => editAttendance({ work_date: st.to }, staff, load));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Attendance', live: true });

  function reportKpis(x, kpi) {
    return `<div class="grid g4" style="margin-bottom:14px">
      ${kpi('Days present', `${x.present} <span class="faint" style="font-size:15px">of ${x.working_days}</span>`, `${pct(x.attendance_pct)} attendance · ${x.absent} absent`, 'calendar-check', x.absent ? 'tone-out' : 'tone-in')}
      ${kpi('Late days', x.late, `${x.late_min} min late in total · avg arrival ${x.avg_arrival || '—'}`, 'clock-exclamation', x.late ? 'tone-out' : 'tone-in')}
      ${kpi('Punctuality', pct(x.punctuality_pct), `${x.early} early leave${x.early === 1 ? '' : 's'} · ${x.missed_out} missed clock-out${x.missed_out === 1 ? '' : 's'}`, 'target', x.punctuality_pct >= 90 ? 'tone-in' : 'tone-out')}
      ${kpi('Hours worked', x.hours, `${x.avg_hours}h a day on average`, 'hourglass', 'tone-info')}</div>`;
  }

  const DAY_STATUS = { present: ['On time', 'in'], extra: ['Day off worked', 'in'], late: ['Late', 'out'], missed: ['No clock-out', 'bad'], absent: ['Absent', 'bad'], off: ['Day off', ''], future: ['', ''], before_start: ['Not started', ''] };
  function calendarHtml(days) {
    if (!days.length) return '';
    const lead = (Number(new Date(days[0].date + 'T00:00:00').getDay()) + 6) % 7; // Monday first
    const cells = Array(lead).fill('<div class="cal-cell blank"></div>').concat(days.map((d) => {
      const [label] = DAY_STATUS[d.status] || ['', ''];
      const tip = [fmtDate(d.date), label, d.in ? `${timeOf(d.in)} – ${d.out ? timeOf(d.out) : '?'}` : '', d.late ? `${d.late_min} min late` : '', d.in_reason, d.out_reason].filter(Boolean).join('\n');
      return `<div class="cal-cell st-${d.status}" title="${esc(tip)}"><div class="cal-d">${+d.date.slice(8)}</div>
        ${d.in ? `<div class="cal-t">${esc(timeOf(d.in))}</div>` : d.status === 'absent' ? '<div class="cal-t">Absent</div>' : ''}
        ${d.late ? `<div class="cal-t">+${d.late_min}m</div>` : ''}${d.status === 'missed' ? '<div class="cal-t">No out</div>' : d.early ? '<div class="cal-t">Early</div>' : ''}</div>`;
    }));
    return `<div class="cal-head">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => `<div>${d}</div>`).join('')}</div><div class="cal">${cells.join('')}</div>
      <div class="chips" style="margin-top:10px">${['present', 'late', 'missed', 'absent', 'extra', 'off'].map((s) => `<span class="cal-key st-${s}">${DAY_STATUS[s][0]}</span>`).join('')}</div>`;
  }
  App.attendanceCalendar = calendarHtml;

  async function staffCalendar(uid, st) {
    const el = App.drawer('Attendance', App.loading());
    try {
      const r = await api('attendance_report', { query: { ...st, user_id: uid } });
      const x = r.rows[0];
      const kpi = (label, value, sub, icon, tone) => `<div class="card kpi"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
      $('.drawer-head h2', el).textContent = `${x.name} · ${fmtDate(r.from, false)} – ${fmtDate(r.to, false)}`;
      $('.drawer-body', el).innerHTML = reportKpis(x, kpi).replace('grid g4', 'grid g2') + calendarHtml(r.calendar)
        + `<a class="btn" style="margin-top:14px" href="#/staff/${uid}"><i class="ti ti-user"></i>Open profile</a>`;
    } catch (e) { App.closeTop(); App.fail(e); }
  }

  function editAttendance(rec, staff, done) {
    const isNew = !rec.id;
    const el = App.modal(isNew ? 'Add attendance' : `Edit ${esc(rec.user_name)} · ${esc(fmtDate(rec.work_date))}`, `
      <form id="ae-form" novalidate>
        ${isNew ? `<div class="form-row"><div class="field"><label>Staff</label><select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), rec.user_id, { placeholder: 'Choose…' })}</select></div>
          <div class="field"><label>Date</label><input type="date" name="work_date" value="${esc(rec.work_date)}"></div></div>` : ''}
        <div class="form-row"><div class="field"><label>Arrived</label><input type="time" name="in_time" value="${rec.in_at ? rec.in_at.slice(11, 16) : ''}"></div>
          <div class="field"><label>Left</label><input type="time" name="out_time" value="${rec.out_at ? rec.out_at.slice(11, 16) : ''}"></div></div>
        <div class="field"><label>Reason <span class="req">*</span></label><input name="note" placeholder="e.g. Phone was off, confirmed with school"><div class="help">Saved with the record and in the audit log.</div></div>
      </form>`, '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ae-ok">Save</button>');
    $('#ae-ok', el).addEventListener('click', async (e) => {
      const form = $('#ae-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = rec.id;
      try { await App.busy(e.currentTarget, () => api('attendance_edit', { data: d })); App.closeTop(); toast('Attendance saved'); done(); } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /* ------------------------------------------------------------- tools */
  const COND = { good: ['Good', 'in'], fair: ['Fair', 'info'], faulty: ['Faulty', 'out'], lost: ['Lost', 'bad'], retired: ['Retired', ''] };
  const condPill = (c) => `<span class="pill ${COND[c]?.[1] || ''}">${esc(COND[c]?.[0] || c)}</span>`;

  App.route('tools', async (ctx) => {
    const staff = (await api('users_list')).items.filter((u) => u.active);
    const st = { q: '', holder: '', condition: '' };
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Work tools</h1><p class="muted">Every tool, who holds it, and its condition.</p></div>
        <div class="actions"><button class="btn primary" id="tl-add"><i class="ti ti-plus"></i>Add tool</button></div></div>
      <div id="tl-kpi"></div>
      <div class="filters" id="tl-filters"><input type="search" name="q" placeholder="Search name, tag, serial…">
        <select name="holder">${App.opts([['store', 'In store']].concat(staff.map((s) => [s.id, s.name])), '', { placeholder: 'Anyone' })}</select>
        <select name="condition">${App.opts(Object.entries(COND).map(([k, [l]]) => [k, l]), '', { placeholder: 'Any condition' })}</select></div>
      <div class="card" id="tl-list">${App.loading()}</div>`;
    let cats = [];
    const load = async () => {
      const r = await api('tools_list', { query: st });
      cats = r.categories;
      const t = r.totals;
      $('#tl-kpi', ctx.el).innerHTML = `<div class="grid g4" style="margin-bottom:14px">
        <div class="card kpi"><div class="kpi-icon tone-brand"><i class="ti ti-tools"></i></div><div class="label">Tools in use</div><div class="value">${+t.n || 0}</div><div class="sub">Not retired</div></div>
        <div class="card kpi"><div class="kpi-icon tone-info"><i class="ti ti-user-check"></i></div><div class="label">Issued to staff</div><div class="value">${+t.issued || 0}</div><div class="sub">${(+t.n || 0) - (+t.issued || 0)} in store</div></div>
        <div class="card kpi"><div class="kpi-icon ${+t.problems ? 'tone-bad' : 'tone-in'}"><i class="ti ti-alert-triangle"></i></div><div class="label">Faulty or lost</div><div class="value">${+t.problems || 0}</div><div class="sub">Need attention</div></div>
        <div class="card kpi"><div class="kpi-icon tone-in"><i class="ti ti-cash"></i></div><div class="label">Total value</div><div class="value">${money(t.value, { short: true })}</div><div class="sub">Purchase value</div></div></div>`;
      const box = $('#tl-list', ctx.el);
      if (!r.items.length) { box.innerHTML = App.empty('tools', 'No tools found', 'Add laptops, toolkits, testers and other equipment so you can track who holds them.'); return; }
      box.innerHTML = `<div class="table-wrap"><table class="tbl"><thead><tr><th>Tool</th><th class="hide-sm">Tag</th><th class="hide-sm">Serial no.</th><th>Condition</th><th>Held by</th><th class="r hide-sm">Value</th></tr></thead><tbody>
        ${r.items.map((x) => `<tr class="click" data-tool="${x.id}"><td><div style="font-weight:500">${esc(x.name)}</div><div class="faint small">${esc(x.category)}</div></td>
          <td class="hide-sm num">${esc(x.tag_code)}</td><td class="hide-sm num small">${esc(x.serial_no)}</td><td>${condPill(x.condition)}</td>
          <td>${x.holder_name ? `${esc(x.holder_name)}<div class="faint small">since ${esc(fmtDate(x.issued_at, false))}</div>` : '<span class="faint">In store</span>'}</td>
          <td class="r num hide-sm">${+x.value ? money(x.value, { short: true }) : ''}</td></tr>`).join('')}</tbody></table></div>`;
      $$('[data-tool]', box).forEach((row) => row.addEventListener('click', () => openTool(+row.dataset.tool, staff, () => load())));
    };
    $('#tl-filters', ctx.el).addEventListener('input', App.debounce((e) => { if (e.target.name) { st[e.target.name] = e.target.value; load().catch(App.fail); } }, 300));
    $('#tl-add', ctx.el).addEventListener('click', () => editTool(null, cats, () => load()));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Work tools', admin: true, live: true });

  async function openTool(id, staff, done) {
    const el = App.drawer('Tool', App.loading());
    const render = async () => {
      let t;
      try { t = (await api('tool_get', { query: { id } })).tool; } catch (e) { App.closeTop(); App.fail(e); return; }
      $('.drawer-head h2', el).textContent = t.name;
      $('.drawer-body', el).innerHTML = `
        <div style="display:flex;gap:6px;margin-bottom:12px">${condPill(t.condition)}${t.holder_name ? `<span class="pill brand"><i class="ti ti-user"></i>${esc(t.holder_name)}</span>` : '<span class="pill">In store</span>'}</div>
        <dl class="kv">${t.category ? `<dt>Category</dt><dd>${esc(t.category)}</dd>` : ''}${t.tag_code ? `<dt>Tag</dt><dd>${esc(t.tag_code)}</dd>` : ''}
          ${t.serial_no ? `<dt>Serial no.</dt><dd>${esc(t.serial_no)}</dd>` : ''}${+t.value ? `<dt>Value</dt><dd>${money(t.value)}</dd>` : ''}
          ${t.purchase_date ? `<dt>Bought</dt><dd>${esc(fmtDate(t.purchase_date))}</dd>` : ''}${t.holder_name ? `<dt>Issued</dt><dd>${esc(fmtDateTime(t.issued_at))}</dd>` : ''}</dl>
        ${t.notes ? `<p style="white-space:pre-wrap;margin-top:8px">${esc(t.notes)}</p>` : ''}
        <div class="sec-title">Photos</div><div id="td-files">${App.gallery(t.files, { removable: true })}</div>
        <div class="sec-title">History</div>
        ${t.moves.length ? `<ul class="list">${t.moves.map((m) => `<li class="li" style="padding:8px 0"><div class="li-icon ${m.action === 'issue' ? 'tone-brand' : m.action === 'return' ? 'tone-in' : 'tone-out'}">
          <i class="ti ti-${m.action === 'issue' ? 'arrow-up-right' : m.action === 'return' ? 'arrow-down-left' : 'adjustments'}"></i></div>
          <div class="main-col"><div class="t">${m.action === 'issue' ? 'Issued to ' + esc(m.user_name) : m.action === 'return' ? 'Returned by ' + esc(m.user_name || '—') : 'Condition: ' + esc(COND[m.condition]?.[0] || m.condition)}</div>
          <div class="s">${esc(fmtDateTime(m.created_at))}${m.by_name ? ' · by ' + esc(m.by_name) : ''}${m.note ? ' · ' + esc(m.note) : ''}</div></div>
          ${m.action === 'return' && m.condition ? condPill(m.condition) : ''}</li>`).join('')}</ul>` : '<p class="faint small">Not issued yet.</p>'}`;
      App.bindGallery($('#td-files', el));
      let foot = $('.drawer-foot', el);
      if (!foot) { foot = document.createElement('div'); foot.className = 'drawer-foot'; el.appendChild(foot); }
      foot.innerHTML = `<button class="btn danger" data-a="delete"><i class="ti ti-trash"></i></button><button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>
        ${t.holder_id ? '<button class="btn" data-a="return"><i class="ti ti-arrow-down-left"></i>Return to store</button>' : ''}
        ${['lost', 'retired'].includes(t.condition) ? '' : `<button class="btn primary" data-a="issue"><i class="ti ti-arrow-up-right"></i>${t.holder_id ? 'Reissue' : 'Issue to staff'}</button>`}`;
      $$('[data-a]', foot).forEach((b) => b.addEventListener('click', async () => {
        const a = b.dataset.a;
        const after = () => { render(); done && done(); };
        if (a === 'edit') editTool(t, [], after);
        else if (a === 'issue') issueTool(t, staff, after);
        else if (a === 'return') returnTool(t, after);
        else if (a === 'delete') {
          if (!(await App.confirm('Delete this tool?', 'Its history and photos will be permanently removed. To keep the record, mark it Retired instead.', { ok: 'Delete', danger: true }))) return;
          try { await api('tool_delete', { data: { id: t.id } }); App.closeAll(); toast('Tool deleted'); done && done(); } catch (e) { App.fail(e); }
        }
      }));
    };
    await render();
  }
  App.openTool = openTool;

  function issueTool(t, staff, done) {
    const el = App.modal(`Issue ${esc(t.name)}`, `<form id="ti-form">
      <div class="field"><label>Give to</label><select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), t.holder_id || '', { placeholder: 'Choose staff…' })}</select></div>
      <div class="field"><label>Note</label><input name="note" placeholder="e.g. For the Mayday installation this week"></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ti-ok">Issue</button>');
    $('#ti-ok', el).addEventListener('click', async (e) => {
      const d = App.formData($('#ti-form', el));
      if (!d.user_id) { toast('Choose a staff member.', true); return; }
      try { await App.busy(e.currentTarget, () => api('tool_issue', { data: { id: t.id, ...d } })); App.closeTop(); toast('Tool issued'); done(); } catch (err) { App.fail(err); }
    });
  }

  function returnTool(t, done) {
    const el = App.modal(`Return ${esc(t.name)}`, `<form id="tr-form">
      <p class="muted" style="margin-bottom:12px">From ${esc(t.holder_name)}. Check it before taking it back.</p>
      <div class="field"><div class="label">Condition on return</div><div class="opts">${Object.entries(COND).filter(([k]) => k !== 'retired').map(([k, [l]]) =>
        `<label class="opt"><input type="radio" name="condition" value="${k}" ${k === t.condition ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
      <div class="field"><label>Note</label><input name="note" placeholder="e.g. Charger missing"></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="tr-ok">Return to store</button>');
    $('#tr-ok', el).addEventListener('click', async (e) => {
      try { await App.busy(e.currentTarget, () => api('tool_return', { data: { id: t.id, ...App.formData($('#tr-form', el)) } })); App.closeTop(); toast('Tool returned'); done(); } catch (err) { App.fail(err); }
    });
  }

  function editTool(t, cats, done) {
    const isNew = !t;
    t = t || { name: '', category: '', serial_no: '', tag_code: '', condition: 'good', value: '', purchase_date: '', notes: '' };
    const el = App.modal(isNew ? 'Add tool' : `Edit ${esc(t.name)}`, `<form id="te-form" novalidate>
      <div class="form-row"><div class="field"><label>Name <span class="req">*</span></label><input name="name" value="${esc(t.name)}" placeholder="e.g. HP ProBook 450 laptop" autofocus></div>
        <div class="field"><label>Category</label><input name="category" list="te-cats" value="${esc(t.category)}" placeholder="e.g. Laptop, Toolkit, Tester">
          <datalist id="te-cats">${cats.concat(['Laptop', 'Toolkit', 'Drill', 'Tester / multimeter', 'Projector', 'Router / MiFi', 'Robotics kit']).filter((v, i, a) => a.indexOf(v) === i).map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div></div>
      <div class="form-row"><div class="field"><label>Tag / asset code</label><input name="tag_code" value="${esc(t.tag_code)}" placeholder="e.g. DT-LAP-007"></div>
        <div class="field"><label>Serial number</label><input name="serial_no" value="${esc(t.serial_no)}"></div></div>
      <div class="field"><div class="label">Condition</div><div class="opts">${Object.entries(COND).map(([k, [l]]) => `<label class="opt"><input type="radio" name="condition" value="${k}" ${k === t.condition ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
      <div class="form-row"><div class="field"><label>Value (${esc(App.cfg.currency)})</label><input type="number" name="value" min="0" step="0.01" value="${esc(+t.value || '')}"></div>
        <div class="field"><label>Purchase date</label><input type="date" name="purchase_date" value="${esc(t.purchase_date || '')}"></div></div>
      <div class="field"><label>Notes</label><textarea name="notes" rows="2">${esc(t.notes || '')}</textarea></div>
      <div class="field"><div class="label">Photos</div><div id="te-up"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="te-ok">${isNew ? 'Add tool' : 'Save'}</button>`, { wide: true });
    const up = App.uploader($('#te-up', el), { existing: t.files?.length || 0, label: 'Add photo' });
    $('#te-ok', el).addEventListener('click', async (e) => {
      const form = $('#te-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = t.id;
      try { await App.busy(e.currentTarget, () => api('tool_save', { data: d, files: up.files() })); App.closeTop(); toast(isNew ? 'Tool added' : 'Tool saved'); done(); } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /* ------------------------------------------------------------ profile */
  App.route('staff/:id', (ctx) => profileView(ctx, +ctx.params.id), { title: 'Staff profile', nav: 'staff', admin: true, live: true });
  App.route('profile', (ctx) => profileView(ctx, null), { title: 'Profile' });

  const PTABS = [['details', 'Details', 'id'], ['tools', 'Tools', 'tools'], ['attendance', 'Attendance', 'clock'], ['jobs', 'Jobs', 'clipboard-list'], ['reports', 'Reports', 'clipboard-text'], ['wallet', 'Wallet', 'wallet']];

  async function profileView(ctx, id) {
    const admin = isAdmin();
    const self = !id || id === App.user.id;
    let tab = App.store.get('dt-ptab', 'details');
    const load = async () => {
      const p = await api('staff_profile', { query: { id: id || '' } });
      const u = p.user;
      const m = p.month;
      ctx.el.innerHTML = `
        <div class="card card-pad profile-head" style="margin-bottom:14px">
          <label class="avatar-edit" title="Change photo">${App.avatar(u, 'xl')}<input type="file" accept="image/*" class="sr" id="pf-photo"><span><i class="ti ti-camera"></i></span></label>
          <div class="main-col" style="min-width:0;flex:1"><h1 style="margin-bottom:2px">${esc(u.name)}</h1>
            <div class="muted">${[u.job_title, u.role === 'admin' ? 'Administrator' : '', u.location].filter(Boolean).map(esc).join(' · ') || 'Staff'}</div>
            <div class="small muted" style="margin-top:4px">${esc(u.email)}${u.phone ? ` · <a href="tel:${esc(u.phone)}">${esc(u.phone)}</a>` : ''}</div>
            ${u.duties.length ? `<div class="chips" style="margin-top:8px">${u.duties.map((d) => `<span class="pill brand">${esc(d)}</span>`).join('')}</div>` : ''}</div>
          <div class="actions">${admin && !self ? `<button class="btn" id="pf-account"><i class="ti ti-key"></i>Account &amp; password</button><a class="btn" href="#/wallet/${u.id}"><i class="ti ti-wallet"></i>Wallet</a>` : ''}
            ${self ? '<button class="btn" id="pf-out"><i class="ti ti-logout"></i>Sign out</button>' : ''}${!u.active ? '<span class="pill">Inactive</span>' : ''}</div>
        </div>
        <div class="grid g4" style="margin-bottom:14px">
          <div class="card kpi"><div class="kpi-icon tone-brand"><i class="ti ti-calendar-check"></i></div><div class="label">Days present</div><div class="value">${m.days}</div><div class="sub">This month</div></div>
          <div class="card kpi"><div class="kpi-icon ${m.late ? 'tone-out' : 'tone-in'}"><i class="ti ti-clock-exclamation"></i></div><div class="label">Late days</div><div class="value">${m.late}</div><div class="sub">After ${esc(App.cfg.work_start)}</div></div>
          <div class="card kpi"><div class="kpi-icon tone-info"><i class="ti ti-hourglass"></i></div><div class="label">Avg hours / day</div><div class="value">${m.avg_hours}</div><div class="sub">${m.hours}h this month</div></div>
          <div class="card kpi"><div class="kpi-icon tone-in"><i class="ti ti-wallet"></i></div><div class="label">Wallet balance</div><div class="value">${money(p.wallet.balance)}</div><div class="sub">${p.tools.length} tool${p.tools.length === 1 ? '' : 's'} held</div></div>
        </div>
        <div class="seg scroll" id="pf-tabs" style="margin-bottom:12px">${PTABS.map(([k, l, i]) => `<button data-t="${k}" class="${k === tab ? 'on' : ''}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
        <div id="pf-body"></div>`;
      const body = $('#pf-body', ctx.el);
      const draw = () => {
        $$('#pf-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.t === tab));
        body.innerHTML = tabHtml(tab, p, admin, self);
        bindTab(tab, body, p, admin, self, load);
      };
      $$('#pf-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { tab = b.dataset.t; App.store.set('dt-ptab', tab); draw(); }));
      $('#pf-out', ctx.el)?.addEventListener('click', App.logout);
      $('#pf-account', ctx.el)?.addEventListener('click', async () => {
        const acct = (await api('users_list')).items.find((x) => x.id === u.id);
        App.editUser?.(acct, load);
      });
      $('#pf-photo', ctx.el).addEventListener('change', async (e) => {
        const f = e.target.files[0];
        if (!f) return;
        try { await api('staff_photo', { data: { id: u.id }, files: [await App.compressImage(f)] }); toast('Photo updated'); load(); } catch (err) { App.fail(err); }
      });
      draw();
    };
    ctx.refresh = null; // profile forms shouldn't redraw while someone is typing
    await load();
  }

  /** Tick-boxes for the schools a staff member works at (several allowed). */
  App.schoolsField = (selected = []) => {
    const list = App.cfg.locations.concat(selected.filter((s) => !App.cfg.locations.includes(s)));
    return `<div class="field"><div class="label">Schools / locations</div><div class="opts">${list.map((s) => `<label class="opt"><input type="checkbox" data-multi="1" name="schools" value="${esc(s)}" ${selected.includes(s) ? 'checked' : ''}>
      <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s)}</span></label>`).join('')}</div><div class="help">Tick every school they work at. Add new schools under Clients.</div></div>`;
  };

  function tabHtml(tab, p, admin, self) {
    const u = p.user;
    if (tab === 'details') {
      const f = (name, label, val, attrs = '') => `<div class="field"><label for="pd-${name}">${label}</label><input id="pd-${name}" name="${name}" value="${esc(val || '')}" ${attrs}></div>`;
      return `<div class="grid g2">
        <form class="card card-pad" id="pd-form"><h3 style="margin-bottom:14px">Personal details</h3>
          ${admin ? `<div class="form-row">${f('job_title', 'Job title', u.job_title, 'placeholder="e.g. Field technician"')}
            <div class="field"><label for="pd-start">Start date</label><input type="date" id="pd-start" name="start_date" value="${esc(u.start_date || '')}"></div></div>
            <div class="field"><label for="pd-salary">Monthly salary (${esc(App.cfg.currency)})</label><input type="number" id="pd-salary" name="salary" min="0" step="0.01" value="${esc(+u.salary ? u.salary : '')}" style="max-width:220px">
              <div class="help">Payroll deducts attendance charges from this. See <a href="#/payroll">Payroll</a>.</div></div>` : ''}
          ${f('phone', 'Phone', u.phone, 'type="tel"')}
          ${App.schoolsField(u.schools)}
          ${f('address', 'Home address', u.address)}
          <div class="form-row">${f('next_of_kin', 'Next of kin', u.next_of_kin)}${f('next_of_kin_phone', 'Next of kin phone', u.next_of_kin_phone, 'type="tel"')}</div>
          <div class="form-row">${f('bank_name', 'Bank', u.bank_name)}${f('bank_account', 'Account number', u.bank_account, 'inputmode="numeric"')}</div>
          ${admin ? `<div class="field"><div class="label">Responsibilities</div><div class="opts">${App.cfg.duties.map((d) => `<label class="opt"><input type="checkbox" data-multi="1" name="duties" value="${esc(d)}" ${u.duties.includes(d) ? 'checked' : ''}><span><i class="ti ti-check" style="font-size:14px"></i>${esc(d)}</span></label>`).join('')}</div>
            <div class="help">Pre-ticked on their daily report so they only see the questions for their kind of work.</div></div>` : u.duties.length ? '' : ''}
          <button class="btn primary" type="submit">Save details</button></form>
        ${self ? `<div class="stack"><form class="card card-pad" id="pw-form" novalidate><h3 style="margin-bottom:14px">Change password</h3>
          <div class="field"><label for="pw-c">Current password</label><input type="password" id="pw-c" name="current" autocomplete="current-password"></div>
          <div class="field"><label for="pw-n">New password</label><input type="password" id="pw-n" name="new" autocomplete="new-password" placeholder="At least 8 characters"></div>
          <div class="field"><label for="pw-n2">Repeat new password</label><input type="password" id="pw-n2" name="new2" autocomplete="new-password"></div>
          <button class="btn primary" type="submit">Change password</button></form>
          <div class="card card-pad"><h3 style="margin-bottom:10px">Appearance</h3><button class="btn" id="pf-theme"><i class="ti ti-moon"></i>Toggle dark mode</button></div></div>` : ''}
      </div>`;
    }
    if (tab === 'tools') {
      return `<div class="card"><div class="card-head"><h3>Tools held</h3>${admin ? '<button class="btn sm primary" id="pt-issue"><i class="ti ti-arrow-up-right"></i>Issue a tool</button>' : ''}</div>
        ${p.tools.length ? `<ul class="list">${p.tools.map((t) => `<li class="li" data-tool="${t.id}"><div class="li-icon tone-brand"><i class="ti ti-tools"></i></div>
          <div class="main-col"><div class="t">${esc(t.name)}</div><div class="s">${[t.category, t.tag_code, t.serial_no].filter(Boolean).map(esc).join(' · ')} · since ${esc(fmtDate(t.issued_at, false))}</div></div>${condPill(t.condition)}</li>`).join('')}</ul>`
          : App.empty('tools', 'No tools issued', admin ? 'Issue tools from the store so you know who holds what.' : 'Tools your admin issues to you show up here.')}</div>`;
    }
    if (tab === 'attendance') {
      return `<div class="card card-pad" style="margin-bottom:14px"><h3 style="margin-bottom:10px">This month</h3><div id="pt-cal">${App.loading()}</div></div>
        <div class="card"><div class="card-head"><h3>Recent clock-ins</h3>${admin ? `<a class="small" href="#/attendance">Attendance register</a>` : ''}</div>
        ${p.attendance.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Arrived</th><th>Left</th><th class="r">Hours</th><th>Where</th></tr></thead><tbody>
          ${p.attendance.map((x) => `<tr><td class="num">${esc(fmtDate(x.work_date, false))}</td><td class="num">${esc(timeOf(x.in_at))} ${x.late ? '<span class="pill out">Late</span>' : ''}</td>
            <td class="num">${x.out_at ? esc(timeOf(x.out_at)) : '<span class="pill info">On site</span>'}</td><td class="r num">${hrs(x.minutes)}</td>
            <td class="small">${esc(x.in_site || (x.source === 'admin' ? 'Entered by admin' : ''))}${x.in_dist !== null && x.in_site ? ` · ${x.in_dist}m` : ''}</td></tr>`).join('')}</tbody></table></div>`
          : App.empty('clock-off', 'No clock-ins yet')}</div>`;
    }
    if (tab === 'jobs') {
      return `<div class="card"><div class="card-head"><h3>Job orders</h3><a class="small" href="#/jobs">All jobs</a></div>
        ${p.jobs.length ? `<ul class="list">${p.jobs.map((j) => `<li class="li" data-job="${j.id}"><div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
          <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.ref)} · ${esc(j.client_name)}${j.due_date ? ' · due ' + esc(fmtDate(j.due_date, false)) : ''}</div></div>${App.jobPill(j.status)}</li>`).join('')}</ul>`
          : App.empty('clipboard-list', 'No jobs assigned')}</div>`;
    }
    if (tab === 'reports') {
      return `<div class="card"><div class="card-head"><h3>Recent reports</h3><a class="small" href="#/reports">All reports</a></div>
        ${p.reports.length ? `<ul class="list">${p.reports.map((x) => `<li class="li" onclick="location.hash='#/reports/${x.id}'"><div class="li-icon tone-brand"><i class="ti ti-clipboard-text"></i></div>
          <div class="main-col"><div class="t">${esc(fmtDate(x.report_date))}</div><div class="s">${esc(x.location)} · ${esc(x.work_type)}</div></div>${App.finishedPill(x.finished)}</li>`).join('')}</ul>`
          : App.empty('clipboard', 'No reports yet')}</div>`;
    }
    const w = p.wallet;
    return `<div class="card card-pad"><div class="grid g3">
      <div><div class="muted small">Balance</div><div style="font-size:22px;font-weight:600" class="${w.balance < 0 ? 'bad' : ''}">${money(w.balance)}</div></div>
      <div><div class="muted small">Spent this month</div><div style="font-size:22px;font-weight:600">${money(w.spent_month)}</div></div>
      <div><div class="muted small">Awaiting review</div><div style="font-size:22px;font-weight:600">${w.pending || 0}</div></div></div>
      <a class="btn" style="margin-top:14px" href="#/${admin && !self ? 'wallet/' + p.user.id : 'wallet'}"><i class="ti ti-wallet"></i>Open wallet</a></div>`;
  }

  function bindTab(tab, body, p, admin, self, reload) {
    if (tab === 'details') {
      $('#pd-form', body).addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = App.formData(e.target);
        d.id = p.user.id;
        if (admin && !d.duties) d.duties = [];
        try {
          const r = await App.busy(e.submitter, () => api('staff_profile_save', { data: d }));
          if (self) App.user = { ...App.user, phone: r.user.phone, location: r.user.location, schools: r.user.schools, duties: r.user.duties, job_title: r.user.job_title };
          toast('Details saved');
          reload();
        } catch (err) { App.showErrors(e.target, err.fields); App.fail(err); }
      });
      $('#pf-theme', body)?.addEventListener('click', App.toggleTheme);
      $('#pw-form', body)?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = App.formData(e.target);
        const errs = {};
        if (!d.current) errs.current = 'Enter your current password.';
        if ((d.new || '').length < 8) errs.new = 'Use at least 8 characters.';
        if (d.new !== d.new2) errs.new2 = 'The passwords don\'t match.';
        if (Object.keys(errs).length) { App.showErrors(e.target, errs); return; }
        try { await App.busy(e.submitter, () => api('password_change', { data: d })); e.target.reset(); App.showErrors(e.target, null); toast('Password changed'); } catch (err) { App.fail(err); }
      });
    }
    if (tab === 'tools') {
      $$('[data-tool]', body).forEach((li) => li.addEventListener('click', async () => {
        if (!admin) return;
        const staff = (await api('users_list')).items.filter((u) => u.active);
        openTool(+li.dataset.tool, staff, reload);
      }));
      $('#pt-issue', body)?.addEventListener('click', async () => {
        const store = (await api('tools_list', { query: { holder: 'store' } })).items.filter((t) => !['lost', 'retired'].includes(t.condition));
        if (!store.length) { toast('No tools in the store. Add one under Work tools.', true); return; }
        const el = App.modal(`Issue a tool to ${esc(p.user.name)}`, `<form id="pi-form">
          <div class="field"><label>Tool</label><select name="id">${App.opts(store.map((t) => [t.id, `${t.name}${t.tag_code ? ' · ' + t.tag_code : ''}`]), '', { placeholder: 'Choose…' })}</select></div>
          <div class="field"><label>Note</label><input name="note"></div></form>`, '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="pi-ok">Issue</button>');
        $('#pi-ok', el).addEventListener('click', async (e) => {
          const d = App.formData($('#pi-form', el));
          if (!d.id) { toast('Choose a tool.', true); return; }
          try { await App.busy(e.currentTarget, () => api('tool_issue', { data: { id: +d.id, user_id: p.user.id, note: d.note } })); App.closeTop(); toast('Tool issued'); reload(); } catch (err) { App.fail(err); }
        });
      });
    }
    if (tab === 'attendance') {
      api('attendance_report', { query: { from: App.cfg.today.slice(0, 8) + '01', to: App.cfg.today, user_id: p.user.id } })
        .then((r) => { const box = $('#pt-cal', body); if (box) box.innerHTML = calendarHtml(r.calendar); })
        .catch(() => { const box = $('#pt-cal', body); if (box) box.innerHTML = ''; });
    }
    if (tab === 'jobs') $$('[data-job]', body).forEach((li) => li.addEventListener('click', () => App.openJob(+li.dataset.job)));
  }
})();
