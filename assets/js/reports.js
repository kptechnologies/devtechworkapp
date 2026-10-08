/* DevTech Staff Portal — daily work reports: wizard, list, detail, issues board */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, isAdmin } = App;

  const fieldsById = () => {
    const out = {};
    App.cfg.schema.forEach((sec) => sec.fields.forEach((f) => { out[f.id] = { ...f, section: sec.id }; }));
    return out;
  };

  function visible(f, data) {
    return (f.show || []).every(([fid, op, val]) => {
      const raw = data[fid] ?? '';
      const list = Array.isArray(raw) ? raw.map(String) : [];
      const v = Array.isArray(raw) ? raw.join(', ') : String(raw);
      switch (op) {
        case 'eq': return v === String(val);
        case 'ne': return v !== String(val);
        case 'gt': return v !== '' && !isNaN(v) && Number(v) > Number(val);
        case 'nempty': return v !== '';
        case 'has': return list.includes(String(val));
        case 'hasany': return [].concat(val).some((x) => list.includes(String(x)));
        default: return true;
      }
    });
  }
  App.reportVisible = visible;
  /** A step is asked only when its section applies to what the person did today. */
  const secVisible = (sec, data) => visible({ show: sec.show }, data);

  /* ------------------------------------------- repeatable rows (devices) */
  const rowColOpts = (c, row) => (c.by ? (App.cfg.fault_types?.[row[c.by]] || []) : (Array.isArray(c.opts) ? c.opts : []));
  function rowHtml(f, row, i) {
    const cols = f.cols.map((c) => {
      const val = row[c.id] ?? (c.type === 'checks' ? [] : '');
      const req = c.req ? ' <span class="req">*</span>' : '';
      const opts = rowColOpts(c, row).slice();
      if (c.type === 'checks') (val || []).forEach((v) => { if (!opts.includes(v)) opts.push(v); });
      let ctl;
      if (c.type === 'select') ctl = `<select data-col="${c.id}">${App.opts(opts, val, { placeholder: 'Choose…' })}</select>`;
      else if (c.type === 'textarea') ctl = `<textarea data-col="${c.id}" rows="2" placeholder="${esc(c.ph || '')}">${esc(val)}</textarea>`;
      else if (c.type === 'number') ctl = `<input type="number" data-col="${c.id}" inputmode="decimal" min="0" value="${esc(val)}" placeholder="0">`;
      else if (c.type === 'radio') ctl = `<div class="opts" data-col="${c.id}">${opts.map((o) => `<label class="opt"><input type="radio" name="${f.id}-${i}-${c.id}" value="${esc(o)}" ${o === val ? 'checked' : ''}><span>${esc(o)}</span></label>`).join('')}</div>`;
      else if (c.type === 'checks') {
        ctl = opts.length ? `<div class="opts" data-col="${c.id}">${opts.map((o) => `<label class="opt"><input type="checkbox" value="${esc(o)}" ${(val || []).includes(o) ? 'checked' : ''}><span><i class="ti ti-check" style="font-size:14px"></i>${esc(o)}</span></label>`).join('')}</div>`
          : `<div class="faint small" data-col="${c.id}">Choose the device first.</div>`;
      } else ctl = `<input type="text" data-col="${c.id}" value="${esc(val)}" placeholder="${esc(c.ph || '')}">`;
      return `<div class="field" data-rowcol="${c.id}"><div class="label">${esc(c.label)}${req}</div>${ctl}</div>`;
    }).join('');
    return `<div class="row-card" data-row="${i}"><div class="row-card-head"><b>${esc(row.device || 'Device')} ${i + 1}</b>
      <button type="button" class="btn ghost sm" data-row-del="${i}" aria-label="Remove"><i class="ti ti-trash"></i>Remove</button></div>${cols}</div>`;
  }
  function rowsHtml(f, rows) {
    return `${rows.map((r, i) => rowHtml(f, r, i)).join('')}
      <button type="button" class="btn" data-row-add="${f.id}"><i class="ti ti-plus"></i>${esc(f.add || 'Add another')}</button>`;
  }
  function readRows(box, f) {
    return $$('[data-row]', box).map((card) => {
      const row = {};
      f.cols.forEach((c) => {
        const el = $(`[data-col="${c.id}"]`, card);
        if (!el) { row[c.id] = c.type === 'checks' ? [] : ''; return; }
        if (c.type === 'checks') row[c.id] = $$('input:checked', el).map((x) => x.value);
        else if (c.type === 'radio') row[c.id] = $('input:checked', el)?.value || '';
        else row[c.id] = el.value.trim();
      });
      return row;
    });
  }
  function rowErrors(f, rows) {
    for (let i = 0; i < rows.length; i++) {
      for (const c of f.cols) {
        const v = rows[i][c.id];
        if (c.req && (v === '' || v === undefined || (Array.isArray(v) && !v.length))) return `Device ${i + 1}: fill in "${c.label}".`;
      }
    }
    return null;
  }
  const filledRows = (rows) => (rows || []).filter((r) => Object.values(r).some((v) => (Array.isArray(v) ? v.length : v !== '' && v !== undefined)));

  const deviceTone = (s) => (s === 'Fixed' ? 'in' : /^Partially|^Pending/.test(s || '') ? 'out' : s ? 'bad' : '');
  App.deviceTone = deviceTone;
  const finishedTone = (v) => (v === 'Yes – completed' ? 'in' : v === 'Partially completed' ? 'out' : v ? 'bad' : '');
  const finishedShort = (v) => (v === 'Yes – completed' ? 'Completed' : v === 'Partially completed' ? 'Partial' : v ? 'Not completed' : '—');
  App.finishedPill = (v) => `<span class="pill ${finishedTone(v)}">${esc(finishedShort(v))}</span>`;

  /* ------------------------------------------------------ field renderer */
  function renderField(f, value) {
    const req = f.req ? ' <span class="req">*</span>' : '';
    const id = `f-${f.id}`;
    const val = value ?? (f.type === 'checks' ? [] : '');
    let control = '';
    const opts = Array.isArray(f.opts) ? f.opts.slice() : [];
    if ((f.type === 'radio' || f.type === 'select') && val && !opts.includes(val)) opts.push(val);
    if (f.type === 'checks') (val || []).forEach((v) => { if (!opts.includes(v)) opts.push(v); });
    switch (f.type) {
      case 'textarea':
        control = `<textarea id="${id}" name="${f.id}" rows="3" placeholder="${esc(f.ph || '')}">${esc(val)}</textarea>`;
        break;
      case 'number':
        control = `<input type="number" id="${id}" name="${f.id}" inputmode="numeric" min="0" step="1" value="${esc(val)}" placeholder="0">`;
        break;
      case 'date': case 'time':
        control = `<input type="${f.type}" id="${id}" name="${f.id}" value="${esc(val)}">`;
        break;
      case 'select':
        control = `<select id="${id}" name="${f.id}">${App.opts(opts, val, { placeholder: 'Choose…' })}</select>`;
        break;
      case 'radio': {
        const long = opts.some((o) => o.length > 22) || opts.length > 4;
        control = `<div class="opts ${long ? 'col' : ''}" data-field="${f.id}" role="radiogroup">${opts.map((o) => `
          <label class="opt"><input type="radio" name="${f.id}" value="${esc(o)}" ${o === val ? 'checked' : ''}><span>${esc(o)}</span></label>`).join('')}</div>`;
        break;
      }
      case 'checks':
        control = `<div class="opts" data-field="${f.id}">${opts.map((o) => `
          <label class="opt"><input type="checkbox" data-multi="1" name="${f.id}" value="${esc(o)}" ${(val || []).includes(o) ? 'checked' : ''}><span><i class="ti ti-check" style="font-size:14px"></i>${esc(o)}</span></label>`).join('')}</div>`;
        break;
      case 'confirm':
        control = `<label class="check-row" data-field="${f.id}"><input type="checkbox" name="${f.id}" ${val ? 'checked' : ''}><span>${esc(f.text || 'I confirm')}</span></label>`;
        break;
      case 'jobs': {
        // The person's open job orders, plus any already saved on this report.
        const jobOpts = (App.myJobs || []).map((j) => `${j.ref} · ${j.title}`);
        (val || []).forEach((v) => { if (!jobOpts.includes(v)) jobOpts.push(v); });
        if (!jobOpts.length) return '';
        control = `<div class="opts col" data-field="${f.id}">${jobOpts.map((o) => `
          <label class="opt"><input type="checkbox" data-multi="1" name="${f.id}" value="${esc(o)}" ${(val || []).includes(o) ? 'checked' : ''}><span><i class="ti ti-check" style="font-size:14px"></i>${esc(o)}</span></label>`).join('')}</div>`;
        break;
      }
      case 'rows':
        control = `<div class="rows" data-field="${f.id}" data-rows="${f.id}">${rowsHtml(f, val && val.length ? val : [{}])}</div>`;
        break;
      default:
        control = `<input type="text" id="${id}" name="${f.id}" value="${esc(val)}" placeholder="${esc(f.ph || '')}">`;
    }
    const labelFor = ['radio', 'checks', 'confirm', 'jobs', 'rows'].includes(f.type) ? '' : ` for="${id}"`;
    return `<div class="field" data-wrap="${f.id}"><label${labelFor} class="label">${esc(f.label)}${req}</label>
      ${f.help ? `<div class="help" style="margin:-2px 0 8px">${esc(f.help)}</div>` : ''}${control}</div>`;
  }

  function readStep(form, sec, data) {
    sec.fields.forEach((f) => {
      if (f.type === 'rows') { const box = $(`[data-rows="${f.id}"]`, form); if (box) data[f.id] = filledRows(readRows(box, f)); return; }
      if (f.type === 'jobs' && !$(`[data-field="${f.id}"]`, form)) return;
      if (f.type === 'checks' || f.type === 'jobs') data[f.id] = $$(`input[name="${f.id}"]:checked`, form).map((i) => i.value);
      else if (f.type === 'radio') data[f.id] = $(`input[name="${f.id}"]:checked`, form)?.value || '';
      else if (f.type === 'confirm') data[f.id] = $(`input[name="${f.id}"]`, form)?.checked ? (f.text || 'Yes') : '';
      else { const el = $(`[name="${f.id}"]`, form); if (el) data[f.id] = el.value.trim(); }
    });
  }

  function validateStep(sec, data) {
    const errs = {};
    if (!secVisible(sec, data)) return errs;
    sec.fields.forEach((f) => {
      if (!visible(f, data)) return;
      const v = data[f.id];
      const empty = v === '' || v === undefined || v === null || (Array.isArray(v) && !v.length);
      if (f.type === 'rows') {
        const e = empty ? (f.req ? 'Add at least one device.' : null) : rowErrors(f, v);
        if (e) errs[f.id] = e;
        return;
      }
      if (f.req && empty) errs[f.id] = f.type === 'confirm' ? 'Tick to confirm.' : 'This is required.';
      else if (f.type === 'number' && !empty && (isNaN(v) || Number(v) < 0)) errs[f.id] = 'Enter a number (0 or more).';
    });
    return errs;
  }

  /* --------------------------------------------------------------- wizard */
  App.route('report/new', (ctx) => wizard(ctx, null), { title: 'Daily report', nav: 'report' });
  App.route('report/:id/edit', (ctx) => wizard(ctx, +ctx.params.id), { title: 'Edit report', nav: 'reports' });

  async function wizard(ctx, id) {
    const schema = App.cfg.schema;
    const draftKey = `dt-draft-${App.user.id}`;
    let data = {};
    let existingFiles = [];
    let owner = App.user;
    if (id) {
      const r = (await api('report_get', { query: { id } })).report;
      data = r.data;
      existingFiles = r.attachments;
      owner = { name: r.user_name, email: r.user_email };
    } else {
      data = App.store.get(draftKey, null) || {};
      if (!data.report_date) data.report_date = App.cfg.today;
      if (!data.location && App.user.location) data.location = App.user.location;
      if (!data.duties && App.user.duties?.length) data.duties = App.user.duties.slice();
    }
    // Open job orders to pick from, and today's clock-in to pre-fill the hours.
    App.myJobs = [];
    if (!id || !isAdmin()) {
      try { App.myJobs = (await api('jobs_list', { query: { mine: 1, open: 1 } })).items; } catch { /* optional */ }
    }
    if (!id && data.report_date === App.cfg.today && (!data.check_in || !data.check_out)) {
      try {
        const a = (await api('attendance_today')).record;
        if (a && !data.check_in) data.check_in = a.in_at.slice(11, 16);
        if (a?.out_at && !data.check_out) data.check_out = a.out_at.slice(11, 16);
      } catch { /* optional */ }
    }
    let step = 0;
    const hadDraft = !id && App.store.get(draftKey, null);
    const steps = () => schema.map((s, i) => i).filter((i) => secVisible(schema[i], data));
    const isLast = () => { const s = steps(); return s[s.length - 1] === step; };

    ctx.el.innerHTML = `<div class="wizard">
      <div class="page-head"><div><h1>${id ? 'Edit report' : 'Daily work report'}</h1>
        <p class="muted">${esc(owner.name)}${owner.email ? ' · ' + esc(owner.email) : ''}</p></div>
        <div class="actions">${hadDraft ? '<button class="btn ghost sm" id="wz-clear"><i class="ti ti-eraser"></i>Start over</button>' : ''}</div></div>
      <div class="stepper" id="wz-steps"></div>
      <form class="card card-pad" id="wz-form" novalidate></form>
      <div class="card card-pad hide" id="wz-files" style="margin-top:14px"><div class="field" style="margin:0"><div class="label">Photos and files (optional)</div>
        ${existingFiles.length ? `<div id="wz-existing" style="margin-bottom:8px">${App.gallery(existingFiles, { removable: true })}</div>` : ''}
        <div id="wz-upload"></div><div class="help">Lesson photos, installation work, before/after repair photos, receipts.</div></div></div>
      <div class="wizard-foot"><button class="btn" id="wz-back"><i class="ti ti-arrow-left"></i>Back</button>
        <span class="faint small" id="wz-saved"></span>
        <button class="btn primary" id="wz-next">Next<i class="ti ti-arrow-right"></i></button></div></div>`;
    const form = $('#wz-form', ctx.el);
    const uploader = App.uploader($('#wz-upload', ctx.el), { existing: existingFiles.length });
    if (existingFiles.length) {
      App.bindGallery($('#wz-existing', ctx.el), () => { uploader.setExisting($$('#wz-existing .thumb', ctx.el).length); });
    }

    let submitted = false;
    const saveDraft = App.debounce(() => {
      if (id || submitted) return;
      App.store.set(draftKey, data);
      const label = $('#wz-saved', ctx.el);
      if (label) label.innerHTML = '<i class="ti ti-device-floppy"></i> Draft saved';
    }, 500);

    function applyVisibility() {
      schema[step].fields.forEach((f) => $(`[data-wrap="${f.id}"]`, form)?.classList.toggle('hide', !visible(f, data)));
    }

    function renderStepper() {
      const s = steps();
      const pos = s.indexOf(step);
      $('#wz-steps', ctx.el).innerHTML = s.map((i, n) => `<div class="s${n < pos ? ' done' : n === pos ? ' on' : ''}" data-step="${i}" title="${esc(schema[i].title)}"></div>`).join('');
      const label = $('#wz-steplabel', form);
      if (label) label.textContent = `Step ${pos + 1} of ${s.length}`;
      $('#wz-files', ctx.el).classList.toggle('hide', !isLast());
      $('#wz-back', ctx.el).style.visibility = pos <= 0 ? 'hidden' : 'visible';
      $('#wz-next', ctx.el).innerHTML = isLast() ? `<i class="ti ti-send"></i>${id ? 'Save changes' : 'Submit report'}` : 'Next<i class="ti ti-arrow-right"></i>';
    }

    function render() {
      const sec = schema[step];
      form.innerHTML = `<div class="step-title"><div class="li-icon tone-brand"><i class="ti ti-${esc(sec.icon || 'forms')}"></i></div>
        <div><div class="faint small" id="wz-steplabel"></div><h2>${esc(sec.title)}</h2></div></div>
        ${sec.fields.map((f) => renderField(f, data[f.id])).join('')}`;
      applyVisibility();
      renderStepper();
    }

    function rerenderRows(f, box, rows) {
      box.innerHTML = rowsHtml(f, rows.length ? rows : [{}]);
      data[f.id] = filledRows(rows);
      saveDraft();
    }
    const rowsField = (box) => schema[step].fields.find((x) => x.id === box.dataset.rows);

    const onEdit = (e) => {
      readStep(form, schema[step], data);
      applyVisibility();
      if (e.target.name === 'duties') renderStepper(); // ticking a duty adds or removes steps
      saveDraft();
    };
    form.addEventListener('input', onEdit);
    form.addEventListener('change', (e) => {
      onEdit(e);
      // Each device type has its own fault list.
      if (e.target.matches('[data-col="device"]')) {
        const box = e.target.closest('[data-rows]');
        const f = rowsField(box);
        rerenderRows(f, box, readRows(box, f));
      }
    });
    form.addEventListener('click', (e) => {
      const add = e.target.closest('[data-row-add]');
      const del = e.target.closest('[data-row-del]');
      if (!add && !del) return;
      const box = e.target.closest('[data-rows]');
      const f = rowsField(box);
      const rows = readRows(box, f);
      if (add) rows.push({});
      else rows.splice(+del.dataset.rowDel, 1);
      rerenderRows(f, box, rows);
      if (add) $$('[data-row]', box).pop()?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    form.addEventListener('submit', (e) => e.preventDefault());

    const go = (n) => { step = n; render(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    const move = (dir) => { const s = steps(); const pos = s.indexOf(step); go(s[Math.max(0, Math.min(s.length - 1, pos + dir))]); };
    $('#wz-back', ctx.el).addEventListener('click', (e) => { e.preventDefault(); readStep(form, schema[step], data); move(-1); });
    $('#wz-steps', ctx.el).addEventListener('click', (e) => {
      const dot = e.target.closest('[data-step]');
      if (!dot) return;
      const target = +dot.dataset.step;
      readStep(form, schema[step], data);
      if (target > step) {
        for (const i of steps().filter((x) => x >= step && x < target)) {
          const errs = validateStep(schema[i], data);
          if (Object.keys(errs).length) { if (i !== step) go(i); App.showErrors(form, errs); return; }
        }
      }
      go(target);
    });
    $('#wz-clear', ctx.el)?.addEventListener('click', async () => {
      if (!(await App.confirm('Start over?', 'Your saved draft will be cleared.', { ok: 'Clear draft', danger: true }))) return;
      App.store.del(draftKey);
      App.reroute();
    });

    $('#wz-next', ctx.el).addEventListener('click', async (e) => {
      e.preventDefault();
      readStep(form, schema[step], data);
      const errs = validateStep(schema[step], data);
      if (Object.keys(errs).length) { App.showErrors(form, errs); toast('Fill in the highlighted questions.', true); return; }
      if (!isLast()) { move(1); return; }
      for (const i of steps()) {
        const e2 = validateStep(schema[i], data);
        if (Object.keys(e2).length) { go(i); App.showErrors(form, e2); toast('Some answers need attention.', true); return; }
      }
      try {
        const r = await App.busy(e.currentTarget, () => api('report_save', { data: { id, data }, files: uploader.files() }));
        submitted = true;
        if (!id) App.store.del(draftKey);
        toast(id ? 'Report updated' : 'Report submitted');
        App.go(id ? `reports/${r.id}` : '');
      } catch (err) {
        if (err.fields) {
          const fid = Object.keys(err.fields)[0];
          const si = schema.findIndex((s) => s.fields.some((f) => f.id === fid));
          if (si >= 0) { go(si); App.showErrors(form, err.fields); }
        }
        App.fail(err);
      }
    });
    go(steps()[0]);
  }

  /* ---------------------------------------------------------- report list */
  App.route('reports', (ctx) => reportsList(ctx), { title: 'Work reports', live: true });
  App.route('reports/:id', async (ctx) => { await reportsList(ctx); openReport(+ctx.params.id); }, { title: 'Work reports', nav: 'reports', live: true });

  const filterState = { from: '', to: '', user_id: '', location: '', finished: '', q: '' };

  async function reportsList(ctx) {
    const admin = isAdmin();
    const staff = admin ? (await api('users_list')).items : [];
    const statuses = ['Yes – completed', 'Partially completed', 'No – not completed'];
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Work reports' : 'My reports'}</h1><p class="muted" id="rp-count"></p></div>
        <div class="actions">${admin ? '<a class="btn" id="rp-export"><i class="ti ti-download"></i>Export CSV</a>' : ''}
          <a class="btn primary" href="#/report/new"><i class="ti ti-plus"></i>New report</a></div></div>
      <div class="filters" id="rp-filters">
        <input type="search" name="q" placeholder="Search answers, names…" value="${esc(filterState.q)}">
        ${admin ? `<select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), filterState.user_id, { placeholder: 'All staff' })}</select>` : ''}
        <select name="location">${App.opts(App.cfg.locations, filterState.location, { placeholder: 'All schools' })}</select>
        <select name="finished">${App.opts(statuses.map((s) => [s, finishedShort(s)]), filterState.finished, { placeholder: 'Any status' })}</select>
        <input type="date" name="from" value="${esc(filterState.from)}" aria-label="From date">
        <input type="date" name="to" value="${esc(filterState.to)}" aria-label="To date">
      </div>
      <div class="card" id="rp-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('reports_list', { query: filterState });
      const items = r.items;
      $('#rp-count', ctx.el).textContent = `${items.length} report${items.length === 1 ? '' : 's'}`;
      const exp = $('#rp-export', ctx.el);
      if (exp) exp.href = 'api.php?action=reports_export&' + new URLSearchParams(Object.entries(filterState).filter(([, v]) => v)).toString();
      const box = $('#rp-list', ctx.el);
      if (!items.length) { box.innerHTML = App.empty('clipboard-off', 'No reports found', 'Try changing the filters, or submit today\'s report.'); return; }
      box.innerHTML = `<div class="table-wrap desktop-only"><table class="tbl"><thead><tr>
          <th>Date</th>${admin ? '<th>Staff</th>' : ''}<th>School</th><th>Work type</th><th>Tasks</th><th class="r">Classes</th><th class="r">Faulty</th><th>Flags</th><th></th></tr></thead><tbody>
          ${items.map((x) => `<tr class="click" data-id="${x.id}"><td class="num">${esc(fmtDate(x.report_date))}</td>
            ${admin ? `<td>${esc(x.user_name)}</td>` : ''}<td>${esc(x.location)}</td><td class="ellipsis">${esc(x.work_type)}</td>
            <td>${App.finishedPill(x.finished)}</td><td class="r num">${x.classes_taught || ''}</td>
            <td class="r num ${x.laptops_faulty > 0 ? 'bad' : ''}">${x.laptops_faulty || ''}</td>
            <td>${flags(x)}</td><td class="faint">${x.files ? `<i class="ti ti-paperclip"></i>${x.files}` : ''}</td></tr>`).join('')}
          </tbody></table></div>
        <ul class="list mobile-only">${items.map((x) => `<li class="li" data-id="${x.id}">
          <div class="li-icon tone-brand"><i class="ti ti-clipboard-text"></i></div>
          <div class="main-col"><div class="t">${admin ? esc(x.user_name) + ' · ' : ''}${esc(fmtDate(x.report_date))}</div>
          <div class="s">${esc(x.location)} · ${esc(x.work_type)}</div></div>${App.finishedPill(x.finished)}</li>`).join('')}</ul>`;
      $$('[data-id]', box).forEach((row) => row.addEventListener('click', () => openReport(+row.dataset.id)));
    };
    $('#rp-filters', ctx.el).addEventListener('input', App.debounce((e) => {
      if (e.target.name) filterState[e.target.name] = e.target.value;
      load().catch(App.fail);
    }, 350));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  function flags(x) {
    const f = [];
    if (x.priority === 'Urgent' || x.priority === 'High') f.push(`<span class="pill ${x.priority === 'Urgent' ? 'bad' : 'out'}"><i class="ti ti-flag"></i>${esc(x.priority)}</span>`);
    if ((x.urgent || '').startsWith('Yes')) f.push('<span class="pill bad" title="Urgent request">Urgent</span>');
    if (x.complaint && x.complaint !== 'No complaint') f.push('<span class="pill bad">Complaint</span>');
    if (x.followup && x.followup !== 'No') f.push('<span class="pill info">Follow-up</span>');
    if (x.installation === 'Yes') f.push('<span class="pill brand">Install</span>');
    if (x.source === 'import') f.push('<span class="pill">Imported</span>');
    return f.join(' ');
  }

  /* -------------------------------------------------------- report drawer */
  async function openReport(id) {
    const el = App.drawer('Report', App.loading());
    let r;
    try { r = (await api('report_get', { query: { id } })).report; } catch (e) { App.closeTop(); App.fail(e); return; }
    const admin = isAdmin();
    const fields = fieldsById();
    const d = r.data || {};
    const secs = App.cfg.schema.map((sec) => {
      // Field-level conditions only: older reports may hold answers for sections their duties no longer switch on.
      const rows = sec.fields.filter((f) => visible(f, d)).map((f) => {
        let v = d[f.id];
        if (f.type === 'rows') {
          return (v || []).map((row, i) => `<div class="row-card view"><div class="row-card-head"><b>${esc(row.device || 'Device')}${row.tag ? ' · ' + esc(row.tag) : ''}</b>
            <span class="pill ${deviceTone(row.status)}">${esc(row.status || '—')}</span></div>
            ${row.faults?.length ? `<div class="chips" style="margin-bottom:6px">${row.faults.map((x) => `<span class="pill">${esc(x)}</span>`).join('')}</div>` : ''}
            ${f.cols.filter((c) => !['device', 'tag', 'faults', 'status'].includes(c.id) && row[c.id] !== '' && row[c.id] !== undefined).map((c) =>
              `<div class="qa"><div class="q">${esc(c.label)}</div><div class="a">${esc(c.id === 'cost' ? App.money(row[c.id]) : row[c.id])}</div></div>`).join('')}</div>`).join('');
        }
        if (Array.isArray(v)) v = v.join(', ');
        if (v === '' || v === undefined || v === null) return '';
        const isLink = typeof v === 'string' && /^https?:\/\//i.test(v.trim());
        const val = isLink ? v.split(/\s+/).map((u) => /^https?:/.test(u) ? `<a href="${esc(u)}" target="_blank" rel="noopener">${esc(u)}</a>` : esc(u)).join(' ') : esc(v);
        return `<div class="qa"><div class="q">${esc(f.label)}</div><div class="a">${val}</div></div>`;
      }).join('');
      return rows ? `<div class="sec-title">${esc(sec.title)}</div>${rows}` : '';
    }).join('');
    const extra = d._extra ? `<div class="sec-title">Other imported answers</div>${Object.entries(d._extra).map(([k, v]) =>
      `<div class="qa"><div class="q">${esc(k)}</div><div class="a">${esc(v)}</div></div>`).join('')}` : '';
    const canEdit = admin || r.user_id == App.user.id;
    $('.drawer-head h2', el).textContent = `${r.user_name} · ${fmtDate(r.report_date)}`;
    $('.drawer-body', el).innerHTML = `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">${App.finishedPill(r.finished)}${flags(r)}
        ${r.issues_closed == 1 ? '<span class="pill in"><i class="ti ti-check"></i>Handled</span>' : ''}</div>
      <dl class="kv" style="margin-bottom:6px"><dt>School</dt><dd>${esc(r.location)}</dd><dt>Did today</dt><dd>${esc(r.work_type)}</dd>
        <dt>Hours</dt><dd>${esc(d.check_in || '—')} – ${esc(d.check_out || '—')}</dd>
        <dt>Submitted</dt><dd>${esc(fmtDateTime(r.created_at))}${r.updated_at ? ` · edited ${esc(fmtDateTime(r.updated_at))}` : ''}</dd></dl>
      <div class="sec-title">Photos and files</div><div id="rd-files">${App.gallery(r.attachments, { removable: canEdit })}</div>
      ${r.expenses?.length ? `<div class="sec-title">Linked expenses</div>${r.expenses.map((x) => `<a class="li" href="#/txn/${x.id}" style="padding:8px 0;text-decoration:none;color:inherit"><div class="main-col"><div class="t">${esc(x.category)}</div><div class="s">${esc(fmtDate(x.txn_date))}</div></div><span class="amt out">${App.money(x.amount)}</span></a>`).join('')}` : ''}
      ${secs}${extra}`;
    App.bindGallery($('#rd-files', el));
    const foot = document.createElement('div');
    foot.className = 'drawer-foot';
    foot.innerHTML = `${admin ? `<button class="btn danger" id="rd-del"><i class="ti ti-trash"></i>Delete</button>
        <button class="btn" id="rd-handled"><i class="ti ti-${r.issues_closed == 1 ? 'arrow-back-up' : 'check'}"></i>${r.issues_closed == 1 ? 'Reopen issues' : 'Mark handled'}</button>` : ''}
      ${canEdit ? `<a class="btn primary" href="#/report/${r.id}/edit"><i class="ti ti-edit"></i>Edit</a>` : ''}`;
    el.appendChild(foot);
    $('#rd-del', el)?.addEventListener('click', async () => {
      if (!(await App.confirm('Delete this report?', 'The report and its photos will be permanently removed.', { ok: 'Delete report', danger: true }))) return;
      try { await api('report_delete', { data: { id: r.id } }); App.closeAll(); toast('Report deleted'); App.current?.refresh?.(); } catch (e) { App.fail(e); }
    });
    $('#rd-handled', el)?.addEventListener('click', async () => {
      try { await api('report_issue_toggle', { data: { id: r.id, closed: r.issues_closed != 1 } }); App.closeAll(); toast(r.issues_closed == 1 ? 'Reopened' : 'Marked as handled'); App.current?.refresh?.(); } catch (e) { App.fail(e); }
    });
  }
  App.openReport = openReport;

  /* --------------------------------------------------------- issues board */
  App.route('issues', async (ctx) => {
    let showClosed = false;
    let from = App.addDays(App.cfg.today, -30);
    ctx.el.innerHTML = `<div class="page-head"><div><h1>Issues board</h1><p class="muted">Pulled from daily reports. Mark a card handled once it's sorted.</p></div>
      <div class="actions"><label class="small muted" style="display:flex;gap:6px;align-items:center">Since <input type="date" id="is-from" value="${from}" style="width:auto;height:34px"></label>
      <label class="opt"><input type="checkbox" id="is-closed"><span>Show handled</span></label></div></div><div id="is-board">${App.loading()}</div>`;
    const cols = [
      ['pending', 'Pending tasks', 'hourglass', 'c-out'], ['laptops', 'Device faults', 'device-laptop-off', 'c-bad'],
      ['people', 'Complaints and follow-ups', 'message-report', 'c-info'], ['urgent', 'Urgent requests', 'urgent', 'c-bad'],
    ];
    const load = async () => {
      const r = await api('issues', { query: { from, closed: showClosed ? 1 : '' } });
      $('#is-board', ctx.el).innerHTML = `<div class="board">${cols.map(([k, title, icon, tone]) => `
        <div class="col"><div class="col-head"><i class="ti ti-${icon}"></i>${esc(title)}<span class="badge count">${r.columns[k].length}</span></div>
        ${r.columns[k].length ? r.columns[k].map((x) => `<div class="tcard ${tone}" data-id="${x.id}" style="${x.issues_closed == 1 ? 'opacity:.6' : ''}">
          <div class="meta"><span>${esc(x.user_name)}</span><span>${x.priority === 'Urgent' || x.priority === 'High' ? `<span class="pill ${x.priority === 'Urgent' ? 'bad' : 'out'}" style="margin-right:4px"><i class="ti ti-flag"></i>${esc(x.priority)}</span>` : ''}${esc(fmtDate(x.report_date, false))}</span></div>
          <div style="font-weight:500;font-size:13px">${esc(x.location)}${k === 'laptops' && x.laptops_faulty > 0 ? ` · ${x.laptops_faulty} faulty` : ''}${x.tag ? ` · ${esc(x.tag)}` : ''}</div>
          ${x.note ? `<div class="note">${esc(x.note)}</div>` : ''}
          <div style="margin-top:6px;text-align:right"><button class="btn sm ghost" data-mkjob="${k}" title="Turn this into a job order"><i class="ti ti-clipboard-list"></i>Create job</button></div></div>`).join('') : '<p class="faint small" style="padding:6px">Nothing here.</p>'}</div>`).join('')}</div>`;
      $$('.tcard', ctx.el).forEach((c) => c.addEventListener('click', (e) => {
        const mk = e.target.closest('[data-mkjob]');
        if (!mk) { openReport(+c.dataset.id); return; }
        const x = r.columns[mk.dataset.mkjob].find((i) => i.id === +c.dataset.id);
        const kind = { pending: 'Pending task', laptops: 'Device fault', people: x.tag || 'Follow-up', urgent: 'Urgent request' }[mk.dataset.mkjob];
        App.editJob?.(null, { title: `${kind}: ${x.location}`.slice(0, 190), client_name: x.location, location: x.location,
          description: `${x.note || ''}\n\nFrom ${x.user_name}'s report on ${fmtDate(x.report_date)}.`.trim(), source_report_id: x.id,
          priority: mk.dataset.mkjob === 'urgent' ? 'urgent' : 'normal', assignees: [x.user_id] });
      }));
    };
    $('#is-closed', ctx.el).addEventListener('change', (e) => { showClosed = e.target.checked; load().catch(App.fail); });
    $('#is-from', ctx.el).addEventListener('change', (e) => { from = e.target.value || from; load().catch(App.fail); });
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Issues', admin: true, live: true });
})();
