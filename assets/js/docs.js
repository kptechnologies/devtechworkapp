/* DevTech Staff Portal — Sheets & docs: spreadsheets and documents staff prepare for their work, on a job order or on
   their own. Built-in files are edited here (spreadsheet grid / Word-style editor) and saved in the portal; Google files
   are Google Sheets/Docs in the company Google Drive, shown inside the portal. */
(() => {
  const { $, $$, esc, api, toast, fmtDate, ago, isManagement } = App;

  const LIB = {
    sheet: ['https://cdn.jsdelivr.net/npm/x-data-spreadsheet@1.1.9/dist/xspreadsheet.css', 'https://cdn.jsdelivr.net/npm/x-data-spreadsheet@1.1.9/dist/xspreadsheet.js'],
    doc: ['https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css', 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js'],
    xlsx: ['https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'],
  };
  const loaded = {};
  /** Load a CSS/JS file once. */
  const loadFile = (url) => (loaded[url] ||= new Promise((resolve, reject) => {
    const el = url.endsWith('.css') ? Object.assign(document.createElement('link'), { rel: 'stylesheet', href: url })
      : Object.assign(document.createElement('script'), { src: url });
    el.onload = resolve;
    el.onerror = () => { delete loaded[url]; reject(new Error('The editor couldn\'t load. Check your internet connection and try again.')); };
    document.head.appendChild(el);
  }));
  const loadLib = (name) => Promise.all(LIB[name].map(loadFile));

  const KIND = { sheet: ['Spreadsheet', 'table', 'tone-in'], doc: ['Document', 'file-text', 'tone-info'] };
  const docIcon = (d) => KIND[d.kind][1];
  const engineTag = (d) => (d.engine === 'google' ? '<span class="pill info" style="font-size:11px"><i class="ti ti-brand-google-drive"></i>Google</span>' : '');
  const safeName = (s) => String(s || 'file').replace(/[\\/:*?"<>|]+/g, ' ').trim().slice(0, 120) || 'file';
  const download = (blob, name) => {
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  };
  const googleEditUrl = (d) => (d.gfile_id ? `https://docs.google.com/${d.kind === 'sheet' ? 'spreadsheets' : 'document'}/d/${encodeURIComponent(d.gfile_id)}/edit` : d.web_url);

  /* ------------------------------------------------- spreadsheet <-> xlsx */
  // The spreadsheet only knows these functions; formulas using others are imported as their value.
  const SHEET_FUNCS = ['SUM', 'AVERAGE', 'MAX', 'MIN', 'IF', 'AND', 'OR', 'CONCAT'];

  /**
   * Work out a formula's value, so the downloaded Excel file shows numbers even in programs that don't recalculate on
   * opening. Handles what the spreadsheet supports: + - * / ^ &, comparisons, cell refs, ranges and SHEET_FUNCS.
   * Returns undefined when it can't.
   */
  function formulaValue(formula, getText, depth = 0) {
    if (depth > 50) return undefined;
    const src = formula.replace(/^=/, '');
    let i = 0;
    const ws = () => { while (src[i] === ' ') i++; };
    const peek = (s) => { ws(); return src.startsWith(s, i); };
    const eat = (s) => { if (peek(s)) { i += s.length; return true; } return false; };
    const colNum = (c) => [...c.toUpperCase()].reduce((n, ch) => n * 26 + ch.charCodeAt(0) - 64, 0) - 1;
    const cellVal = (ref) => {
      const m = ref.replace(/\$/g, '').match(/^([A-Za-z]+)(\d+)$/);
      const t = getText(+m[2] - 1, colNum(m[1]));
      if (t === undefined || t === null || t === '') return 0;
      if (String(t)[0] === '=') return formulaValue(String(t), getText, depth + 1) ?? 0;
      return /^\s*-?\d+(\.\d+)?\s*$/.test(t) ? +t : String(t);
    };
    const flat = (a) => (Array.isArray(a) ? a.flat(Infinity) : [a]);
    const nums = (args) => args.flatMap(flat).filter((v) => typeof v === 'number');
    const FN = {
      SUM: (a) => nums(a).reduce((x, y) => x + y, 0),
      AVERAGE: (a) => { const n = nums(a); return n.length ? n.reduce((x, y) => x + y, 0) / n.length : 0; },
      MAX: (a) => { const n = nums(a); return n.length ? Math.max(...n) : 0; },
      MIN: (a) => { const n = nums(a); return n.length ? Math.min(...n) : 0; },
      IF: (a) => (a[0] ? a[1] : a[2] ?? false),
      AND: (a) => a.flatMap(flat).every(Boolean),
      OR: (a) => a.flatMap(flat).some(Boolean),
      CONCAT: (a) => a.flatMap(flat).join(''),
    };
    const primary = () => {
      ws();
      let m;
      if (eat('(')) { const v = compare(); if (!eat(')')) throw 0; return v; }
      if (eat('-')) return -primary();
      if (eat('+')) return primary();
      if (src[i] === '"') { const j = src.indexOf('"', i + 1); if (j < 0) throw 0; const s = src.slice(i + 1, j); i = j + 1; return s; }
      if ((m = src.slice(i).match(/^\d+(\.\d+)?/))) { i += m[0].length; return +m[0]; }
      if ((m = src.slice(i).match(/^(\$?[A-Za-z]+\$?\d+):(\$?[A-Za-z]+\$?\d+)/))) {
        i += m[0].length;
        const [a, b] = [m[1], m[2]].map((r) => r.replace(/\$/g, '').match(/^([A-Za-z]+)(\d+)$/));
        const [r1, r2] = [+a[2], +b[2]].sort((x, y) => x - y);
        const [c1, c2] = [colNum(a[1]), colNum(b[1])].sort((x, y) => x - y);
        const out = [];
        for (let r = r1; r <= r2; r++) for (let c = c1; c <= c2; c++) out.push(cellVal(X_ADDR(c) + r));
        return out;
      }
      if ((m = src.slice(i).match(/^([A-Za-z]+)\s*\(/)) && FN[m[1].toUpperCase()]) {
        i += m[0].length;
        const args = [];
        if (!eat(')')) { do args.push(compare()); while (eat(',')); if (!eat(')')) throw 0; }
        return FN[m[1].toUpperCase()](args);
      }
      if ((m = src.slice(i).match(/^\$?[A-Za-z]+\$?\d+/))) { i += m[0].length; return cellVal(m[0]); }
      if ((m = src.slice(i).match(/^(TRUE|FALSE)\b/i))) { i += m[0].length; return m[0].toUpperCase() === 'TRUE'; }
      throw 0;
    };
    const power = () => { let v = primary(); while (eat('^')) v = v ** primary(); return v; };
    const term = () => { let v = power(); for (;;) { if (eat('*')) v *= power(); else if (eat('/')) v /= power(); else return v; } };
    const add = () => { let v = term(); for (;;) { if (eat('+')) v += term(); else if (eat('-')) v -= term(); else return v; } };
    const concat = () => { let v = add(); while (eat('&')) v = String(v) + String(add()); return v; };
    const compare = () => {
      const v = concat();
      for (const [op, f] of [['<=', (a, b) => a <= b], ['>=', (a, b) => a >= b], ['<>', (a, b) => a !== b], ['<', (a, b) => a < b], ['>', (a, b) => a > b], ['=', (a, b) => a === b]]) {
        if (eat(op)) return f(v, concat());
      }
      return v;
    };
    try {
      const v = compare();
      ws();
      if (i < src.length || Array.isArray(v) || (typeof v === 'number' && !Number.isFinite(v))) return undefined;
      return v;
    } catch { return undefined; }
  }
  const X_ADDR = (c) => { let s = ''; c += 1; while (c > 0) { const m = (c - 1) % 26; s = String.fromCharCode(65 + m) + s; c = Math.floor((c - 1) / 26); } return s; };

  /** Built-in spreadsheet data (one entry per tab) to a SheetJS workbook. */
  function sheetsToWorkbook(sheets) {
    const X = window.XLSX;
    const wb = X.utils.book_new();
    const used = new Set();
    (sheets || []).forEach((s, i) => {
      const getText = (r, c) => s.rows?.[r]?.cells?.[c]?.text;
      const ws = {};
      let maxR = 0;
      let maxC = 0;
      Object.entries(s.rows || {}).forEach(([r, row]) => {
        if (r === 'len' || !row || !row.cells) return;
        Object.entries(row.cells).forEach(([c, cell]) => {
          const text = cell?.text;
          if (text === undefined || text === null || text === '') return;
          const ri = +r;
          const ci = +c;
          maxR = Math.max(maxR, ri);
          maxC = Math.max(maxC, ci);
          const t = String(text);
          const addr = X.utils.encode_cell({ r: ri, c: ci });
          if (t[0] === '=' && t.length > 1) {
            const v = formulaValue(t, getText);
            ws[addr] = typeof v === 'string' ? { t: 's', f: t.slice(1), v } : typeof v === 'boolean' ? { t: 'b', f: t.slice(1), v }
              : v === undefined ? { t: 'n', f: t.slice(1) } : { t: 'n', f: t.slice(1), v };
          }
          else if (t.trim() !== '' && /^-?\d+(\.\d+)?$/.test(t.trim())) ws[addr] = { t: 'n', v: +t };
          else ws[addr] = { t: 's', v: t };
        });
      });
      (s.merges || []).forEach((m) => { const rg = X.utils.decode_range(m); maxR = Math.max(maxR, rg.e.r); maxC = Math.max(maxC, rg.e.c); });
      ws['!ref'] = X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: maxR, c: maxC } });
      if (s.merges?.length) ws['!merges'] = s.merges.map((m) => X.utils.decode_range(m));
      const cols = [];
      Object.entries(s.cols || {}).forEach(([c, v]) => { if (c !== 'len' && v?.width) cols[+c] = { wpx: v.width }; });
      if (cols.length) ws['!cols'] = Array.from(cols, (c) => c || {});
      let name = safeName(s.name || `Sheet${i + 1}`).replace(/[[\]]/g, '').slice(0, 31) || `Sheet${i + 1}`;
      while (used.has(name.toLowerCase())) name = (name.slice(0, 28) + ' ' + (i + 1)).slice(0, 31);
      used.add(name.toLowerCase());
      X.utils.book_append_sheet(wb, ws, name);
    });
    if (!wb.SheetNames.length) X.utils.book_append_sheet(wb, X.utils.aoa_to_sheet([[]]), 'Sheet1');
    return wb;
  }

  /** A SheetJS workbook (from an uploaded Excel file) to built-in spreadsheet data. */
  function workbookToSheets(wb) {
    const X = window.XLSX;
    return wb.SheetNames.slice(0, 20).map((name) => {
      const ws = wb.Sheets[name];
      const out = { name, rows: {}, merges: [], cols: {}, styles: [] };
      const range = X.utils.decode_range(ws['!ref'] || 'A1');
      range.e.r = Math.min(range.e.r, 4999);
      range.e.c = Math.min(range.e.c, 199);
      for (let r = range.s.r; r <= range.e.r; r++) {
        for (let c = range.s.c; c <= range.e.c; c++) {
          const cell = ws[X.utils.encode_cell({ r, c })];
          if (!cell) continue;
          let text;
          const funcs = cell.f ? (cell.f.toUpperCase().match(/[A-Z][A-Z0-9.]*(?=\()/g) || []) : [];
          if (cell.f && funcs.every((f) => SHEET_FUNCS.includes(f))) text = '=' + cell.f;
          else text = cell.w ?? (cell.v === undefined || cell.v === null ? '' : String(cell.v));
          if (text === '') continue;
          (out.rows[r] ||= { cells: {} }).cells[c] = { text };
        }
      }
      (ws['!merges'] || []).forEach((m) => {
        out.merges.push(X.utils.encode_range(m));
        const row = (out.rows[m.s.r] ||= { cells: {} });
        (row.cells[m.s.c] ||= { text: '' }).merge = [m.e.r - m.s.r, m.e.c - m.s.c];
      });
      (ws['!cols'] || []).forEach((col, i) => { const w = col?.wpx || (col?.wch ? Math.round(col.wch * 7 + 5) : 0); if (w) out.cols[i] = { width: Math.max(40, Math.min(600, w)) }; });
      out.rows.len = Math.max(100, range.e.r + 20);
      out.cols.len = Math.max(26, range.e.c + 5);
      return out;
    });
  }

  /** A Word document the browser can download: Word, LibreOffice and Google Docs all open this HTML format. */
  function wordHtml(title, body) {
    return `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="utf-8"><title>${esc(title)}</title><!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->
<style>body{font-family:Calibri,Arial,sans-serif;font-size:11pt;line-height:1.4} h1{font-size:20pt} h2{font-size:16pt} h3{font-size:13pt}
table{border-collapse:collapse;width:100%} td,th{border:1px solid #999;padding:4px 6px;vertical-align:top} img{max-width:100%}
.ql-align-center{text-align:center} .ql-align-right{text-align:right} .ql-align-justify{text-align:justify}</style></head><body>${body}</body></html>`;
  }

  /* -------------------------------------------------------- new file */
  /**
   * Make a new spreadsheet or document. opts: { job: {id, ref, title}, kind, template (management: make a template) }.
   * Opens it when made.
   */
  App.newDoc = async (opts = {}) => {
    let info;
    try { info = await api('docs_list', { query: { templates: 1 } }); } catch (e) { App.fail(e); return; }
    const templates = opts.template ? [] : info.docs;
    const google = info.google;
    const kind0 = opts.kind || 'sheet';
    const engine0 = google ? info.default_engine : 'builtin';
    const el = App.modal(opts.template ? 'New template' : opts.job ? `New file for ${esc(opts.job.ref)}` : 'New spreadsheet or document', `<form id="nd-form" novalidate>
      <div class="field"><div class="label">What do you want to make?</div><div class="opts">
        ${Object.entries(KIND).map(([k, [l, icon]]) => `<label class="opt"><input type="radio" name="kind" value="${k}" ${k === kind0 ? 'checked' : ''}><span><i class="ti ti-${icon}"></i>${l}</span></label>`).join('')}</div></div>
      <div class="field"><label for="nd-title">Name</label><input id="nd-title" name="title" maxlength="190" placeholder="${opts.job ? `e.g. Materials used - ${esc(opts.job.ref)}` : 'e.g. Weekly inventory'}" autofocus>
        ${opts.job ? `<div class="help">It's saved on job ${esc(opts.job.ref)} · ${esc(opts.job.title)}. Everyone on the job and management can open it.</div>` : ''}</div>
      ${opts.template ? '' : `<div class="field"><div class="label">Start from</div><div class="opts">
        <label class="opt"><input type="radio" name="from" value="blank" checked><span><i class="ti ti-file"></i>Blank</span></label>
        <label class="opt"><input type="radio" name="from" value="template" ${templates.length ? '' : 'disabled'}><span><i class="ti ti-template"></i>Template${templates.length ? '' : ' (none yet)'}</span></label>
        <label class="opt"><input type="radio" name="from" value="upload"><span><i class="ti ti-upload"></i>Upload Excel / Word file</span></label></div></div>
      <div class="field hide" id="nd-tpl-f"><label for="nd-tpl">Template</label><select id="nd-tpl" name="template_id"></select></div>
      <div class="field hide" id="nd-file-f"><label for="nd-file">File</label><input type="file" id="nd-file" accept=".xlsx,.xls,.csv,.docx,.doc">
        <div class="help" id="nd-file-help"></div></div>`}
      <div class="field" id="nd-eng-f"><div class="label">Where to edit it</div><div class="opts">
        <label class="opt"><input type="radio" name="engine" value="builtin" ${engine0 === 'builtin' ? 'checked' : ''}><span><i class="ti ti-layout-dashboard"></i>Built-in editor</span></label>
        <label class="opt"><input type="radio" name="engine" value="google" ${engine0 === 'google' ? 'checked' : ''} ${google ? '' : 'disabled'}><span><i class="ti ti-brand-google-drive"></i>Google ${google ? '' : '(not connected)'}</span></label></div>
        <div class="help" id="nd-eng-help"></div>
        ${google ? '' : `<div class="note-box" style="margin-top:8px"><i class="ti ti-brand-google-drive"></i> ${App.isAdmin()
    ? 'Google isn\'t connected yet. <a href="#/settings?gdrive=setup">Connect Google Drive in Settings</a> to use Google Sheets and Docs.'
    : 'Google isn\'t connected yet. Ask an admin to connect Google Drive in Settings. You can use the built-in editor meanwhile.'}</div>`}</div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="nd-ok"><i class="ti ti-plus"></i>Create</button>`, { wide: true });
    const form = $('#nd-form', el);
    const val = (n) => form.querySelector(`[name="${n}"]:checked`)?.value;
    const sync = () => {
      const kind = val('kind');
      const from = val('from') || 'blank';
      const tplF = $('#nd-tpl-f', el);
      if (tplF) {
        const list = templates.filter((t) => t.kind === kind);
        tplF.classList.toggle('hide', from !== 'template');
        $('#nd-tpl', el).innerHTML = list.length ? list.map((t) => `<option value="${t.id}">${esc(t.title)}${t.engine === 'google' ? ' (Google)' : ''}</option>`).join('')
          : `<option value="">No ${KIND[kind][0].toLowerCase()} templates yet</option>`;
        $('#nd-file-f', el).classList.toggle('hide', from !== 'upload');
        $('#nd-file-help', el).textContent = val('engine') === 'google' ? 'Excel (.xlsx, .xls, .csv) becomes a Google Sheet; Word (.docx, .doc) becomes a Google Doc.'
          : 'Excel files (.xlsx, .xls, .csv) open in the built-in spreadsheet. For Word files, choose Google.';
      }
      $('#nd-eng-f', el).classList.toggle('hide', from === 'template');
      $('#nd-eng-help', el).textContent = val('engine') === 'google'
        ? `A Google ${kind === 'sheet' ? 'Sheet' : 'Doc'} is made in the company Google Drive. ${info.share === 'link' ? 'Anyone with the link can edit it.' : `You edit it signed in to Google as ${info.my_google_email}.`}`
        : 'Edited right here in the portal. You can download it as Excel or Word at any time.';
    };
    form.addEventListener('change', sync);
    sync();
    $('#nd-ok', el).addEventListener('click', async (e) => {
      const kind = val('kind');
      const from = val('from') || 'blank';
      const engine = val('engine');
      const data = { kind, engine, title: form.title.value.trim(), job_id: opts.job?.id || null, template: opts.template ? 1 : 0 };
      let files = null;
      try {
        await App.busy(e.currentTarget, async () => {
          if (from === 'template') {
            if (!form.template_id.value) throw new Error('Pick a template.');
            data.template_id = +form.template_id.value;
          } else if (from === 'upload') {
            const f = $('#nd-file', el).files[0];
            if (!f) throw new Error('Choose the file to upload.');
            const ext = f.name.split('.').pop().toLowerCase();
            if (!data.title) data.title = f.name.replace(/\.[^.]+$/, '').slice(0, 190);
            if (engine === 'google') {
              files = [{ field: 'file', file: f, name: f.name }];
            } else {
              if (!['xlsx', 'xls', 'csv'].includes(ext)) throw new Error('The built-in editor opens Excel files. For Word files, choose Google.');
              if (f.size > 15 * 1048576) throw new Error('That file is too big (over 15 MB).');
              await loadLib('xlsx');
              const wb = window.XLSX.read(await f.arrayBuffer(), { type: 'array', cellFormula: true, cellText: true, sheetStubs: true });
              data.kind = 'sheet';
              data.content = JSON.stringify(workbookToSheets(wb));
            }
          }
          const r = await api('doc_create', { data, files });
          App.closeTop();
          toast(opts.template ? 'Template created' : `${KIND[data.kind]?.[0] || 'File'} created`);
          App.go(`docs/${r.id}`);
        });
      } catch (err) { App.fail(err); }
    });
  };

  /* ------------------------------------------------ list (page + job) */
  function docRows(docs, { showJob = true } = {}) {
    return `<ul class="list">${docs.map((d) => `<li class="li" data-doc="${d.id}">
      <div class="li-icon ${KIND[d.kind][2]}"><i class="ti ti-${docIcon(d)}"></i></div>
      <div class="main-col"><div class="t">${esc(d.title)}</div>
        <div class="s">${KIND[d.kind][0]}${showJob && d.job_ref ? ` · ${esc(d.job_ref)} ${esc(d.job_title || '')}` : ''} · ${esc(d.created_by_name || '')} · ${d.updated_at !== d.created_at ? 'edited ' : ''}${esc(ago(d.updated_at))}</div></div>
      ${engineTag(d)}<i class="ti ti-chevron-right faint"></i></li>`).join('')}</ul>`;
  }
  const bindRows = (box) => $$('[data-doc]', box).forEach((li) => li.addEventListener('click', () => App.go(`docs/${li.dataset.doc}`)));

  /** The "Sheets & docs" section in the job drawer. */
  App.jobDocsHtml = (j) => `<div class="sec-title" style="display:flex;align-items:center;justify-content:space-between;gap:8px">Sheets &amp; docs
      ${j.status !== 'cancelled' ? `<span style="display:flex;gap:6px"><button class="btn sm ghost" data-jdoc-new="sheet"><i class="ti ti-table-plus"></i>Spreadsheet</button>
        <button class="btn sm ghost" data-jdoc-new="doc"><i class="ti ti-file-plus"></i>Document</button></span>` : ''}</div>
    ${j.docs?.length ? `<div class="card" style="margin-bottom:8px">${docRows(j.docs, { showJob: false })}</div>`
      : '<p class="muted small" style="margin-bottom:8px">No spreadsheets or documents yet. Make one to record materials, measurements, costs or a report on the work.</p>'}`;
  App.bindJobDocs = (box, j) => {
    bindRows(box);
    $$('[data-jdoc-new]', box).forEach((b) => b.addEventListener('click', () => App.newDoc({ job: { id: j.id, ref: j.ref, title: j.title }, kind: b.dataset.jdocNew })));
  };

  const stAll = { tab: 'all', kind: '', engine: '', q: '' };
  const stSheets = { tab: 'all', kind: 'sheet', engine: '', q: '' };
  /** The Sheets & docs page, or with sheetsOnly the Spreadsheet page (spreadsheets only). */
  async function docsView(ctx, sheetsOnly = false) {
    const mgmt = isManagement();
    const st = sheetsOnly ? stSheets : stAll;
    const tabs = [['all', mgmt ? 'All files' : 'My files', 'files'], ['jobs', 'On job orders', 'clipboard-list'], ['general', 'Not on a job', 'file'], ['templates', 'Templates', 'template']];
    if (mgmt) tabs.splice(1, 0, ['mine', 'Mine', 'user']);
    ctx.el.innerHTML = `
      <div class="page-head"><div><h1>${sheetsOnly ? 'Spreadsheet' : 'Sheets &amp; docs'}</h1><p class="muted">${sheetsOnly ? 'Spreadsheets for your work: materials used, measurements, costs, inventories and inspection sheets. Upload an Excel file or start a new one.'
          : 'Spreadsheets and documents for your work: materials used, measurements, costs, inspection sheets and reports.'}${mgmt ? ' You can open every file staff make.' : ''}</p></div>
        <div class="actions">${sheetsOnly ? '' : '<button class="btn" id="dc-doc"><i class="ti ti-file-plus"></i>New document</button>'}<button class="btn primary" id="dc-sheet"><i class="ti ti-table-plus"></i>New spreadsheet</button></div></div>
      <div class="seg" id="dc-tabs" style="margin-bottom:12px;max-width:100%;overflow-x:auto">${tabs.map(([k, l, i]) => `<button data-t="${k}" class="${k === st.tab ? 'on' : ''}"><i class="ti ti-${i}"></i> ${l}</button>`).join('')}</div>
      <div class="filters" id="dc-filters"><input type="search" name="q" placeholder="Search by name, job or staff…" value="${esc(st.q)}">
        ${sheetsOnly ? '' : `<select name="kind">${App.opts([['sheet', 'Spreadsheets'], ['doc', 'Documents']], st.kind, { placeholder: 'All types' })}</select>`}
        <select name="engine">${App.opts([['builtin', 'Built-in'], ['google', 'Google']], st.engine, { placeholder: 'Built-in & Google' })}</select></div>
      <div id="dc-google"></div>
      <div id="dc-list">${App.loading()}</div>`;
    const load = async () => {
      const q = { kind: st.kind, engine: st.engine, q: st.q };
      if (st.tab === 'templates') q.templates = 1; else if (st.tab !== 'all') q.scope = st.tab;
      const r = await api('docs_list', { query: q });
      const box = $('#dc-list', ctx.el);
      const g = $('#dc-google', ctx.el);
      g.innerHTML = r.google && r.share === 'people' ? `<p class="small muted" style="margin:-4px 0 12px"><i class="ti ti-brand-google"></i> You open Google files as <b>${esc(r.my_google_email)}</b>. <a href="#" id="dc-gmail">Change</a></p>` : '';
      $('#dc-gmail', g)?.addEventListener('click', (e) => { e.preventDefault(); changeGoogleEmail(r.my_google_email, load); });
      const tplNew = st.tab === 'templates' && mgmt ? '<button class="btn sm" id="dc-tpl-new"><i class="ti ti-plus"></i>New template</button>' : '';
      if (!r.docs.length) {
        box.innerHTML = `<div class="card">${App.empty(st.tab === 'templates' ? 'template' : 'files', st.tab === 'templates' ? 'No templates yet' : sheetsOnly ? 'No spreadsheets yet' : 'No files yet',
          st.tab === 'templates' ? (mgmt ? 'Make a template (for example a materials-used sheet) so staff can start from it.' : 'Management hasn\'t made any templates yet.')
            : sheetsOnly ? 'Make a spreadsheet, or open a job order and add one there.' : 'Make a spreadsheet or document, or open a job order and add one there.', tplNew)}</div>`;
      } else {
        box.innerHTML = `${tplNew ? `<div style="display:flex;justify-content:flex-end;margin-bottom:8px">${tplNew}</div>` : ''}<div class="card">${docRows(r.docs)}</div>`;
      }
      bindRows(box);
      $('#dc-tpl-new', box)?.addEventListener('click', () => App.newDoc({ template: true, kind: sheetsOnly ? 'sheet' : undefined }));
    };
    $$('#dc-tabs button', ctx.el).forEach((b) => b.addEventListener('click', () => {
      st.tab = b.dataset.t;
      $$('#dc-tabs button', ctx.el).forEach((x) => x.classList.toggle('on', x === b));
      load().catch(App.fail);
    }));
    $('#dc-filters', ctx.el).addEventListener('input', App.debounce((e) => { if (e.target.name) { st[e.target.name] = e.target.value; load().catch(App.fail); } }, 300));
    $('#dc-sheet', ctx.el).addEventListener('click', () => App.newDoc({ kind: 'sheet', template: st.tab === 'templates' && mgmt }));
    $('#dc-doc', ctx.el)?.addEventListener('click', () => App.newDoc({ kind: 'doc', template: st.tab === 'templates' && mgmt }));
    ctx.refresh = () => load().catch(() => {});
    await load();
  }

  async function changeGoogleEmail(current, done) {
    const el = App.modal('Your Google account', `<form id="ge-form" novalidate>
      <p class="muted" style="margin-bottom:12px">Google files from the portal are shared with this Google account. Use the Gmail or Google account you sign in to Google with. Leave it empty to use your portal email.</p>
      <div class="field"><label for="ge-email">Google email</label><input type="email" id="ge-email" name="email" value="${esc(current)}" autofocus></div></form>`,
    `<button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="ge-ok">Save</button>`);
    $('#ge-ok', el).addEventListener('click', async (e) => {
      try {
        await App.busy(e.currentTarget, () => api('doc_google_email', { data: { email: $('#ge-email', el).value.trim() } }));
        App.closeTop();
        toast('Saved. Open a Google file and press "Give me access" to use the new account.');
        done && done();
      } catch (err) { App.fail(err); }
    });
  }

  /* ---------------------------------------------------------- editor */
  async function docView(ctx) {
    const id = +ctx.params.id;
    const r = await api('doc_get', { query: { id } });
    const d = r.doc;
    try { d.content = d.content_json ? JSON.parse(d.content_json) : null; } catch { d.content = null; }
    App.setTitle(d.title);
    const mgmt = isManagement();
    const back = d.is_template ? 'docs' : d.job_id ? `jobs/${d.job_id}` : 'docs';
    const crumbs = d.is_template ? 'Template' : d.job_id ? `<a href="#/jobs/${d.job_id}">${esc(d.job_ref)} · ${esc(d.job_title || '')}</a>` : 'Not on a job';
    ctx.el.innerHTML = `<div class="doc-page">
      <div class="doc-bar">
        <a class="icon-btn" href="#/${back}" title="Back" aria-label="Back"><i class="ti ti-arrow-left"></i></a>
        <div class="li-icon ${KIND[d.kind][2]}" style="width:32px;height:32px"><i class="ti ti-${docIcon(d)}"></i></div>
        <div class="doc-name"><input id="dv-title" value="${esc(d.title)}" maxlength="190" ${d.can_edit ? '' : 'readonly'} aria-label="File name">
          <div class="small faint">${crumbs} · ${esc(d.created_by_name || '')}${d.updated_by_name ? ` · last edit by ${esc(d.updated_by_name)} ${esc(ago(d.updated_at))}` : ''}</div></div>
        <span class="small faint" id="dv-state">${d.can_edit ? '' : '<i class="ti ti-eye"></i> View only'}</span>
        <div class="doc-actions" id="dv-actions"></div>
      </div>
      <div class="note-box bad hide" id="dv-conflict" style="margin-bottom:8px"></div>
      <div class="doc-area" id="dv-area">${App.loading()}</div></div>`;
    const actions = $('#dv-actions', ctx.el);
    const area = $('#dv-area', ctx.el);
    const state = $('#dv-state', ctx.el);
    let version = d.version;
    let dirty = false;
    let saving = null;
    let getContent = null;
    let editor = null;

    // Rename
    const titleIn = $('#dv-title', ctx.el);
    titleIn.addEventListener('change', async () => {
      const t = titleIn.value.trim();
      if (!t) { titleIn.value = d.title; return; }
      try { await api('doc_save', { data: { id, title: t } }); version++; d.title = t; App.setTitle(t); toast('Renamed'); } catch (e) { App.fail(e); titleIn.value = d.title; }
    });

    const menu = (items) => {
      const html = items.filter(Boolean).map(([k, icon, label]) => `<button class="menu-item" data-m="${k}"><i class="ti ti-${icon}"></i>${label}</button>`).join('');
      actions.insertAdjacentHTML('beforeend', `<div class="menu-wrap"><button class="btn sm ghost" id="dv-more"><i class="ti ti-dots"></i>More</button><div class="menu hide" id="dv-menu">${html}</div></div>`);
      const m = $('#dv-menu', actions);
      $('#dv-more', actions).addEventListener('click', (e) => { e.stopPropagation(); m.classList.toggle('hide'); });
      document.addEventListener('click', () => m.classList.add('hide'), { once: false, capture: false });
      return m;
    };
    const common = [
      !d.is_template && mgmt ? ['template', 'template', 'Save a copy as template'] : null,
      d.can_delete ? ['delete', 'trash', d.is_template ? 'Delete template' : 'Delete file'] : null,
    ];
    const onCommon = async (k, btn) => {
      if (k === 'template') {
        const name = await App.confirm('Save as template', 'Staff will be able to start new files from a copy of this one.', { ok: 'Save template', input: { label: 'Template name', placeholder: d.title } });
        if (name === null) return;
        try { await App.busy(btn, () => api('doc_make_template', { data: { id, title: name === true ? '' : name } })); toast('Template saved. Find it under Sheets & docs → Templates.'); } catch (e) { App.fail(e); }
      } else if (k === 'delete') {
        const extra = d.engine === 'google' ? ' It is moved to the trash in the company Google Drive.' : '';
        if (!(await App.confirm(`Delete "${d.title}"?`, `Nobody will be able to open it from the portal.${extra}`, { ok: 'Delete', danger: true }))) return;
        try { dirty = false; await api('doc_delete', { data: { id } }); toast('Deleted'); App.go(back); } catch (e) { App.fail(e); }
      }
    };

    /* ---- Google file: shown in a frame, with a button to open it in Google */
    if (d.engine === 'google') {
      const url = googleEditUrl(d);
      actions.innerHTML = `<button class="btn sm ghost" id="dv-access" title="Share this file with your Google account again"><i class="ti ti-key"></i>Give me access</button>
        <a class="btn sm primary" href="${esc(url)}" target="_blank" rel="noopener"><i class="ti ti-external-link"></i>Open in Google</a>`;
      const m = menu(common);
      $$('[data-m]', m).forEach((b) => b.addEventListener('click', () => onCommon(b.dataset.m, b)));
      $('#dv-access', actions).addEventListener('click', async (e) => {
        try { const x = await App.busy(e.currentTarget, () => api('doc_google_access', { data: { id } })); toast(x.message); $('iframe', area)?.setAttribute('src', url); } catch (err) { App.fail(err); }
      });
      area.innerHTML = `<iframe class="doc-frame" src="${esc(url)}" title="${esc(d.title)}" allow="clipboard-read; clipboard-write" referrerpolicy="no-referrer-when-downgrade"></iframe>
        <p class="small faint" style="margin-top:6px">Can't see the file? Sign in to Google in this browser with the account it's shared with, press <b>Give me access</b>, or use <b>Open in Google</b>.</p>`;
      return;
    }

    /* ---- Built-in file */
    const setState = (s) => { state.innerHTML = s; };
    const save = async (force = false) => {
      if (!d.can_edit || !getContent) return;
      if (saving) { await saving; if (!dirty) return; }
      dirty = false;
      setState('<span class="spinner" style="width:12px;height:12px;border-width:2px;vertical-align:-2px"></span> Saving…');
      saving = (async () => {
        try {
          const x = await api('doc_save', { data: { id, content: JSON.stringify(getContent()), version, force: force ? 1 : 0 } });
          version = x.version;
          setState('<i class="ti ti-cloud-check"></i> Saved');
          $('#dv-conflict', ctx.el).classList.add('hide');
        } catch (e) {
          dirty = true;
          if (e.status === 409 && e.data?.conflict) {
            const box = $('#dv-conflict', ctx.el);
            box.innerHTML = `<b>${esc(e.message)}</b> Your changes aren't saved yet.
              <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><button class="btn sm" id="dv-reload">Load their version (lose my changes)</button>
              <button class="btn sm danger" id="dv-force">Keep mine (replace theirs)</button></div>`;
            box.classList.remove('hide');
            $('#dv-reload', box).addEventListener('click', () => { dirty = false; App.reroute(); });
            $('#dv-force', box).addEventListener('click', () => save(true));
            setState('<i class="ti ti-alert-triangle"></i> Not saved');
          } else {
            setState('<i class="ti ti-alert-triangle"></i> Not saved');
            App.fail(e);
          }
        } finally { saving = null; }
      })();
      await saving;
    };
    const autosave = App.debounce(() => { if (dirty) save(); }, 2000);
    const changed = () => { if (!d.can_edit) return; dirty = true; setState('<i class="ti ti-pencil"></i> Editing…'); autosave(); };

    // Save before leaving the page or closing the tab.
    const onLeave = () => { if (dirty) save(); window.removeEventListener('hashchange', onLeave); window.removeEventListener('beforeunload', onUnload); };
    const onUnload = (e) => { if (dirty) { save(); e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('hashchange', onLeave);
    window.addEventListener('beforeunload', onUnload);

    try {
      await loadLib(d.kind);
    } catch (e) { area.innerHTML = App.empty('wifi-off', e.message); return; }

    const sendToGoogle = async (btn, blob, name) => {
      if (!(await App.confirm('Copy to Google Drive?', 'A Google copy is made in the company Google Drive and opened. This built-in file stays as it is.', { ok: 'Copy to Google' }))) return;
      try {
        if (dirty) await save();
        const x = await App.busy(btn, async () => api('doc_to_google', { data: { id }, files: [{ field: 'file', file: await blob(), name }] }));
        toast('Copied to Google Drive');
        App.go(`docs/${x.id}`);
      } catch (e) { App.fail(e); }
    };

    if (d.kind === 'sheet') {
      area.innerHTML = '<div class="sheet-host" id="dv-sheet"></div>';
      const host = $('#dv-sheet', area);
      editor = window.x_spreadsheet(host, {
        mode: d.can_edit ? 'edit' : 'read',
        showToolbar: d.can_edit,
        showContextmenu: d.can_edit,
        showBottomBar: true,
        view: { height: () => host.clientHeight, width: () => host.clientWidth },
        row: { len: 100, height: 25 },
        col: { len: 26, width: 100, indexWidth: 60, minWidth: 40 },
      });
      editor.loadData(Array.isArray(d.content) && d.content.length ? d.content : [{ name: 'Sheet1' }]);
      editor.change(changed);
      getContent = () => editor.getData();
      const xlsxBlob = async () => {
        await loadLib('xlsx');
        const out = window.XLSX.write(sheetsToWorkbook(editor.getData()), { type: 'array', bookType: 'xlsx' });
        return new Blob([out], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
      };
      actions.innerHTML = `<button class="btn sm ghost" id="dv-xlsx"><i class="ti ti-file-spreadsheet"></i>Download Excel</button>`;
      const m = menu([['csv', 'file-type-csv', 'Download CSV (current tab)'], d.can_edit ? ['import', 'upload', 'Replace with an Excel file'] : null,
        d.can_edit && r.google ? ['google', 'brand-google-drive', 'Copy to Google Drive'] : null, ...common]);
      $('#dv-xlsx', actions).addEventListener('click', async (e) => {
        try { await App.busy(e.currentTarget, async () => download(await xlsxBlob(), safeName(d.title) + '.xlsx')); } catch (err) { App.fail(err); }
      });
      $$('[data-m]', m).forEach((b) => b.addEventListener('click', async () => {
        const k = b.dataset.m;
        if (k === 'csv') {
          await loadLib('xlsx');
          const wb = sheetsToWorkbook(editor.getData());
          const idx = Math.max(0, editor.sheet?.data ? editor.datas?.indexOf(editor.sheet.data) ?? 0 : 0);
          const name = wb.SheetNames[idx] || wb.SheetNames[0];
          download(new Blob(['﻿' + window.XLSX.utils.sheet_to_csv(wb.Sheets[name])], { type: 'text/csv' }), `${safeName(d.title)} - ${safeName(name)}.csv`);
        } else if (k === 'import') {
          const inp = Object.assign(document.createElement('input'), { type: 'file', accept: '.xlsx,.xls,.csv' });
          inp.onchange = async () => {
            const f = inp.files[0];
            if (!f) return;
            if (!(await App.confirm('Replace this spreadsheet?', `Everything in it is replaced with ${esc(f.name)}.`, { ok: 'Replace', danger: true }))) return;
            try {
              await loadLib('xlsx');
              const wb = window.XLSX.read(await f.arrayBuffer(), { type: 'array', cellFormula: true, cellText: true, sheetStubs: true });
              editor.loadData(workbookToSheets(wb));
              changed();
              toast('Excel file loaded');
            } catch (err) { App.fail(err); }
          };
          inp.click();
        } else if (k === 'google') {
          sendToGoogle(b, xlsxBlob, safeName(d.title) + '.xlsx');
        } else onCommon(k, b);
      }));
      // Keep the grid sized to the window.
      const ro = new ResizeObserver(() => { if (!document.body.contains(host)) { ro.disconnect(); return; } editor.reRender?.(); });
      ro.observe(host);
    } else {
      area.innerHTML = '<div class="docx-host"><div id="dv-doc"></div></div>';
      const Quill = window.Quill;
      const toolbar = d.can_edit ? [
        [{ header: [1, 2, 3, false] }], [{ size: ['small', false, 'large', 'huge'] }],
        ['bold', 'italic', 'underline', 'strike'], [{ color: [] }, { background: [] }],
        [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }], [{ indent: '-1' }, { indent: '+1' }], [{ align: [] }],
        ['blockquote', 'link', 'image'], ['clean'],
      ] : false;
      editor = new Quill($('#dv-doc', area), {
        theme: 'snow',
        readOnly: !d.can_edit,
        placeholder: d.can_edit ? 'Write your report here…' : '',
        modules: { toolbar, table: true },
      });
      if (d.content?.delta) editor.setContents(d.content.delta, 'silent');
      editor.on('text-change', (_, __, source) => { if (source === 'user' || source === 'api') changed(); });
      getContent = () => ({ delta: editor.getContents() });
      const html = () => editor.getSemanticHTML ? editor.getSemanticHTML() : editor.root.innerHTML;
      const docBlob = async () => new Blob(['﻿', wordHtml(d.title, html())], { type: 'application/msword' });
      const table = editor.getModule('table');
      actions.innerHTML = `${d.can_edit && table ? `<div class="menu-wrap"><button class="btn sm ghost" id="dv-tbl"><i class="ti ti-table"></i>Table</button><div class="menu hide" id="dv-tbl-menu">
          ${[['insert', 'table-plus', 'Insert table (3 × 3)'], ['row', 'row-insert-bottom', 'Add row below'], ['col', 'column-insert-right', 'Add column right'],
            ['delrow', 'row-remove', 'Delete row'], ['delcol', 'column-remove', 'Delete column'], ['deltable', 'table-minus', 'Delete table']]
            .map(([k, i, l]) => `<button class="menu-item" data-t="${k}"><i class="ti ti-${i}"></i>${l}</button>`).join('')}</div></div>` : ''}
        <button class="btn sm ghost" id="dv-word"><i class="ti ti-file-word"></i>Download Word</button>`;
      const m = menu([['print', 'printer', 'Print or save as PDF'], d.can_edit && r.google ? ['google', 'brand-google-drive', 'Copy to Google Drive'] : null, ...common]);
      $('#dv-word', actions).addEventListener('click', async () => download(await docBlob(), safeName(d.title) + '.doc'));
      if ($('#dv-tbl', actions)) {
        const tm = $('#dv-tbl-menu', actions);
        $('#dv-tbl', actions).addEventListener('click', (e) => { e.stopPropagation(); tm.classList.toggle('hide'); });
        document.addEventListener('click', () => tm.classList.add('hide'));
        $$('[data-t]', tm).forEach((b) => b.addEventListener('click', () => {
          editor.focus();
          const fn = { insert: () => table.insertTable(3, 3), row: () => table.insertRowBelow(), col: () => table.insertColumnRight(),
            delrow: () => table.deleteRow(), delcol: () => table.deleteColumn(), deltable: () => table.deleteTable() }[b.dataset.t];
          try { fn(); } catch { toast('Put the cursor inside a table first.', true); }
        }));
      }
      $$('[data-m]', m).forEach((b) => b.addEventListener('click', () => {
        const k = b.dataset.m;
        if (k === 'print') {
          const w = window.open('', '_blank');
          if (!w) { toast('Allow pop-ups to print.', true); return; }
          w.document.write(wordHtml(d.title, `<h1 style="font-size:18pt;margin:0 0 12px">${esc(d.title)}</h1>${html()}`));
          w.document.close();
          w.focus();
          setTimeout(() => w.print(), 300);
        } else if (k === 'google') {
          sendToGoogle(b, async () => new Blob([wordHtml(d.title, html())], { type: 'text/html' }), safeName(d.title) + '.html');
        } else onCommon(k, b);
      }));
    }
    if (d.can_edit) setState('<i class="ti ti-cloud-check"></i> Saved');
  }

  App.route('docs', (ctx) => docsView(ctx), { title: 'Sheets & docs', live: true });
  App.route('spreadsheets', (ctx) => docsView(ctx, true), { title: 'Spreadsheet', live: true });
  App.route('docs/:id', docView, { title: 'Sheets & docs', nav: 'docs' });
})();
