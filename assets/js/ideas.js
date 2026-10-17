/* DevTech Staff Portal — ideas & plans board: staff post ideas, plans and recommendations; everyone votes and comments; management assigns an owner */
(() => {
  const { $, $$, esc, api, toast, fmtDate, fmtDateTime, ago, isAdmin, isManagement } = App;

  const KIND = { idea: ['Idea', 'bulb', 'brand'], plan: ['Plan / to-do', 'checklist', 'info'], recommendation: ['Recommendation', 'thumb-up', 'in'] };
  const STATUS = { new: ['New', 'brand'], review: ['Under review', 'info'], approved: ['Approved', 'in'], in_progress: ['In progress', 'out'], done: ['Done', 'in'], declined: ['Declined', 'bad'] };
  const TABS = [['open', 'Open', 'bulb'], ['progress', 'In progress', 'progress'], ['done', 'Done', 'circle-check'], ['declined', 'Declined', 'x'], ['mine', 'Mine', 'user']];
  const CATEGORIES = ['Operations', 'Training', 'Sales & clients', 'Tech & tools', 'Staff welfare', 'Finance', 'Marketing'];
  const kindPill = (k) => `<span class="pill ${KIND[k]?.[2] || ''}"><i class="ti ti-${KIND[k]?.[1] || 'bulb'}"></i>${esc(KIND[k]?.[0] || k)}</span>`;
  const statusPill = (s) => `<span class="pill ${STATUS[s]?.[1] || ''}">${esc(STATUS[s]?.[0] || s)}</span>`;
  const dueText = (i) => (i.due_date ? `<span style="${i.overdue ? 'color:var(--bad);font-weight:500' : ''}"><i class="ti ti-calendar"></i> ${i.overdue ? 'Overdue · ' : 'Due '}${esc(fmtDate(i.due_date, false))}</span>` : '');
  const voteBtn = (i) => `<button class="vote-btn ${i.voted ? 'on' : ''}" data-vote="${i.id}" ${i.user_id === +App.user.id ? 'disabled title="Your own post"' : `title="${i.voted ? 'Remove your vote' : 'Vote for this'}"`}>
    <i class="ti ti-arrow-big-up"></i><span>${i.votes}</span></button>`;

  const st = { tab: 'open', kind: '', sort: 'top', q: '' };

  async function ideasView(ctx) {
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>Ideas &amp; plans</h1><p class="muted">Share ideas, things the company should do, and recommendations. Vote for the ones you want to see happen.</p></div>
        <div class="actions"><button class="btn primary" id="id-new"><i class="ti ti-plus"></i>Post an idea</button></div></div>
      <div class="seg" id="id-tabs" style="margin-bottom:12px;max-width:100%;overflow-x:auto">${TABS.map(([k, l, i]) => `<button data-t="${k}" class="${k === st.tab ? 'on' : ''}"><i class="ti ti-${i}"></i> ${l}<span class="faint" data-count="${k}"></span></button>`).join('')}</div>
      <div class="filters" id="id-filters"><input type="search" name="q" placeholder="Search ideas…" value="${esc(st.q)}">
        <select name="kind">${App.opts(Object.entries(KIND).map(([k, [l]]) => [k, l]), st.kind, { placeholder: 'All types' })}</select>
        <select name="sort">${App.opts([['top', 'Most votes'], ['new', 'Newest'], ['due', 'Due soonest']], st.sort)}</select></div>
      <div id="id-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('ideas_list', { query: st });
      for (const [k] of TABS) {
        const c = $(`[data-count="${k}"]`, ctx.el);
        if (c) c.textContent = r.counts[k] ? ` ${r.counts[k]}` : '';
      }
      const box = $('#id-list', ctx.el);
      if (!r.items.length) {
        box.innerHTML = `<div class="card">${App.empty('bulb', st.tab === 'open' ? 'No open ideas yet' : 'Nothing here yet', st.tab === 'open' ? 'Got an idea, a plan or a recommendation? Post it so everyone can see and vote.' : '')}</div>`;
        return;
      }
      box.innerHTML = `<div class="idea-list">${r.items.map((i) => `<div class="card idea-card" data-idea="${i.id}">
        ${voteBtn(i)}
        <div class="main-col">
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px">${kindPill(i.kind)}${statusPill(i.status)}${i.category ? `<span class="pill">${esc(i.category)}</span>` : ''}</div>
          <div class="idea-title">${esc(i.title)}</div>
          <div class="idea-body">${esc(i.body || '')}</div>
          <div class="idea-meta faint small">
            <span>${esc(i.ref)} · ${esc(i.user_name)} · ${esc(ago(i.created_at))}</span>
            ${i.comments ? `<span><i class="ti ti-message"></i> ${i.comments}</span>` : ''}${i.files ? `<span><i class="ti ti-photo"></i> ${i.files}</span>` : ''}
            ${i.owner_name ? `<span><i class="ti ti-user-check"></i> ${esc(i.owner_name)}</span>` : ''}${dueText(i)}
          </div></div></div>`).join('')}</div>`;
      $$('[data-idea]', box).forEach((c) => c.addEventListener('click', (e) => { if (!e.target.closest('[data-vote]')) App.go(`ideas/${c.dataset.idea}`); }));
      $$('[data-vote]', box).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); vote(+b.dataset.vote, b); }));
    };
    $$('#id-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => {
      st.tab = b.dataset.t;
      $$('#id-tabs button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      load().catch(App.fail);
    }));
    $('#id-filters', ctx.el).addEventListener('input', App.debounce((e) => { if (e.target.name) { st[e.target.name] = e.target.value; load().catch(App.fail); } }, 300));
    $('#id-new', ctx.el).addEventListener('click', () => compose(null));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  async function vote(id, btn) {
    try {
      const r = await api('idea_vote', { data: { id } });
      btn.classList.toggle('on', r.voted);
      btn.innerHTML = `<i class="ti ti-arrow-big-up"></i><span>${r.votes}</span>`;
    } catch (e) { App.fail(e); }
  }

  /** Post a new idea, or edit one (i given). */
  function compose(i) {
    const isNew = !i;
    i = i || { kind: 'idea', title: '', body: '', category: '' };
    const el = App.modal(isNew ? 'Post an idea' : `Edit ${esc(i.ref)}`, `<form id="ic-form" novalidate>
      <div class="field"><div class="label">What is it?</div><div class="opts">${Object.entries(KIND).map(([k, [l, icon]]) =>
        `<label class="opt"><input type="radio" name="kind" value="${k}" ${k === i.kind ? 'checked' : ''}><span><i class="ti ti-${icon}"></i>${l}</span></label>`).join('')}</div>
        <div class="help">An idea to try, a plan or task the company should do, or a recommendation to improve how we work.</div></div>
      <div class="field"><label for="ic-title">Title <span class="req">*</span></label><input id="ic-title" name="title" value="${esc(i.title)}" placeholder="e.g. Weekly coding club for JSS students" autofocus></div>
      <div class="field"><label for="ic-body">Details <span class="req">*</span></label><textarea id="ic-body" name="body" rows="6" placeholder="What is it, why does it matter, and what would it take?">${esc(i.body || '')}</textarea></div>
      <div class="field"><label>Area</label><input name="category" list="ic-cats" value="${esc(i.category || '')}" placeholder="e.g. Training">
        <datalist id="ic-cats">${CATEGORIES.map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div>
      <div class="field"><div class="label">Photos or files (optional)</div><div id="ic-up"></div></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ic-ok"><i class="ti ti-send"></i>${isNew ? 'Post' : 'Save'}</button>`, { wide: true });
    const up = App.uploader($('#ic-up', el), { existing: i.files || 0, label: 'Add file' });
    $('#ic-ok', el).addEventListener('click', async (e) => {
      const form = $('#ic-form', el);
      const d = App.formData(form);
      if (!isNew) d.id = i.id;
      try {
        const r = await App.busy(e.currentTarget, () => api(isNew ? 'idea_post' : 'idea_edit', { data: d, files: up.files() }));
        App.closeTop();
        toast(isNew ? 'Posted. Management has been told.' : 'Saved');
        if (isNew) App.go(`ideas/${r.id}`); else App.current?.refresh?.();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  async function openIdea(id) {
    const el = App.drawer('Idea', App.loading());
    const render = async () => {
      let i;
      try { i = (await api('idea_get', { query: { id } })).idea; } catch (e) { App.closeTop(); App.fail(e); return; }
      const body = $('.drawer-body', el) || el;
      $('.drawer-head h2', el).textContent = i.ref;
      const owner = i.owner_id === +App.user.id;
      body.innerHTML = `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">${kindPill(i.kind)}${statusPill(i.status)}${i.category ? `<span class="pill">${esc(i.category)}</span>` : ''}</div>
        <div style="display:flex;gap:12px;align-items:flex-start">${voteBtn(i)}<div style="min-width:0"><h2 style="margin-bottom:4px">${esc(i.title)}</h2>
          <div class="muted small">By <b>${esc(i.user_name)}</b> · ${esc(fmtDateTime(i.created_at))}</div></div></div>
        <div class="note-box" style="white-space:pre-wrap;margin:12px 0">${esc(i.body || '')}</div>
        ${i.owner_name || i.due_date ? `<dl class="kv">${i.owner_name ? `<dt>Owner</dt><dd>${esc(i.owner_name)}</dd>` : ''}${i.due_date ? `<dt>Target date</dt><dd>${dueText(i)}</dd>` : ''}
          ${i.done_at ? `<dt>Done</dt><dd>${esc(fmtDateTime(i.done_at))}</dd>` : ''}</dl>` : ''}
        ${i.status === 'declined' && i.decision_note ? `<div class="note-box bad" style="margin-bottom:12px"><b>Declined</b>${i.decided_by_name ? ' by ' + esc(i.decided_by_name) : ''}\n${esc(i.decision_note)}</div>` : ''}
        ${i.files_list.length ? `<div class="sec-title">Photos &amp; files</div><div id="ii-files">${App.gallery(i.files_list, { removable: i.can_edit })}</div>` : ''}
        ${i.voters.length ? `<details style="margin:12px 0"><summary class="small muted">${i.voters.length} vote${i.voters.length > 1 ? 's' : ''}</summary>
          <div class="chips" style="margin-top:6px">${i.voters.map((n) => `<span class="pill">${esc(n)}</span>`).join('')}</div></details>` : ''}
        <div class="sec-title">Discussion &amp; progress</div>
        ${i.thread.length ? i.thread.map((c) => c.kind === 'status'
          ? `<div class="idea-status-line"><i class="ti ti-flag"></i><div><b>${esc(c.body.split('\n')[0])}</b> · ${esc(c.user_name)} · ${esc(ago(c.created_at))}${c.body.includes('\n') ? `<div style="white-space:pre-wrap">${esc(c.body.slice(c.body.indexOf('\n') + 1))}</div>` : ''}</div></div>`
          : `<div class="qa"><div class="q">${esc(c.user_name)} · ${esc(ago(c.created_at))}</div><div class="a" style="white-space:pre-wrap">${esc(c.body)}</div></div>`).join('')
          : '<p class="faint small">No comments yet. Add your thoughts.</p>'}
        <form id="ir-form" style="margin-top:10px"><div class="field"><label for="ir-body">Comment</label><textarea id="ir-body" name="body" rows="3" placeholder="Add a thought, a question or a way to help"></textarea></div>
          <button class="btn" type="submit"><i class="ti ti-send"></i>Comment</button></form>`;
      if ($('#ii-files', body)) App.bindGallery($('#ii-files', body));
      $('[data-vote]', body)?.addEventListener('click', async (e) => { await vote(i.id, e.currentTarget); App.current?.refresh?.(); });
      $('#ir-form', body).addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = App.formData(e.target);
        if (!d.body) { toast('Write a comment.', true); return; }
        try { await App.busy(e.submitter, () => api('idea_comment', { data: { id: i.id, body: d.body } })); render(); App.current?.refresh?.(); } catch (err) { App.fail(err); }
      });

      $('.drawer-foot', el)?.remove();
      const btns = [];
      if (i.can_delete) btns.push('<button class="btn danger" data-a="delete"><i class="ti ti-trash"></i></button>');
      if (i.can_edit) btns.push('<button class="btn ghost" data-a="edit"><i class="ti ti-edit"></i>Edit</button>');
      if (isManagement()) {
        if (['new'].includes(i.status)) btns.push('<button class="btn" data-a="review"><i class="ti ti-eye"></i>Under review</button>');
        if (!['declined', 'done'].includes(i.status)) btns.push('<button class="btn" data-a="decline"><i class="ti ti-x"></i>Decline</button>');
        if (['new', 'review', 'declined'].includes(i.status)) btns.push('<button class="btn success" data-a="approve"><i class="ti ti-check"></i>Approve</button>');
        else if (['approved', 'in_progress'].includes(i.status)) btns.push('<button class="btn ghost" data-a="approve"><i class="ti ti-user-cog"></i>Change owner</button>');
        if (i.status === 'done') btns.push('<button class="btn" data-a="reopen"><i class="ti ti-rotate"></i>Reopen</button>');
      }
      if (owner || isManagement()) {
        if (i.status === 'approved') btns.push('<button class="btn primary" data-a="start"><i class="ti ti-player-play"></i>Start</button>');
        if (i.status === 'in_progress') btns.push('<button class="btn ghost" data-a="update"><i class="ti ti-message-plus"></i>Progress note</button>');
        if (['approved', 'in_progress'].includes(i.status)) btns.push('<button class="btn primary" data-a="done"><i class="ti ti-circle-check"></i>Mark done</button>');
      }
      if (!btns.length) return;
      const foot = document.createElement('div');
      foot.className = 'drawer-foot';
      foot.innerHTML = btns.join('');
      el.appendChild(foot);
      const after = () => { render(); App.current?.refresh?.(); };
      $$('[data-a]', foot).forEach((b) => b.addEventListener('click', async () => {
        const a = b.dataset.a;
        try {
          if (a === 'edit') compose(i);
          else if (a === 'delete') {
            if (!(await App.confirm('Delete this post?', 'Its votes, comments and files are removed too.', { ok: 'Delete', danger: true }))) return;
            await api('idea_delete', { data: { id: i.id } });
            App.closeTop(); toast('Deleted'); App.go('ideas');
          } else if (a === 'review') {
            await App.busy(b, () => api('idea_status', { data: { id: i.id, status: 'review' } })); toast('Marked under review'); after();
          } else if (a === 'decline') {
            const note = await App.confirm('Decline this?', `${esc(i.user_name)} is told why.`, { ok: 'Decline', danger: true, input: { label: 'Reason', required: true } });
            if (note === null) return;
            await App.busy(b, () => api('idea_status', { data: { id: i.id, status: 'declined', note } })); toast('Declined'); after();
          } else if (a === 'reopen') {
            await App.busy(b, () => api('idea_status', { data: { id: i.id, status: 'in_progress', owner_id: i.owner_id, due_date: i.due_date } })); toast('Reopened'); after();
          } else if (a === 'approve') approve(i, after);
          else if (['start', 'done', 'update'].includes(a)) {
            const status = a === 'start' ? 'in_progress' : a === 'done' ? 'done' : 'in_progress';
            const note = await App.confirm(a === 'start' ? 'Start work on this?' : a === 'done' ? 'Mark this as done?' : 'Add a progress note',
              a === 'done' ? 'Everyone following it is told.' : '', { ok: a === 'start' ? 'Start' : a === 'done' ? 'Mark done' : 'Save', input: { label: a === 'done' ? 'What was done' : 'Note', required: a === 'update' } });
            if (note === null) return;
            await App.busy(b, () => api('idea_progress', { data: { id: i.id, status, note } })); toast(a === 'done' ? 'Marked done' : 'Saved'); after();
          }
        } catch (err) { App.fail(err); }
      }));
    };
    await render();
  }

  /** Approve with an owner and target date, or change them later. */
  async function approve(i, done) {
    let staff;
    try { staff = (await api('users_list')).items.filter((u) => u.active); } catch (e) { App.fail(e); return; }
    const keep = ['approved', 'in_progress'].includes(i.status);
    const el = App.modal(keep ? `Owner for ${esc(i.ref)}` : `Approve ${esc(i.ref)}`, `<form id="ia-form" novalidate>
      <p class="muted" style="margin-bottom:12px">${esc(i.title)}</p>
      <div class="field"><label>Owner <span class="req">*</span></label><select name="owner_id">${App.opts(staff.map((s) => [s.id, s.name]), i.owner_id || '', { placeholder: 'Who will carry it out?' })}</select></div>
      <div class="field"><label>Target date <span class="req">*</span></label><input type="date" name="due_date" min="${esc(App.cfg.today)}" value="${esc(i.due_date || '')}"></div>
      <div class="field"><label>Note</label><textarea name="note" rows="2" placeholder="e.g. Start with two schools as a pilot"></textarea></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ia-ok"><i class="ti ti-check"></i>${keep ? 'Save' : 'Approve'}</button>`);
    $('#ia-ok', el).addEventListener('click', async (e) => {
      const form = $('#ia-form', el);
      const d = App.formData(form);
      try {
        await App.busy(e.currentTarget, () => api('idea_status', { data: { id: i.id, status: keep ? i.status : 'approved', ...d } }));
        App.closeTop(); toast(keep ? 'Saved' : 'Approved. The owner has been told.'); done();
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  App.route('ideas', ideasView, { title: 'Ideas & plans', live: true });
  App.route('ideas/:id', async (ctx) => { await ideasView(ctx); openIdea(+ctx.params.id); }, { title: 'Ideas & plans', nav: 'ideas', live: true });
})();
