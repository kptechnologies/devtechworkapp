/* AI assistant: daily brief, staff review, report summaries, job next steps and suggested actions (management only). */
(() => {
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, isManagement } = App;

  const SEV = { urgent: ['bad', 'alert-octagon', 'Urgent'], warning: ['out', 'alert-triangle', 'Watch'], info: ['info', 'info-circle', 'Note'], good: ['in', 'thumb-up', 'Good'] };
  const TYPE = { job_task: ['list-check', 'Task on job'], followup: ['message-forward', 'Follow-up'], reminder: ['bell', 'Reminder'], job: ['clipboard-plus', 'New job'] };
  const TABS = [['brief', 'Daily brief'], ['staff', 'Staff review'], ['reports', 'Reports'], ['suggestions', 'Suggestions']];
  const iso = (d) => d.toISOString().slice(0, 10);
  const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };

  /** Provider picked on the AI page ('claude' / 'openai'); '' uses the one chosen in Settings. */
  const chosenProvider = () => App.store.get('ai-provider', '') || '';
  const byLine = (run) => `${esc(run.provider_name || 'AI')}${run.model ? ' · ' + esc(run.model) : ''}`;

  let staffCache = null;
  const staffList = async () => {
    if (!staffCache) staffCache = (await api('users_list')).items.filter((u) => +u.active && u.role !== 'admin');
    return staffCache;
  };

  /** Plain text from the AI with "- " lines turned into a list. */
  function prose(text) {
    const out = [];
    let list = [];
    const flush = () => { if (list.length) { out.push(`<ul style="margin:6px 0 10px 18px;display:grid;gap:4px">${list.map((l) => `<li>${esc(l)}</li>`).join('')}</ul>`); list = []; } };
    String(text || '').split('\n').forEach((line) => {
      const t = line.trim();
      if (/^[-•*]\s+/.test(t)) list.push(t.replace(/^[-•*]\s+/, ''));
      else { flush(); if (t) out.push(`<p style="margin:0 0 8px">${esc(t)}</p>`); }
    });
    flush();
    return out.join('');
  }

  function suggestionRow(s) {
    const p = s.payload || {};
    const [icon, label] = TYPE[s.type] || ['sparkles', s.type];
    const target = [s.job_ref && `${s.job_ref}${s.job_title ? ' · ' + s.job_title : ''}`, s.user_name, p.due_date && 'due ' + fmtDate(p.due_date, false)].filter(Boolean).join(' · ');
    const done = s.status !== 'pending';
    return `<li class="li" data-sg="${s.id}" style="cursor:default;align-items:flex-start">
      <div class="li-icon tone-brand"><i class="ti ti-${icon}"></i></div>
      <div class="main-col"><div class="t">${esc(p.title || '')} <span class="pill info" style="margin-left:4px">${esc(label)}</span></div>
        ${target ? `<div class="s">${esc(target)}</div>` : ''}
        ${p.text ? `<div class="small" style="margin-top:4px;white-space:pre-wrap">${esc(p.text)}</div>` : ''}
        ${p.reason ? `<div class="small faint" style="margin-top:4px"><i class="ti ti-bulb"></i> ${esc(p.reason)}</div>` : ''}</div>
      <div style="white-space:nowrap;display:flex;gap:6px">${done
        ? `<span class="pill ${s.status === 'accepted' ? 'in' : ''}">${s.status === 'accepted' ? 'Created' : 'Dismissed'}${s.acted_by_name ? ' · ' + esc(s.acted_by_name) : ''}</span>`
        : `<button class="btn sm primary" data-sg-ok><i class="ti ti-check"></i>Create</button><button class="btn sm ghost" data-sg-no aria-label="Dismiss"><i class="ti ti-x"></i></button>`}</div></li>`;
  }

  function runHtml(run) {
    const o = run.output || {};
    const findings = o.findings || [];
    return `
      <div class="card card-pad" style="margin-bottom:14px">
        <div style="display:flex;gap:8px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px">
          <h3 style="margin:0">${esc(o.headline || 'AI summary')}</h3>
          <span class="faint small">by ${byLine(run)} · ${esc(run.created_by_name)} · ${esc(fmtDateTime(run.created_at))}</span></div>
        ${prose(o.summary)}
        ${findings.length ? `<div class="sec-title">What stands out</div><ul class="list" style="margin:0 -16px">${findings.map((f) => {
          const [tone, icon, lbl] = SEV[f.severity] || SEV.info;
          const who = [f.staff, f.job_ref].filter(Boolean).join(' · ');
          return `<li class="li" style="cursor:default;align-items:flex-start"><div class="li-icon tone-${tone === 'bad' ? 'bad' : tone === 'out' ? 'out' : tone === 'in' ? 'in' : 'info'}"><i class="ti ti-${icon}"></i></div>
            <div class="main-col"><div class="t"><span class="pill ${tone}">${lbl}</span> ${esc(who)}</div><div class="small" style="margin-top:4px">${esc(f.text)}</div></div></li>`;
        }).join('')}</ul>` : ''}
        ${(o.recommendations || []).length ? `<div class="sec-title">Recommended actions</div><ol style="margin:0 0 0 18px;display:grid;gap:6px">${o.recommendations.map((r) => `<li>${esc(r)}</li>`).join('')}</ol>` : ''}
        <div class="actions" style="margin-top:14px"><button class="btn sm ghost" data-copy><i class="ti ti-copy"></i>Copy text</button></div>
      </div>
      ${run.suggestions.length ? `<div class="card" style="margin-bottom:14px"><div class="card-head"><h3>Suggested actions</h3><span class="faint small">Nothing is created until you click Create</span></div>
        <ul class="list">${run.suggestions.map(suggestionRow).join('')}</ul></div>` : ''}`;
  }

  function runText(run) {
    const o = run.output || {};
    return [o.headline, '', o.summary, '',
      ...(o.findings || []).map((f) => `- ${[f.staff, f.job_ref].filter(Boolean).join(' · ')}${f.staff || f.job_ref ? ': ' : ''}${f.text}`), '',
      ...(o.recommendations || []).map((r, i) => `${i + 1}. ${r}`)].join('\n').trim();
  }

  function bindRun(box, run, reload) {
    $('[data-copy]', box)?.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(runText(run)); toast('Copied'); } catch { toast('Could not copy on this device.', true); }
    });
    bindSuggestions(box, run.suggestions, reload);
  }

  function bindSuggestions(box, list, reload) {
    $$('[data-sg]', box).forEach((row) => {
      const s = list.find((x) => x.id === +row.dataset.sg);
      if (!s) return;
      $('[data-sg-ok]', row)?.addEventListener('click', () => acceptSuggestion(s, reload));
      $('[data-sg-no]', row)?.addEventListener('click', async (e) => {
        try { await App.busy(e.currentTarget, () => api('ai_suggestion_act', { data: { id: s.id, status: 'dismissed' } })); toast('Dismissed'); reload(); } catch (err) { App.fail(err); }
      });
    });
  }

  /** Let the manager check and edit the draft, then create it through the normal job actions. */
  async function acceptSuggestion(s, reload) {
    const p = s.payload || {};
    let staff = [];
    try { staff = await staffList(); } catch (e) { App.fail(e); return; }
    const picked = new Set((p.assignee_ids || []).map(Number));
    if (s.type === 'reminder' && s.user_id) picked.add(s.user_id);
    const people = (multi) => `<div class="field"><label>${multi ? 'Who' : 'Send to'}</label><div class="chips" style="max-height:180px;overflow:auto">
      ${staff.map((u) => `<label class="check-row" style="margin:0 10px 6px 0"><input type="${multi ? 'checkbox' : 'radio'}" name="who" value="${u.id}" ${picked.has(+u.id) ? 'checked' : ''}><span>${esc(u.name)}</span></label>`).join('')}</div></div>`;
    const due = `<div class="field"><label>Due date</label><input type="date" name="due_date" value="${esc(p.due_date || '')}" min="${iso(new Date())}"></div>`;
    const fields = {
      job_task: `<div class="field"><label>Task</label><input name="title" value="${esc(p.title)}" maxlength="190"></div>
        <div class="field"><label>Notes</label><textarea name="text" rows="3">${esc(p.text)}</textarea></div>${due}${people(true)}`,
      followup: `<div class="field"><label>Follow-up note to the people on ${esc(s.job_ref)}</label><textarea name="text" rows="4">${esc(p.text || p.title)}</textarea></div>`,
      reminder: `<div class="field"><label>Title</label><input name="title" value="${esc(p.title)}" maxlength="190"></div>
        <div class="field"><label>Message</label><textarea name="text" rows="3">${esc(p.text)}</textarea></div>${people(false)}`,
      job: `<div class="field"><label>Job title</label><input name="title" value="${esc(p.title)}" maxlength="190"></div>
        <div class="field"><label>School or company</label><input name="client_name" value="${esc(p.client_name || '')}" list="ai-clients"></div>
        <datalist id="ai-clients">${(App.cfg.locations || []).map((l) => `<option value="${esc(l)}">`).join('')}</datalist>
        <div class="field"><label>Description</label><textarea name="text" rows="3">${esc(p.text)}</textarea></div>
        <div class="form-row"><div class="field"><label>Priority</label><select name="priority">${App.opts(['low', 'normal', 'high', 'urgent'], p.priority || 'normal')}</select></div>${due}</div>${people(true)}`,
    }[s.type];
    const [, label] = TYPE[s.type] || ['', 'Suggestion'];
    const el = App.modal(esc(label), `<form id="ai-sg-form" novalidate>${fields}${p.reason ? `<p class="small faint"><i class="ti ti-bulb"></i> ${esc(p.reason)}</p>` : ''}</form>`,
      '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ai-sg-save"><i class="ti ti-check"></i>Create</button>', { wide: true });
    $('#ai-sg-save', el).addEventListener('click', async (e) => {
      const form = $('#ai-sg-form', el);
      const d = App.formData(form);
      const who = $$('[name="who"]:checked', form).map((x) => +x.value);
      try {
        await App.busy(e.currentTarget, async () => {
          if (s.type === 'job_task') {
            await api('job_task_save', { data: { id: s.job_id, title: d.title, notes: d.text, due_date: d.due_date, assignees: who } });
          } else if (s.type === 'followup') {
            if (!d.text.trim()) throw new Error('Write the follow-up note.');
            await api('job_update', { data: { id: s.job_id, body: d.text, links: [] } });
          } else if (s.type === 'job') {
            const r = await api('job_save', { data: { title: d.title, client_name: d.client_name, description: d.text, priority: d.priority, due_date: d.due_date, assignees: who } });
            s.created_job = r.id;
          }
          await api('ai_suggestion_act', { data: { id: s.id, status: 'accepted', title: d.title, text: d.text, user_id: who[0] || s.user_id } });
        });
        App.closeTop();
        toast(s.type === 'reminder' ? 'Reminder sent' : s.type === 'followup' ? 'Follow-up posted' : s.type === 'job' ? 'Job created' : 'Task added');
        reload();
      } catch (err) {
        if (err.fields) App.showErrors(form, err.fields);
        App.fail(err);
      }
    });
  }

  async function ask(btn, action, data, box, reload) {
    box.innerHTML = `<div class="card card-pad muted" style="display:flex;gap:10px;align-items:center"><span class="spinner" style="width:18px;height:18px;border-width:2px"></span>
      The AI is reading the portal data. This can take up to a minute…</div>`;
    try {
      const r = await App.busy(btn, () => api(action, { data: { ...data, provider: chosenProvider() } }));
      box.innerHTML = runHtml(r.run);
      bindRun(box, r.run, reload);
      App.refreshBadges?.();
    } catch (err) {
      box.innerHTML = '';
      App.fail(err);
    }
  }

  async function showRun(box, id, reload) {
    box.innerHTML = App.loading();
    try {
      const r = await api('ai_runs', { query: { id } });
      box.innerHTML = runHtml(r.run);
      bindRun(box, r.run, reload);
    } catch (err) { box.innerHTML = ''; App.fail(err); }
  }

  async function history(el, kind, box, reload) {
    try {
      const { runs } = await api('ai_runs', { query: { kind } });
      el.innerHTML = runs.length ? `<div class="card"><div class="card-head"><h3>Earlier</h3></div><ul class="list">${runs.slice(0, 10).map((r) => `
        <li class="li" data-run="${r.id}"><div class="main-col"><div class="t">${esc(r.headline || '—')}</div>
          <div class="s">${esc([r.scope.staff_name, r.scope.from && `${fmtDate(r.scope.from, false)} – ${fmtDate(r.scope.to, false)}`, r.provider_name, r.created_by_name, fmtDateTime(r.created_at)].filter(Boolean).join(' · '))}</div></div></li>`).join('')}</ul></div>` : '';
      $$('[data-run]', el).forEach((li) => li.addEventListener('click', () => { showRun(box, +li.dataset.run, reload); box.scrollIntoView({ behavior: 'smooth' }); }));
    } catch { el.innerHTML = ''; }
  }

  const rangeFields = (from, to) => `<div class="field" style="margin:0"><label>From</label><input type="date" name="from" value="${from}"></div>
    <div class="field" style="margin:0"><label>To</label><input type="date" name="to" value="${to}"></div>`;

  App.route('ai', async (ctx) => {
    let tab = App.store.get('ai-tab', 'brief');
    const st = await api('ai_status');
    const draw = async () => {
      App.store.set('ai-tab', tab);
      ctx.el.innerHTML = `
        <div class="page-head"><div><h1>AI assistant</h1><p class="muted">The AI reads jobs, staff reports and attendance, then recommends what to do. You approve every action.</p></div>
          <div class="actions">${st.providers.length > 1 ? `<div class="seg" id="ai-prov" title="Which AI answers">${st.providers.map((p) =>
            `<button data-p="${p.id}" class="${p.id === (st.providers.some((x) => x.id === chosenProvider()) ? chosenProvider() : st.default_provider) ? 'on' : ''}"><i class="ti ti-sparkles"></i>${esc(p.name)}</button>`).join('')}</div>` : ''}
          <div class="seg" id="ai-tabs">${TABS.map(([k, l]) => `<button data-t="${k}" class="${k === tab ? 'on' : ''}">${l}${k === 'suggestions' && st.pending ? ` (${st.pending})` : ''}</button>`).join('')}</div></div></div>
        ${st.problem ? `<div class="note-box warn" style="margin-bottom:14px"><i class="ti ti-plug-off"></i> ${esc(st.problem)}${App.isAdmin() ? ' <a href="#/settings">Open Settings</a>' : ' Ask an admin to set it up.'}</div>` : ''}
        <div id="ai-body"></div>`;
      $$('#ai-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => { tab = b.dataset.t; draw(); }));
      $$('#ai-prov button', ctx.el).forEach((b) => b.addEventListener('click', () => {
        App.store.set('ai-provider', b.dataset.p);
        $$('#ai-prov button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      }));
      const body = $('#ai-body', ctx.el);
      const reload = async () => { Object.assign(st, await api('ai_status')); if (tab === 'suggestions') draw(); };

      if (tab === 'brief') {
        body.innerHTML = `<div class="card card-pad" style="margin-bottom:14px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <div><b>Today's brief</b><div class="muted small">Overdue and stalled jobs, problems in the last two days of reports and attendance, and what to do today.${st.brief ? '' : ' Sent automatically each morning when turned on in Settings.'}</div></div>
            <button class="btn primary" id="ai-go" ${st.problem ? 'disabled' : ''}><i class="ti ti-sparkles"></i>${st.brief ? 'Make a new brief' : 'Make today\'s brief'}</button></div>
          <div id="ai-out"></div><div id="ai-hist"></div>`;
        const out = $('#ai-out', body);
        if (st.brief) { out.innerHTML = runHtml(st.brief); bindRun(out, st.brief, reload); }
        $('#ai-go', body).addEventListener('click', (e) => ask(e.currentTarget, 'ai_brief', {}, out, reload));
        history($('#ai-hist', body), 'brief', out, reload);
      } else if (tab === 'staff') {
        const staff = await staffList();
        body.innerHTML = `<form class="card card-pad" id="ai-f" style="margin-bottom:14px"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end">
            <div class="field" style="margin:0"><label>Staff member</label><select name="user_id">${App.opts(staff.map((u) => [u.id, u.name]), App.store.get('ai-staff', ''), { placeholder: 'Choose…' })}</select></div>
            ${rangeFields(daysAgo(13), iso(new Date()))}
            <button class="btn primary" type="submit" ${st.problem ? 'disabled' : ''}><i class="ti ti-user-search"></i>Review</button></div>
            <p class="muted small" style="margin:10px 0 0">Looks at their reports, jobs, tasks, attendance, refused clock-ins and ignored reminders, and looks for patterns.</p></form>
          <div id="ai-out"></div><div id="ai-hist"></div>`;
        const out = $('#ai-out', body);
        $('#ai-f', body).addEventListener('submit', (e) => {
          e.preventDefault();
          const d = App.formData(e.target);
          if (!d.user_id) { toast('Choose a staff member.', true); return; }
          App.store.set('ai-staff', d.user_id);
          ask(e.submitter, 'ai_staff_review', d, out, reload);
        });
        history($('#ai-hist', body), 'staff_review', out, reload);
      } else if (tab === 'reports') {
        body.innerHTML = `<form class="card card-pad" id="ai-f" style="margin-bottom:14px">
            <div class="seg" id="ai-quick" style="margin-bottom:12px"><button type="button" data-d="0">Today</button><button type="button" data-d="6" class="on">Last 7 days</button><button type="button" data-d="29">Last 30 days</button></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end">
            ${rangeFields(daysAgo(6), iso(new Date()))}
            <button class="btn primary" type="submit" ${st.problem ? 'disabled' : ''}><i class="ti ti-report-analytics"></i>Summarise reports</button></div></form>
          <div id="ai-out"></div><div id="ai-hist"></div>`;
        const out = $('#ai-out', body);
        const f = $('#ai-f', body);
        $$('#ai-quick button', body).forEach((b) => b.addEventListener('click', () => {
          $$('#ai-quick button', body).forEach((x) => x.classList.toggle('on', x === b));
          f.from.value = daysAgo(+b.dataset.d);
          f.to.value = iso(new Date());
        }));
        f.addEventListener('submit', (e) => { e.preventDefault(); ask(e.submitter, 'ai_report_summary', App.formData(f), out, reload); });
        history($('#ai-hist', body), 'report_summary', out, reload);
      } else {
        let status = 'pending';
        const list = async () => {
          body.innerHTML = `<div class="seg" id="ai-sst" style="margin-bottom:12px">${[['pending', 'To review'], ['accepted', 'Created'], ['dismissed', 'Dismissed']].map(([k, l]) =>
            `<button data-s="${k}" class="${k === status ? 'on' : ''}">${l}</button>`).join('')}</div><div id="ai-sl">${App.loading()}</div>`;
          $$('#ai-sst button', body).forEach((b) => b.addEventListener('click', () => { status = b.dataset.s; list(); }));
          const { suggestions } = await api('ai_suggestions', { query: { status } });
          const sl = $('#ai-sl', body);
          sl.innerHTML = suggestions.length ? `<div class="card"><ul class="list">${suggestions.map(suggestionRow).join('')}</ul></div>`
            : App.empty('sparkles', status === 'pending' ? 'Nothing to review' : 'None yet', status === 'pending' ? 'Suggestions from briefs and reviews show up here.' : '');
          bindSuggestions(sl, suggestions, async () => { Object.assign(st, await api('ai_status')); list(); $('#ai-tabs [data-t="suggestions"]', ctx.el).textContent = `Suggestions${st.pending ? ` (${st.pending})` : ''}`; });
        };
        list();
      }
    };
    draw();
  }, { title: 'AI assistant', manager: true });

  /** Dashboard card: the latest brief headline and the count of suggestions to review. */
  App.renderAiCard = async (el) => {
    if (!el || !isManagement()) return;
    try {
      const st = await api('ai_status');
      if (st.problem && !st.brief) return;
      const o = st.brief?.output || {};
      el.innerHTML = `<a class="card card-pad" href="#/ai" style="display:flex;gap:12px;align-items:center;color:inherit;text-decoration:none">
        <div class="li-icon tone-brand"><i class="ti ti-sparkles"></i></div>
        <div style="flex:1;min-width:0"><div style="font-weight:600">${esc(o.headline || 'AI assistant ready')}</div>
          <div class="muted small">${st.brief ? `AI brief by ${byLine(st.brief)} · ` + esc(fmtDateTime(st.brief.created_at)) : 'Make today\'s brief, review a staff member or summarise reports.'}</div></div>
        ${st.pending ? `<span class="pill out">${st.pending} to review</span>` : ''}<i class="ti ti-chevron-right faint"></i></a>`;
    } catch { el.innerHTML = ''; }
  };

  /** Job drawer button: next steps for one job in a modal. */
  App.aiJobNextSteps = async (btn, jobId, after) => {
    try {
      const r = await App.busy(btn, () => api('ai_job_next_steps', { data: { id: jobId, provider: chosenProvider() } }));
      const el = App.modal('<i class="ti ti-sparkles"></i> AI: next steps', `<div id="ai-job">${runHtml(r.run)}</div>`, '', { wide: true });
      const box = $('#ai-job', el);
      const reload = async () => {
        const fresh = await api('ai_runs', { query: { id: r.run.id } });
        box.innerHTML = runHtml(fresh.run);
        bindRun(box, fresh.run, reload);
        after?.();
      };
      bindRun(box, r.run, reload);
    } catch (err) { App.fail(err); }
  };
})();
