/* DevTech Staff Portal — lesson delivery evidence: lesson plans with prep proof, classes taught with photos, review and compliance */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, ago, isAdmin } = App;

  const STATUS = { assigned: ['To prepare', 'out'], submitted: ['Waiting for review', 'info'], approved: ['Approved', 'in'], returned: ['Returned to fix', 'bad'], cancelled: ['Did not hold', ''] };
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

  /** The lesson as management wrote it, shown read-only to the staff member preparing it. */
  const briefHtml = (p) => `<div class="card card-pad" style="margin-bottom:12px">
      <div class="small muted" style="margin-bottom:6px"><i class="ti ti-speakerphone"></i> Posted by ${esc(p.brief?.created_by_name || 'management')}${p.brief?.class_level ? ` · for ${esc(p.brief.class_level)}` : ''}${p.school ? ` · ${esc(p.school)}` : ''}</div>
      <h3 style="margin-bottom:2px">${esc(p.topic)}</h3><div class="small muted" style="margin-bottom:8px">${esc(p.subject)} · project: ${esc(p.project_title)}</div>
      <div class="qa"><div class="q">Objectives</div><div class="a">${esc(p.objectives)}</div></div>
      <div class="qa"><div class="q">Lesson steps</div><div class="a" style="white-space:pre-wrap">${esc(p.activities)}</div></div>
      ${p.materials ? `<div class="qa"><div class="q">Materials</div><div class="a">${esc(p.materials)}</div></div>` : ''}
      ${p.brief?.note ? `<div class="note-box" style="margin-top:8px">${esc(p.brief.note)}</div>` : ''}
      ${p.brief?.files?.length ? `<div class="small muted" style="margin-top:8px">Reference files</div>${App.gallery(p.brief.files)}` : ''}</div>`;

  /* --------------------------------------------------------- lesson plan */
  /** New or returned lesson plan. Needs screenshots of the project the staff member built themselves. */
  async function planForm(plan = null, prefill = {}) {
    const v = plan || { planned_date: App.cfg.today, school: await currentSchool() || (App.user.schools || [])[0] || '', subject: (App.cfg.lesson_subjects || [])[0], ...prefill };
    const posted = !!plan?.assignment_id; // the admin wrote this lesson; the staff member prepares it
    const fixedSchool = posted && plan.brief?.target === 'school' && plan.school;
    const preparing = plan?.status === 'assigned';
    const el = App.modal(preparing ? `Prepare: ${esc(plan.topic)}` : plan ? `Fix lesson plan ${esc(plan.ref)}` : 'New lesson plan', `<form id="lp-form" novalidate>
      ${v.class_name && !plan ? `<div class="note-box" style="margin-bottom:12px"><i class="ti ti-calendar-time"></i> For <b>${esc(v.class_name)}</b> ${esc(v.subject || '')} at ${esc(v.school || '')}, ${esc(fmtDate(v.planned_date))}${v.planned_time ? ' ' + esc(v.planned_time) : ''}.</div>` : ''}
      ${plan?.status === 'returned' && plan.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>What to fix:</b> ${esc(plan.review_note)}</div>` : ''}
      <div class="note-box" style="margin-bottom:12px"><i class="ti ti-bulb"></i> Build the project yourself first, then upload screenshots of it here <b>before</b> you go to class. Classes can only be logged against a submitted plan.</div>
      ${posted ? `${briefHtml(plan)}${fixedSchool ? '' : `<div class="field"><label for="lp-school">School <span class="req">*</span></label><select id="lp-school" name="school">${schoolOpts(v.school)}</select></div>`}` : `
      <div class="form-row"><div class="field"><label for="lp-school">School <span class="req">*</span></label><select id="lp-school" name="school">${schoolOpts(v.school)}</select></div>
        <div class="field"><label for="lp-subj">Subject <span class="req">*</span></label><select id="lp-subj" name="subject">${App.opts(App.cfg.lesson_subjects || [], v.subject, { placeholder: 'Choose…' })}</select></div></div>
      <div class="field hide" id="lp-week-f"><label for="lp-week">Week from the scheme of work</label><select id="lp-week" name="scheme_item_id"></select><div class="help">Pick the week you're teaching and its topic fills in.</div></div>
      <div class="field"><label for="lp-topic">Topic <span class="req">*</span></label><input id="lp-topic" name="topic" value="${esc(v.topic || '')}" placeholder="e.g. Loops in Scratch"></div>
      <div class="field"><label for="lp-proj">Project the pupils will build <span class="req">*</span></label><input id="lp-proj" name="project_title" value="${esc(v.project_title || '')}" placeholder="e.g. Animated dancing cat"></div>
      <div class="field"><label for="lp-obj">Objectives <span class="req">*</span></label><textarea id="lp-obj" name="objectives" rows="2" placeholder="By the end, pupils can use a repeat block to make a sprite move in a pattern.">${esc(v.objectives || '')}</textarea></div>
      <div class="field"><label for="lp-act">Lesson steps <span class="req">*</span></label><textarea id="lp-act" name="activities" rows="5" placeholder="1. Introduction (5 min): …&#10;2. Demonstration (10 min): …&#10;3. Pupils build the project (25 min): …&#10;4. Show and tell / wrap-up (5 min): …">${esc(v.activities || '')}</textarea></div>
      <div class="field"><label for="lp-mat">Materials</label><input id="lp-mat" name="materials" value="${esc(v.materials || '')}" placeholder="Laptops with Scratch, projector, robotics kits"></div>`}
      <div class="form-row"><div class="field"><label for="lp-date">First class date <span class="req">*</span></label><input type="date" id="lp-date" name="planned_date" value="${esc(v.planned_date || '')}"></div>
        <div class="field"><label for="lp-time">Time</label><input type="time" id="lp-time" name="planned_time" value="${esc(v.planned_time || '')}"></div></div>
      <div class="field" data-field="prep"><div class="label">Your finished project: screenshots or photos <span class="req">*</span></div><div id="lp-prep"></div>
        ${plan?.prep_photos?.length ? `<div class="help">${plan.prep_photos.length} already uploaded.</div>` : ''}</div>
      <div class="field"><div class="label">Lesson plan document (optional)</div><div id="lp-plan"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="lp-ok"><i class="ti ti-send"></i>${preparing ? 'Submit my project' : 'Submit plan'}</button>`, { wide: true });
    const prep = App.uploader($('#lp-prep', el), { existing: plan?.prep_photos?.length || 0, label: 'Add screenshots', accept: 'image/*' });
    const doc = App.uploader($('#lp-plan', el), { existing: plan?.plan_files?.length || 0, label: 'Add plan' });
    App.bindGallery(el);
    if (!posted) {
      // Weeks of the scheme of work at the chosen school; picking one fills in its topic.
      const form = $('#lp-form', el);
      let weeks = [];
      const loadWeeks = async () => {
        try { weeks = (await api('scheme_weeks', { query: { school: form.school.value } })).items; } catch { weeks = []; }
        const keep = v.scheme_item_id && !weeks.some((w) => w.id === v.scheme_item_id) ? [[v.scheme_item_id, 'Linked week (kept)']] : [];
        $('#lp-week', el).innerHTML = App.opts(keep.concat(weeks.map((w) => [w.id, w.label])), v.scheme_item_id || '', { placeholder: 'Not from the scheme' });
        $('#lp-week-f', el).classList.toggle('hide', !weeks.length && !keep.length);
      };
      form.school.addEventListener('change', loadWeeks);
      $('#lp-week', el).addEventListener('change', (e) => {
        const w = weeks.find((x) => x.id === +e.target.value);
        if (!w) return;
        form.topic.value = w.topic;
        if (w.project_title) form.project_title.value = w.project_title;
        if (w.objectives) form.objectives.value = w.objectives;
        if (w.subject) form.subject.value = w.subject;
      });
      loadWeeks();
    }
    $('#lp-ok', el).addEventListener('click', async (e) => {
      const form = $('#lp-form', el);
      App.showErrors(form, null);
      if (!prep.count() && !plan?.prep_photos?.length) { App.showErrors(form, { prep: 'Upload at least one screenshot of the project you built.' }); return; }
      const files = prep.files().map((f) => ({ field: 'prep[]', file: f })).concat(doc.files().map((f) => ({ field: 'plan[]', file: f })));
      try {
        const r = await App.busy(e.currentTarget, () => api('lesson_plan_save', { data: { ...App.formData(form), id: plan?.id || 0, class_name: v.class_name || '', slot_id: v.slot_id || 0 }, files }));
        App.closeAll();
        toast(preparing ? 'Lesson prepared. You can now log the classes you teach with it.' : plan ? 'Plan resubmitted' : `Plan ${r.ref} submitted. You can now log the classes you teach with it.`);
        App.current?.refresh?.();
        App.poll?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.lessonPlanForm = planForm;

  /* ---------------------------------------------------------- log classes */
  /** Log several classes taught from one plan (each with its own photos), or fix one returned class. */
  async function logClasses(plan, fix = null, start = null) {
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
    addRow(fix || start || {});
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
  /** Log a class taught from a plan, with the class, school and date filled in (from the timetable). */
  App.logLessonClassFor = async (planId, start) => {
    try { logClasses((await api('lesson_plan_get', { query: { id: planId } })).plan, null, start); } catch (e) { App.fail(e); }
  };

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
      <div style="margin-bottom:12px">${pill(p.status)}${p.brief ? ` <span class="pill brand"><i class="ti ti-speakerphone"></i>Posted by ${esc(p.brief.created_by_name)}</span>` : ''}</div>
      ${p.status === 'assigned' && own ? '<div class="note-box warn" style="margin-bottom:12px">Build this project yourself, then tap <b>Prepare lesson</b> and upload screenshots of it before you teach.</div>' : ''}
      ${p.brief?.note ? `<div class="note-box" style="margin-bottom:12px"><b>Note from ${esc(p.brief.created_by_name)}:</b> ${esc(p.brief.note)}</div>` : ''}
      <dl class="kv"><dt>Staff</dt><dd>${admin ? `<a href="#/staff/${p.user_id}">${esc(p.user_name)}</a>` : esc(p.user_name)}</dd>
        <dt>Subject</dt><dd>${esc(p.subject)}</dd><dt>Project</dt><dd>${esc(p.project_title)}</dd><dt>School</dt><dd>${esc(p.school)}</dd>
        <dt>Planned</dt><dd>${esc(fmtDate(p.planned_date))}${p.planned_time ? ' ' + esc(p.planned_time) : ''}</dd>
        <dt>${p.status === 'assigned' ? 'Posted' : 'Submitted'}</dt><dd>${esc(fmtDate(p.status === 'assigned' ? p.created_at : p.submitted_at))} · ${esc(ago(p.status === 'assigned' ? p.created_at : p.submitted_at))}</dd></dl>
      <div class="sec-title">Objectives</div><div class="note-box">${esc(p.objectives)}</div>
      <div class="sec-title">Lesson steps</div><div class="note-box" style="white-space:pre-wrap">${esc(p.activities)}</div>
      ${p.materials ? `<div class="sec-title">Materials</div><p>${esc(p.materials)}</p>` : ''}
      ${p.brief?.files?.length ? `<div class="sec-title">Reference files</div>${App.gallery(p.brief.files)}` : ''}
      <div class="sec-title">Staff member's own project</div><div id="lp-gal-prep">${p.status === 'assigned' ? '<p class="faint small">Not prepared yet.</p>' : App.gallery(p.prep_photos)}</div>
      ${p.plan_files.length ? `<div class="sec-title">Lesson plan document</div>${App.gallery(p.plan_files)}` : ''}
      ${p.review_note ? `<div class="note-box ${p.status === 'returned' ? 'bad' : ''}" style="margin-top:10px"><b>${esc(p.reviewed_by_name || 'Admin')}:</b> ${esc(p.review_note)}</div>` : ''}
      <div class="sec-title">Classes taught (${p.sessions.filter((s) => s.status !== 'cancelled').length})</div>
      ${p.sessions.length ? p.sessions.map((s) => sessionHtml(s, admin, own)).join('') : '<p class="faint">No classes logged yet.</p>'}`;
    App.bindGallery($('.drawer-body', el));
    const foot = [];
    if (admin && p.status === 'submitted') foot.push('<button class="btn" data-ret="plan:' + p.id + '"><i class="ti ti-arrow-back-up"></i>Return plan</button><button class="btn primary" data-ok="plan:' + p.id + '"><i class="ti ti-check"></i>Approve plan</button>');
    if (own && p.status === 'assigned') foot.push('<button class="btn primary" id="lp-edit"><i class="ti ti-upload"></i>Prepare lesson</button>');
    else if (own && p.status !== 'approved') foot.push('<button class="btn" id="lp-edit"><i class="ti ti-edit"></i>' + (p.status === 'returned' ? 'Fix and resubmit' : 'Edit plan') + '</button>');
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

  /* ------------------------------------------------- planned by management */
  let staffCache = null;
  const activeStaff = async () => (staffCache = staffCache || (await api('users_list')).items.filter((u) => +u.active && u.role === 'staff'));
  const atSchool = (staff, school) => staff.filter((s) => (s.schools || []).some((n) => n.toLowerCase() === String(school).toLowerCase()));
  const levelList = () => `<datalist id="lv-list">${(App.cfg.class_levels || []).map((c) => `<option value="${esc(c)}">`).join('')}</datalist>`;

  /** "Send to": a school (everyone assigned there), chosen staff, or (for training) all staff. */
  const recipientsHtml = (staff, { mode = 'school', school = '', all = false, schoolNeeded = true } = {}) => `
    <div class="field"><div class="label">Send to</div><div class="opts">${[['school', 'Staff at a school'], ['staff', 'Chosen staff'], ...(all ? [['all', 'All staff']] : [])].map(([k, l]) =>
      `<label class="opt"><input type="radio" name="send_to" value="${k}" ${mode === k ? 'checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
    <div class="field" data-rc="school${schoolNeeded ? ' staff' : ''}"><label>School <span class="req" data-rc="school">*</span><span class="faint" data-rc="staff"> (optional: staff pick it if blank)</span></label>
      <select name="school">${App.opts(App.cfg.locations || [], school, { placeholder: 'Choose…' })}</select><div class="help" data-rc-preview></div></div>
    <div class="field" data-rc="staff" data-field="user_ids"><div class="label">Staff <span class="req">*</span></div><div class="opts">${staff.map((s) => `<label class="opt"><input type="checkbox" data-multi="1" name="user_ids" value="${s.id}">
      <span><i class="ti ti-check" style="font-size:14px"></i>${esc(s.name)}</span></label>`).join('')}</div></div>`;
  const bindRecipients = (form, staff) => {
    const sync = () => {
      const m = form.querySelector('[name=send_to]:checked')?.value || 'school';
      $$('[data-rc]', form).forEach((x) => x.classList.toggle('hide', !x.dataset.rc.split(' ').includes(m)));
      const who = atSchool(staff, form.school.value);
      const pv = $('[data-rc-preview]', form);
      pv.textContent = m === 'school' && form.school.value ? (who.length ? `Goes to ${who.length}: ${who.map((s) => s.name).join(', ')}` : 'No staff are assigned to this school yet.') : '';
    };
    form.addEventListener('change', (e) => { if (['send_to', 'school'].includes(e.target.name)) sync(); });
    sync();
  };

  /** Admin writes a lesson and posts it; each staff member gets their own copy to prepare and teach. */
  async function postLesson(prefill = {}) {
    let staff;
    try { staff = await activeStaff(); } catch (e) { App.fail(e); return; }
    const v = { send_to: 'school', planned_date: App.cfg.today, subject: (App.cfg.lesson_subjects || [])[0], ...prefill };
    const el = App.modal('Post a lesson', `<form id="pl-form" novalidate>
      ${v.scheme_item_id ? `<div class="note-box" style="margin-bottom:12px"><i class="ti ti-list-numbers"></i> From the scheme of work: week ${esc(v.week_no)}</div>` : ''}
      <p class="muted" style="margin-bottom:12px">Each staff member gets this lesson to prepare: they build the project themselves and upload screenshots before class, then log the classes they teach.</p>
      ${recipientsHtml(staff, { mode: v.send_to, school: v.school })}
      <div class="form-row"><div class="field"><label for="pl-subj">Subject <span class="req">*</span></label><select id="pl-subj" name="subject">${App.opts(App.cfg.lesson_subjects || [], v.subject, { placeholder: 'Choose…' })}</select></div>
        <div class="field"><label for="pl-lv">Class</label><input id="pl-lv" name="class_level" list="lv-list" value="${esc(v.class_level || '')}" placeholder="e.g. Grade 5"></div></div>
      <div class="field"><label for="pl-topic">Topic <span class="req">*</span></label><input id="pl-topic" name="topic" value="${esc(v.topic || '')}" placeholder="e.g. Loops in Scratch"></div>
      <div class="field"><label for="pl-proj">Project the pupils will build <span class="req">*</span></label><input id="pl-proj" name="project_title" value="${esc(v.project_title || '')}" placeholder="e.g. Animated dancing cat"></div>
      <div class="field"><label for="pl-obj">Objectives <span class="req">*</span></label><textarea id="pl-obj" name="objectives" rows="2" placeholder="By the end, pupils can use a repeat block to make a sprite move in a pattern.">${esc(v.objectives || '')}</textarea></div>
      <div class="field"><label for="pl-act">Lesson steps <span class="req">*</span></label><textarea id="pl-act" name="activities" rows="5" placeholder="1. Introduction (5 min): …&#10;2. Demonstration (10 min): …&#10;3. Pupils build the project (25 min): …&#10;4. Show and tell / wrap-up (5 min): …">${esc(v.activities || '')}</textarea></div>
      <div class="field"><label for="pl-mat">Materials</label><input id="pl-mat" name="materials" value="${esc(v.materials || '')}" placeholder="Laptops with Scratch, projector, robotics kits"></div>
      <div class="form-row"><div class="field"><label for="pl-date">Teach on <span class="req">*</span></label><input type="date" id="pl-date" name="planned_date" value="${esc(v.planned_date || '')}"></div>
        <div class="field"><label for="pl-time">Time</label><input type="time" id="pl-time" name="planned_time" value="${esc(v.planned_time || '')}"></div></div>
      <div class="field"><label for="pl-note">Note to staff</label><textarea id="pl-note" name="note" rows="2" placeholder="Optional, e.g. Use the new robotics kits. Ask the ICT teacher for the projector key.">${esc(v.note || '')}</textarea></div>
      <div class="field"><div class="label">Reference files (optional)</div><div id="pl-files"></div><div class="help">A sample of the finished project, worksheet or slides.</div></div>
      ${levelList()}</form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="pl-ok"><i class="ti ti-send"></i>Post lesson</button>`, { wide: true });
    const form = $('#pl-form', el);
    bindRecipients(form, staff);
    const up = App.uploader($('#pl-files', el), { label: 'Add files' });
    $('#pl-ok', el).addEventListener('click', async (e) => {
      App.showErrors(form, null);
      try {
        const r = await App.busy(e.currentTarget, () => api('lesson_assign', { data: { ...App.formData(form), scheme_item_id: v.scheme_item_id || 0 },
          files: up.files().map((f) => ({ field: 'brief[]', file: f })) }));
        App.closeAll();
        toast(`Lesson posted to ${r.staff} staff member${r.staff === 1 ? '' : 's'}`);
        App.current?.refresh?.();
        App.poll?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }
  App.postLesson = postLesson;

  const postedList = (items) => (items.length ? items.map((a) => {
    const ready = a.plans.filter((p) => p.status !== 'assigned').length;
    return `<div class="card card-pad" style="margin-bottom:12px">
      <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><div><h3 style="margin-bottom:2px">${esc(a.topic)}</h3>
        <div class="small muted">${esc(a.subject)}${a.class_level ? ` · ${esc(a.class_level)}` : ''} · ${a.school ? esc(a.school) : 'Chosen staff'} · ${esc(fmtDate(a.planned_date, false))}${a.planned_time ? ' ' + esc(a.planned_time) : ''} · posted by ${esc(a.created_by_name)}</div></div>
        <button class="btn sm ghost" data-wd="${a.id}"><i class="ti ti-arrow-back-up"></i>Withdraw</button></div>
      <div class="small muted" style="margin:8px 0 6px">${ready} of ${a.plans.length} prepared · ${a.plans.reduce((n, p) => n + p.classes, 0)} classes taught</div>
      <div class="chips">${a.plans.map((p) => `<span class="pill ${STATUS[p.status]?.[1] || ''}" data-plan="${p.id}" style="cursor:pointer">${esc(p.user_name)} · ${esc(STATUS[p.status]?.[0] || p.status)}${p.classes ? ` · ${p.classes} class${p.classes === 1 ? '' : 'es'}` : ''}</span>`).join('')}</div></div>`;
  }).join('') : App.empty('speakerphone', 'No posted lessons', 'Lessons you post to staff show up here with their progress.'));

  async function withdrawPosted(id, btn) {
    if (!(await App.confirm('Withdraw this lesson?', 'Staff who haven\'t prepared it yet will have it removed. Anything already prepared or taught is kept.', { ok: 'Withdraw', danger: true }))) return;
    try {
      const r = await App.busy(btn, () => api('lesson_assign_withdraw', { data: { id } }));
      toast(`Withdrawn${r.removed ? `; removed from ${r.removed} staff member${r.removed === 1 ? '' : 's'}` : ''}`);
      App.current?.refresh?.();
    } catch (e) { App.fail(e); }
  }

  /* ------------------------------------------------------ scheme of work */
  /** Admin plans the term week by week for one school, class and subject. */
  async function schemeForm(s = null) {
    const v = s || { school: '', subject: (App.cfg.lesson_subjects || [])[0], class_level: '', term: '', start_date: App.cfg.today, items: [] };
    const el = App.modal(s ? 'Edit scheme of work' : 'New scheme of work', `<form id="sc-form" novalidate>
      <p class="muted" style="margin-bottom:12px">What the pupils should learn this term, week by week. Staff at the school see it, and each week is ticked off when a class is taught from it.</p>
      <div class="form-row"><div class="field"><label>School <span class="req">*</span></label><select name="school">${App.opts(App.cfg.locations || [], v.school, { placeholder: 'Choose…' })}</select></div>
        <div class="field"><label>Subject <span class="req">*</span></label><select name="subject">${App.opts(App.cfg.lesson_subjects || [], v.subject, { placeholder: 'Choose…' })}</select></div></div>
      <div class="form-row"><div class="field"><label>Class <span class="req">*</span></label><input name="class_level" list="lv-list" value="${esc(v.class_level)}" placeholder="e.g. Grade 5"></div>
        <div class="field"><label>Term <span class="req">*</span></label><input name="term" value="${esc(v.term)}" placeholder="e.g. First term 2026/27"></div>
        <div class="field"><label>Week 1 starts <span class="req">*</span></label><input type="date" name="start_date" value="${esc(v.start_date)}"></div></div>
      <div class="sec-title">Weeks</div><div class="field" data-field="items"><div id="sc-rows"></div></div>
      <button class="btn" type="button" id="sc-add"><i class="ti ti-plus"></i>Add week</button>${levelList()}</form>`,
    `<button class="btn ghost" data-close>Cancel</button>${s ? '<button class="btn danger" id="sc-del"><i class="ti ti-trash"></i>Delete</button>' : ''}<button class="btn primary" id="sc-ok">Save scheme</button>`, { wide: true });
    const form = $('#sc-form', el);
    const box = $('#sc-rows', el);
    const addRow = (it = {}) => {
      const n = box.children.length + 1;
      const row = document.createElement('div');
      row.className = 'card card-pad';
      row.style.marginBottom = '8px';
      row.dataset.id = it.id || 0;
      row.innerHTML = `<div class="form-row"><div class="field" style="max-width:90px"><label>Week</label><input type="number" min="1" max="60" data-k="week_no" value="${esc(it.week_no || n)}"></div>
          <div class="field"><label>Topic</label><input data-k="topic" value="${esc(it.topic || '')}" placeholder="e.g. Loops in Scratch"></div>
          <div class="field"><label>Project</label><input data-k="project_title" value="${esc(it.project_title || '')}" placeholder="e.g. Dancing cat"></div>
          <button type="button" class="btn sm ghost" data-rm aria-label="Remove week" style="align-self:center" ${it.covered ? 'disabled title="Already taught"' : ''}><i class="ti ti-trash"></i></button></div>
        <div class="field" style="margin-bottom:0"><input data-k="objectives" value="${esc(it.objectives || '')}" placeholder="What pupils should be able to do by the end of the week (optional)"></div>`;
      $('[data-rm]', row).addEventListener('click', () => row.remove());
      box.appendChild(row);
    };
    (v.items.length ? v.items : [{}, {}, {}]).forEach(addRow);
    $('#sc-add', el).addEventListener('click', () => addRow());
    $('#sc-ok', el).addEventListener('click', async (e) => {
      App.showErrors(form, null);
      const items = $$('#sc-rows > div', el).map((r) => {
        const d = { id: +r.dataset.id };
        $$('[data-k]', r).forEach((x) => { d[x.dataset.k] = x.value; });
        return d;
      });
      const d = App.formData(form);
      try {
        await App.busy(e.currentTarget, () => api('scheme_save', { data: { id: s?.id || 0, school: d.school, subject: d.subject, class_level: d.class_level, term: d.term, start_date: d.start_date, items } }));
        App.closeAll();
        toast('Scheme of work saved');
        App.current?.refresh?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
    $('#sc-del', el)?.addEventListener('click', async (e) => {
      if (!(await App.confirm('Delete this scheme?', 'Its weeks will be removed.', { ok: 'Delete', danger: true }))) return;
      try {
        await App.busy(e.currentTarget, () => api('scheme_delete', { data: { id: s.id } }));
        App.closeAll();
        toast('Scheme deleted');
        App.current?.refresh?.();
      } catch (err) { App.fail(err); }
    });
  }

  const weekState = (s, it) => {
    if (it.covered) return `<span class="pill in" title="${esc(it.covered.staff)}"><i class="ti ti-check"></i>Taught ${esc(fmtDate(it.covered.date, false))}</span>`;
    if (it.week_no === s.current_week) return `<span class="pill brand">This week${it.plans ? ' · planned' : ''}</span>`;
    if (it.week_no < s.current_week) return '<span class="pill bad">Not taught</span>';
    return it.plans ? '<span class="pill info">Planned</span>' : '';
  };
  const schemeCards = (items, admin) => (items.length ? `<div class="grid g2">${items.map((s) => `<div class="card card-pad">
      <div style="display:flex;justify-content:space-between;gap:8px"><div><h3 style="margin-bottom:2px">${esc(s.school)} · ${esc(s.class_level)} ${esc(s.subject)}</h3>
        <div class="small muted">${esc(s.term)} · week 1 starts ${esc(fmtDate(s.start_date, false))}</div></div>
        ${admin ? `<button class="btn sm ghost" data-sc-edit="${s.id}" aria-label="Edit scheme"><i class="ti ti-edit"></i></button>` : ''}</div>
      <div style="display:flex;align-items:center;gap:10px;margin:10px 0"><div class="bar-track" style="flex:1"><div class="bar-fill" style="width:${s.items.length ? Math.round(s.covered / s.items.length * 100) : 0}%"></div></div>
        <span class="small muted">${s.covered}/${s.items.length} weeks taught</span></div>
      <ul class="list">${s.items.map((it) => `<li class="li" ${it.covered ? `data-plan="${it.covered.plan_id}"` : ''} style="${it.week_no === s.current_week ? 'background:var(--brand-soft)' : ''}">
        <div class="li-icon tone-${it.covered ? 'in' : 'brand'}"><b>${it.week_no}</b></div>
        <div class="main-col"><div class="t">${esc(it.topic)}</div><div class="s">${esc(fmtDate(it.week_start, false))}${it.project_title ? ` · ${esc(it.project_title)}` : ''}${it.covered ? ` · ${esc(it.covered.staff)}` : ''}</div></div>
        ${weekState(s, it)}${!it.covered ? `<button class="btn sm" data-sc-post="${s.id}:${it.id}">${admin ? '<i class="ti ti-send"></i>Post' : '<i class="ti ti-file-plus"></i>Plan it'}</button>` : ''}</li>`).join('')}</ul></div>`).join('')}</div>`
    : App.empty('list-numbers', 'No scheme of work yet', admin ? 'Plan what pupils at each school should learn, week by week.' : 'Your schools\' term plans will show up here.'));

  function bindSchemes(root, items, admin) {
    $$('[data-sc-edit]', root).forEach((b) => b.addEventListener('click', () => schemeForm(items.find((s) => s.id === +b.dataset.scEdit))));
    $$('[data-sc-post]', root).forEach((b) => b.addEventListener('click', (e) => {
      e.stopPropagation();
      const [sid, iid] = b.dataset.scPost.split(':').map(Number);
      const s = items.find((x) => x.id === sid);
      const it = s.items.find((x) => x.id === iid);
      const fill = { school: s.school, subject: s.subject, topic: it.topic, project_title: it.project_title, objectives: it.objectives, scheme_item_id: it.id,
        week_no: it.week_no, planned_date: it.week_start > App.cfg.today ? it.week_start : App.cfg.today };
      if (admin) postLesson({ ...fill, send_to: 'school', class_level: s.class_level });
      else planForm(null, fill);
    }));
  }

  /* ------------------------------------------------------------ training */
  const TSTATUS = { todo: ['To do', 'out'], submitted: ['To check', 'info'], done: ['Done', 'in'], returned: ['Returned to you', 'bad'] };
  const tpill = (s) => `<span class="pill ${TSTATUS[s]?.[1] || ''}">${esc(TSTATUS[s]?.[0] || s)}</span>`;
  const dueText = (d) => (d ? `due ${fmtDate(d, false)}${d < App.cfg.today ? ' (overdue)' : ''}` : 'no due date');

  /** Admin sets something staff must learn themselves. */
  async function trainingForm(t = null) {
    let staff;
    try { staff = await activeStaff(); } catch (e) { App.fail(e); return; }
    const v = t || { title: '', description: '', resources: '', due_date: App.addDays(App.cfg.today, 7) };
    const el = App.modal(t ? 'Edit training' : 'New training for staff', `<form id="tr-form" novalidate>
      <div class="field"><label>What should they learn? <span class="req">*</span></label><input name="title" value="${esc(v.title)}" placeholder="e.g. Arduino ultrasonic sensor" autofocus></div>
      <div class="field"><label>Details and how to show it <span class="req">*</span></label><textarea name="description" rows="4" placeholder="Build an obstacle-avoiding car with the ultrasonic sensor. Send a photo of it working and the code.">${esc(v.description)}</textarea></div>
      <div class="field"><label>Resources</label><textarea name="resources" rows="2" placeholder="Links to videos, tutorials or documents (one per line)">${esc(v.resources || '')}</textarea></div>
      <div class="field" style="max-width:220px"><label>Due date</label><input type="date" name="due_date" value="${esc(v.due_date || '')}"></div>
      ${t ? '<div class="sec-title">Add more staff (optional)</div>' : ''}
      ${recipientsHtml(staff, { mode: t ? 'staff' : 'school', all: true, schoolNeeded: false })}
      <div class="field"><div class="label">Materials (optional)</div><div id="tr-files"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="tr-ok"><i class="ti ti-send"></i>${t ? 'Save' : 'Send to staff'}</button>`, { wide: true });
    const form = $('#tr-form', el);
    bindRecipients(form, staff);
    const up = App.uploader($('#tr-files', el), { existing: t?.files?.length || 0, label: 'Add files' });
    $('#tr-ok', el).addEventListener('click', async (e) => {
      App.showErrors(form, null);
      try {
        const r = await App.busy(e.currentTarget, () => api('training_save', { data: { ...App.formData(form), id: t?.id || 0 }, files: up.files().map((f) => ({ field: 'ref[]', file: f })) }));
        App.closeAll();
        toast(r.added ? `Sent to ${r.added} staff member${r.added === 1 ? '' : 's'}` : 'Training saved');
        App.current?.refresh?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /** Staff send what they did as proof that they learned it. */
  function trainingSubmit(t, a) {
    const el = App.modal(`Done: ${esc(t.title)}`, `<form id="ts-form" novalidate>
      ${a.status === 'returned' && a.review_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>What to add:</b> ${esc(a.review_note)}</div>` : ''}
      <div class="field"><label>What did you learn and build? <span class="req">*</span></label><textarea name="proof_note" rows="4" placeholder="e.g. Built the obstacle car; it stops and turns at 15cm. Code: https://…">${esc(a.proof_note || '')}</textarea></div>
      <div class="field" data-field="proof"><div class="label">Proof: screenshots, photos or certificate</div><div id="ts-up"></div>${a.files?.length ? `<div class="help">${a.files.length} already uploaded.</div>` : ''}</div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ts-ok"><i class="ti ti-send"></i>Submit</button>`);
    const form = $('#ts-form', el);
    const up = App.uploader($('#ts-up', el), { existing: a.files?.length || 0, label: 'Add proof' });
    $('#ts-ok', el).addEventListener('click', async (e) => {
      App.showErrors(form, null);
      try {
        await App.busy(e.currentTarget, () => api('training_submit', { data: { id: t.id, proof_note: form.proof_note.value }, files: up.files().map((f) => ({ field: 'proof[]', file: f })) }));
        App.closeAll();
        toast('Sent for checking');
        App.current?.refresh?.();
        App.poll?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  async function openTraining(id) {
    const el = App.drawer('Training', App.loading());
    let t;
    try { t = (await api('training_get', { query: { id } })).training; } catch (e) { App.closeTop(); App.fail(e); return; }
    const admin = isAdmin();
    const mine = t.assignees.find((a) => a.user_id === App.user.id);
    $('.drawer-head h2', el).textContent = t.title;
    const proof = (a) => `${a.proof_note ? `<div class="qa"><div class="q">What they did</div><div class="a" style="white-space:pre-wrap">${esc(a.proof_note)}</div></div>` : ''}
      ${a.files.length ? App.gallery(a.files) : ''}
      ${a.review_note ? `<div class="note-box ${a.status === 'returned' ? 'bad' : ''}" style="margin-top:8px"><b>${esc(a.reviewed_by_name || 'Admin')}:</b> ${esc(a.review_note)}</div>` : ''}`;
    $('.drawer-body', el).innerHTML = `
      <div style="margin-bottom:12px">${t.status === 'closed' ? '<span class="pill">Closed</span> ' : ''}${mine ? tpill(mine.status) : ''} <span class="small muted">${esc(dueText(t.due_date))} · set by ${esc(t.created_by_name)}</span></div>
      <div class="note-box">${esc(t.description)}</div>
      ${t.resources ? `<div class="sec-title">Resources</div><div class="note-box">${esc(t.resources)}</div>` : ''}
      ${t.files.length ? `<div class="sec-title">Materials</div>${App.gallery(t.files)}` : ''}
      ${admin ? `<div class="sec-title">Staff (${t.assignees.filter((a) => a.status === 'done').length}/${t.assignees.length} done)</div>
        ${t.assignees.map((a) => `<div class="card card-pad" style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:6px"><b>${esc(a.user_name)}</b><div>${tpill(a.status)}</div></div>
          ${a.submitted_at ? `<div class="small muted" style="margin-bottom:6px">Sent ${esc(fmtDate(a.submitted_at))}</div>` : ''}${proof(a)}
          ${a.status === 'submitted' ? `<div class="actions" style="margin-top:10px"><button class="btn sm primary" data-tok="${a.id}"><i class="ti ti-check"></i>Mark done</button><button class="btn sm" data-tret="${a.id}"><i class="ti ti-arrow-back-up"></i>Return</button></div>` : ''}</div>`).join('')}`
    : mine && (mine.proof_note || mine.files.length || mine.review_note) ? `<div class="sec-title">Your proof</div>${proof(mine)}` : ''}`;
    App.bindGallery($('.drawer-body', el));
    const foot = [];
    if (admin) foot.push(`<button class="btn" id="tr-close">${t.status === 'closed' ? 'Reopen' : 'Close training'}</button><button class="btn" id="tr-edit"><i class="ti ti-edit"></i>Edit / add staff</button>`);
    if (mine && t.status === 'open' && ['todo', 'returned'].includes(mine.status)) foot.push('<button class="btn primary" id="tr-done"><i class="ti ti-check"></i>Mark as done</button>');
    if (foot.length) {
      const f = document.createElement('div');
      f.className = 'drawer-foot';
      f.innerHTML = foot.join('');
      el.appendChild(f);
    }
    $('#tr-done', el)?.addEventListener('click', () => { App.closeTop(); trainingSubmit(t, mine); });
    $('#tr-edit', el)?.addEventListener('click', () => { App.closeTop(); trainingForm(t); });
    $('#tr-close', el)?.addEventListener('click', async (e) => {
      try {
        await App.busy(e.currentTarget, () => api('training_close', { data: { id: t.id, reopen: t.status === 'closed' } }));
        App.closeTop();
        App.current?.refresh?.();
      } catch (err) { App.fail(err); }
    });
    const review = async (aid, decision, btn) => {
      let note = '';
      if (decision === 'return') {
        note = await App.confirm('Return to the staff member', 'Say what they still need to learn or show. They\'ll be notified.', { ok: 'Return', input: { label: 'What to add', required: true } });
        if (note === null) return;
      }
      try {
        await App.busy(btn, () => api('training_review', { data: { assignee_id: aid, decision, note } }));
        toast(decision === 'return' ? 'Returned' : 'Marked done');
        App.closeTop();
        App.current?.refresh?.();
        App.poll?.();
        openTraining(id);
      } catch (e) { App.fail(e); }
    };
    $$('[data-tok]', el).forEach((b) => b.addEventListener('click', () => review(+b.dataset.tok, 'done', b)));
    $$('[data-tret]', el).forEach((b) => b.addEventListener('click', () => review(+b.dataset.tret, 'return', b)));
  }
  App.openTraining = openTraining;

  const trainingList = (items, admin) => (items.length ? `<ul class="list">${items.map((t) => `<li class="li" data-tr="${t.id}">
      <div class="li-icon tone-info"><i class="ti ti-certificate"></i></div>
      <div class="main-col"><div class="t">${esc(t.title)}</div><div class="s">${esc(dueText(t.due_date))}${admin ? ` · ${t.done}/${t.assignees} done${t.returned ? ` · ${t.returned} returned` : ''}` : ''}</div>
        ${admin && t.assignees ? `<div class="bar-track" style="margin-top:6px;max-width:260px"><div class="bar-fill" style="width:${Math.round(t.done / t.assignees * 100)}%"></div></div>` : ''}</div>
      ${admin ? (t.submitted ? `<span class="pill info">${t.submitted} to check</span>` : '') : tpill(t.status)}</li>`).join('')}</ul>`
    : App.empty('certificate', 'No training', admin ? 'Set things staff should learn, with a due date, and check their proof.' : 'Training set for you by management shows up here.'));
  const bindTraining = (root) => $$('[data-tr]', root).forEach((x) => x.addEventListener('click', () => openTraining(+x.dataset.tr)));

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
      <th class="r">Pupils</th><th class="r hide-sm">Finished project</th><th class="r">Prepared first</th><th class="r">Taught, no evidence</th><th class="r hide-sm">No clock-in</th><th class="r" title="Lessons posted to them whose date passed without the project being prepared">Posted, not prepared</th><th class="r" title="Timetabled classes with no lesson plan posted for them">No plan, timetabled</th><th class="r">To fix</th></tr></thead>
      <tbody>${r.rows.map((x) => `<tr class="click" data-u="${x.id}"><td>${esc(x.name)}</td><td class="r num">${x.plans}</td><td class="r num">${x.classes}${x.cancelled ? `<span class="faint"> +${x.cancelled}✕</span>` : ''}</td>
        <td class="r num hide-sm">${x.schools}</td><td class="r num">${x.pupils}</td><td class="r num hide-sm">${pct(x.completion_pct)}</td>
        <td class="r"><span class="pill ${x.prepared_pct === null ? '' : x.prepared_pct >= 90 ? 'in' : x.prepared_pct >= 60 ? 'out' : 'bad'}">${pct(x.prepared_pct)}</span></td>
        <td class="r num ${x.gaps ? 'bad' : ''}">${x.gaps}</td><td class="r num hide-sm ${x.no_clockin ? 'out' : ''}">${x.no_clockin}</td><td class="r num ${x.not_prepared ? 'bad' : ''}">${x.not_prepared}</td><td class="r num ${x.no_plan ? 'bad' : ''}">${x.no_plan}</td><td class="r num ${x.returned ? 'bad' : ''}">${x.returned}</td></tr>`).join('')}</tbody></table></div>`;
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
    if (!admin && !['mine', 'scheme', 'training', 'classes', 'stats'].includes(st.tab)) st.tab = 'mine';
    if (admin && !['review', 'posted', 'scheme', 'training', 'classes', 'compliance', 'schools'].includes(st.tab)) st.tab = 'review';
    staffCache = null;
    const staff = admin ? await activeStaff() : [];
    const tabs = admin ? [['review', 'Review', 'checks'], ['posted', 'Posted lessons', 'speakerphone'], ['scheme', 'Scheme of work', 'list-numbers'], ['training', 'Staff training', 'certificate'],
      ['classes', 'All classes', 'chalkboard'], ['compliance', 'Compliance', 'target'], ['schools', 'Schools', 'building-community']]
      : [['mine', 'My lessons', 'school'], ['scheme', 'Scheme of work', 'list-numbers'], ['training', 'My training', 'certificate'], ['classes', 'Classes taught', 'chalkboard'], ['stats', 'My record', 'target']];
    // Filters a tab doesn't use are hidden on it.
    const noDates = 'posted scheme training';
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${admin ? 'Lessons' : 'My lessons'}</h1>
        <p class="muted">${admin ? 'Post lessons to staff, plan what pupils learn each week and what staff should learn, and check the evidence from every class taught.'
          : 'Build the project yourself and submit a lesson plan before class. After each class, log it with photos of the pupils at work and their projects.'}</p></div>
        ${admin ? '<div class="actions"><button class="btn primary" id="ls-post"><i class="ti ti-speakerphone"></i>Post a lesson</button></div>'
          : '<div class="actions"><a class="btn ghost" href="#/timetable"><i class="ti ti-calendar-time"></i>Timetable</a><button class="btn" id="ls-plan"><i class="ti ti-file-plus"></i>New lesson plan</button><button class="btn primary" id="ls-log"><i class="ti ti-camera-plus"></i>Log classes</button></div>'}</div>
      <div class="seg scroll" id="ls-tabs" style="margin-bottom:12px">${tabs.map(([k, l, i]) => `<button data-t="${k}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
      <div class="filters" id="ls-f">
        <label class="small muted" style="display:flex;gap:6px;align-items:center" data-hide="${noDates}">From <input type="date" name="from" value="${st.from}" style="width:auto"></label>
        <label class="small muted" style="display:flex;gap:6px;align-items:center" data-hide="${noDates}">To <input type="date" name="to" value="${st.to}" style="width:auto"></label>
        ${admin ? `<select name="user_id" data-hide="${noDates}">${App.opts(staff.map((s) => [s.id, s.name]), st.user_id, { placeholder: 'All staff' })}</select>
          <select name="school" data-hide="training">${App.opts(App.cfg.locations || [], st.school, { placeholder: 'All schools' })}</select>
          <select name="subject" data-hide="scheme training">${App.opts(App.cfg.lesson_subjects || [], st.subject, { placeholder: 'All subjects' })}</select>
          <select name="flag" data-only="classes">${App.opts(Object.entries(FLAGS).map(([k, [l]]) => [k, l]), st.flag, { placeholder: 'Any flag' })}</select>` : ''}</div>
      <div id="ls-body">${App.loading()}</div>`;
    const body = $('#ls-body', ctx.el);

    const load = async () => {
      $$('#ls-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.t === st.tab));
      $$('[data-only]', ctx.el).forEach((x) => x.classList.toggle('hide', x.dataset.only !== st.tab));
      $$('[data-hide]', ctx.el).forEach((x) => x.classList.toggle('hide', x.dataset.hide.split(' ').includes(st.tab)));
      $('#ls-f', ctx.el).classList.toggle('hide', !admin && noDates.split(' ').includes(st.tab));
      const q = { from: st.from, to: st.to, user_id: st.user_id, school: st.school, subject: st.subject };
      if (st.tab === 'posted') {
        const r = await api('lesson_assignments_list', { query: { school: st.school, subject: st.subject } });
        body.innerHTML = postedList(r.items);
        bindOpen(body);
        $$('[data-wd]', body).forEach((b) => b.addEventListener('click', () => withdrawPosted(+b.dataset.wd, b)));
        return;
      }
      if (st.tab === 'scheme') {
        const r = await api('schemes_list', { query: { school: admin ? st.school : '' } });
        body.innerHTML = `${admin ? '<div class="actions" style="margin-bottom:12px"><button class="btn" id="sc-new"><i class="ti ti-plus"></i>New scheme of work</button></div>' : ''}${schemeCards(r.items, admin)}`;
        $('#sc-new', body)?.addEventListener('click', () => schemeForm());
        bindSchemes(body, r.items, admin);
        bindOpen(body);
        return;
      }
      if (st.tab === 'training') {
        const r = await api('trainings_list', { query: { closed: admin && st.closed ? 1 : '' } });
        body.innerHTML = `${admin ? `<div class="actions" style="margin-bottom:12px"><button class="btn" id="tr-new"><i class="ti ti-plus"></i>New training</button>
            <label class="check-row"><input type="checkbox" id="tr-closed" ${st.closed ? 'checked' : ''}><span>Show closed</span></label></div>` : ''}<div class="card">${trainingList(r.items, admin)}</div>`;
        $('#tr-new', body)?.addEventListener('click', () => trainingForm());
        $('#tr-closed', body)?.addEventListener('change', (e) => { st.closed = e.target.checked; load().catch(App.fail); });
        bindTraining(body);
        return;
      }
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
        const tr = (await api('trainings_list')).items.filter((x) => x.submitted);
        body.innerHTML = `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3>Lesson plans to review (${plans.length})</h3></div>${planList(plans)}</div>
          <div class="card" style="margin-bottom:14px"><div class="card-head"><h3>Classes to review (${sessions.length})</h3></div>${sessionTable(sessions)}</div>
          ${tr.length ? `<div class="card"><div class="card-head"><h3>Staff training to check (${tr.reduce((n, x) => n + x.submitted, 0)})</h3></div>${trainingList(tr, true)}</div>` : ''}`;
        bindTraining(body);
      } else if (st.tab === 'mine') {
        const returned = r.plans.filter((p) => p.status === 'returned').length + r.sessions.filter((s) => s.status === 'returned').length;
        const toPrep = r.plans.filter((p) => p.status === 'assigned');
        const own = r.plans.filter((p) => p.status !== 'assigned');
        const unused = own.filter((p) => !p.classes && p.status !== 'returned');
        body.innerHTML = `${toPrep.length ? `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3>Lessons to prepare (${toPrep.length})</h3></div>
            <p class="small muted" style="padding:0 16px">Posted for you by management. Build each project yourself and upload screenshots before class.</p>${planList(toPrep)}</div>` : ''}
          ${returned ? `<div class="note-box bad" style="margin-bottom:14px"><i class="ti ti-arrow-back-up"></i> ${returned} item${returned === 1 ? ' was' : 's were'} returned to you. Open ${returned === 1 ? 'it' : 'them'}, fix and resubmit.</div>` : ''}
          ${unused.length ? `<div class="note-box warn" style="margin-bottom:14px">${unused.length} lesson plan${unused.length === 1 ? ' has' : 's have'} no classes logged yet. After you teach, tap <b>Log classes</b>.</div>` : ''}
          <div class="card">${planList(own)}</div>`;
      } else {
        body.innerHTML = `<div class="card">${sessionTable(r.sessions)}</div>`;
      }
      bindOpen(body);
    };
    $$('#ls-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { st.tab = b.dataset.t; load().catch(App.fail); }));
    $('#ls-f', ctx.el).addEventListener('change', (e) => { if (e.target.name) { st[e.target.name] = e.target.value; load().catch(App.fail); } });
    $('#ls-plan', ctx.el)?.addEventListener('click', () => planForm());
    $('#ls-post', ctx.el)?.addEventListener('click', () => postLesson(st.subject ? { school: st.school, subject: st.subject } : { school: st.school }));
    $('#ls-log', ctx.el)?.addEventListener('click', pickPlanAndLog);
    ctx.refresh = () => load().catch(() => {});
    await load();
  }
  App.route('lessons', lessonsView, { title: 'Lessons', live: true });
  App.route('lessons/plan/:id', async (ctx) => { await lessonsView(ctx); openPlan(+ctx.params.id); }, { title: 'Lessons', nav: 'lessons', live: true });
  App.route('lessons/training/:id', async (ctx) => { st.tab = 'training'; await lessonsView(ctx); openTraining(+ctx.params.id); }, { title: 'Lessons', nav: 'lessons', live: true });
  App.route('lessons/log',async (ctx) => { await lessonsView(ctx); pickPlanAndLog(); }, { title: 'Lessons', nav: 'lessons', live: true });
})();
