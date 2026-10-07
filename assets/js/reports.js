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
      let v = data[fid] ?? '';
      if (Array.isArray(v)) v = v.join(', ');
      v = String(v);
      switch (op) {
        case 'eq': return v === String(val);
        case 'ne': return v !== String(val);
        case 'gt': return v !== '' && !isNaN(v) && Number(v) > Number(val);
        case 'nempty': return v !== '';
        default: return true;
      }
    });
  }
  App.reportVisible = visible;

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
      default:
        control = `<input type="text" id="${id}" name="${f.id}" value="${esc(val)}" placeholder="${esc(f.ph || '')}">`;
    }
    const labelFor = ['radio', 'checks', 'confirm'].includes(f.type) ? '' : ` for="${id}"`;
    return `<div class="field" data-wrap="${f.id}"><label${labelFor} class="label">${esc(f.label)}${req}</label>${control}</div>`;
  }

  function readStep(form, sec, data) {
    sec.fields.forEach((f) => {
      if (f.type === 'checks') data[f.id] = $$(`input[name="${f.id}"]:checked`, form).map((i) => i.value);
      else if (f.type === 'radio') data[f.id] = $(`input[name="${f.id}"]:checked`, form)?.value || '';
      else if (f.type === 'confirm') data[f.id] = $(`input[name="${f.id}"]`, form)?.checked ? (f.text || 'Yes') : '';
      else { const el = $(`[name="${f.id}"]`, form); if (el) data[f.id] = el.value.trim(); }
    });
  }

  function validateStep(sec, data) {
    const errs = {};
    sec.fields.forEach((f) => {
      if (!visible(f, data)) return;
      const v = data[f.id];
      const empty = v === '' || v === undefined || v === null || (Array.isArray(v) && !v.length);
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
    }
    let step = 0;
    const total = schema.length;
    let uploader = null;
    const hadDraft = !id && App.store.get(draftKey, null);

    ctx.el.innerHTML = `<div class="wizard">
      <div class="page-head"><div><h1>${id ? 'Edit report' : 'Daily work report'}</h1>
        <p class="muted">${esc(owner.name)}${owner.email ? ' · ' + esc(owner.email) : ''}</p></div>
        <div class="actions">${hadDraft ? '<button class="btn ghost sm" id="wz-clear"><i class="ti ti-eraser"></i>Start over</button>' : ''}</div></div>
      <div class="stepper" id="wz-steps">${schema.map((s, i) => `<div class="s" data-step="${i}" title="${esc(s.title)}"></div>`).join('')}</div>
      <form class="card card-pad" id="wz-form" novalidate></form>
      <div class="card card-pad hide" id="wz-files" style="margin-top:14px"><div class="field" style="margin:0"><div class="label">Photos and files (optional)</div>
        ${existingFiles.length ? `<div id="wz-existing" style="margin-bottom:8px">${App.gallery(existingFiles, { removable: true })}</div>` : ''}
        <div id="wz-upload"></div><div class="help">Lesson photos, installation work, faulty laptops, receipts.</div></div></div>
      <div class="wizard-foot"><button class="btn" id="wz-back"><i class="ti ti-arrow-left"></i>Back</button>
        <span class="faint small" id="wz-saved"></span>
        <button class="btn primary" id="wz-next">Next<i class="ti ti-arrow-right"></i></button></div></div>`;
    const form = $('#wz-form', ctx.el);
    uploader = App.uploader($('#wz-upload', ctx.el), { existing: existingFiles.length });
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

    function render() {
      const sec = schema[step];
      $$('.s', ctx.el).forEach((s, i) => { s.className = 's' + (i < step ? ' done' : i === step ? ' on' : ''); });
      form.innerHTML = `<div class="step-title"><div class="li-icon tone-brand"><i class="ti ti-${esc(sec.icon || 'forms')}"></i></div>
        <div><div class="faint small">Step ${step + 1} of ${total}</div><h2>${esc(sec.title)}</h2></div></div>
        ${sec.fields.map((f) => renderField(f, data[f.id])).join('')}`;
      $('#wz-files', ctx.el).classList.toggle('hide', step !== total - 1);
      applyVisibility();
      $('#wz-back', ctx.el).style.visibility = step === 0 ? 'hidden' : 'visible';
      $('#wz-next', ctx.el).innerHTML = step === total - 1 ? `<i class="ti ti-send"></i>${id ? 'Save changes' : 'Submit report'}` : 'Next<i class="ti ti-arrow-right"></i>';
    }

    form.addEventListener('input', () => { readStep(form, schema[step], data); applyVisibility(); saveDraft(); });
    form.addEventListener('change', () => { readStep(form, schema[step], data); applyVisibility(); saveDraft(); });
    form.addEventListener('submit', (e) => e.preventDefault());

    const go = (n) => { step = Math.max(0, Math.min(total - 1, n)); render(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    $('#wz-back', ctx.el).addEventListener('click', (e) => { e.preventDefault(); readStep(form, schema[step], data); go(step - 1); });
    $$('.s', ctx.el).forEach((s) => s.addEventListener('click', () => {
      const target = +s.dataset.step;
      readStep(form, schema[step], data);
      if (target > step) {
        for (let i = step; i < target; i++) {
          const errs = validateStep(schema[i], data);
          if (Object.keys(errs).length) { if (i !== step) go(i); App.showErrors(form, errs); return; }
        }
      }
      go(target);
    }));
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
      if (step < total - 1) { go(step + 1); return; }
      for (let i = 0; i < total; i++) {
        const e2 = validateStep(schema[i], data);
        if (Object.keys(e2).length) { go(i); App.showErrors(form, e2); toast('Some answers need attention.', true); return; }
      }
      try {
        const r = await App.busy(e.currentTarget, () => api('report_save', { data: { id, data }, files: uploader ? uploader.files() : [] }));
        submitted = true;
        if (!id) App.store.del(draftKey);
        toast(id ? 'Report updated' : 'Report submitted');
        App.go(isAdmin() && id ? `reports/${r.id}` : id ? `reports/${r.id}` : '');
      } catch (err) {
        if (err.fields) {
          const fid = Object.keys(err.fields)[0];
          const si = schema.findIndex((s) => s.fields.some((f) => f.id === fid));
          if (si >= 0) { go(si); App.showErrors(form, err.fields); }
        }
        App.fail(err);
      }
    });
    render();
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
      const rows = sec.fields.filter((f) => visible(f, d)).map((f) => {
        let v = d[f.id];
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
      <dl class="kv" style="margin-bottom:6px"><dt>School</dt><dd>${esc(r.location)}</dd><dt>Work type</dt><dd>${esc(r.work_type)}</dd>
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
      ['pending', 'Pending tasks', 'hourglass', 'c-out'], ['laptops', 'Laptop faults', 'device-laptop-off', 'c-bad'],
      ['people', 'Complaints and follow-ups', 'message-report', 'c-info'], ['urgent', 'Urgent requests', 'urgent', 'c-bad'],
    ];
    const load = async () => {
      const r = await api('issues', { query: { from, closed: showClosed ? 1 : '' } });
      $('#is-board', ctx.el).innerHTML = `<div class="board">${cols.map(([k, title, icon, tone]) => `
        <div class="col"><div class="col-head"><i class="ti ti-${icon}"></i>${esc(title)}<span class="badge count">${r.columns[k].length}</span></div>
        ${r.columns[k].length ? r.columns[k].map((x) => `<div class="tcard ${tone}" data-id="${x.id}" style="${x.issues_closed == 1 ? 'opacity:.6' : ''}">
          <div class="meta"><span>${esc(x.user_name)}</span><span>${esc(fmtDate(x.report_date, false))}</span></div>
          <div style="font-weight:500;font-size:13px">${esc(x.location)}${k === 'laptops' ? ` · ${x.laptops_faulty} faulty` : ''}${x.tag ? ` · ${esc(x.tag)}` : ''}</div>
          ${x.note ? `<div class="note">${esc(x.note)}</div>` : ''}</div>`).join('') : '<p class="faint small" style="padding:6px">Nothing here.</p>'}</div>`).join('')}</div>`;
      $$('.tcard', ctx.el).forEach((c) => c.addEventListener('click', () => openReport(+c.dataset.id)));
    };
    $('#is-closed', ctx.el).addEventListener('change', (e) => { showClosed = e.target.checked; load().catch(App.fail); });
    $('#is-from', ctx.el).addEventListener('change', (e) => { from = e.target.value || from; load().catch(App.fail); });
    ctx.refresh = () => load().catch(() => {});
    await load();
  }, { title: 'Issues', admin: true, live: true });
})();
