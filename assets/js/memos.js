/* DevTech Staff Portal — memos to staff, notes to management, and management requests to the admin */
(() => {
  const { $, $$, esc, api, toast, fmtDateTime, ago, isAdmin, isManagement, roleLabel } = App;

  const KIND = { memo: ['Memo', 'mail', 'brand'], note: ['Note', 'note', 'info'], request: ['Request', 'hand-stop', 'out'] };
  const STATUS = { approved: ['Approved', 'in'], declined: ['Declined', 'bad'], open: ['Waiting for admin', 'out'] };
  const AUDIENCE = { all_staff: 'All staff', selected: 'Selected people', management: 'Management', admin: 'Admin' };
  const kindPill = (k) => `<span class="pill ${KIND[k]?.[2] || ''}"><i class="ti ti-${KIND[k]?.[1] || 'mail'}"></i>${esc(KIND[k]?.[0] || k)}</span>`;
  const statusPill = (m) => (m.kind === 'request' ? `<span class="pill ${STATUS[m.status]?.[1] || ''}">${esc(STATUS[m.status]?.[0] || m.status)}</span>` : '');

  let box = 'inbox';

  async function memosView(ctx) {
    const boxes = [['inbox', 'Inbox', 'inbox'], ['sent', 'Sent', 'send']].concat(isAdmin() ? [['requests', 'Requests', 'hand-stop']] : []);
    if (!boxes.some(([k]) => k === box)) box = 'inbox';
    const intro = isManagement() ? 'Send memos to staff, read notes from staff' + (isAdmin() ? ', and answer requests from management.' : ', and make requests to the admin.')
      : 'Send a note or memo to management, and read memos sent to you.';
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${isManagement() ? 'Memos & requests' : 'Notes & memos'}</h1><p class="muted">${esc(intro)}</p></div>
        <div class="actions">${isManagement() && !isAdmin() ? '<button class="btn" id="mm-req"><i class="ti ti-hand-stop"></i>Request to admin</button>' : ''}
          <button class="btn primary" id="mm-new"><i class="ti ti-pencil"></i>${isManagement() ? 'New memo' : 'Write to management'}</button></div></div>
      <div class="seg" id="mm-tabs" style="margin-bottom:12px">${boxes.map(([k, l, i]) => `<button data-b="${k}" class="${k === box ? 'on' : ''}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
      <div class="card" id="mm-list">${App.loading()}</div>`;
    const load = async () => {
      const r = await api('memos_list', { query: { box } });
      const el = $('#mm-list', ctx.el);
      if (!r.items.length) {
        el.innerHTML = App.empty('mail-off', box === 'sent' ? 'Nothing sent yet' : box === 'requests' ? 'No requests yet' : 'No memos yet');
        return;
      }
      el.innerHTML = `<ul class="list">${r.items.map((m) => `<li class="li" data-memo="${m.id}" style="${m.unread ? 'font-weight:600' : ''}">
        <div class="li-icon tone-${KIND[m.kind]?.[2] || 'brand'}"><i class="ti ti-${KIND[m.kind]?.[1] || 'mail'}"></i></div>
        <div class="main-col"><div class="t">${m.unread ? '<span class="pill brand" style="padding:0 6px">New</span> ' : ''}${esc(m.subject)}</div>
          <div class="s">${esc(m.ref)} · ${box === 'sent' ? 'To ' + esc(AUDIENCE[m.audience] || m.audience) + ` (${+m.recipients})` : 'From ' + esc(m.sender_name)} · ${esc(ago(m.created_at))}${+m.replies ? ` · ${+m.replies} repl${+m.replies === 1 ? 'y' : 'ies'}` : ''}</div></div>
        ${statusPill(m)}</li>`).join('')}</ul>`;
      $$('[data-memo]', el).forEach((li) => li.addEventListener('click', () => App.go(`memos/${li.dataset.memo}`)));
    };
    $$('#mm-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => {
      box = b.dataset.b;
      $$('#mm-tabs button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      load().catch(App.fail);
    }));
    $('#mm-new', ctx.el).addEventListener('click', () => compose(isManagement() ? 'memo' : 'note'));
    $('#mm-req', ctx.el)?.addEventListener('click', () => compose('request'));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  /** Write a memo, a note to management, or a request to the admin. */
  async function compose(kind) {
    let people = [];
    if (kind === 'memo' && isManagement()) {
      try { people = (await api('memo_people')).items; } catch (e) { App.fail(e); return; }
    }
    const title = kind === 'request' ? 'Request to the admin' : isManagement() ? 'New memo' : 'Write to management';
    const toField = kind === 'request' ? '<p class="muted" style="margin-bottom:12px">The admin approves or declines it, and you are told the answer.</p>'
      : !isManagement() ? `<div class="field"><div class="label">Type</div><div class="opts">
          <label class="opt"><input type="radio" name="kind" value="note" checked><span><i class="ti ti-note"></i>Note</span></label>
          <label class="opt"><input type="radio" name="kind" value="memo"><span><i class="ti ti-mail"></i>Memo</span></label></div>
          <div class="help">Goes to the admin, managers, sub admins and supervisors.</div></div>`
      : `<div class="field" data-field="audience"><div class="label">To</div><div class="opts">
          <label class="opt"><input type="radio" name="audience" value="all_staff" checked><span><i class="ti ti-users"></i>All staff</span></label>
          <label class="opt"><input type="radio" name="audience" value="selected"><span><i class="ti ti-user-check"></i>Choose people</span></label>
          <label class="opt"><input type="radio" name="audience" value="management"><span><i class="ti ti-shield"></i>Management</span></label></div></div>
        <div class="field hide" id="mc-people"><div class="label">People</div><div class="opts" style="max-height:220px;overflow:auto">${people.map((u) => `<label class="opt"><input type="checkbox" data-multi="1" name="user_ids" value="${u.id}">
          <span><i class="ti ti-check" style="font-size:14px"></i>${esc(u.name)}${u.role !== 'staff' ? ` <span class="faint small">(${esc(roleLabel(u.role).toLowerCase())})</span>` : ''}</span></label>`).join('')}</div></div>`;
    const el = App.modal(title, `<form id="mc-form" novalidate>${toField}
      <div class="field"><label for="mc-sub">Subject <span class="req">*</span></label><input id="mc-sub" name="subject" autofocus></div>
      <div class="field"><label for="mc-body">Message <span class="req">*</span></label><textarea id="mc-body" name="body" rows="7"></textarea></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="mc-ok"><i class="ti ti-send"></i>Send</button>`);
    const form = $('#mc-form', el);
    form.addEventListener('change', () => {
      const d = App.formData(form);
      $('#mc-people', el)?.classList.toggle('hide', d.audience !== 'selected');
    });
    $('#mc-ok', el).addEventListener('click', async (e) => {
      const d = App.formData(form);
      d.kind = kind === 'request' ? 'request' : d.kind || kind;
      d.user_ids = [].concat(d.user_ids || []).map(Number);
      try {
        const r = await App.busy(e.currentTarget, () => api('memo_send', { data: d }));
        App.closeTop();
        toast(kind === 'request' ? 'Request sent to the admin' : 'Sent');
        App.go(`memos/${r.id}`);
      } catch (err) { App.showErrors(form, err.fields); App.fail(err); }
    });
  }

  async function openMemo(id) {
    const el = App.drawer('Memo', App.loading());
    const render = async () => {
      let m;
      try { m = (await api('memo_get', { query: { id } })).memo; } catch (e) { App.closeTop(); App.fail(e); return; }
      App.poll();
      const body = $('.drawer-body', el) || el;
      const readBy = m.recipient_list.filter((r) => r.read_at).length;
      body.innerHTML = `
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">${kindPill(m.kind)}${statusPill(m)}</div>
        <h2 style="margin-bottom:4px">${esc(m.subject)}</h2>
        <div class="muted small" style="margin-bottom:12px">${esc(m.ref)} · from <b>${esc(m.sender_name)}</b> to ${esc(AUDIENCE[m.audience] || m.audience)} · ${esc(fmtDateTime(m.created_at))}</div>
        <div class="note-box" style="white-space:pre-wrap;margin-bottom:12px">${esc(m.body)}</div>
        ${m.kind === 'request' && m.status !== 'open' ? `<div class="note-box ${m.status === 'approved' ? '' : 'bad'}" style="margin-bottom:12px"><b>${esc(STATUS[m.status][0])}</b> by ${esc(m.decided_by_name || 'admin')}, ${esc(fmtDateTime(m.decided_at))}${m.decision_note ? '\n' + esc(m.decision_note) : ''}</div>` : ''}
        ${m.recipient_list.length ? `<details style="margin-bottom:12px"><summary class="small muted">Read by ${readBy} of ${m.recipient_list.length}</summary>
          <div class="chips" style="margin-top:6px">${m.recipient_list.map((r) => `<span class="pill ${r.read_at ? 'in' : ''}" title="${r.read_at ? 'Read ' + esc(fmtDateTime(r.read_at)) : 'Not read yet'}">${esc(r.name)}</span>`).join('')}</div></details>` : ''}
        <div class="sec-title">Replies</div>
        ${m.replies.length ? m.replies.map((x) => `<div class="qa"><div class="q">${esc(x.user_name)} · ${esc(ago(x.created_at))}</div><div class="a" style="white-space:pre-wrap">${esc(x.body)}</div></div>`).join('')
          : '<p class="faint small">No replies yet.</p>'}
        <form id="mr-form" style="margin-top:10px"><div class="field"><label for="mr-body">Reply</label><textarea id="mr-body" name="body" rows="3"></textarea></div>
          <button class="btn" type="submit"><i class="ti ti-send"></i>Send reply</button></form>`;
      $('#mr-form', body).addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = App.formData(e.target);
        if (!d.body) { toast('Write a reply.', true); return; }
        try { await App.busy(e.submitter, () => api('memo_reply', { data: { id: m.id, body: d.body } })); toast('Reply sent'); render(); } catch (err) { App.fail(err); }
      });
      $('.drawer-foot', el)?.remove();
      if (isAdmin() && m.kind === 'request' && m.status === 'open') {
        const foot = document.createElement('div');
        foot.className = 'drawer-foot';
        foot.innerHTML = '<button class="btn danger" data-d="decline"><i class="ti ti-x"></i>Decline</button><button class="btn success" data-d="approve"><i class="ti ti-check"></i>Approve</button>';
        el.appendChild(foot);
        $$('[data-d]', foot).forEach((b) => b.addEventListener('click', async () => {
          const approve = b.dataset.d === 'approve';
          const note = await App.confirm(approve ? 'Approve this request?' : 'Decline this request?', `${esc(m.sender_name)} is told your answer.`,
            { ok: approve ? 'Approve' : 'Decline', danger: !approve, input: { label: approve ? 'Note (optional)' : 'Why?', required: !approve } });
          if (note === null) return;
          try {
            await App.busy(b, () => api('memo_decide', { data: { id: m.id, decision: b.dataset.d, note } }));
            toast(approve ? 'Request approved' : 'Request declined');
            App.current?.refresh?.();
            render();
          } catch (err) { App.fail(err); }
        }));
      }
    };
    await render();
  }

  App.route('memos', memosView, { title: 'Memos', live: true });
  App.route('memos/:id', async (ctx) => { await memosView(ctx); openMemo(+ctx.params.id); }, { title: 'Memos', nav: 'memos', live: true });
})();
