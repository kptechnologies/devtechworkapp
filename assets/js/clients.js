/* DevTech Staff Portal — clients directory: schools and companies, contacts, jobs, reports, device faults */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime } = App;

  const TYPES = [['school', 'School'], ['company', 'Company'], ['other', 'Other']];
  const typeLabel = (t) => (TYPES.find(([k]) => k === t) || [t, t])[1];
  const mapUrl = (c) => (c.lat !== null && c.lat !== undefined ? `https://maps.google.com/?q=${encodeURIComponent(c.lat + ',' + c.lng)}` : '');

  App.route('clients', async (ctx) => {
    const st = { q: '', type: '', all: '' };
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Clients</h1><p class="muted">Schools and companies you work for, with their jobs and history.</p></div>
        <div class="actions"><button class="btn primary" id="cl-add"><i class="ti ti-plus"></i>Add client</button></div></div>
      <div class="filters" id="cl-filters"><input type="search" name="q" placeholder="Search name, area, contact…">
        <select name="type">${App.opts(TYPES, '', { placeholder: 'All types' })}</select>
        <label class="opt"><input type="checkbox" name="all"><span>Show inactive</span></label></div>
      <div class="card" id="cl-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('clients_list', { query: st });
      const box = $('#cl-list', ctx.el);
      if (!r.items.length) { box.innerHTML = App.empty('building-community', 'No clients found', 'Add the schools and companies you work for.'); return; }
      box.innerHTML = `<div class="table-wrap"><table class="tbl"><thead><tr><th>Client</th><th class="hide-sm">Contact</th><th class="r">Open jobs</th><th class="r">Device faults</th><th class="hide-sm">Last visit</th><th></th></tr></thead><tbody>
        ${r.items.map((c) => `<tr class="click" data-c="${c.id}" style="${+c.active ? '' : 'opacity:.55'}">
          <td><div style="font-weight:500">${esc(c.name)}</div><div class="faint small">${esc(typeLabel(c.type))}${c.area ? ' · ' + esc(c.area) : ''}${c.lat !== null ? ' · <i class="ti ti-map-pin"></i>' : ''}</div></td>
          <td class="hide-sm">${esc(c.contact_name)}${c.contact_phone ? `<div class="faint small">${esc(c.contact_phone)}</div>` : ''}</td>
          <td class="r num">${+c.open_jobs || ''}${+c.overdue ? ` <span class="pill bad">${c.overdue} overdue</span>` : ''}</td>
          <td class="r num ${c.faults ? 'bad' : ''}">${c.faults || ''}</td>
          <td class="hide-sm small">${c.last_visit ? esc(fmtDate(c.last_visit, false)) : '<span class="faint">—</span>'}</td>
          <td><i class="ti ti-chevron-right faint"></i></td></tr>`).join('')}</tbody></table></div>`;
      $$('[data-c]', box).forEach((row) => row.addEventListener('click', () => App.go(`clients/${row.dataset.c}`)));
    };
    $('#cl-filters', ctx.el).addEventListener('input', App.debounce((e) => {
      if (!e.target.name) return;
      st[e.target.name] = e.target.type === 'checkbox' ? (e.target.checked ? 1 : '') : e.target.value;
      load().catch(App.fail);
    }, 300));
    $('#cl-add', ctx.el).addEventListener('click', () => editClient(null, (id) => App.go(`clients/${id}`)));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Clients', admin: true, live: true });

  const CTABS = [['open', 'Open jobs', 'clipboard-list'], ['history', 'Job history', 'history'], ['signoffs', 'Sign-offs', 'signature'],
    ['reports', 'Reports', 'clipboard-text'], ['devices', 'Device faults', 'device-laptop'], ['notes', 'Notes', 'notes']];

  App.route('clients/:id', async (ctx) => {
    let tab = 'open';
    const load = async () => {
      const r = await api('client_get', { query: { id: ctx.params.id } });
      const c = r.client;
      const open = r.jobs.filter((j) => !['done', 'cancelled'].includes(j.status));
      const pending = r.devices.filter((d) => d.status && d.status !== 'Fixed');
      ctx.el.innerHTML = `
        <div class="card card-pad" style="margin-bottom:14px;display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">
          <div class="li-icon tone-brand" style="width:56px;height:56px"><i class="ti ti-${c.type === 'company' ? 'building' : 'school'}" style="font-size:26px"></i></div>
          <div style="flex:1;min-width:200px"><h1>${esc(c.name)}</h1>
            <div class="muted">${esc(typeLabel(c.type))}${c.area ? ' · ' + esc(c.area) : ''}${+c.active ? '' : ' · <span class="pill">Inactive</span>'}</div>
            ${c.address ? `<div class="small" style="margin-top:4px">${esc(c.address)}</div>` : ''}
            <div class="small" style="margin-top:6px">${c.contact_name ? `<b>${esc(c.contact_name)}</b>` : '<span class="faint">No contact yet</span>'}
              ${c.contact_phone ? ` · <a href="tel:${esc(c.contact_phone)}">${esc(c.contact_phone)}</a>` : ''}${c.contact_email ? ` · <a href="mailto:${esc(c.contact_email)}">${esc(c.contact_email)}</a>` : ''}</div></div>
          <div class="actions">${mapUrl(c) ? `<a class="btn" href="${esc(mapUrl(c))}" target="_blank" rel="noopener"><i class="ti ti-map-pin"></i>Map</a>` : ''}
            <button class="btn" id="cd-job"><i class="ti ti-plus"></i>New job</button><button class="btn primary" id="cd-edit"><i class="ti ti-edit"></i>Edit</button></div>
        </div>
        <div class="grid g4" style="margin-bottom:14px">
          <div class="card kpi"><div class="kpi-icon tone-brand"><i class="ti ti-clipboard-list"></i></div><div class="label">Open jobs</div><div class="value">${open.length}</div><div class="sub">${open.filter((j) => j.overdue).length} overdue</div></div>
          <div class="card kpi"><div class="kpi-icon tone-in"><i class="ti ti-circle-check"></i></div><div class="label">Jobs done</div><div class="value">${r.jobs.filter((j) => j.status === 'done').length}</div><div class="sub">${r.signoffs.filter((s) => s.signoff_name).length} signed by client</div></div>
          <div class="card kpi"><div class="kpi-icon ${pending.length ? 'tone-bad' : 'tone-in'}"><i class="ti ti-device-laptop-off"></i></div><div class="label">Devices not fixed</div><div class="value">${pending.length}</div><div class="sub">${r.devices.length} device repairs logged</div></div>
          <div class="card kpi"><div class="kpi-icon tone-info"><i class="ti ti-clipboard-text"></i></div><div class="label">Reports here</div><div class="value">${r.reports.length}</div><div class="sub">${r.reports[0] ? 'Last ' + esc(fmtDate(r.reports[0].report_date, false)) : 'No visits yet'}</div></div>
        </div>
        <div class="seg scroll" id="cd-tabs" style="margin-bottom:12px">${CTABS.map(([k, l, i]) => `<button data-t="${k}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
        <div id="cd-body"></div>`;
      const jobList = (jobs, emptyText) => (jobs.length ? `<ul class="list">${jobs.map((j) => `<li class="li" data-job="${j.id}"><div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
        <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.ref)} · ${esc(j.assignees.map((a) => a.name).join(', ') || 'Not assigned')}${j.due_date ? ' · due ' + esc(fmtDate(j.due_date, false)) : ''}</div></div>${App.jobPill(j.status)}</li>`).join('')}</ul>`
        : App.empty('clipboard-list', emptyText));
      const draw = () => {
        $$('#cd-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.t === tab));
        const body = $('#cd-body', ctx.el);
        let html = '';
        if (tab === 'open') html = `<div class="card">${jobList(open, 'No open jobs')}</div>`;
        else if (tab === 'history') html = `<div class="card">${jobList(r.jobs.filter((j) => ['done', 'cancelled'].includes(j.status)), 'No finished jobs yet')}</div>`;
        else if (tab === 'signoffs') {
          html = `<div class="card">${r.signoffs.length ? `<ul class="list">${r.signoffs.map((s) => `<li class="li" data-job="${s.id}"><div class="li-icon ${s.signoff_name ? 'tone-in' : 'tone-out'}"><i class="ti ti-${s.signoff_name ? 'signature' : 'signature-off'}"></i></div>
            <div class="main-col"><div class="t">${esc(s.ref)} · ${esc(s.title)}</div><div class="s">${s.signoff_name ? `Signed by ${esc(s.signoff_name)}${s.signoff_phone ? ' (' + esc(s.signoff_phone) + ')' : ''} · ${esc(fmtDateTime(s.signoff_at))}`
              : `Not signed: ${esc(s.signoff_skipped || 'no sign-off recorded')}`}</div></div></li>`).join('')}</ul>` : App.empty('signature', 'No completed jobs yet')}</div>`;
        } else if (tab === 'reports') {
          html = `<div class="card">${r.reports.length ? `<ul class="list">${r.reports.map((x) => `<li class="li" onclick="location.hash='#/reports/${x.id}'"><div class="li-icon tone-brand"><i class="ti ti-clipboard-text"></i></div>
            <div class="main-col"><div class="t">${esc(fmtDate(x.report_date))} · ${esc(x.user_name)}</div><div class="s">${esc(x.work_type)}</div></div>
            ${x.priority === 'Urgent' || x.priority === 'High' ? `<span class="pill ${x.priority === 'Urgent' ? 'bad' : 'out'}">${esc(x.priority)}</span>` : ''}${App.finishedPill(x.finished)}</li>`).join('')}</ul>` : App.empty('clipboard', 'No reports from this location')}</div>`;
        } else if (tab === 'devices') {
          html = `<div class="card">${r.devices.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Device</th><th>Fault</th><th class="hide-sm">What was done</th><th>Status</th></tr></thead><tbody>
            ${r.devices.map((d) => `<tr class="click" onclick="location.hash='#/reports/${d.report_id}'"><td class="num">${esc(fmtDate(d.report_date, false))}<div class="faint small">${esc(d.user_name)}</div></td>
              <td>${esc(d.device)}<div class="faint small">${esc(d.tag || '')}</div></td><td class="small">${esc((d.faults || []).join(', '))}</td>
              <td class="small hide-sm">${esc(d.action || '')}${d.parts ? `<div class="faint">Parts: ${esc(d.parts)}</div>` : ''}</td>
              <td><span class="pill ${App.deviceTone(d.status)}">${esc(d.status || '—')}</span>${d.priority === 'Urgent' || d.priority === 'High' ? ` <span class="pill ${d.priority === 'Urgent' ? 'bad' : 'out'}">${esc(d.priority)}</span>` : ''}</td></tr>`).join('')}
            </tbody></table></div>` : App.empty('device-laptop', 'No device repairs logged here')}</div>`;
        } else {
          html = `<div class="card card-pad">${c.notes ? `<p style="white-space:pre-wrap">${esc(c.notes)}</p>` : '<p class="faint">No notes. Use Edit to add access instructions, gate codes, lab locations and so on.</p>'}</div>`;
        }
        body.innerHTML = html;
        $$('[data-job]', body).forEach((li) => li.addEventListener('click', () => App.openJob(+li.dataset.job)));
      };
      $$('#cd-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { tab = b.dataset.t; draw(); }));
      $('#cd-edit', ctx.el).addEventListener('click', () => editClient(c, () => load()));
      $('#cd-job', ctx.el).addEventListener('click', () => App.editJob(null, { client_name: c.name, client_type: c.type, location: [c.address, c.area].filter(Boolean).join(', '),
        contact_name: c.contact_name, contact_phone: c.contact_phone }));
      App.setTitle(c.name);
      draw();
    };
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Client', nav: 'clients', admin: true, live: true });

  function editClient(c, done) {
    const isNew = !c;
    c = c || { name: '', type: 'school', address: '', area: '', contact_name: '', contact_phone: '', contact_email: '', lat: null, lng: null, notes: '', active: 1 };
    const el = App.modal(isNew ? 'Add client' : `Edit ${esc(c.name)}`, `<form id="ce-form" novalidate>
      <div class="field"><label for="ce-n">Name <span class="req">*</span></label><input id="ce-n" name="name" value="${esc(c.name)}" autofocus></div>
      <div class="field"><div class="label">Type</div><div class="opts">${TYPES.map(([k, l]) => `<label class="opt"><input type="radio" name="type" value="${k}" ${c.type === k ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
      <div class="form-row"><div class="field"><label>Address</label><input name="address" value="${esc(c.address)}"></div><div class="field"><label>Area / town</label><input name="area" value="${esc(c.area)}" placeholder="e.g. Independence Layout"></div></div>
      <div class="form-row"><div class="field"><label>Contact person</label><input name="contact_name" value="${esc(c.contact_name)}" placeholder="e.g. School director"></div>
        <div class="field"><label>Contact phone</label><input type="tel" name="contact_phone" value="${esc(c.contact_phone)}"></div></div>
      <div class="field"><label>Contact email</label><input type="email" name="contact_email" value="${esc(c.contact_email)}"></div>
      <div class="field"><div class="label" style="display:flex;justify-content:space-between;align-items:center">GPS location <button type="button" class="btn sm ghost" id="ce-here"><i class="ti ti-current-location"></i>Use my current location</button></div>
        <div class="form-row"><input name="lat" inputmode="decimal" placeholder="Latitude, e.g. 6.4421" value="${c.lat ?? ''}"><input name="lng" inputmode="decimal" placeholder="Longitude, e.g. 7.4985" value="${c.lng ?? ''}"></div>
        <div class="help">Used to name the nearest school when staff clock in. Stand at the school and press the button, or copy the numbers from Google Maps.</div></div>
      <div class="field"><label>Notes</label><textarea name="notes" rows="3" placeholder="Access instructions, lab location, who to ask for…">${esc(c.notes || '')}</textarea></div>
      ${isNew ? '' : `<label class="check-row"><input type="checkbox" name="active" ${+c.active ? 'checked' : ''}><span>Active</span></label>`}
      </form>`, `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ce-ok">${isNew ? 'Add client' : 'Save'}</button>`, { wide: true });
    $('#ce-here', el).addEventListener('click', (e) => {
      const btn = e.currentTarget;
      if (!navigator.geolocation) { toast('This browser can\'t share location.', true); return; }
      btn.disabled = true;
      navigator.geolocation.getCurrentPosition((p) => {
        btn.disabled = false;
        const f = $('#ce-form', el);
        f.lat.value = p.coords.latitude.toFixed(7);
        f.lng.value = p.coords.longitude.toFixed(7);
        toast(`Location set (±${Math.round(p.coords.accuracy)}m)`);
      }, () => { btn.disabled = false; toast('Couldn\'t get your location.', true); }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
    $('#ce-ok', el).addEventListener('click', async (e) => {
      const form = $('#ce-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = c.id;
      else d.active = true;
      try {
        const r = await App.busy(e.currentTarget, () => api('client_save', { data: d }));
        App.cfg = (await api('session')).config;
        App.closeTop();
        toast(isNew ? 'Client added' : 'Client saved');
        done && done(r.id);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
})();
