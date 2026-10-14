/* DevTech Staff Portal — job orders: list, detail with timeline, assign, complete with photos, verify */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, ago, isAdmin } = App;

  const JOB_STATUS = {
    requested: ['Requested', 'info'], open: ['Open', 'brand'], in_progress: ['In progress', 'out'], awaiting_check: ['Awaiting check', 'out'],
    returned: ['Sent back', 'bad'], done: ['Done', 'in'], cancelled: ['Cancelled', ''],
  };
  const PRIORITY = { urgent: ['Urgent', 'bad'], high: ['High', 'out'], normal: ['Normal', ''], low: ['Low', ''] };
  const jobPill = (s) => { const [l, t] = JOB_STATUS[s] || [s, '']; return `<span class="pill ${t}">${esc(l)}</span>`; };
  const prioPill = (p) => (p === 'normal' || p === 'low' ? '' : `<span class="pill ${PRIORITY[p]?.[1] || ''}"><i class="ti ti-flag"></i>${esc(PRIORITY[p]?.[0] || p)}</span>`);
  const KIND_ICON = { created: 'circle-plus', assign: 'users', status: 'arrows-exchange', complete: 'circle-check', comment: 'message', followup: 'message-forward', report: 'clipboard-text', visit: 'map-pin', edit: 'edit', priority: 'flag', task: 'checkbox', extension: 'clock-plus' };

  /** Finger/mouse signature pad. Returns { file: () => Promise<File|null>, clear, isEmpty }. */
  function signaturePad(canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let empty = true;
    const fit = () => {
      const r = canvas.getBoundingClientRect();
      canvas.width = Math.round(r.width * devicePixelRatio);
      canvas.height = Math.round(r.height * devicePixelRatio);
      ctx.setTransform(devicePixelRatio, 0, 0, devicePixelRatio, 0, 0);
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, r.width, r.height);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111';
      empty = true;
    };
    const pt = (e) => { const r = canvas.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
    canvas.addEventListener('pointerdown', (e) => { drawing = true; canvas.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.moveTo(...pt(e)); e.preventDefault(); });
    canvas.addEventListener('pointermove', (e) => { if (!drawing) return; ctx.lineTo(...pt(e)); ctx.stroke(); empty = false; e.preventDefault(); });
    canvas.addEventListener('pointerup', () => { drawing = false; });
    setTimeout(fit, 30);
    return {
      clear: fit,
      isEmpty: () => empty,
      file: () => new Promise((resolve) => (empty ? resolve(null) : canvas.toBlob((b) => resolve(b ? new File([b], 'signature.png', { type: 'image/png' }) : null), 'image/png'))),
    };
  }
  App.jobPill = jobPill;
  /** Tells an open clock-out checklist to reload. */
  const jobsChanged = () => document.dispatchEvent(new Event('jobs:changed'));

  let staffCache = null;
  let clientCache = null;
  const activeStaff = async () => {
    if (!staffCache) staffCache = (await api('users_list')).items.filter((u) => u.active);
    return staffCache;
  };

  const assigneeChips = (list) => (list.length ? list.map((a) => `<span class="chip-user"><span class="avatar xs">${esc(App.initials(a.name))}</span>${esc(a.name)}</span>`).join('')
    : '<span class="faint small">Not assigned</span>');

  /** "14:00" → "2:00pm". */
  const timeLabel = (t) => { if (!t) return ''; const [h, m] = t.split(':').map(Number); return `${h % 12 || 12}:${String(m).padStart(2, '0')}${h < 12 ? 'am' : 'pm'}`; };
  /** "14 Oct, 2:00pm"; no date means it's due today. */
  const dueLabel = (d, t) => (d ? `${fmtDate(d, false)}${t ? ', ' + timeLabel(t) : ''}` : `today${t ? ', ' + timeLabel(t) : ''}`);
  App.dueLabel = dueLabel;
  const dueText = (j) => (j.due_date ? `<span class="${j.overdue ? 'bad' : ''}">${j.overdue ? '<i class="ti ti-alert-triangle"></i> ' : ''}Due ${esc(dueLabel(j.due_date, j.due_time))}</span>` : '');
  /** Time left before an item expires: its due date and time (or the end of the work day when it has no time). */
  App.timeLeft = (date, time) => {
    const end = App.parseDate(`${date || App.cfg.today} ${(time || App.cfg.work_end || '17:00').slice(0, 5)}:00`);
    const min = Math.round((end.getTime() - Date.now()) / 60000);
    const span = (m) => (m >= 1440 ? `${Math.floor(m / 1440)}d ${Math.floor((m % 1440) / 60)}h` : m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m}m`);
    return min >= 0 ? { late: false, text: `${span(min)} left` } : { late: true, text: `Expired ${span(-min)} ago` };
  };
  /** "2h 10m left" / "Expired 40m ago" pill for an open item. */
  App.timeLeftPill = (date, time) => { const t = App.timeLeft(date, time); return `<span class="pill ${t.late ? 'bad' : 'info'}" data-left="${esc(date || '')}|${esc(time || '')}"><i class="ti ti-hourglass"></i>${esc(t.text)}</span>`; };
  /** Keeps every time-left pill inside root current while it's on screen. */
  App.tickTimeLeft = (root) => {
    const t = setInterval(() => {
      if (!root.isConnected) { clearInterval(t); return; }
      $$('[data-left]', root).forEach((p) => {
        const [d, h] = p.dataset.left.split('|');
        const x = App.timeLeft(d, h);
        p.className = `pill ${x.late ? 'bad' : 'info'}`;
        p.lastChild.textContent = x.text;
      });
    }, 60000);
  };
  /** Due today, overdue or undated: it's on the staff member's checklist for today. */
  const isDue = (x) => !x.due_date || x.due_date <= App.cfg.today;
  const taskChip = (j) => (j.tasks_total ? ` <span class="pill ${j.tasks_done === j.tasks_total ? 'in' : ''}"><i class="ti ti-checkbox"></i>${j.tasks_done}/${j.tasks_total} tasks</span>` : '')
    + (j.ext_pending ? ' <span class="pill out"><i class="ti ti-clock-plus"></i>More time asked</span>' : '')
    + (j.self_closed ? ' <span class="pill out" title="Closed by staff: no admin checked it within an hour"><i class="ti ti-user-check"></i>No admin check</span>' : '');

  /* ----------------------------------------------------------------- list */
  const TABS_ADMIN = [['active', 'Active'], ['awaiting_check', 'To check'], ['requested', 'Requested'], ['done', 'Done'], ['cancelled', 'Cancelled'], ['', 'All']];
  const TABS_STAFF = [['active', 'Active'], ['awaiting_check', 'Waiting check'], ['done', 'Done'], ['', 'All']];
  const state = { status: 'active', q: '', user_id: '', overdue: '' };

  App.route('jobs', (ctx) => jobsList(ctx), { title: 'Job orders', live: true });
  App.route('jobs/:id', async (ctx) => { await jobsList(ctx); openJob(+ctx.params.id); }, { title: 'Job orders', nav: 'jobs', live: true });

  async function jobsList(ctx) {
    const admin = isAdmin();
    App.setTitle(admin ? 'Job orders' : 'My jobs');
    const tabs = admin ? TABS_ADMIN : TABS_STAFF;
    const staff = admin ? await activeStaff() : [];
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Job orders' : 'My jobs'}</h1><p class="muted">${admin ? 'Tasks collected from schools and companies.' : 'Jobs assigned to you, and the ones you logged.'}</p></div>
        <div class="actions">${admin ? '<a class="btn" id="jb-export"><i class="ti ti-download"></i>Export CSV</a>' : ''}
          <button class="btn primary" id="jb-new"><i class="ti ti-plus"></i>${admin ? 'New job' : 'Log a job'}</button></div></div>
      <div class="seg scroll" id="jb-tabs" style="margin-bottom:12px">${tabs.map(([k, l]) => `<button data-s="${k}" class="${k === state.status ? 'on' : ''}">${l}<span class="count" data-count="${k}"></span></button>`).join('')}</div>
      <div class="filters" id="jb-filters">
        <input type="search" name="q" placeholder="Search job, school, company, JO-0001…" value="${esc(state.q)}">
        ${admin ? `<select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), state.user_id, { placeholder: 'Anyone' })}</select>` : ''}
        <label class="opt"><input type="checkbox" name="overdue" ${state.overdue ? 'checked' : ''}><span>Overdue only</span></label>
      </div>
      <div class="card" id="jb-list">${App.loading()}</div>`;
    if (!tabs.some(([k]) => k === state.status)) state.status = 'active';
    const load = async () => {
      const r = await api('jobs_list', { query: state });
      const c = r.counts;
      const tabCount = { active: (c.open || 0) + (c.in_progress || 0) + (c.returned || 0) + (c.requested || 0) + (c.awaiting_check || 0) };
      $$('[data-count]', ctx.el).forEach((el) => {
        const n = el.dataset.count === 'active' ? tabCount.active : el.dataset.count ? c[el.dataset.count] || 0 : Object.values(c).reduce((a, b) => a + b, 0);
        el.textContent = n ? ` ${n}` : '';
      });
      const exp = $('#jb-export', ctx.el);
      if (exp) exp.href = 'api.php?action=jobs_export&' + new URLSearchParams(Object.entries(state).filter(([, v]) => v)).toString();
      const box = $('#jb-list', ctx.el);
      if (!r.items.length) {
        box.innerHTML = App.empty('clipboard-list', 'No jobs here', admin ? 'Create a job and assign it to staff.' : 'Jobs your admin assigns to you show up here.');
        return;
      }
      box.innerHTML = `<div class="table-wrap desktop-only"><table class="tbl"><thead><tr><th>Job</th><th>Client</th><th>Assigned to</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
        ${r.items.map((j) => `<tr class="click" data-job="${j.id}"><td><div style="font-weight:500">${esc(j.title)}</div><div class="faint small">${esc(j.ref)} ${prioPill(j.priority)}${taskChip(j)}</div></td>
          <td>${esc(j.client_name)}<div class="faint small">${esc(j.location)}</div></td><td><div class="chips">${assigneeChips(j.assignees)}</div></td>
          <td class="small">${dueText(j) || '<span class="faint">—</span>'}</td><td>${jobPill(j.status)}</td>
          <td class="faint small">${j.photos ? `<i class="ti ti-photo"></i>${j.photos}` : ''}</td></tr>`).join('')}</tbody></table></div>
        <ul class="list mobile-only">${r.items.map((j) => `<li class="li" data-job="${j.id}">
          <div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
          <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.ref)} · ${esc(j.client_name)}${j.assignees.length && admin ? ' · ' + esc(j.assignees.map((a) => a.name.split(' ')[0]).join(', ')) : ''}</div>
          ${j.due_date || j.tasks_total || j.ext_pending || j.self_closed ? `<div class="s">${dueText(j)}${taskChip(j)}</div>` : ''}</div><div style="text-align:right">${jobPill(j.status)}${prioPill(j.priority) ? '<div style="margin-top:3px">' + prioPill(j.priority) + '</div>' : ''}</div></li>`).join('')}</ul>`;
      $$('[data-job]', box).forEach((row) => row.addEventListener('click', () => openJob(+row.dataset.job)));
    };
    $$('#jb-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => {
      state.status = b.dataset.s;
      $$('#jb-tabs button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      load().catch(App.fail);
    }));
    $('#jb-filters', ctx.el).addEventListener('input', App.debounce((e) => {
      if (!e.target.name) return;
      state[e.target.name] = e.target.type === 'checkbox' ? (e.target.checked ? 1 : '') : e.target.value;
      load().catch(App.fail);
    }, 300));
    $('#jb-new', ctx.el).addEventListener('click', () => editJob(null));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  /** "Signed off by …" with the client's signature, or a warning when the client didn't sign. */
  function signoffHtml(j) {
    if (!j.completed_at) return '';
    if (j.signoff_skipped) return `<div class="note-box warn" style="margin-top:12px"><i class="ti ti-signature-off"></i> <b>Client didn't sign:</b> ${esc(j.signoff_skipped)}</div>`;
    if (!j.signoff_name) return '';
    const sig = j.updates.flatMap((u) => u.files).filter((f) => f.name === 'signature.png').pop();
    return `<div class="sec-title">Client sign-off</div><div class="signoff">
      ${sig ? `<img src="file.php?id=${sig.id}" alt="Signature of ${esc(j.signoff_name)}">` : ''}
      <div><b>${esc(j.signoff_name)}</b>${j.signoff_phone ? ` · <a href="tel:${esc(j.signoff_phone)}">${esc(j.signoff_phone)}</a>` : ''}
      <div class="faint small">Signed ${esc(fmtDateTime(j.signoff_at))}</div></div></div>`;
  }

  /** "12:05pm" from "2026-10-14 12:05:00". */
  const clock = (dt) => timeLabel(String(dt || '').slice(11, 16));
  /** What the person who asked for more time can do: wait, or go ahead once an admin hasn't answered within the hour. */
  App.extWaitHtml = (x) => (x.can_proceed
    ? `<div class="small" style="margin-top:6px">No admin answered within an hour.</div>
       <button type="button" class="btn sm primary" data-ext-proceed="${x.id}" style="margin-top:6px"><i class="ti ti-player-play"></i>No answer: go ahead with the new time</button>`
    : `<div class="faint small" style="margin-top:4px">Waiting for an admin to answer. If nobody answers by ${esc(clock(x.proceed_at))}, you can go ahead yourself.</div>`);
  /** Go ahead with a request for more time that no admin answered within the hour. */
  App.proceedExtension = async (btn, extId, done) => {
    if (!(await App.confirm('Go ahead without an answer?', 'The due date moves to the time you asked for. The admins are told nobody answered.', { ok: 'Go ahead' }))) return;
    try {
      await App.busy(btn, () => api('job_extension_proceed', { data: { extension_id: extId } }));
      toast('Due date moved. The admins have been told.');
      App.current?.refresh?.();
      jobsChanged();
      done && done();
    } catch (err) { App.fail(err); }
  };

  /** A pending request for more time, with Approve / Refuse for admins. */
  const extBox = (x, admin) => `<div class="ext-box" data-ext="${x.id}"><div class="small"><i class="ti ti-clock-plus"></i> <b>${esc(x.user_name)}</b> asked for more time,
    until <b>${esc(dueLabel(x.new_due_date, x.new_due_time))}</b> <span class="faint">· ${esc(ago(x.created_at))}</span></div>
    <div class="small" style="white-space:pre-wrap;margin-top:2px">${esc(x.reason)}</div>
    ${admin ? `<div style="display:flex;gap:6px;margin-top:8px"><button class="btn sm success" data-ext-decide="approve"><i class="ti ti-check"></i>Approve</button>
      <button class="btn sm danger" data-ext-decide="reject"><i class="ti ti-x"></i>Refuse</button></div>`
      : x.user_id === App.user.id ? App.extWaitHtml(x) : '<div class="faint small" style="margin-top:4px">Waiting for an admin to answer.</div>'}</div>`;

  /** The job's tasks as a checklist. */
  function tasksHtml(j, admin) {
    const t = j.tasks || [];
    const canEdit = admin && !['done', 'cancelled'].includes(j.status);
    if (!t.length && !canEdit) return '';
    const canTick = ['open', 'in_progress', 'returned', 'requested'].includes(j.status);
    const done = t.filter((x) => x.status === 'done').length;
    const row = (x) => {
      const pe = x.pending_extension;
      const askMore = !admin && x.mine && x.status === 'open' && !pe && isDue(x) && ['open', 'in_progress', 'returned'].includes(j.status);
      return `<div class="task-row ${x.status === 'done' ? 'done' : ''}" data-task="${x.id}">
        <input type="checkbox" data-tick ${x.status === 'done' ? 'checked' : ''} ${canTick && (x.mine || admin) ? '' : 'disabled'} aria-label="Done: ${esc(x.title)}">
        <div class="task-main"><div class="task-t" style="font-weight:500">${esc(x.title)}</div>
          ${x.notes ? `<div class="small muted" style="white-space:pre-wrap">${esc(x.notes)}</div>` : ''}
          <div class="small">${x.status === 'done' ? `<span class="faint">Done${x.done_by_name ? ' by ' + esc(x.done_by_name) : ''} · ${esc(fmtDateTime(x.done_at))}</span>${x.done_note ? `<div class="muted">${esc(x.done_note)}</div>` : ''}`
            : `${dueText(x) || '<span class="faint">No due date</span>'} ${App.timeLeftPill(x.due_date, x.due_time)}`}</div>
          <div class="chips">${assigneeChips(x.assignees)}</div>
          ${pe ? extBox(pe, admin) : ''}
          ${askMore ? '<button class="btn sm ghost" data-extend style="margin-top:6px"><i class="ti ti-clock-plus"></i>Ask for more time</button>' : ''}</div>
        ${canEdit ? `<div class="task-acts"><button class="icon-btn" data-edit-task title="Edit task"><i class="ti ti-edit"></i></button>
          <button class="icon-btn" data-del-task title="Delete task"><i class="ti ti-trash"></i></button></div>` : ''}</div>`;
    };
    return `<div class="sec-title" style="display:flex;align-items:center;justify-content:space-between">
        <span>Tasks ${t.length ? `<span class="faint small">· ${done} of ${t.length} done</span>` : ''}</span>
        ${canEdit ? '<button class="btn sm ghost" id="jd-task-add"><i class="ti ti-plus"></i>Add task</button>' : ''}</div>
      ${t.length ? `<div class="task-bar"><span style="width:${Math.round((done / t.length) * 100)}%"></span></div><div id="jd-tasks">${t.map(row).join('')}</div>`
        : '<p class="faint small">Split the job into tasks, each with its own staff and time frame.</p>'}`;
  }

  /** Approve or refuse a request for more time. */
  async function decideExtension(btn, extId, decision, done) {
    let note = '';
    if (decision === 'reject') {
      note = await App.confirm('Refuse more time?', 'They\'ll be told to finish it today, and can\'t clock out for the day until it\'s done.', { ok: 'Refuse', danger: true,
        input: { label: 'Why?', required: true } });
      if (!note) return;
    } else if (!(await App.confirm('Approve more time?', 'The due date moves to the time they asked for, and they can clock out.', { ok: 'Approve' }))) return;
    try {
      await App.busy(btn, () => api('job_extension_decide', { data: { extension_id: extId, decision, note } }));
      toast(decision === 'approve' ? 'More time approved' : 'Request refused');
      App.current?.refresh?.();
      done && done();
    } catch (err) { App.fail(err); }
  }

  /** Ask an admin for more time on a job or task that is due. item: {job_id, task_id?, ref, title}. */
  App.requestExtension = (item, done) => {
    const tomorrow = App.addDays ? App.addDays(App.cfg.today, 1) : '';
    const el = App.modal(`More time for ${esc(item.ref)}`, `<form id="jx2-form" novalidate>
      <p class="muted" style="margin-bottom:12px"><b>${esc(item.title)}</b><br>Work isn't carried to another day without an admin's approval.
        You can't end your day until they answer.</p>
      <div class="field"><label for="jx2-r">Why isn't it finished? <span class="req">*</span></label>
        <textarea id="jx2-r" name="reason" rows="3" placeholder="e.g. The school's power was off from 11am; 2 of 4 projectors are mounted"></textarea></div>
      <div class="form-row"><div class="field"><label for="jx2-d">Finish by <span class="req">*</span></label><input type="date" id="jx2-d" name="new_due_date" min="${esc(App.cfg.today)}" value="${esc(tomorrow)}"></div>
        <div class="field"><label for="jx2-t">Time</label><input type="time" id="jx2-t" name="new_due_time"></div></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="jx2-ok"><i class="ti ti-send"></i>Ask admin</button>');
    $('#jx2-ok', el).addEventListener('click', async (e) => {
      const form = $('#jx2-form', el);
      const d = App.formData(form);
      const errs = {};
      if (!d.reason.trim()) errs.reason = 'Say why it isn\'t finished.';
      if (!d.new_due_date) errs.new_due_date = 'Choose when it will be finished.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(e.currentTarget, () => api('job_extend', { data: { id: item.job_id, task_id: item.task_id || 0, ...d } }));
        App.closeTop();
        toast('Sent. An admin has been asked to approve.');
        jobsChanged();
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  };

  /** Admin adds or edits one task. */
  async function editTask(j, t, done) {
    const staff = await activeStaff();
    const v = { title: '', notes: '', due_date: j.due_date && j.due_date >= App.cfg.today ? j.due_date : App.cfg.today, due_time: '', ...(t || {}) };
    const who = new Set((t?.assignees || j.assignees || []).map((a) => +a.id));
    const el = App.modal(t ? 'Edit task' : `Add a task to ${esc(j.ref)}`, `<form id="jt-form" novalidate>
      <div class="field"><label for="jt-t">Task <span class="req">*</span></label><input id="jt-t" name="title" value="${esc(v.title)}" placeholder="e.g. Mount the projector in JSS2" autofocus></div>
      <div class="field"><label for="jt-n">Notes</label><textarea id="jt-n" name="notes" rows="2" placeholder="Details, materials, who to see">${esc(v.notes || '')}</textarea></div>
      <div class="form-row"><div class="field"><label for="jt-d">Due date <span class="req">*</span></label><input type="date" id="jt-d" name="due_date" value="${esc(v.due_date || '')}"></div>
        <div class="field"><label for="jt-h">Due time</label><input type="time" id="jt-h" name="due_time" value="${esc(v.due_time || '')}"></div></div>
      <div class="field" data-field="assignees"><div class="label">Who does it? <span class="req">*</span></div><div class="opts">${staff.map((s) => `<label class="opt">
        <input type="checkbox" data-multi="1" name="assignees" value="${s.id}" ${who.has(s.id) ? 'checked' : ''}><span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}</span></label>`).join('')}</div>
        <div class="help">Anyone not on the job yet is added to it.</div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button>${t ? '' : '<button class="btn" id="jt-more">Save and add another</button>'}<button class="btn primary" id="jt-ok">Save task</button>`, { wide: true });
    const save = async (btn, again) => {
      const form = $('#jt-form', el);
      const d = App.formData(form);
      const errs = {};
      if (!d.title.trim()) errs.title = 'Say what needs to be done.';
      if (!d.due_date) errs.due_date = 'Set a due date.';
      if (!(d.assignees || []).length) errs.assignees = 'Choose at least one person.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(btn, () => api('job_task_save', { data: { id: j.id, task_id: t?.id || 0, ...d, assignees: d.assignees.map(Number) } }));
        toast(t ? 'Task saved' : 'Task added');
        App.current?.refresh?.();
        if (again) { form.title.value = ''; form.notes.value = ''; form.title.focus(); } else App.closeTop();
        done && done();
      } catch (err) { App.fail(err); }
    };
    $('#jt-ok', el).addEventListener('click', (e) => save(e.currentTarget, false));
    $('#jt-more', el)?.addEventListener('click', (e) => save(e.currentTarget, true));
  }

  /* ------------------------------------------------------------- detail */
  async function openJob(id) {
    const el = App.drawer('Job', App.loading());
    const render = async () => {
      let j;
      try { j = (await api('job_get', { query: { id } })).job; } catch (e) { App.closeTop(); App.fail(e); return; }
      const admin = isAdmin();
      const mine = j.is_assignee;
      const active = ['open', 'in_progress', 'returned'].includes(j.status);
      $('.drawer-head h2', el).textContent = `${j.ref} · ${j.title}`;
      $('.drawer-body', el).innerHTML = `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">${jobPill(j.status)}${prioPill(j.priority)}${j.overdue ? '<span class="pill bad"><i class="ti ti-alert-triangle"></i>Overdue</span>' : ''}
          ${active && j.due_date ? App.timeLeftPill(j.due_date, j.due_time) : ''}${j.self_closed ? '<span class="pill out"><i class="ti ti-user-check"></i>Closed without admin check</span>' : ''}</div>
        ${j.status === 'awaiting_check' && j.self_close_at ? `<div class="note-box ${admin ? 'warn' : ''}" style="margin-bottom:12px"><i class="ti ti-hourglass"></i>
          ${admin ? `Check the photos by ${esc(clock(j.self_close_at))}. After that, the staff on this job can close it themselves.`
            : new Date() >= App.parseDate(j.self_close_at) ? 'No admin checked this within an hour. You can close it yourself.'
              : `Waiting for an admin to check. If nobody checks it by ${esc(clock(j.self_close_at))}, you can close it yourself.`}</div>` : ''}
        ${j.status === 'returned' && j.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>Sent back:</b> ${esc(j.review_note)}</div>` : ''}
        ${j.status === 'requested' ? `<div class="note-box warn" style="margin-bottom:12px">Logged by ${esc(j.created_by_name || 'staff')}. ${admin ? 'Assign someone to accept it.' : 'Waiting for an admin to accept and assign it.'}</div>` : ''}
        ${j.pending_extension ? extBox(j.pending_extension, admin) : ''}
        <dl class="kv"><dt>${j.client_type === 'company' ? 'Company' : j.client_type === 'school' ? 'School' : 'Client'}</dt><dd>${esc(j.client_name)}</dd>
          ${j.location ? `<dt>Location</dt><dd>${esc(j.location)}</dd>` : ''}
          ${j.contact_name || j.contact_phone ? `<dt>Contact</dt><dd>${esc(j.contact_name)}${j.contact_phone ? ` · <a href="tel:${esc(j.contact_phone)}">${esc(j.contact_phone)}</a>` : ''}</dd>` : ''}
          ${j.due_date ? `<dt>Due</dt><dd>${dueText(j)}</dd>` : ''}
          <dt>Logged</dt><dd>${esc(fmtDateTime(j.created_at))}${j.created_by_name ? ' by ' + esc(j.created_by_name) : ''}</dd>
          ${j.source_report_id ? `<dt>From report</dt><dd><a href="#/reports/${j.source_report_id}">View report</a></dd>` : ''}</dl>
        ${j.description ? `<div class="sec-title">What needs to be done</div><p style="white-space:pre-wrap">${esc(j.description)}</p>` : ''}
        ${signoffHtml(j)}
        ${tasksHtml(j, admin)}
        <div class="sec-title" style="display:flex;align-items:center;justify-content:space-between">Assigned to
          ${admin && !['done', 'cancelled'].includes(j.status) ? '<button class="btn sm ghost" id="jd-assign"><i class="ti ti-user-plus"></i>Assign / reassign</button>' : ''}</div>
        <div class="chips">${assigneeChips(j.assignees)}</div>
        <div class="sec-title">Timeline</div>
        <ol class="timeline">${j.updates.map((u) => `<li class="tl-${esc(u.kind)}"><div class="tl-dot"><i class="ti ti-${KIND_ICON[u.kind] || 'point'}"></i></div>
          <div class="tl-body"><div class="small"><b>${esc(u.user_name || 'System')}</b> <span class="faint">· ${esc(ago(u.created_at))}</span></div>
          ${u.body ? `<div style="white-space:pre-wrap">${esc(u.body)}</div>` : ''}
          ${u.report_id ? `<a class="small" href="#/reports/${u.report_id}">Open report</a>` : ''}
          ${u.files.length ? `<div class="tl-files" style="margin-top:6px">${App.gallery(u.files)}</div>` : ''}</div></li>`).join('')}</ol>
        ${!['cancelled'].includes(j.status) ? `<form id="jd-comment" class="card card-pad" style="margin-top:8px;background:var(--surface-2)">
          <div class="field" style="margin-bottom:8px"><label for="jd-body">${admin ? 'Follow up' : 'Add an update'}</label>
            <textarea id="jd-body" name="body" rows="2" placeholder="${admin ? 'Ask for progress, give instructions…' : 'What happened, what\'s next, anything you need…'}"></textarea></div>
          <div id="jd-up"></div><button class="btn sm" type="submit" style="margin-top:8px"><i class="ti ti-send"></i>Post</button></form>` : ''}`;
      $$('.tl-files', el).forEach((g) => App.bindGallery(g));
      const up = $('#jd-up', el) ? App.uploader($('#jd-up', el), { label: 'Photo', capture: true }) : null;
      $('#jd-comment', el)?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = e.target.body.value.trim();
        if (!body && !up.count()) { toast('Write an update or add a photo.', true); return; }
        try { await App.busy(e.submitter, () => api('job_update', { data: { id: j.id, body }, files: up.files() })); toast('Update posted'); render(); } catch (err) { App.fail(err); }
      });
      $('#jd-assign', el)?.addEventListener('click', () => assignJob(j, render));
      $('#jd-task-add', el)?.addEventListener('click', () => editTask(j, null, render));
      $$('[data-ext-decide]', el).forEach((b) => b.addEventListener('click', () => decideExtension(b, +b.closest('[data-ext]').dataset.ext, b.dataset.extDecide, render)));
      $$('[data-ext-proceed]', el).forEach((b) => b.addEventListener('click', () => App.proceedExtension(b, +b.dataset.extProceed, render)));
      App.tickTimeLeft($('.drawer-body', el));
      $$('[data-task]', el).forEach((row) => {
        const t = j.tasks.find((x) => x.id === +row.dataset.task);
        $('[data-tick]', row).addEventListener('change', async (e) => {
          e.target.disabled = true;
          try {
            const r = await api('job_task_toggle', { data: { task_id: t.id, done: e.target.checked ? 1 : 0 } });
            toast(e.target.checked ? (r.open ? 'Task done' : 'All tasks done. Mark the job complete with photos.') : 'Task reopened');
            jobsChanged();
            App.current?.refresh?.();
            render();
          } catch (err) { e.target.checked = !e.target.checked; e.target.disabled = false; App.fail(err); }
        });
        $('[data-edit-task]', row)?.addEventListener('click', () => editTask(j, t, render));
        $('[data-del-task]', row)?.addEventListener('click', async (e) => {
          if (!(await App.confirm('Delete this task?', esc(t.title), { ok: 'Delete', danger: true }))) return;
          try { await App.busy(e.currentTarget, () => api('job_task_delete', { data: { task_id: t.id } })); toast('Task deleted'); App.current?.refresh?.(); render(); } catch (err) { App.fail(err); }
        });
        $('[data-extend]', row)?.addEventListener('click', () => App.requestExtension({ job_id: j.id, task_id: t.id, ref: j.ref, title: t.title }, render));
      });

      let foot = $('.drawer-foot', el);
      if (!foot) { foot = document.createElement('div'); foot.className = 'drawer-foot'; el.appendChild(foot); }
      const btns = [];
      if (admin) {
        if (j.status !== 'done' && j.status !== 'cancelled') btns.push('<button class="btn ghost" data-a="cancel"><i class="ti ti-ban"></i>Cancel job</button>');
        if (['done', 'cancelled'].includes(j.status)) btns.push('<button class="btn" data-a="reopen"><i class="ti ti-arrow-back-up"></i>Reopen</button>');
        btns.push('<button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>');
        if (j.status === 'requested') btns.push('<button class="btn primary" data-a="accept"><i class="ti ti-user-check"></i>Accept & assign</button>');
        if (j.status === 'awaiting_check' || j.status === 'done') btns.push('<button class="btn danger" data-a="return"><i class="ti ti-arrow-back"></i>Send back</button>');
        if (j.status === 'awaiting_check') btns.push('<button class="btn success" data-a="approve"><i class="ti ti-check"></i>Approve</button>');
      }
      if (!admin && (mine || +j.created_by === App.user.id) && j.priority !== 'urgent' && !['done', 'cancelled', 'awaiting_check'].includes(j.status)) {
        btns.push('<button class="btn ghost" data-a="escalate"><i class="ti ti-flag"></i>Raise priority</button>');
      }
      const openTasks = (j.tasks || []).filter((t) => t.status === 'open').length;
      if (mine && active && !openTasks && !j.pending_extension && isDue(j)) btns.push('<button class="btn ghost" data-a="extend"><i class="ti ti-clock-plus"></i>More time</button>');
      if (mine && ['open', 'returned'].includes(j.status)) btns.push('<button class="btn" data-a="start"><i class="ti ti-player-play"></i>Start</button>');
      if (mine && active) btns.push('<button class="btn primary" data-a="complete"><i class="ti ti-camera-check"></i>Mark complete</button>');
      if (mine && j.status === 'awaiting_check' && j.self_close_at && new Date() >= App.parseDate(j.self_close_at)) {
        btns.push('<button class="btn primary" data-a="self_close"><i class="ti ti-lock-check"></i>Close job myself</button>');
      }
      if (!admin && !mine && j.status === 'requested') btns.push('<button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>');
      foot.innerHTML = btns.join('');
      foot.classList.toggle('hide', !btns.length);
      $$('[data-a]', foot).forEach((b) => b.addEventListener('click', () => act(b, b.dataset.a, j)));
    };
    const act = async (btn, a, j) => {
      if (a === 'edit') { editJob(j, null, render); return; }
      if (a === 'accept') { assignJob(j, render); return; }
      if (a === 'extend') { App.requestExtension({ job_id: j.id, ref: j.ref, title: j.title }, render); return; }
      if (a === 'complete') {
        const open = (j.tasks || []).filter((t) => t.status === 'open').length;
        if (open && !(await App.confirm(`${open} task${open === 1 ? ' is' : 's are'} still open`, 'Tick off the finished tasks first. Complete the whole job anyway?', { ok: 'Complete anyway' }))) return;
        completeJob(j, render);
        return;
      }
      if (a === 'escalate') { escalateJob(j, render); return; }
      let note = '';
      if (a === 'return') {
        note = await App.confirm('Send back to staff?', 'They\'ll be notified and the job goes back to their active list.', { ok: 'Send back', danger: true,
          input: { label: 'What still needs to be done?', required: true } });
        if (!note) return;
      } else if (a === 'cancel') {
        note = await App.confirm('Cancel this job?', 'Assigned staff will be notified.', { ok: 'Cancel job', danger: true, input: { label: 'Reason (optional)' } });
        if (note === null) return;
      } else if (a === 'approve') {
        note = await App.confirm('Approve and close?', 'Make sure the photos show the work was done.', { ok: 'Approve', input: { label: 'Note (optional)' } });
        if (note === null) return;
      } else if (a === 'self_close') {
        note = await App.confirm('Close this job yourself?', 'No admin checked it within an hour. The admins are told, and can still send it back.', { ok: 'Close job', input: { label: 'Note (optional)' } });
        if (note === null) return;
      }
      try {
        await App.busy(btn, () => api('job_status', { data: { id: j.id, action: a, note: note === true ? '' : note } }));
        toast({ start: 'Job started', approve: 'Job approved', return: 'Sent back', cancel: 'Job cancelled', reopen: 'Job reopened', self_close: 'Job closed. The admins have been told.' }[a]);
        App.current?.refresh?.();
        render();
      } catch (err) { App.fail(err); }
    };
    await render();
  }
  App.openJob = openJob;

  /* --------------------------------------------------------- complete */
  function completeJob(j, done) {
    const el = App.modal(`Complete ${esc(j.ref)}`, `
      <form id="jc-form" novalidate>
        <p class="muted" style="margin-bottom:12px">Take photos of the finished work and get the client to sign. An admin will check before the job is closed.</p>
        <div class="field" data-field="files"><div class="label">Photos of the finished work <span class="req">*</span></div><div id="jc-up"></div></div>
        <div class="field"><label for="jc-note">What did you do? <span class="req">*</span></label><textarea id="jc-note" name="note" rows="3" placeholder="e.g. Installed and calibrated the interactive board in JSS2 classroom, tested with the teacher."></textarea></div>
        <div class="sec-title">Client sign-off</div>
        <div id="jc-sign">
          <div class="form-row"><div class="field"><label for="jc-sn">Client contact name <span class="req">*</span></label><input id="jc-sn" name="signoff_name" value="${esc(j.contact_name || '')}" placeholder="Who checked the work"></div>
            <div class="field"><label for="jc-sp">Their phone</label><input type="tel" id="jc-sp" name="signoff_phone" value="${esc(j.contact_phone || '')}"></div></div>
          <div class="field" data-field="signature"><div class="label" style="display:flex;justify-content:space-between;align-items:center"><span>Signature <span class="req">*</span></span>
            <button type="button" class="btn sm ghost" id="jc-clear"><i class="ti ti-eraser"></i>Clear</button></div>
            <canvas class="sigpad" id="jc-pad"></canvas><div class="help">Hand the phone to the client to sign with their finger.</div></div>
        </div>
        <label class="check-row"><input type="checkbox" name="signoff_unavailable" id="jc-na"><span>The client wasn't available to sign</span></label>
        <div class="field hide" id="jc-why"><label for="jc-sk">Why not? <span class="req">*</span></label><input id="jc-sk" name="signoff_skipped" placeholder="e.g. Director travelling; teacher saw the work"></div>
      </form>`, '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="jc-ok"><i class="ti ti-send"></i>Send for checking</button>', { wide: true });
    const up = App.uploader($('#jc-up', el), { label: 'Take photo', accept: 'image/*', capture: true });
    const pad = signaturePad($('#jc-pad', el));
    $('#jc-clear', el).addEventListener('click', () => pad.clear());
    $('#jc-na', el).addEventListener('change', (e) => {
      $('#jc-sign', el).classList.toggle('hide', e.target.checked);
      $('#jc-why', el).classList.toggle('hide', !e.target.checked);
    });
    $('#jc-ok', el).addEventListener('click', async (e) => {
      const form = $('#jc-form', el);
      const d = App.formData(form);
      const note = (d.note || '').trim();
      const errs = {};
      if (!up.count()) errs.files = 'Add at least one photo.';
      if (!note) errs.note = 'Say what was done.';
      if (d.signoff_unavailable) { if (!d.signoff_skipped.trim()) errs.signoff_skipped = 'Say why the client couldn\'t sign.'; }
      else {
        if (!d.signoff_name.trim()) errs.signoff_name = 'Enter the client contact\'s name.';
        if (pad.isEmpty()) errs.signature = 'Ask the client to sign.';
      }
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      const sig = d.signoff_unavailable ? null : await pad.file();
      const files = up.files().concat(sig ? [{ field: 'signature', file: sig, name: 'signature.png' }] : []);
      try {
        await App.busy(e.currentTarget, () => api('job_status', { data: { id: j.id, action: 'complete', note, signoff_name: d.signoff_name, signoff_phone: d.signoff_phone,
          signoff_unavailable: d.signoff_unavailable ? 1 : '', signoff_skipped: d.signoff_skipped }, files }));
        App.closeTop();
        toast('Sent for checking');
        jobsChanged();
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /* --------------------------------------------------------- escalate */
  function escalateJob(j, done) {
    const el = App.modal(`Raise priority of ${esc(j.ref)}`, `<form id="jx-form" novalidate>
      <p class="muted" style="margin-bottom:12px">The office is emailed straight away so they can follow up with the client.</p>
      <div class="field" data-field="priority"><div class="label">New priority</div><div class="opts">
        ${j.priority !== 'high' ? '<label class="opt"><input type="radio" name="priority" value="high"><span><i class="ti ti-flag"></i>High</span></label>' : ''}
        <label class="opt"><input type="radio" name="priority" value="urgent"><span><i class="ti ti-urgent"></i>Urgent</span></label></div></div>
      <div class="field"><label for="jx-r">Why? <span class="req">*</span></label><textarea id="jx-r" name="reason" rows="3" placeholder="e.g. Exams start Monday and the lab has no power"></textarea></div></form>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn danger solid" id="jx-ok"><i class="ti ti-flag"></i>Raise priority</button>');
    $('#jx-ok', el).addEventListener('click', async (e) => {
      const form = $('#jx-form', el);
      const d = App.formData(form);
      const errs = {};
      if (!d.priority) errs.priority = 'Choose High or Urgent.';
      if (!d.reason.trim()) errs.reason = 'Say why.';
      if (Object.keys(errs).length) { App.showErrors(form, errs); return; }
      try {
        await App.busy(e.currentTarget, () => api('job_escalate', { data: { id: j.id, ...d } }));
        App.closeTop();
        toast('Priority raised. The office has been told.');
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.fail(err); }
    });
  }

  /* ----------------------------------------------------------- assign */
  async function assignJob(j, done) {
    const staff = await activeStaff();
    const current = new Set(j.assignees.map((a) => +a.id));
    const el = App.modal(`Assign ${esc(j.ref)}`, `
      <p class="muted" style="margin-bottom:10px">Tick everyone who should work on this. Untick someone to take them off it.</p>
      <input type="search" id="ja-q" placeholder="Search staff…" style="margin-bottom:10px">
      <div class="opts col" id="ja-list">${staff.map((s) => `<label class="opt" data-name="${esc(s.name.toLowerCase())}"><input type="checkbox" value="${s.id}" ${current.has(s.id) ? 'checked' : ''}>
        <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}${s.location ? ` <span class="faint small">· ${esc(s.location)}</span>` : ''}${s.role === 'admin' ? ' <span class="faint small">(admin)</span>' : ''}</span></label>`).join('')}</div>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ja-ok">Save</button>');
    $('#ja-q', el).addEventListener('input', (e) => {
      const q = e.target.value.trim().toLowerCase();
      $$('#ja-list .opt', el).forEach((o) => o.classList.toggle('hide', q && !o.dataset.name.includes(q)));
    });
    $('#ja-ok', el).addEventListener('click', async (e) => {
      const ids = $$('#ja-list input:checked', el).map((x) => +x.value);
      if (!ids.length) { toast('Choose at least one staff member.', true); return; }
      try {
        await App.busy(e.currentTarget, () => api('job_assign', { data: { id: j.id, user_ids: ids } }));
        App.closeTop();
        toast('Assignment saved');
        App.current?.refresh?.();
        done && done();
      } catch (err) { App.fail(err); }
    });
  }

  /* ------------------------------------------------------- create / edit */
  async function editJob(j, prefill = null, done = null) {
    const admin = isAdmin();
    const isNew = !j;
    const v = { title: '', client_type: 'school', client_name: '', location: '', contact_name: '', contact_phone: '', description: '', priority: 'normal', due_date: '', due_time: '', ...(j || {}), ...(prefill || {}) };
    const staff = admin && isNew ? await activeStaff() : [];
    const preset = new Set((v.assignees || []).map(Number));
    const el = App.modal(isNew ? (admin ? 'New job order' : 'Log a job') : `Edit ${esc(j.ref)}`, `
      <form id="je-form" novalidate>
        ${!admin && isNew ? '<p class="muted" style="margin-bottom:12px">Picked up a task at a school or company? Log it here. An admin will accept and assign it.</p>' : ''}
        <div class="field"><label for="je-t">What needs to be done? <span class="req">*</span></label><input id="je-t" name="title" value="${esc(v.title)}" placeholder="e.g. Install 2 projectors in the ICT lab" autofocus></div>
        <div class="field"><div class="label">Client type</div><div class="opts">${[['school', 'School'], ['company', 'Company'], ['other', 'Other']].map(([k, l]) =>
          `<label class="opt"><input type="radio" name="client_type" value="${k}" ${v.client_type === k ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
        <div class="form-row"><div class="field"><label for="je-c">School / company name <span class="req">*</span></label><input id="je-c" name="client_name" list="je-locs" value="${esc(v.client_name)}">
          <datalist id="je-locs">${[...new Set((App.cfg.clients || []).concat(App.cfg.locations))].map((l) => `<option value="${esc(l)}">`).join('')}</datalist>
          <div class="help">Pick from the clients list, or type a new name to add it.</div></div>
          <div class="field"><label for="je-l">Location / address</label><input id="je-l" name="location" value="${esc(v.location)}" placeholder="Area, street or room"></div></div>
        <div class="form-row"><div class="field"><label for="je-cn">Contact person</label><input id="je-cn" name="contact_name" value="${esc(v.contact_name)}"></div>
          <div class="field"><label for="je-cp">Contact phone</label><input type="tel" id="je-cp" name="contact_phone" value="${esc(v.contact_phone)}"></div></div>
        <div class="field"><label for="je-d">Details</label><textarea id="je-d" name="description" rows="4" placeholder="What exactly is needed, quantities, faults reported, access notes…">${esc(v.description)}</textarea></div>
        <div class="form-row"><div class="field"><label for="je-p">Priority</label><select id="je-p" name="priority">${App.opts(Object.entries(PRIORITY).map(([k, [l]]) => [k, l]), v.priority)}</select></div>
          <div class="field"><label for="je-due">Due date${admin ? ' <span class="req">*</span>' : ''}</label><input type="date" id="je-due" name="due_date" value="${esc(v.due_date || '')}"></div>
          <div class="field"><label for="je-dt">Due time</label><input type="time" id="je-dt" name="due_time" value="${esc(v.due_time || '')}"></div></div>
        ${staff.length ? `<div class="field"><div class="label">Assign to</div><div class="opts">${staff.map((s) => `<label class="opt"><input type="checkbox" data-multi="1" name="assignees" value="${s.id}" ${preset.has(s.id) ? 'checked' : ''}>
          <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}</span></label>`).join('')}</div><div class="help">You can add more people or reassign later.</div></div>
        <div class="field"><div class="label">Tasks (optional)</div>
          <div class="help" style="margin:-2px 0 6px">Split the job into tasks, each with its own staff and time frame. Staff tick them off and can't end their day with one due and unfinished.</div>
          <div id="je-tasks"></div><button type="button" class="btn sm ghost" id="je-task-add" style="margin-top:8px"><i class="ti ti-plus"></i>Add task</button></div>` : ''}
        ${isNew ? '<div class="field"><div class="label">Photos (optional)</div><div id="je-up"></div></div>' : ''}
      </form>`, `<button class="btn ghost" data-close>Cancel</button>${!isNew && admin ? '<button class="btn danger" id="je-del"><i class="ti ti-trash"></i>Delete</button>' : ''}
        <button class="btn primary" id="je-save">${isNew ? (admin ? 'Create job' : 'Send to admin') : 'Save'}</button>`, { wide: true });
    const up = isNew ? App.uploader($('#je-up', el), { label: 'Add photo' }) : null;
    // Task rows on a new job: title, due date/time and staff for each.
    let taskN = 0;
    $('#je-task-add', el)?.addEventListener('click', () => {
      const n = taskN++;
      const form = $('#je-form', el);
      const row = document.createElement('div');
      row.className = 'task-new';
      row.dataset.taskRow = n;
      row.innerHTML = `<div style="display:flex;gap:8px"><input data-k="title" placeholder="Task, e.g. Mount projector in JSS2" style="flex:1" aria-label="Task">
          <button type="button" class="icon-btn" data-rm title="Remove task"><i class="ti ti-x"></i></button></div>
        <div class="form-row" style="margin:0"><input type="date" data-k="due_date" value="${esc(form.due_date.value || App.cfg.today)}" aria-label="Due date">
          <input type="time" data-k="due_time" value="${esc(form.due_time.value || '')}" aria-label="Due time"></div>
        <div class="opts">${staff.map((s) => `<label class="opt"><input type="checkbox" data-k="who" value="${s.id}" ${$$('input[name="assignees"]:checked', form).some((x) => +x.value === s.id) ? 'checked' : ''}>
          <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}</span></label>`).join('')}</div>`;
      $('[data-rm]', row).addEventListener('click', () => row.remove());
      $('#je-tasks', el).appendChild(row);
      $('[data-k="title"]', row).focus();
    });
    const readTasks = () => $$('[data-task-row]', el).map((row) => ({
      title: $('[data-k="title"]', row).value.trim(), due_date: $('[data-k="due_date"]', row).value, due_time: $('[data-k="due_time"]', row).value,
      assignees: $$('[data-k="who"]:checked', row).map((x) => +x.value),
    })).filter((t) => t.title);
    if (admin) {
      // Fill in the client's address and contact when an existing client is picked.
      $('#je-c', el).addEventListener('change', async (e) => {
        const form = $('#je-form', el);
        try {
          clientCache = clientCache || (await api('clients_list')).items;
          const c = clientCache.find((x) => x.name.toLowerCase() === e.target.value.trim().toLowerCase());
          if (!c) return;
          const fill = (n, v) => { if (v && !form[n].value) form[n].value = v; };
          fill('location', [c.address, c.area].filter(Boolean).join(', '));
          fill('contact_name', c.contact_name);
          fill('contact_phone', c.contact_phone);
          const t = form.querySelector(`input[name=client_type][value="${c.type}"]`);
          if (t) t.checked = true;
        } catch { /* optional */ }
      });
    }
    $('#je-save', el).addEventListener('click', async (e) => {
      const form = $('#je-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = j.id;
      if (prefill?.source_report_id) d.source_report_id = prefill.source_report_id;
      if (staff.length) d.assignees = (d.assignees || []).map(Number);
      if (admin && !d.due_date && !['done', 'cancelled'].includes(j?.status)) { App.showErrors(form, { due_date: 'Set a due date so staff know the time frame.' }); return; }
      if (isNew && admin) {
        d.tasks = readTasks();
        const bad = d.tasks.find((t) => !t.due_date || !t.assignees.length);
        if (bad) { toast(`Give "${bad.title}" a due date and at least one person.`, true); return; }
      }
      try {
        const r = await App.busy(e.currentTarget, () => api('job_save', { data: d, files: up ? up.files() : [] }));
        if (App.cfg.clients && !App.cfg.clients.includes(d.client_name.trim())) App.cfg.clients.push(d.client_name.trim());
        clientCache = null;
        App.closeTop();
        toast(isNew ? (admin ? 'Job created' : 'Job sent to admin') : 'Job saved');
        if (done) done();
        else if (isNew) App.go(`jobs/${r.id}`);
        App.current?.refresh?.();
      } catch (err) { App.showErrors($('#je-form', el), err.fields); App.fail(err); }
    });
    $('#je-del', el)?.addEventListener('click', async () => {
      if (!(await App.confirm('Delete this job?', 'The job, its timeline and photos will be permanently removed.', { ok: 'Delete job', danger: true }))) return;
      try { await api('job_delete', { data: { id: j.id } }); App.closeAll(); toast('Job deleted'); App.current?.refresh?.(); } catch (err) { App.fail(err); }
    });
  }
  App.editJob = editJob;

  /** Compact "My jobs" card for the staff home page. */
  App.renderMyJobs = async (box) => {
    if (!box) return;
    try {
      const r = await api('jobs_list', { query: { mine: 1, status: 'active' } });
      box.innerHTML = `<div class="card-head"><h3>My jobs</h3><a href="#/jobs" class="small">All jobs</a></div>
        ${r.items.length ? `<ul class="list">${r.items.slice(0, 6).map((j) => `<li class="li" data-job="${j.id}"><div class="li-icon ${j.overdue ? 'tone-bad' : 'tone-brand'}"><i class="ti ti-clipboard-list"></i></div>
          <div class="main-col"><div class="t">${esc(j.title)}</div><div class="s">${esc(j.client_name)}${j.due_date ? ' · ' : ''}${dueText(j)}</div></div>${jobPill(j.status)}</li>`).join('')}</ul>`
          : '<p class="faint small card-body">No active jobs right now.</p>'}`;
      $$('[data-job]', box).forEach((li) => li.addEventListener('click', () => App.go(`jobs/${li.dataset.job}`)));
    } catch { box.innerHTML = ''; }
  };
})();
