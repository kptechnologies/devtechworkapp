/* DevTech Staff Portal — sign and acknowledge a statement of account.
   When the office sends a statement with "Require signature" on, the staff member gets a pop-up they cannot close:
   they open the PDF, draw their signature, type their full name and tick to acknowledge. Until then the portal stays locked. */
(function () {
  'use strict';
  const { $, esc, api, toast } = App;

  let open = false;

  /** Signature pad on a canvas: mouse, pen and finger. Returns { empty(), data(), clear() }. */
  function pad(canvas) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    let ink = 0;
    let last = null;
    const size = () => {
      const r = canvas.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      canvas.width = Math.round(r.width * dpr);
      canvas.height = Math.round(r.height * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#13294b';
      ink = 0;
    };
    const pt = (e) => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
    canvas.addEventListener('pointerdown', (e) => { e.preventDefault(); canvas.setPointerCapture(e.pointerId); drawing = true; last = pt(e); });
    canvas.addEventListener('pointermove', (e) => {
      if (!drawing) return;
      const p = pt(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      ink += Math.hypot(p.x - last.x, p.y - last.y);
      last = p;
    });
    const stop = () => { drawing = false; };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    requestAnimationFrame(size);
    return {
      empty: () => ink < 25,
      data: () => canvas.toDataURL('image/png'),
      clear: () => ctx.clearRect(0, 0, canvas.width, canvas.height) || (ink = 0),
    };
  }

  /** Shows the sign-off pop-up if this user has a statement waiting. Safe to call repeatedly. */
  App.checkStatementAck = async () => {
    if (open || !App.user || App.isAdmin?.()) return;
    open = true;
    let p;
    try { p = (await api('statement_ack_pending')).pending; } catch (e) { open = false; return; }
    if (!p) { open = false; return; }
    App.closeAll();
    const el = App.modal('Sign your statement of account', `
      <p style="margin-bottom:10px">Your statement of account for <b>${esc(p.label)}</b> is ready. Please open it, check it, then sign and acknowledge it below.
        <b>You can't continue in the portal until you do.</b></p>
      ${p.file_id ? `<p style="margin-bottom:14px"><a class="btn" href="file.php?id=${p.file_id}" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf"></i>View / download statement</a></p>` : ''}
      <div class="field"><label for="sa-sig">Your signature <span class="req">*</span></label>
        <canvas id="sa-sig" style="width:100%;height:150px;border:1px dashed var(--line, #c8d6e4);border-radius:10px;background:#fff;touch-action:none;cursor:crosshair"></canvas>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px"><span class="faint small">Draw with your finger or mouse.</span>
        <button type="button" class="btn sm ghost" id="sa-clear"><i class="ti ti-eraser"></i>Clear</button></div></div>
      <div class="field"><label for="sa-name">Full name <span class="req">*</span></label><input id="sa-name" maxlength="120" autocomplete="name" value="${esc(App.user.name || '')}"></div>
      <label class="check-row"><input type="checkbox" id="sa-agree"><span>I have read and acknowledge my statement of account for ${esc(p.label)}.</span></label>`,
    `<button class="btn ghost" id="sa-out"><i class="ti ti-logout"></i>Sign out</button><button class="btn primary" id="sa-sign"><i class="ti ti-signature"></i>Sign &amp; continue</button>`,
    { locked: true, onClose: () => { open = false; } });
    const sig = pad($('#sa-sig', el));
    $('#sa-clear', el).addEventListener('click', sig.clear);
    $('#sa-out', el).addEventListener('click', () => { el.closeLayer(); App.logout(); });
    $('#sa-sign', el).addEventListener('click', async (e) => {
      const name = $('#sa-name', el).value.trim();
      if (sig.empty()) return toast('Draw your signature in the box.', true);
      if (name.length < 3) return toast('Type your full name.', true);
      if (!$('#sa-agree', el).checked) return toast('Tick the box to acknowledge the statement.', true);
      try {
        const r = await App.busy(e.currentTarget, () => api('statement_ack_sign', { data: { id: p.id, name, agree: 1, signature: sig.data() } }));
        el.closeLayer();
        toast('Thank you. Your statement is signed.');
        if (r.pending) App.checkStatementAck();
      } catch (err) { App.fail(err); }
    });
  };
})();
