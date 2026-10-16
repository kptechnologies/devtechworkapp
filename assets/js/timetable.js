/* DevTech Staff Portal — class timetables per school, with each class's record of topics taught, this week and coming up */
(function () {
  'use strict';
  const { $, $$, esc, api, toast, fmtDate, isManager } = App;

  const DAY_SHORT = { 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat', 7: 'Sun' };
  const st = { view: 'school', school: '' };
  const time = (t) => App.dueLabel(App.cfg.today, t).replace(/^.*?, /, '');
  const span = (s) => `${time(s.start_time)}–${time(s.end_time)}`;
  /** "Plan posted" / "No plan posted" for the period's next class. */
  const planPill = (s) => (!s.teacher_id ? '<span class="pill">No teacher</span>'
    : s.next_plan ? `<span class="pill in" title="${esc(s.next_plan.topic)}"><i class="ti ti-check"></i>Plan posted</span>`
      : '<span class="pill bad"><i class="ti ti-alert-triangle"></i>No plan posted</span>');
  const nextText = (s) => (s.next_date === App.cfg.today ? 'Today' : fmtDate(s.next_date, false));

  App.route('timetable', (ctx) => timetableView(ctx), { title: 'Timetable', live: true });

  async function timetableView(ctx) {
    const admin = isManager();
    ctx.el.innerHTML = `<div class="page-head"><div><h1>${admin ? 'Timetables' : 'Timetable'}</h1>
        <p class="muted">Each school's weekly classes. Post the topic and lesson plan before every class, and log what was taught after it.</p></div>
        <div class="actions"><button class="btn primary" id="tt-add"><i class="ti ti-plus"></i>Add period</button></div></div>
      <div class="seg scroll" id="tt-tabs" style="margin-bottom:12px"><button data-v="school">School timetable</button><button data-v="mine">My week</button></div>
      <div class="filters" id="tt-filters"></div>
      <div id="tt-body">${App.loading()}</div>`;
    let data = null;
    const load = async () => {
      $$('#tt-tabs button', ctx.el).forEach((b) => b.classList.toggle('on', b.dataset.v === st.view));
      data = await api('timetable_get', { query: st.view === 'mine' ? { mine: 1 } : { school: st.school } });
      if (st.view === 'school') st.school = data.school;
      $('#tt-filters', ctx.el).innerHTML = st.view === 'school' && data.schools.length
        ? `<select id="tt-school" aria-label="School">${App.opts(data.schools, st.school)}</select>` : '';
      $('#tt-school', ctx.el)?.addEventListener('change', (e) => { st.school = e.target.value; load().catch(App.fail); });
      draw();
    };
    const draw = () => {
      const box = $('#tt-body', ctx.el);
      if (st.view === 'school' && !data.schools.length) {
        box.innerHTML = App.empty('calendar-time', 'No schools', 'Ask an admin to assign you to your schools.');
        return;
      }
      const slots = data.slots;
      if (!slots.length) {
        box.innerHTML = `<div class="card">${App.empty('calendar-time', 'No classes yet', st.view === 'mine'
          ? 'Add the periods you teach at each school.' : `Add the classes taught at ${esc(st.school)}.`)}</div>`;
        return;
      }
      const days = [1, 2, 3, 4, 5].concat([6, 7].filter((d) => slots.some((s) => s.day === d)));
      const missing = slots.filter((s) => s.mine && !s.next_plan).length;
      box.innerHTML = `${missing ? `<div class="note-box bad" style="margin-bottom:12px"><i class="ti ti-alert-triangle"></i> ${missing} of your classes ${missing === 1 ? 'has' : 'have'} no lesson plan posted for the next lesson.
          Open the class and post the topic and plan before it starts.</div>` : ''}
        <div class="tt-grid" style="--tt-days:${days.length}">${days.map((d) => `<div class="tt-day"><div class="tt-day-h">${esc(data.days[d])}</div>
          ${slots.filter((s) => s.day === d).map((s) => `<button type="button" class="tt-slot ${s.mine ? 'mine' : ''}" data-slot="${s.id}">
            <div class="small num">${esc(span(s))}</div>
            <div style="font-weight:600">${esc(s.class_name)} · ${esc(s.subject)}</div>
            <div class="small muted">${st.view === 'mine' ? esc(s.school) : esc(s.teacher_name || 'No teacher')}${s.room ? ` · ${esc(s.room)}` : ''}</div>
            <div class="small" style="margin-top:4px">${planPill(s)} <span class="faint">${esc(nextText(s))}</span></div></button>`).join('')
            || '<div class="faint small" style="padding:6px 2px">No classes</div>'}</div>`).join('')}</div>`;
      $$('[data-slot]', box).forEach((b) => b.addEventListener('click', () => classRecord(+b.dataset.slot, () => load().catch(App.fail))));
    };
    $$('#tt-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { st.view = b.dataset.v; load().catch(App.fail); }));
    $('#tt-add', ctx.el).addEventListener('click', () => slotForm(null, data, () => load().catch(App.fail)));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  /** Add or edit a period. Staff add their own; admins choose the teacher. */
  async function slotForm(s, data, done) {
    const admin = isManager();
    let schools = data?.schools || [];
    let teachers = data?.teachers || [];
    if (!schools.length || (admin && !teachers.length)) {
      const r = await api('timetable_get', { query: { school: s?.school || st.school } });
      schools = r.schools;
      teachers = r.teachers;
    }
    const v = { school: s?.school || st.school || schools[0] || '', day: 1, start_time: '', end_time: '', class_name: '', subject: (App.cfg.lesson_subjects || [])[0] || '',
      teacher_id: admin ? '' : App.user.id, room: '', note: '', ...(s || {}) };
    const teacherOpts = () => {
      const here = teachers.filter((t) => t.here);
      const rest = teachers.filter((t) => !t.here);
      return `<option value="">No teacher yet</option>${here.length ? `<optgroup label="At this school">${App.opts(here.map((t) => [t.id, t.name]), v.teacher_id)}</optgroup>` : ''}
        <optgroup label="Other staff">${App.opts(rest.map((t) => [t.id, t.name]), v.teacher_id)}</optgroup>`;
    };
    const el = App.modal(s ? 'Edit period' : 'Add a period', `<form id="tt-form" novalidate>
      <div class="field"><label for="tt-s">School <span class="req">*</span></label><select id="tt-s" name="school">${App.opts(schools, v.school, { placeholder: 'Choose…' })}</select></div>
      <div class="form-row"><div class="field"><label for="tt-d">Day <span class="req">*</span></label><select id="tt-d" name="day">${App.opts(Object.entries(DAY_SHORT).map(([k]) => [k, (data?.days || {})[k] || DAY_SHORT[k]]), v.day)}</select></div>
        <div class="field"><label for="tt-c">Class <span class="req">*</span></label><input id="tt-c" name="class_name" list="tt-levels" value="${esc(v.class_name)}" placeholder="e.g. JSS1 A">
          <datalist id="tt-levels">${(App.cfg.class_levels || []).map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div></div>
      <div class="form-row"><div class="field"><label for="tt-st">Starts <span class="req">*</span></label><input type="time" id="tt-st" name="start_time" value="${esc(v.start_time || '')}"></div>
        <div class="field"><label for="tt-en">Ends <span class="req">*</span></label><input type="time" id="tt-en" name="end_time" value="${esc(v.end_time || '')}"></div></div>
      <div class="form-row"><div class="field"><label for="tt-sub">Subject <span class="req">*</span></label><select id="tt-sub" name="subject">${App.opts(App.cfg.lesson_subjects || [], v.subject, { placeholder: 'Choose…' })}</select></div>
        <div class="field"><label for="tt-r">Room / lab</label><input id="tt-r" name="room" value="${esc(v.room || '')}" placeholder="e.g. ICT lab"></div></div>
      ${admin ? `<div class="field"><label for="tt-t">Teacher</label><select id="tt-t" name="teacher_id">${teacherOpts()}</select></div>`
        : '<p class="small muted" style="margin-bottom:10px">You\'re the teacher for this period.</p>'}
      <div class="field"><label for="tt-n">Note</label><input id="tt-n" name="note" value="${esc(v.note || '')}" placeholder="e.g. Alternate weeks with Robotics"></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="tt-ok">${s ? 'Save' : 'Add period'}</button>`);
    if (admin) {
      $('#tt-s', el).addEventListener('change', async (e) => {
        try { teachers = (await api('timetable_get', { query: { school: e.target.value } })).teachers; v.teacher_id = $('#tt-t', el).value; $('#tt-t', el).innerHTML = teacherOpts(); } catch { /* keep the list */ }
      });
    }
    $('#tt-ok', el).addEventListener('click', async (e) => {
      const form = $('#tt-form', el);
      App.showErrors(form, null);
      try {
        await App.busy(e.currentTarget, () => api('timetable_slot_save', { data: { ...App.formData(form), id: s?.id || 0 } }));
        App.closeTop();
        toast(s ? 'Period saved' : 'Period added');
        done && done();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  /** A class's record: what was taught, this week's plan, plans coming up, and next classes still without a plan. */
  async function classRecord(id, changed) {
    const el = App.drawer('Class', App.loading());
    const render = async () => {
      let r;
      try { r = await api('timetable_class', { query: { id } }); } catch (e) { App.closeTop(); App.fail(e); return; }
      const s = r.slot;
      const teach = s.mine;
      $('.drawer-head h2', el).textContent = `${s.class_name} · ${s.subject}`;
      const planLi = (p) => `<li class="li" data-plan="${p.id}"><div class="li-icon tone-brand"><i class="ti ti-notebook"></i></div>
        <div class="main-col"><div class="t">${esc(p.topic)}</div><div class="s">${esc(p.ref)} · ${esc(fmtDate(p.planned_date))}${p.planned_time ? ' ' + esc(p.planned_time) : ''} · ${esc(p.user_name)}</div>
          ${p.project_title ? `<div class="s">Project: ${esc(p.project_title)}</div>` : ''}</div>
        ${teach && p.planned_date <= App.cfg.today ? `<button class="btn sm" data-log="${p.id}" data-date="${esc(p.planned_date)}"><i class="ti ti-chalkboard"></i>Log what was taught</button>` : ''}</li>`;
      $('.drawer-body', el).innerHTML = `
        <dl class="kv"><dt>School</dt><dd>${esc(s.school)}</dd><dt>When</dt><dd>${esc(s.day_name)}s, ${esc(span(s))}${s.room ? ` · ${esc(s.room)}` : ''}</dd>
          <dt>Teacher</dt><dd>${esc(s.teacher_name || 'No teacher yet')}</dd>${s.note ? `<dt>Note</dt><dd>${esc(s.note)}</dd>` : ''}</dl>
        ${r.missing_plans.length ? `<div class="note-box bad" style="margin:12px 0"><i class="ti ti-alert-triangle"></i> No lesson plan posted yet for
          ${r.missing_plans.map((d) => esc(d === App.cfg.today ? 'today' : fmtDate(d, false))).join(' or ')}.
          ${teach ? `<div style="margin-top:8px">${r.missing_plans.map((d) => `<button class="btn sm primary" data-post="${esc(d)}"><i class="ti ti-notebook"></i>Post plan for ${esc(d === App.cfg.today ? 'today' : fmtDate(d, false))}</button>`).join(' ')}</div>` : ''}</div>` : ''}
        <div class="sec-title">This week</div>
        ${r.this_week.length ? `<ul class="list">${r.this_week.map(planLi).join('')}</ul>` : '<p class="faint small">No topic posted for this week.</p>'}
        <div class="sec-title">Coming up</div>
        ${r.coming_up.length ? `<ul class="list">${r.coming_up.map(planLi).join('')}</ul>` : '<p class="faint small">No upcoming topics posted yet.</p>'}
        ${teach ? '<button class="btn sm ghost" id="tc-plan" style="margin-top:6px"><i class="ti ti-plus"></i>Post a topic / lesson plan</button>' : ''}
        <div class="sec-title">Taught</div>
        ${r.taught.length ? `<ul class="list">${r.taught.map((x) => `<li class="li" data-plan="${x.plan_id}"><div class="li-icon tone-in"><i class="ti ti-chalkboard"></i></div>
          <div class="main-col"><div class="t">${esc(x.topic)}</div><div class="s">${esc(fmtDate(x.session_date))} · ${esc(x.user_name)}${x.outcome ? ' · ' + esc(x.outcome) : ''}</div>
            ${x.achieved ? `<div class="s" style="white-space:pre-wrap">${esc(x.achieved)}</div>` : ''}</div></li>`).join('')}</ul>`
          : '<p class="faint small">No classes logged for this class yet.</p>'}`;
      const post = (date) => App.lessonPlanForm(null, { school: s.school, subject: s.subject, class_name: s.class_name, slot_id: s.id, planned_date: date, planned_time: s.start_time });
      $$('[data-post]', el).forEach((b) => b.addEventListener('click', () => post(b.dataset.post)));
      $('#tc-plan', el)?.addEventListener('click', () => post(s.next_date));
      $$('[data-log]', el).forEach((b) => b.addEventListener('click', (e) => {
        e.stopPropagation();
        App.logLessonClassFor(+b.dataset.log, { school: s.school, class_name: s.class_name, session_date: b.dataset.date });
      }));
      $$('[data-plan]', el).forEach((li) => li.addEventListener('click', () => App.openLessonPlan(+li.dataset.plan)));
      let foot = $('.drawer-foot', el);
      if (!foot) { foot = document.createElement('div'); foot.className = 'drawer-foot'; el.appendChild(foot); }
      foot.innerHTML = s.can_edit ? '<button class="btn danger" id="tc-del"><i class="ti ti-trash"></i>Remove period</button><button class="btn" id="tc-edit"><i class="ti ti-edit"></i>Edit period</button>' : '';
      foot.classList.toggle('hide', !s.can_edit);
      $('#tc-edit', foot)?.addEventListener('click', () => slotForm(s, null, () => { render(); changed && changed(); }));
      $('#tc-del', foot)?.addEventListener('click', async (e) => {
        if (!(await App.confirm('Remove this period?', `${esc(s.class_name)} ${esc(s.subject)}, ${esc(s.day_name)}s ${esc(span(s))}. Lesson plans and classes already logged are kept.`, { ok: 'Remove', danger: true }))) return;
        try { await App.busy(e.currentTarget, () => api('timetable_slot_delete', { data: { id: s.id } })); App.closeTop(); toast('Period removed'); changed && changed(); } catch (err) { App.fail(err); }
      });
    };
    await render();
  }
})();
