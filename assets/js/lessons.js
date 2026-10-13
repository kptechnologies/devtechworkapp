/* DevTech Staff Portal — lesson delivery evidence: lesson plans with prep proof, classes taught with photos, review and compliance */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, ago, isAdmin } = App;

  const STATUS = { submitted: ['Waiting for review', 'info'], approved: ['Approved', 'in'], returned: ['Returned to fix', 'bad'], cancelled: ['Did not hold', ''] };
  const pill = (s) => `<span class="pill ${STATUS[s]?.[1] || ''}">${esc(STATUS[s]?.[0] || s)}</span>`;
  const FLAGS = { prep_late: ['Prepared on site', 'Lesson plan submitted after arriving at the school'], no_clockin: ['No clock-in', 'Not clocked in at this school that day'] };
  const flagPills = (flags) => String(flags || '').split(',').filter(Boolean)
    .map((f) => `<span class="pill out" title="${esc(FLAGS[f]?.[1] || f)}">${esc(FLAGS[f]?.[0] || f)}</span>`).join(' ');
  const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
  const kpi = (label, value, sub, icon, tone) => `<div class="card kpi"><div class="kpi-icon ${tone}"><i class="ti ti-${icon}"></i></div><div class="label">${label}</div><div class="value">${value}</div><div class="sub">${sub}</div></div>`;
  const PRIVACY = '<div class="help">Only you and management can see these photos. Take photos only at schools that have agreed to pupils being photographed.</div>';

  /** The school the staff member is clocked in at right now, if any. */
  async function currentSchool() {
    try { return (await api('attendance_today')).open?.location || ''; } catch { return ''; }
  }
  const schoolOpts = (sel) => {
    const mine = App.user.schools || [];
    const rest = (App.cfg.locations || []).filter((n) => !mine.includes(n));
    return `<option value="">Choose…</option>${mine.length ? `<optgroup label="My schools">${App.opts(mine, sel)}</optgroup>` : ''}<optgroup label="All">${App.opts(rest, sel)}</optgroup>`;
  };

  /* --------------------------------------------------------- lesson plan */
  /** New or returned lesson plan. Needs screenshots of the project the staff member built themselves. */
  async function planForm(plan = null) {
    const v = plan || { planned_date: App.cfg.today, school: await currentSchool() || (App.user.schools || [])[0] || '', subject: (App.cfg.lesson_subjects || [])[0] };
    const el = App.modal(plan ? `Fix lesson plan ${esc(plan.ref)}` : 'New lesson plan', `<form id="lp-form" novalidate>
      ${plan?.status === 'returned' && plan.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>What to fix:</b> ${esc(plan.review_note)}</div>` : ''}
      <div class="note-box" style="margin-bottom:12px"><i class="ti ti-bulb"></i> Build the project yourself first, then upload screenshots of it here <b>before</b> you go to class. Classes can only be logged against a submitted plan.</div>
      <div class="form-row"><div class="field"><label for="lp-school">School <span class="req">*</span></label><select id="lp-school" name="school">${schoolOpts(v.school)}</select></div>
        <div class="field"><label for="lp-subj">Subject <span class="req">*</span></label><select id="lp-subj" name="subject">${App.opts(App.cfg.lesson_subjects || [], v.subject, { placeholder: 'Choose…' })}</select></div></div>
      <div class="field"><label for="lp-topic">Topic <span class="req">*</span></label><input id="lp-topic" name="topic" value="${esc(v.topic || '')}" placeholder="e.g. Loops in Scratch"></div>
      <div class="field"><label for="lp-proj">Project the pupils will build <span class="req">*</span></label><input id="lp-proj" name="project_title" value="${esc(v.project_title || '')}" placeholder="e.g. Animated dancing cat"></div>
      <div class="field"><label for="lp-obj">Objectives <span class="req">*</span></label><textarea id="lp-obj" name="objectives" rows="2" placeholder="By the end, pupils can use a repeat block to make a sprite move in a pattern.">${esc(v.objectives || '')}</textarea></div>
      <div class="field"><label for="lp-act">Lesson steps <span class="req">*</span></label><textarea id="lp-act" name="activities" rows="5" placeholder="1. Introduction (5 min): …&#10;2. Demonstration (10 min): …&#10;3. Pupils build the project (25 min): …&#10;4. Show and tell / wrap-up (5 min): …">${esc(v.activities || '')}</textarea></div>
      <div class="field"><label for="lp-mat">Materials</label><input id="lp-mat" name="materials" value="${esc(v.materials || '')}" placeholder="Laptops with Scratch, projector, robotics kits"></div>
      <div class="form-row"><div class="field"><label for="lp-date">First class date <span class="req">*</span></label><input type="date" id="lp-date" name="planned_date" value="${esc(v.planned_date || '')}"></div>
        <div class="field"><label for="lp-time">Time</label><input type="time" id="lp-time" name="planned_time" value="${esc(v.planned_time || '')}"></div></div>
      <div class="field" data-field="prep"><div class="label">Your finished project: screenshots or photos <span class="req">*</span></div><div id="lp-prep"></div>
        ${plan?.prep_photos?.length ? `<div class="help">${plan.prep_photos.length} already uploaded.</div>` : ''}</div>
      <div class="field"><div class="label">Lesson plan document (optional)</div><div id="lp-plan"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="lp-ok"><i class="ti ti-send"></i>Submit plan</button>`, { wide: true });
    const prep = App.uploader($('#lp-prep', el), { existing: plan?.prep_photos?.length || 0, label: 'Add screenshots', accept: 'image/*' });
    const doc = App.uploader($('#lp-plan', el), { existing: plan?.plan_files?.length || 0, label: 'Add plan' });
    $('#lp-ok', el).addEventListener('click', async (e) => {
      const form = $('#lp-form', el);
      App.showErrors(form, null);
      if (!prep.count() && !plan?.prep_photos?.length) { App.showErrors(form, { prep: 'Upload at least one screenshot of the project you built.' }); return; }
      const files = prep.files().map((f) => ({ field: 'prep[]', file: f })).concat(doc.files().map((f) => ({ field: 'plan[]', file: f })));
      try {
        const r = await App.busy(e.currentTarget, () => api('lesson_plan_save', { data: { ...App.formData(form), id: plan?.id || 0 }, files }));
        App.closeAll();
        toast(plan ? 'Plan resubmitted' : `Plan ${r.ref} submitted. You can now log the classes you teach with it.`);
        App.current?.refresh?.();
        App.poll?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.lessonPlanForm = planForm;

  /* ---------------------------------------------------------- log classes */
  /** Log several classes taught from one plan (each with its own photos), or fix one returned class. */
  async function logClasses(plan, fix = null) {
    const school = fix?.school || await currentSchool() || plan.school;
    const rows = [];
    const el = App.modal(fix ? `Fix ${esc(fix.class_name)}` : `Log classes · ${esc(plan.topic)}`, `
      <div class="note-box" style="margin-bottom:12px"><b>${esc(plan.ref)}</b> · ${esc(plan.subject)} · ${esc(plan.topic)}${plan.project_title ? ` · project: ${esc(plan.project_title)}` : ''}</div>
      ${fix?.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>What to fix:</b> ${esc(fix.review_note)}</div>` : ''}
      <div id="lc-rows"></div>
      ${fix ? '' : '<button class="btn" type="button" id="lc-add"><i class="ti ti-plus"></i>Add another class</button>'}
      <datalist id="lc-levels">${(App.cfg.class_levels || []).map((c) => `<option value="${esc(c)}">`).join('')}</datalist>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="lc-ok"><i class="ti ti-send"></i>Submit</button>`, { wide: true });
    const box = $('#lc-rows', el);

    const addRow = (v = {}) => {
      const i = rows.length;
      const prev = rows[rows.length - 1]?.read() || {};
      v = { school: prev.school || school, session_date: prev.session_date || App.cfg.today, ...v };
      const card = document.createElement('div');
      card.className = 'card card-pad';
      card.style.marginBottom = '12px';
      const f = (k) => `data-field="sessions.${i}.${k}"`;
      card.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><h3>Class ${i + 1}</h3>
          ${!fix && i ? '<button type="button" class="btn sm ghost" data-rm aria-label="Remove class"><i class="ti ti-trash"></i></button>' : ''}</div>
        <div class="form-row"><div class="field" ${f('class_name')}><label>Class <span class="req">*</span></label><input data-k="class_name" list="lc-levels" value="${esc(v.class_name || '')}" placeholder="e.g. Grade 5 A"></div>
          <div class="field" ${f('session_date')}><label>Date <span class="req">*</span></label><input type="date" data-k="session_date" max="${App.cfg.today}" value="${esc(v.session_date)}"></div></div>
        <div class="field" ${f('school')}><label>School <span class="req">*</span></label><select data-k="school">${schoolOpts(v.school)}</select></div>
        <label class="check-row" style="margin-bottom:10px"><input type="checkbox" data-k="cancelled" ${v.status === 'cancelled' ? 'checked' : ''}><span>This class didn't hold</span></label>
        <div data-cancel class="${v.status === 'cancelled' ? '' : 'hide'}"><div class="field" ${f('cancel_reason')}><label>Why not? <span class="req">*</span></label><input data-k="cancel_reason" value="${esc(v.cancel_reason || '')}" placeholder="e.g. School exams, class went for sports"></div></div>
        <div data-held class="${v.status === 'cancelled' ? 'hide' : ''}">
          <div class="form-row"><div class="field" ${f('students_total')}><label>Pupils in class</label><input type="number" min="0" data-k="students_total" value="${esc(v.students_total ?? '')}"></div>
            <div class="field" ${f('students_present')}><label>Present <span class="req">*</span></label><input type="number" min="0" data-k="students_present" value="${esc(v.students_present ?? '')}"></div>
            <div class="field" ${f('students_completed')}><label>Finished the project <span class="req">*</span></label><input type="number" min="0" data-k="students_completed" value="${esc(v.students_completed ?? '')}"></div></div>
          <div class="field" ${f('class_photos')}><div class="label">Class at work: at least 2 photos <span class="req">*</span></div><div data-up="class"></div>${v.class_photos?.length ? `<div class="help">${v.class_photos.length} already uploaded.</div>` : ''}</div>
          <div class="field" ${f('work_photos')}><div class="label">Pupils' projects: at least 1 photo or screenshot <span class="req">*</span></div><div data-up="work"></div>${v.work_photos?.length ? `<div class="help">${v.work_photos.length} already uploaded.</div>` : ''}${PRIVACY}</div>
          <div class="field" ${f('outcome')}><label>How did it go? <span class="req">*</span></label><select data-k="outcome">${App.opts(App.cfg.lesson_outcomes || [], v.outcome || '', { placeholder: 'Choose…' })}</select></div>
          <div class="field" ${f('achieved')}><label>What did the pupils achieve? <span class="req">*</span></label><textarea data-k="achieved" rows="2" placeholder="e.g. 18 of 22 pupils made the cat dance using repeat blocks; 4 needed help with costumes.">${esc(v.achieved || '')}</textarea></div>
          <div class="field" ${f('challenges')}><label>Challenges</label><textarea data-k="challenges" rows="2" placeholder="e.g. 5 laptops had no charge; power went off for 20 minutes.">${esc(v.challenges || '')}</textarea></div>
          <div class="field" ${f('support_needed')}><label>Support needed from management</label><input data-k="support_needed" value="${esc(v.support_needed || '')}" placeholder="Optional"></div></div>`;
      box.appendChild(card);
      const upClass = App.uploader($('[data-up="class"]', card), { existing: v.class_photos?.length || 0, label: 'Class photos', accept: 'image/*', capture: true });
      const upWork = App.uploader($('[data-up="work"]', card), { existing: v.work_photos?.length || 0, label: 'Project photos', accept: 'image/*' });
      const cancel = $('[data-k="cancelled"]', card);
      cancel.addEventListener('change', () => { $('[data-cancel]', card).classList.toggle('hide', !cancel.checked); $('[data-held]', card).classList.toggle('hide', cancel.checked); });
      const row = {
        card, upClass, upWork,
        read: () => {
          const d = { id: v.id || 0 };
          $$('[data-k]', card).forEach((x) => { d[x.dataset.k] = x.type === 'checkbox' ? x.checked : x.value; });
          return d;
        },
      };
      $('[data-rm]', card)?.addEventListener('click', () => { rows.splice(rows.indexOf(row), 1); card.remove(); });
      rows.push(row);
    };
    addRow(fix || {});
    $('#lc-add', el)?.addEventListener('click', () => addRow());

    $('#lc-ok', el).addEventListener('click', async (e) => {
      App.showErrors(el, null);
      // Field names carry the row's position at send time, so number the cards again after any removal.
      const sessions = [];
      const files = [];
      rows.forEach((r, i) => {
        sessions.push(r.read());
        r.upClass.files().forEach((f) => files.push({ field: `class_${i}[]`, file: f }));
        r.upWork.files().forEach((f) => files.push({ field: `work_${i}[]`, file: f }));
        $$('[data-field]', r.card).forEach((x) => { x.dataset.field = x.dataset.field.replace(/^sessions\.\d+\./, `sessions.${i}.`); });
      });
      try {
        const r = await App.busy(e.currentTarget, () => api('lesson_sessions_save', { data: { plan_id: plan.id, sessions }, files }));
        App.closeAll();
        toast(fix ? 'Class resubmitted' : `${r.ids.length} class${r.ids.length === 1 ? '' : 'es'} logged`);
        App.current?.refresh?.();
        App.poll?.();
      } catch (err) { App.showErrors(el, err.fields); App.fail(err); }
    });
  }

  /** Pick which plan the classes were taught from, then log them. */
  async function pickPlanAndLog() {
    let items;
    try { items = (await api('lesson_plans_open')).items; } catch (e) { App.fail(e); return; }
    if (!items.length) {
      const ok = await App.confirm('No lesson plan yet', 'Classes are logged against a lesson plan with screenshots of the project you built. Write the plan first.', { ok: 'Write a lesson plan' });
      if (ok !== null) planForm();
      return;
    }
    const el = App.modal('Which lesson did you teach?', `<ul class="list">${items.map((p) => `<li class="li" data-p="${p.id}"><div class="li-icon tone-brand"><i class="ti ti-school"></i></div>
      <div class="main-col"><div class="t">${esc(p.topic)}</div><div class="s">${esc(p.ref)} · ${esc(p.subject)} · ${esc(p.project_title)} · ${p.classes} class${p.classes === 1 ? '' : 'es'} so far</div></div>${pill(p.status)}</li>`).join('')}</ul>
      <button class="btn" id="lp-new" style="margin-top:12px"><i class="ti ti-plus"></i>New lesson plan</button>`);
    $$('[data-p]', el).forEach((li) => li.addEventListener('click', () => { App.closeTop(); logClasses(items.find((p) => p.id === +li.dataset.p)); }));
    $('#lp-new', el).addEventListener('click', () => { App.closeTop(); planForm(); });
  }
  App.logLessonClasses = pickPlanAndLog;

  /* ------------------------------------------------------- plan details */
  const sessionHtml = (s, admin, own) => `<div class="card card-pad" style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px"><div><b>${esc(s.class_name)}</b> · ${esc(s.school)} · ${esc(fmtDate(s.session_date, false))}</div>
        <div>${pill(s.status)} ${flagPills(s.flags)}</div></div>
      ${s.status === 'cancelled' ? `<div class="muted">Didn't hold: ${esc(s.cancel_reason)}</div>` : `
      <div class="small muted" style="margin-bottom:6px">${s.students_present ?? '?'} present${s.students_total ? ` of ${s.students_total}` : ''} · ${s.students_completed ?? '?'} finished the project · ${esc(s.outcome)}</div>
      <div class="qa"><div class="q">Achieved</div><div class="a">${esc(s.achieved)}</div></div>
      ${s.challenges ? `<div class="qa"><div class="q">Challenges</div><div class="a">${esc(s.challenges)}</div></div>` : ''}
      ${s.support_needed ? `<div class="qa"><div class="q">Support needed</div><div class="a">${esc(s.support_needed)}</div></div>` : ''}
      <div class="small muted" style="margin-top:8px">Class at work</div>${App.gallery(s.class_photos)}
      <div class="small muted" style="margin-top:8px">Pupils' projects</div>${App.gallery(s.work_photos)}`}
      ${s.review_note ? `<div class="note-box ${s.status === 'returned' ? 'bad' : ''}" style="margin-top:8px"><b>${esc(s.reviewed_by_name || 'Admin')}:</b> ${esc(s.review_note)}</div>` : ''}
      <div class="actions" style="margin-top:10px">
        ${admin && s.status === 'submitted' ? `<button class="btn sm primary" data-ok="session:${s.id}"><i class="ti ti-check"></i>Approve</button><button class="btn sm" data-ret="session:${s.id}"><i class="ti ti-arrow-back-up"></i>Return</button>` : ''}
        ${admin && s.status === 'cancelled' && !s.reviewed_at ? `<button class="btn sm" data-ret="session:${s.id}"><i class="ti ti-arrow-back-up"></i>Question this</button>` : ''}
        ${own && s.status === 'returned' ? `<button class="btn sm primary" data-fix="${s.id}"><i class="ti ti-edit"></i>Fix and resubmit</button>` : ''}</div></div>`;

  async function openPlan(id) {
    const el = App.drawer('Lesson', App.loading());
    let p;
    try { p = (await api('lesson_plan_get', { query: { id } })).plan; } catch (e) { App.closeTop(); App.fail(e); return; }
    const admin = isAdmin();
    const own = p.user_id === App.user.id;
    $('.drawer-head h2', el).textContent = `${p.ref} · ${p.topic}`;
    $('.drawer-body', el).innerHTML = `
      <div style="margin-bottom:12px">${pill(p.status)}</div>
      <dl class="kv"><dt>Staff</dt><dd>${admin ? `<a href="#/staff/${p.user_id}">${esc(p.user_name)}</a>` : esc(p.user_name)}</dd>
        <dt>Subject</dt><dd>${esc(p.subject)}</dd><dt>Project</dt><dd>${esc(p.project_title)}</dd><dt>School</dt><dd>${esc(p.school)}</dd>
        <dt>Planned</dt><dd>${esc(fmtDate(p.planned_date))}${p.planned_time ? ' ' + esc(p.planned_time) : ''}</dd>
        <dt>Submitted</dt><dd>${esc(fmtDate(p.submitted_at))} · ${esc(ago(p.submitted_at))}</dd></dl>
      <div class="sec-title">Objectives</div><div class="note-box">${esc(p.objectives)}</div>
      <div class="sec-title">Lesson steps</div><div class="note-box" style="white-space:pre-wrap">${esc(p.activities)}</div>
      ${p.materials ? `<div class="sec-title">Materials</div><p>${esc(p.materials)}</p>` : ''}
      <div class="sec-title">Staff member's own project</div><div id="lp-gal-prep">${App.gallery(p.prep_photos)}</div>
      ${p.plan_files.length ? `<div class="sec-title">Lesson plan document</div>${App.gallery(p.plan_files)}` : ''}
      ${p.review_note ? `<div class="note-box ${p.status === 'returned' ? 'bad' : ''}" style="margin-top:10px"><b>${esc(p.reviewed_by_name || 'Admin')}:</b> ${esc(p.review_note)}</div>` : ''}
      <div class="sec-title">Classes taught (${p.sessions.filter((s) => s.status !== 'cancelled').length})</div>
      ${p.sessions.length ? p.sessions.map((s) => sessionHtml(s, admin, own)).join('') : '<p class="faint">No classes logged yet.</p>'}`;
    App.bindGallery($('.drawer-body', el));
    const foot = [];
    if (admin && p.status === 'submitted') foot.push('<button class="btn" data-ret="plan:' + p.id + '"><i class="ti ti-arrow-back-up"></i>Return plan</button><button class="btn primary" data-ok="plan:' + p.id + '"><i class="ti ti-check"></i>Approve plan</button>');
    if (own && p.status !== 'approved') foot.push('<button class="btn" id="lp-edit"><i class="ti ti-edit"></i>' + (p.status === 'returned' ? 'Fix and resubmit' : 'Edit plan') + '</button>');
    if (own && ['submitted', 'approved'].includes(p.status)) foot.push('<button class="btn primary" id="lp-log"><i class="ti ti-plus"></i>Log classes</button>');
    if (foot.length) {
      const f = document.createElement('div');
      f.className = 'drawer-foot';
      f.innerHTML = foot.join('');
      el.appendChild(f);
    }
    $('#lp-edit', el)?.addEventListener('click', () => { App.closeTop(); planForm(p); });
    $('#lp-log', el)?.addEventListener('click', () => { App.closeTop(); logClasses(p); });
    $$('[data-fix]', el).forEach((b) => b.addEventListener('click', () => { App.closeTop(); logClasses(p, p.sessions.find((s) => s.id === +b.dataset.fix)); }));
    $$('[data-ok]', el).forEach((b) => b.addEventListener('click', () => review(b.dataset.ok, 'approve', b, id)));
    $$('[data-ret]', el).forEach((b) => b.addEventListener('click', () => review(b.dataset.ret, 'return', b, id)));
  }
  App.openLessonPlan = openPlan;

  async function review(key, decision, btn, planId) {
    const [type, id] = key.split(':');
    let note = '';
    if (decision === 'return') {
      note = await App.confirm('Return to the staff member', 'Say what needs fixing. They\'ll be notified and the item comes back to you when they resubmit.',
        { ok: 'Return', input: { label: 'What to fix', required: true, placeholder: 'e.g. Photos don\'t show the pupils working on the project.' } });
      if (note === null) return;
    }
    try {
      await App.busy(btn, () => api('lesson_review', { data: { type, id: +id, decision, note } }));
      toast(decision === 'return' ? 'Returned' : 'Approved');
      App.closeTop();
      App.current?.refresh?.();
      App.poll?.();
      openPlan(planId);
    } catch (e) { App.fail(e); }
  }

  /* --------------------------------------------------------------- lists */
  const planList = (plans) => (plans.length ? `<ul class="list">${plans.map((p) => `<li class="li" data-plan="${p.id}">
      ${p.thumb ? `<img class="li-thumb" src="file.php?id=${p.thumb}&thumb=1" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px">` : '<div class="li-icon tone-brand"><i class="ti ti-school"></i></div>'}
      <div class="main-col"><div class="t">${isAdmin() ? `${esc(p.user_name)} · ` : ''}${esc(p.topic)}</div>
      <div class="s">${esc(p.ref)} · ${esc(p.subject)} · ${esc(p.school)} · ${p.classes} class${p.classes === 1 ? '' : 'es'} · ${esc(fmtDate(p.submitted_at, false))}</div></div>${pill(p.status)}</li>`).join('')}</ul>`
    : App.empty('school', 'No lesson plans', 'Lesson plans show up here once submitted.'));

  const sessionTable = (items) => (items.length ? `<div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th>${isAdmin() ? '<th>Staff</th>' : ''}<th>School / class</th><th>Topic</th>
      <th class="r">Pupils</th><th>Photos</th><th>Status</th></tr></thead><tbody>${items.map((s) => `<tr class="click" data-plan="${s.plan_id}">
      <td class="num">${esc(fmtDate(s.session_date, false))}</td>${isAdmin() ? `<td>${esc(s.user_name)}</td>` : ''}
      <td><div style="font-weight:500">${esc(s.class_name)}</div><div class="faint small">${esc(s.school)}</div></td>
      <td><div>${esc(s.topic)}</div><div class="faint small">${esc(s.subject)} · ${esc(s.project_title)}</div></td>
      <td class="r num">${s.status === 'cancelled' ? '—' : `${s.students_completed ?? 0}/${s.students_present ?? 0}`}</td>
      <td><div class="gallery sm">${s.work_photos.concat(s.class_photos).slice(0, 3).map((f) => `<div class="thumb"><img src="file.php?id=${f.id}&thumb=1" alt="" loading="lazy"></div>`).join('')}</div></td>
      <td>${pill(s.status)} ${flagPills(s.flags)}</td></tr>`).join('')}</tbody></table></div>`
    : App.empty('chalkboard', 'No classes', 'Classes taught show up here.'));

  const bindOpen = (root) => $$('[data-plan]', root).forEach((x) => x.addEventListener('click', () => openPlan(+x.dataset.plan)));

  const complianceTable = (r) => `<div class="table-wrap"><table class="tbl"><thead><tr><th>Staff</th><th class="r">Plans</th><th class="r">Classes</th><th class="r hide-sm">Schools</th>
      <th class="r">Pupils</th><th class="r hide-sm">Finished project</th><th class="r">Prepared first</th><th class="r">Taught, no evidence</th><th class="r hide-sm">No clock-in</th><th class="r">To fix</th></tr></thead>
      <tbody>${r.rows.map((x) => `<tr class="click" data-u="${x.id}"><td>${esc(x.name)}</td><td class="r num">${x.plans}</td><td class="r num">${x.classes}${x.cancelled ? `<span class="faint"> +${x.cancelled}✕</span>` : ''}</td>
        <td class="r num hide-sm">${x.schools}</td><td class="r num">${x.pupils}</td><td class="r num hide-sm">${pct(x.completion_pct)}</td>
        <td class="r"><span class="pill ${x.prepared_pct === null ? '' : x.prepared_pct >= 90 ? 'in' : x.prepared_pct >= 60 ? 'out' : 'bad'}">${pct(x.prepared_pct)}</span></td>
        <td class="r num ${x.gaps ? 'bad' : ''}">${x.gaps}</td><td class="r num hide-sm ${x.no_clockin ? 'out' : ''}">${x.no_clockin}</td><td class="r num ${x.returned ? 'bad' : ''}">${x.returned}</td></tr>`).join('')}</tbody></table></div>`;
  const gapList = (gaps) => (gaps.length ? `<ul class="list">${gaps.map((g) => `<li class="li"><div class="li-icon tone-bad"><i class="ti ti-alert-circle"></i></div>
      <div class="main-col"><div class="t">${esc(g.user_name)} · ${esc(g.location)}</div><div class="s">${esc(fmtDate(g.work_date, false))} · clocked in ${esc(g.in_at.slice(11, 16))}${g.out_at ? `–${esc(g.out_at.slice(11, 16))}` : ''} for lesson delivery, but logged no class</div></div></li>`).join('')}</ul>`
    : '<p class="faint card-pad">None. Every teaching visit has a class logged.</p>');

  /** Staff profile tab: one staff member's numbers for this month. */
  App.lessonStats = async (box, uid) => {
    try {
      const r = await api('lessons_compliance', { query: { from: App.cfg.today.slice(0, 8) + '01', to: App.cfg.today, user_id: uid } });
      const x = r.rows[0];
      box.innerHTML = x ? `<div class="grid g4" style="margin-bottom:14px">
          ${kpi('Classes taught', x.classes, `${x.plans} lesson plan${x.plans === 1 ? '' : 's'} · ${x.schools} school${x.schools === 1 ? '' : 's'}`, 'chalkboard', 'tone-brand')}
          ${kpi('Pupils reached', x.pupils, `${pct(x.completion_pct)} finished the project`, 'users', 'tone-info')}
          ${kpi('Prepared first', pct(x.prepared_pct), 'Plan and own project before class', 'bulb', x.prepared_pct >= 90 ? 'tone-in' : 'tone-out')}
          ${kpi('Taught, no evidence', x.gaps, `${x.returned} returned to fix`, 'alert-circle', x.gaps || x.returned ? 'tone-bad' : 'tone-in')}</div>
        ${r.gaps.length ? `<div class="card"><div class="card-head"><h3>Teaching visits without a class logged</h3></div>${gapList(r.gaps)}</div>` : ''}
        <a class="btn" style="margin-top:12px" href="#/lessons"><i class="ti ti-school"></i>Open lessons</a>` : App.empty('school', 'No data');
    } catch { box.innerHTML = ''; }
  };

  /* --------------------------------------------------------------- page */
  const st = { tab: 'review', from: '', to: '', user_id: '', school: '', subject: '', status: '', flag: '' };
  async function lessonsView(ctx) {
    const admin = isAdmin();
    const t = App.cfg.today;
    if (!st.from) { st.from = App.addDays(t, -30); st.to = t; }
    if (!admin && !['mine', 'classes', 'stats'].includes(st.tab)) st.tab = 'mine';
    if (admin && !['review', 'classes', 'compliance', 'schools'].includes(st.tab)) st.tab = 'review';
    const staff = admin ? (await api('users_list')).items.filter((u) => +u.active && u.role === 'staff') : [];
    const tabs = admin ? [['review', 'Review', 'checks'], ['classes', 'All classes', 'chalkboard'], ['compliance', 'Compliance', 'target'], ['schools', 'Schools', 'building-community']]
      : [['mine', 'My lessons', 'school'], ['classes', 'Classes taught', 'chalkboard'], ['stats', 'My record', 'target']];
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Lessons' : 'My lessons'}</h1>
        <p class="muted">${admin ? 'Lesson plans with the staff member\'s own finished project, and photo evidence from every class taught.'
          : 'Build the project yourself and submit a lesson plan before class. After each class, log it with photos of the pupils at work and their projects.'}</p></div>
        ${admin ? '' : '<div class="actions"><button class="btn" id="ls-plan"><i class="ti ti-file-plus"></i>New lesson plan</button><button class="btn primary" id="ls-log"><i class="ti ti-camera-plus"></i>Log classes</button></div>'}</div>
      <div class="seg scroll" id="ls-tabs" style="margin-bottom:12px">${tabs.map(([k, l, i]) => `<button data-t="${k}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
      <div class="filters" id="ls-f">
        <label class="small muted" style="display:flex;gap:6px;align-items:center">From <input type="date" name="from" value="${st.from}" style="width:auto"></label>
        <label class="small muted" style="display:flex;gap:6px;align-items:center">To <input type="date" name="to" value="${st.to}" style="width:auto"></label>
        ${admin ? `<select name="user_id">${App.opts(staff.map((s) => [s.id, s.name]), st.user_id, { placeholder: 'All staff' })}</select>
          <select name="school">${App.opts(App.cfg.locations || [], st.school, { placeholder: 'All schools' })}</select>
          <select name="subject">${App.opts(App.cfg.lesson_subjects || [], st.subject, { placeholder: 'All subjects' })}</select>
          <select name="flag" data-only="classes">${App.opts(Object.entries(FLAGS).map(([k, [l]]) => [k, l]), st.flag, { placeholder: 'Any flag' })}</select>` : ''}</div>
      <div id="ls-body">${App.loading()}</div>`;
    const body = $('#ls-body', ctx.el);

    const load = async () => {
      $$('#ls-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.t === st.tab));
      $$('[data-only]', ctx.el).forEach((x) => x.classList.toggle('hide', x.dataset.only !== st.tab));
      const q = { from: st.from, to: st.to, user_id: st.user_id, school: st.school, subject: st.subject };
      if (st.tab === 'compliance' || st.tab === 'stats') {
        const r = await api('lessons_compliance', { query: q });
        if (st.tab === 'stats') { await App.lessonStats(body, App.user.id); return; }
        const tot = r.rows.reduce((a, x) => ({ classes: a.classes + x.classes, pupils: a.pupils + x.pupils, gaps: a.gaps + x.gaps, prepared: a.prepared + (x.prepared_pct ?? 0) * x.classes }), { classes: 0, pupils: 0, gaps: 0, prepared: 0 });
        body.innerHTML = `<div class="grid g4" style="margin-bottom:14px">
            ${kpi('Classes taught', tot.classes, `${fmtDate(r.from, false)} – ${fmtDate(r.to, false)}`, 'chalkboard', 'tone-brand')}
            ${kpi('Pupils reached', tot.pupils, 'Sum of pupils present', 'users', 'tone-info')}
            ${kpi('Prepared first', pct(tot.classes ? Math.round(tot.prepared / tot.classes) : null), 'Plan + own project before arriving', 'bulb', 'tone-in')}
            ${kpi('Taught, no evidence', tot.gaps, 'Clocked in for lessons, no class logged', 'alert-circle', tot.gaps ? 'tone-bad' : 'tone-in')}</div>
          <div class="card" style="margin-bottom:14px">${complianceTable(r)}</div>
          <div class="card"><div class="card-head"><h3>Teaching visits without a class logged</h3></div>${gapList(r.gaps)}</div>`;
        $$('[data-u]', body).forEach((row) => row.addEventListener('click', () => { st.user_id = row.dataset.u; st.tab = 'classes'; $('[name=user_id]', ctx.el).value = st.user_id; load().catch(App.fail); }));
        return;
      }
      if (st.tab === 'schools') {
        const r = await api('lessons_schools', { query: q });
        body.innerHTML = r.items.length ? `<div class="grid g2">${r.items.map((s) => `<div class="card card-pad">
            <h3 style="margin-bottom:4px">${esc(s.school)}</h3>
            <div class="small muted" style="margin-bottom:10px">${s.classes} class${+s.classes === 1 ? '' : 'es'} · ${s.class_groups} class group${+s.class_groups === 1 ? '' : 's'} · ${s.pupils} pupils · last ${esc(fmtDate(s.last_date, false))}<br>Staff: ${esc(s.staff)}</div>
            ${App.gallery(s.photos)}
            <div class="sec-title">Topics taught</div><ul class="small" style="padding-left:18px;margin:0">${s.topics.slice(0, 12).map((x) => `<li>${esc(fmtDate(x.date, false))} · ${esc(x.class_name)} · <b>${esc(x.topic)}</b>${x.project ? ` (${esc(x.project)})` : ''}</li>`).join('')}</ul></div>`).join('')}</div>`
          : App.empty('building-community', 'No classes in this period');
        App.bindGallery(body);
        return;
      }
      const r = await api('lessons_list', { query: { ...q, status: st.tab === 'review' ? 'submitted' : st.status, flag: st.tab === 'classes' ? st.flag : '' } });
      if (st.tab === 'review') {
        const plans = r.plans.filter((p) => p.status === 'submitted');
        const sessions = r.sessions.filter((s) => s.status === 'submitted');
        body.innerHTML = `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3>Lesson plans to review (${plans.length})</h3></div>${planList(plans)}</div>
          <div class="card"><div class="card-head"><h3>Classes to review (${sessions.length})</h3></div>${sessionTable(sessions)}</div>`;
      } else if (st.tab === 'mine') {
        const returned = r.plans.filter((p) => p.status === 'returned').length + r.sessions.filter((s) => s.status === 'returned').length;
        const unused = r.plans.filter((p) => !p.classes && p.status !== 'returned');
        body.innerHTML = `${returned ? `<div class="note-box bad" style="margin-bottom:14px"><i class="ti ti-arrow-back-up"></i> ${returned} item${returned === 1 ? ' was' : 's were'} returned to you. Open ${returned === 1 ? 'it' : 'them'}, fix and resubmit.</div>` : ''}
          ${unused.length ? `<div class="note-box warn" style="margin-bottom:14px">${unused.length} lesson plan${unused.length === 1 ? ' has' : 's have'} no classes logged yet. After you teach, tap <b>Log classes</b>.</div>` : ''}
          <div class="card">${planList(r.plans)}</div>`;
      } else {
        body.innerHTML = `<div class="card">${sessionTable(r.sessions)}</div>`;
      }
      bindOpen(body);
    };
    $$('#ls-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { st.tab = b.dataset.t; load().catch(App.fail); }));
    $('#ls-f', ctx.el).addEventListener('change', (e) => { if (e.target.name) { st[e.target.name] = e.target.value; load().catch(App.fail); } });
    $('#ls-plan', ctx.el)?.addEventListener('click', () => planForm());
    $('#ls-log', ctx.el)?.addEventListener('click', pickPlanAndLog);
    ctx.refresh = () => load().catch(() => {});
    await load();
  }
  App.route('lessons', lessonsView, { title: 'Lessons', live: true });
  App.route('lessons/plan/:id', async (ctx) => { await lessonsView(ctx); openPlan(+ctx.params.id); }, { title: 'Lessons', nav: 'lessons', live: true });
  App.route('lessons/log', async (ctx) => { await lessonsView(ctx); pickPlanAndLog(); }, { title: 'Lessons', nav: 'lessons', live: true });
})();
