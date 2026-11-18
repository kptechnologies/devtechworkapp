/* DevTech Staff Portal — staff statement of account as a branded PDF (letterhead, logo, watermark).
   Built in the browser with jsPDF, loaded only when a statement is made. Admins download it or send it to the staff
   member (saved to their "My deductions" page with a phone alert); staff can download their own published months. */
(function () {
  'use strict';
  const { $, esc, api, toast, fmtDate } = App;

  const LIBS = [
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js',
  ];
  const NAVY = [31, 78, 121];
  const BRAND = [59, 94, 168];
  const INK = [33, 37, 41];
  const GREY = [110, 117, 125];
  const LINE = [200, 214, 228];
  const SOFT = [234, 241, 247];
  const GREEN_SOFT = [231, 243, 233];
  const GREEN = [46, 125, 50];
  const RED = [176, 42, 42];

  let ready = null;
  function loadScript(src) {
    return new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = src;
      s.onload = res;
      s.onerror = () => rej(new Error('Could not load the PDF tools. Check your connection and try again.'));
      document.head.appendChild(s);
    });
  }
  function loadLibs() {
    if (!ready) {
      ready = (async () => {
        if (!window.jspdf) await loadScript(LIBS[0]);
        if (!window.jspdf.jsPDF.API.autoTable) await loadScript(LIBS[1]);
      })().catch((e) => { ready = null; throw e; });
    }
    return ready;
  }

  let logo = null;
  async function loadLogo() {
    if (logo) return logo;
    const blob = await (await fetch('assets/logo.png', { credentials: 'same-origin' })).blob();
    const data = await new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(blob); });
    const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = data; });
    logo = { data, ratio: img.naturalWidth / img.naturalHeight };
    return logo;
  }

  /* ------------------------------------------------------------ helpers */

  const naira = (n) => {
    const v = Math.abs(Number(n) || 0);
    const s = 'N' + v.toLocaleString('en-NG', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
    return n < 0 ? `(${s})` : s;
  };
  const monthName = (m) => new Date(m + '-01T00:00:00').toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  const longDate = (d) => new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
  const shortDate = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  const periodLabel = (from, to) => (from === to ? monthName(from) : `${monthName(from)} – ${monthName(to)}`);

  const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen',
    'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
  const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
  function under1000(n) {
    const h = Math.floor(n / 100);
    const r = n % 100;
    const rest = r < 20 ? ONES[r] : TENS[Math.floor(r / 10)] + (r % 10 ? '-' + ONES[r % 10] : '');
    return [h ? ONES[h] + ' Hundred' : '', rest].filter(Boolean).join(' and ');
  }
  function words(n) {
    if (n === 0) return 'Zero';
    const scales = ['', ' Thousand', ' Million', ' Billion', ' Trillion'];
    const parts = [];
    let units = 0;
    for (let i = 0; n > 0 && i < scales.length; i++) {
      const chunk = n % 1000;
      if (chunk) parts.unshift(under1000(chunk) + scales[i]);
      if (i === 0) units = chunk;
      n = Math.floor(n / 1000);
    }
    // British style: "One Thousand and Fifty", but "Two Million, Three Hundred Thousand".
    if (parts.length > 1 && units > 0 && units < 100) {
      const last = parts.pop();
      return parts.join(', ') + ' and ' + last;
    }
    return parts.join(', ');
  }
  function amountInWords(v) {
    const abs = Math.abs(Math.round((Number(v) || 0) * 100));
    const n = Math.floor(abs / 100);
    const k = abs % 100;
    return `${words(n)} Naira${k ? ' and ' + words(k) + ' Kobo' : ''} Only.`;
  }

  /* ------------------------------------------------------------- the PDF */

  /** Builds the statement PDF from staff_statement data. Returns { doc, blob, name, period }. */
  async function buildPdf(s) {
    await loadLibs();
    const lg = await loadLogo().catch(() => null);
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    const H = doc.internal.pageSize.getHeight();
    const M = 18;
    const lh = s.letterhead;
    const u = s.user;
    const period = periodLabel(s.from, s.to);
    const months = s.months;
    const multi = months.length > 1;
    const sum = (f) => months.reduce((t, m) => t + f(m), 0);
    const totalNet = sum((m) => m.net);
    const final = months.length && months.every((m) => m.status === 'final');

    doc.setProperties({ title: `Statement of Account – ${u.name} – ${period}`, author: lh.company, creator: App.cfg.app_name || 'Staff portal' });

    // Letterhead: logo left, company details right, rule underneath.
    let y = 14;
    if (lg) doc.addImage(lg.data, 'PNG', M, y - 2, 15 * lg.ratio, 15);
    doc.setFont('helvetica', 'bold').setFontSize(11).setTextColor(...INK);
    doc.text(lh.company.toUpperCase(), W - M, y + 1, { align: 'right' });
    doc.setFont('helvetica', 'normal').setFontSize(8.5).setTextColor(...GREY);
    const addr = doc.splitTextToSize(lh.address, 82);
    doc.text(addr, W - M, y + 5.5, { align: 'right', lineHeightFactor: 1.35 });
    const contact = [lh.phone, lh.email].filter(Boolean).join('  |  ');
    if (contact) doc.text(contact, W - M, y + 5.5 + addr.length * 4.1, { align: 'right' });
    y = Math.max(y + 16, y + 7 + addr.length * 4.1 + (contact ? 3 : 0));
    doc.setDrawColor(...NAVY).setLineWidth(0.8).line(M, y, W - M, y);
    doc.setDrawColor(...BRAND).setLineWidth(0.3).line(M, y + 1.3, W - M, y + 1.3);

    // Title
    y += 11;
    doc.setFont('helvetica', 'bold').setFontSize(19).setTextColor(...NAVY).text('Staff Statement of Account', M, y);
    y += 6.5;
    doc.setFont('helvetica', 'normal').setFontSize(10).setTextColor(...GREY).text(`Salary, deductions and net pay · ${period}`, M, y);
    y += 5;

    const base = { theme: 'grid', margin: { left: M, right: M, top: 20, bottom: 22 }, styles: { font: 'helvetica', fontSize: 9, textColor: INK, lineColor: LINE, lineWidth: 0.2, cellPadding: { top: 3.2, bottom: 3.2, left: 4, right: 4 } } };

    doc.autoTable({
      ...base, startY: y,
      body: [
        ['Prepared for', u.name.toUpperCase()],
        ...(u.job_title ? [['Job title', u.job_title]] : []),
        ['Statement period', period],
        ['Statement date', longDate(App.cfg.today)],
        ['Status', final ? 'Final – figures confirmed after month-end review' : 'Provisional – figures may change until the month is finalised'],
      ],
      columnStyles: { 0: { fontStyle: 'bold', fillColor: SOFT, cellWidth: 48 } },
    });
    y = doc.lastAutoTable.finalY + 9;

    const heading = (text) => {
      if (y > H - 50) { doc.addPage(); y = 22; }
      doc.setFont('helvetica', 'bold').setFontSize(12.5).setTextColor(...NAVY).text(text, M, y);
      y += 3;
    };

    // Summary across months
    if (multi) {
      heading('Summary');
      doc.autoTable({
        ...base, startY: y,
        head: [['Month', 'Earnings', 'Deductions', 'Advances', 'Net pay']],
        body: [
          ...months.map((m) => [monthName(m.month) + (m.status === 'final' ? '' : ' *'), naira(m.earnings), naira(-m.deductions), naira(-m.advances_total), naira(m.net)]),
        ],
        foot: [['TOTAL', naira(sum((m) => m.earnings)), naira(-sum((m) => m.deductions)), naira(-sum((m) => m.advances_total)), naira(totalNet)]],
        headStyles: { fillColor: NAVY, textColor: 255, fontStyle: 'bold' },
        footStyles: { fillColor: NAVY, textColor: 255, fontStyle: 'bold' },
        columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' }, 4: { halign: 'right' } },
        didParseCell: (d) => { if (d.section !== 'body' && d.column.index > 0) d.cell.styles.halign = 'right'; },
      });
      y = doc.lastAutoTable.finalY + 3;
      if (months.some((m) => m.status !== 'final')) {
        doc.setFont('helvetica', 'italic').setFontSize(8).setTextColor(...GREY).text('* Provisional: this month has not been finalised yet.', M, y + 2);
        y += 4;
      }
      y += 6;
    }

    // Statement of account, one table per month
    months.forEach((m) => {
      heading(multi ? `Statement of Account – ${monthName(m.month)}` : 'Statement of Account');
      const body = [];
      const styleRows = {};
      const row = (cells, kind) => { styleRows[body.length] = kind; body.push(cells); };
      row(['Basic salary', '', naira(m.salary)]);
      if (m.overtime || m.overtime_days) row(['Overtime (approved)', String(m.overtime_days), naira(m.overtime)]);
      m.bonuses.forEach((b) => row([`Bonus – ${b.label}${b.reason ? ` (${b.reason})` : ''}`, '', naira(b.amount)]));
      row(['TOTAL EARNINGS', '', naira(m.earnings)], 'total');
      const groups = m.groups.filter((g) => g.count || g.amount);
      groups.forEach((g) => row([`Less: ${g.label}`, String(g.count), naira(-g.amount)]));
      m.advances.forEach((a) => row([`Less: Salary advance – ${shortDate(a.date)}${a.note ? ` (${a.note})` : ''}`, '', naira(-a.amount)]));
      if (!groups.length && !m.advances.length) row(['No deductions this month', '', naira(0)]);
      row(['TOTAL DEDUCTIONS & ADVANCES', '', naira(-(m.deductions + m.advances_total))], 'paid');
      row([m.net < 0 ? 'BALANCE OWED BY STAFF' : 'NET PAY', '', naira(m.net)], 'net');
      doc.autoTable({
        ...base, startY: y,
        head: [['Item / Description', 'Count', 'Amount']],
        body,
        headStyles: { fillColor: NAVY, textColor: 255, fontStyle: 'bold' },
        columnStyles: { 1: { halign: 'center', cellWidth: 20 }, 2: { halign: 'right', cellWidth: 40 } },
        didParseCell: (d) => {
          if (d.section === 'head' && d.column.index === 2) d.cell.styles.halign = 'right';
          if (d.section === 'head' && d.column.index === 1) d.cell.styles.halign = 'center';
          if (d.section !== 'body') return;
          const k = styleRows[d.row.index];
          if (d.row.index % 2 === 1 && !k) d.cell.styles.fillColor = [247, 250, 252];
          if (k) d.cell.styles.fontStyle = 'bold';
          if (k === 'total') d.cell.styles.fillColor = SOFT;
          if (k === 'paid') d.cell.styles.fillColor = GREEN_SOFT;
          if (k === 'net') { d.cell.styles.fillColor = NAVY; d.cell.styles.textColor = 255; d.cell.styles.cellPadding = { top: 4.5, bottom: 4.5, left: 4, right: 4 }; }
        },
      });
      y = doc.lastAutoTable.finalY + 8;
    });

    // Amount in words
    if (y > H - 40) { doc.addPage(); y = 22; }
    y -= 2;
    doc.setFont('helvetica', 'bold').setFontSize(9.5).setTextColor(...INK);
    const lbl = `${multi ? 'Total net pay' : 'Net pay'} in words: `;
    doc.text(lbl, M, y);
    doc.setFont('helvetica', 'normal');
    const w = doc.splitTextToSize((totalNet < 0 ? 'Minus ' : '') + amountInWords(totalNet), W - 2 * M - doc.getTextWidth(lbl));
    doc.text(w, M + doc.getTextWidth(lbl), y);
    y += w.length * 4.5 + 8;

    // Deductions breakdown
    const lines = months.flatMap((m) => m.lines);
    heading('Deductions Breakdown');
    if (lines.length) {
      doc.autoTable({
        ...base, startY: y,
        head: [['Date', 'Deduction', 'Reason', 'Amount']],
        body: lines.map((l) => [shortDate(l.date), l.label, [l.reason, l.waived].filter(Boolean).join('\n'), l.waived ? 'Waived' : naira(-l.amount)]),
        headStyles: { fillColor: NAVY, textColor: 255, fontStyle: 'bold' },
        styles: { ...base.styles, fontSize: 8.5, cellPadding: { top: 2.4, bottom: 2.4, left: 3, right: 3 } },
        columnStyles: { 0: { cellWidth: 25 }, 1: { cellWidth: 52 }, 3: { halign: 'right', cellWidth: 28 } },
        alternateRowStyles: { fillColor: [247, 250, 252] },
        didParseCell: (d) => {
          if (d.section === 'head' && d.column.index === 3) d.cell.styles.halign = 'right';
          if (d.section === 'body' && d.column.index === 3) d.cell.styles.textColor = lines[d.row.index].waived ? GREEN : RED;
        },
      });
      y = doc.lastAutoTable.finalY + 9;
    } else {
      doc.setFont('helvetica', 'normal').setFontSize(9.5).setTextColor(...INK).text('No deductions in this period. Well done.', M, y + 4);
      y += 13;
    }

    // Notes
    const notes = [
      `Deductions follow the company pay rules (lateness, absence, daily reports, clock-out, GPS, client sign-off, overdue job items and rework).`,
      lines.some((l) => l.waived) ? 'Items marked "Waived" were reviewed and not charged.' : null,
      months.some((m) => m.advances.length) ? `Salary advances of ${naira(sum((m) => m.advances_total))} are recovered from pay in the month given.` : null,
      final ? null : 'Months not yet finalised can still change after the month-end review.',
      'If any item is wrong, raise it with management within 7 days of receiving this statement.',
    ].filter(Boolean);
    heading('Notes');
    y += 3;
    doc.setFont('helvetica', 'normal').setFontSize(9).setTextColor(...INK);
    notes.forEach((n) => {
      const t = doc.splitTextToSize(n, W - 2 * M - 4);
      if (y + t.length * 4.3 > H - 25) { doc.addPage(); y = 22; }
      doc.text('•', M, y);
      doc.text(t, M + 3.5, y);
      y += t.length * 4.3 + 1;
    });
    y += 6;

    // Payment details and sign-off
    if (y > H - 55) { doc.addPage(); y = 22; }
    heading('Payment Details');
    y += 4;
    doc.setFontSize(9.5).setTextColor(...INK);
    const pair = (label, val, x) => {
      doc.setFont('helvetica', 'bold').text(label, x, y);
      const lw = doc.getTextWidth(label);
      doc.setFont('helvetica', 'normal').text(val || '-', x + lw + 1.5, y);
    };
    pair('Account Name:', u.name.toUpperCase(), M);
    pair('Bank:', u.bank_name, M + 95);
    y += 5;
    pair('Account Number:', u.bank_account, M);
    y += 11;
    doc.setFont('helvetica', 'bold').setFontSize(8.5).setTextColor(...GREY).text(`For: ${lh.company}`, M, y);
    doc.setFont('helvetica', 'normal');
    if (lh.signatory) doc.text(`${lh.signatory}${lh.signatory_title ? ' | ' + lh.signatory_title : ''}`, M, y + 4.3);
    if (contact) doc.text(contact, M, y + 8.6);
    // Staff acknowledgement line on the right.
    if (s.ack?.signature) {
      try { doc.addImage(s.ack.signature, 'PNG', W - M - 62, y - 9, 40, 14); } catch (e) { /* unreadable image: leave the line blank */ }
      doc.setFont('helvetica', 'normal').setFontSize(7.5).setTextColor(...GREY)
        .text(`Signed by ${s.ack.signed_name} on ${fmtDate(s.ack.signed_at)}`, W - M - 62, y + 14);
      doc.setFontSize(8.5);
    }
    doc.setDrawColor(...GREY).setLineWidth(0.2).line(W - M - 62, y + 6, W - M, y + 6);
    doc.text('Staff signature & date', W - M - 62, y + 10);

    // Watermark and footer on every page
    const pages = doc.getNumberOfPages();
    for (let p = 1; p <= pages; p++) {
      doc.setPage(p);
      doc.saveGraphicsState();
      doc.setGState(new doc.GState({ opacity: 0.06 }));
      const r = 26;
      doc.setFontSize(46).setFont('helvetica', 'bold');
      const tw = doc.getTextWidth('TECH');
      const cx = (W - (2 * r + 3 + tw)) / 2 + r;
      const cy = H / 2 + 8;
      doc.setFillColor(...BRAND).circle(cx, cy, r, 'F');
      doc.setTextColor(255, 255, 255).text('DEV', cx - r + 6, cy + 6);
      doc.setTextColor(...BRAND).text('TECH', cx + r + 3, cy + 6);
      doc.setFontSize(11).setFont('helvetica', 'normal').text(lh.company.toUpperCase(), W / 2, cy + r + 9, { align: 'center', charSpace: 2 });
      doc.restoreGraphicsState();

      doc.setDrawColor(...NAVY).setLineWidth(0.4).line(M, H - 14, W - M, H - 14);
      doc.setFont('helvetica', 'normal').setFontSize(7.5).setTextColor(...GREY);
      doc.text(`${lh.company} · Confidential staff statement · ${u.name} · ${period}`, M, H - 9.5);
      doc.text(`Page ${p} of ${pages}`, W - M, H - 9.5, { align: 'right' });
    }

    const safe = (t) => t.replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-');
    const name = `Statement-of-Account-${safe(u.name)}-${s.from === s.to ? s.from : s.from + '-to-' + s.to}.pdf`;
    return { doc, blob: doc.output('blob'), name, period, key: s.from === s.to ? s.from : `${s.from}_${s.to}` };
  }

  function download(blob, name) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  }

  async function sendPdf(uid, pdf, note, requireAck = true) {
    const file = new File([pdf.blob], pdf.name, { type: 'application/pdf' });
    return api('staff_statement_send', { data: { user_id: uid, period: pdf.period, period_key: pdf.key, require_ack: requireAck ? 1 : 0, note },
      files: [{ field: 'files[]', file, name: pdf.name }] });
  }

  App.statementPdf = { build: buildPdf, download, send: sendPdf, periodLabel };

  /* ------------------------------------------------------------- dialogs */

  const sentList = (sent) => (sent.length ? `<ul class="list">${sent.map((f) => `<li class="li" style="display:flex;justify-content:space-between;gap:10px;align-items:center">
      <span><i class="ti ti-file-type-pdf bad"></i> ${esc(f.name)}<div class="faint small">Sent ${esc(fmtDate(f.sent_at))}${f.by ? ' by ' + esc(f.by) : ''}</div></span>
      <a class="btn sm" href="file.php?id=${f.id}" target="_blank" rel="noopener"><i class="ti ti-download"></i>Open</a></li>`).join('')}</ul>`
    : '<p class="muted small card-pad">Nothing sent yet.</p>');

  /** Admin: make, download or send a statement for one staff member. */
  App.statementDialog = (uid, month) => {
    const m = month || App.cfg.today.slice(0, 7);
    const el = App.modal('Statement of account', `
      <form id="sd-form"><div class="form-row">
        <div class="field"><label for="sd-from">From</label><input type="month" id="sd-from" name="from" value="${m}" max="${App.cfg.today.slice(0, 7)}"></div>
        <div class="field"><label for="sd-to">To</label><input type="month" id="sd-to" name="to" value="${m}" max="${App.cfg.today.slice(0, 7)}"></div></div>
        <div class="field"><label for="sd-note">Message to the staff member (optional, when sending)</label><input id="sd-note" name="note" maxlength="500" placeholder="e.g. Please review and sign by Friday"></div>
        <label class="check-row"><input type="checkbox" id="sd-ack" checked><span><b>Require signature</b><br><span class="muted small">They must sign and acknowledge it before they can use the portal.</span></span></label>
        <p class="faint small">PDF on the company letterhead with logo and watermark: earnings, every deduction with its reason, advances and net pay. Up to 12 months.</p></form>
      <h3 style="margin:14px 0 6px">Already sent</h3><div class="card" id="sd-sent">${App.loading()}</div>`,
    `<button class="btn" id="sd-view"><i class="ti ti-eye"></i>Preview</button><button class="btn" id="sd-dl"><i class="ti ti-download"></i>Download PDF</button>
     <button class="btn primary" id="sd-send"><i class="ti ti-send"></i>Send to staff</button>`, { wide: true });
    const form = $('#sd-form', el);
    let last = null;
    const make = async () => {
      const f = App.formData(form);
      const s = await api('staff_statement', { query: { user_id: uid, from: f.from, to: f.to } });
      $('#sd-sent', el).innerHTML = sentList(s.sent);
      if (!s.months.length) throw new Error('No payroll for that period.');
      last = s;
      return buildPdf(s);
    };
    api('staff_statement', { query: { user_id: uid, from: m, to: m } }).then((s) => { $('#sd-sent', el).innerHTML = sentList(s.sent); $('.drawer-head h2', el).textContent = `Statement of account · ${s.user.name}`; }).catch(() => {});
    $('#sd-view', el).addEventListener('click', async (e) => {
      const w = window.open('', '_blank');
      try { const pdf = await App.busy(e.currentTarget, make); if (w) w.location = URL.createObjectURL(pdf.blob); else download(pdf.blob, pdf.name); } catch (err) { if (w) w.close(); App.fail(err); }
    });
    $('#sd-dl', el).addEventListener('click', async (e) => {
      try { const pdf = await App.busy(e.currentTarget, make); download(pdf.blob, pdf.name); } catch (err) { App.fail(err); }
    });
    $('#sd-send', el).addEventListener('click', async (e) => {
      try {
        await App.busy(e.currentTarget, async () => {
          const pdf = await make();
          await sendPdf(uid, pdf, App.formData(form).note || '', $('#sd-ack', el).checked);
          const s = await api('staff_statement', { query: { user_id: uid, from: last.from, to: last.to } });
          $('#sd-sent', el).innerHTML = sentList(s.sent);
        });
        toast('Statement sent');
      } catch (err) { App.fail(err); }
    });
  };

  /** Admin: send this month's statement to every staff member on the payroll. */
  App.statementSendAll = async (rows, month) => {
    if (!rows.length) return;
    if (!(await App.confirm('Send statements', `Make a ${esc(monthName(month))} statement of account PDF for ${rows.length} staff member${rows.length === 1 ? '' : 's'} and send each one to them? They get a phone alert and email, and must <b>sign and acknowledge</b> it before they can continue in the portal.`, { ok: 'Send all' }))) return;
    const el = App.modal('Sending statements', `<div id="ss-prog" class="muted">Starting…</div>`, '');
    let done = 0;
    const failed = [];
    for (const r of rows) {
      $('#ss-prog', el).textContent = `${done + failed.length + 1} of ${rows.length}: ${r.name}`;
      try {
        const s = await api('staff_statement', { query: { user_id: r.id, from: month, to: month } });
        await sendPdf(r.id, await buildPdf(s), '');
        done++;
      } catch (e) { failed.push(`${r.name}: ${e.message}`); }
    }
    $('#ss-prog', el).innerHTML = `<p><b>${done}</b> statement${done === 1 ? '' : 's'} sent.</p>${failed.length ? `<p class="bad small">${failed.map(esc).join('<br>')}</p>` : ''}`;
    $('#ss-prog', el).insertAdjacentHTML('beforeend', '<div class="actions" style="margin-top:12px"><button class="btn" id="ss-signs"><i class="ti ti-signature"></i>Sign-offs</button><button class="btn primary" id="ss-done">Done</button></div>');
    $('#ss-done', el).addEventListener('click', App.closeTop);
    $('#ss-signs', el).addEventListener('click', () => { App.closeTop(); App.statementSignoffs(month); });
  };

  /** Admin: who has signed this month's statement and who has not. */
  App.statementSignoffs = async (month) => {
    const el = App.modal(`Statement sign-offs · ${esc(monthName(month))}`, `<div id="so-body">${App.loading()}</div>`, '<button class="btn" data-close>Close</button>', { wide: true });
    try {
      const r = await api('statement_acks', { query: { period: month } });
      const signed = r.rows.filter((x) => x.signed_at).length;
      $('#so-body', el).innerHTML = r.rows.length ? `<p class="muted" style="margin-bottom:10px"><b>${signed}</b> of ${r.rows.length} signed.</p>
        <div class="card"><ul class="list">${r.rows.map((x) => `<li class="li" style="display:flex;justify-content:space-between;gap:10px;align-items:center">
          <span><b>${esc(x.name)}</b><div class="faint small">${x.signed_at ? `Signed ${esc(fmtDate(x.signed_at))} as “${esc(x.signed_name)}”` : `Sent ${esc(fmtDate(x.requested_at))} · <span class="bad">not signed yet</span>`}</div></span>
          <span style="display:flex;gap:8px;align-items:center">${x.signature ? `<img src="${esc(x.signature)}" alt="Signature of ${esc(x.name)}" style="height:38px;max-width:120px;background:#fff;border-radius:6px;border:1px solid var(--line, #ddd)">` : ''}
          ${x.file_id ? `<a class="btn sm" href="file.php?id=${x.file_id}" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf"></i>PDF</a>` : ''}</span></li>`).join('')}</ul></div>`
        : '<p class="muted">No statements needing a signature have been sent for this month.</p>';
    } catch (e) { $('#so-body', el).innerHTML = `<p class="bad">${esc(e.message)}</p>`; }
  };

  /** Staff: statements sent by the office, plus a PDF of their own published months. */
  App.renderMyStatements = async (el) => {
    if (!el) return;
    let r;
    try { r = await api('statements_mine'); } catch (e) { return; }
    el.innerHTML = r.sent.length ? `<h3 style="margin:0 0 8px">Statements from the office</h3><div class="card" style="margin-bottom:14px">${sentList(r.sent)}</div>` : '';
  };

  App.myStatementPdf = () => {
    const to = App.cfg.today.slice(0, 7);
    const el = App.modal('Download my statement', `<p class="muted" style="margin-bottom:12px">Your statement of account as a PDF. Only months your admin has finalised are included.</p>
      <div class="form-row"><div class="field"><label for="ms-from">From</label><input type="month" id="ms-from" value="${to}" max="${to}"></div>
      <div class="field"><label for="ms-to">To</label><input type="month" id="ms-to" value="${to}" max="${to}"></div></div>`,
    '<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ms-dl"><i class="ti ti-download"></i>Download PDF</button>');
    $('#ms-dl', el).addEventListener('click', async (e) => {
      try {
        await App.busy(e.currentTarget, async () => {
          const s = await api('staff_statement', { query: { from: $('#ms-from', el).value, to: $('#ms-to', el).value } });
          if (!s.months.length) throw new Error('None of those months have been finalised yet.');
          const pdf = await buildPdf(s);
          download(pdf.blob, pdf.name);
        });
        App.closeTop();
      } catch (err) { App.fail(err); }
    });
  };
})();
