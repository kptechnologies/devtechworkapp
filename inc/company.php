<?php
/**
 * Company wallet: the company's own money for contract work (inverters, CCTV, procurement, interactive boards…).
 * kind:   topup (money put in) | expense (money spent) | income (client payment received) | adjust (± correction)
 * effect: signed amount applied to the balance. Voided rows keep their history but stop counting.
 * Projects group expenses and income per contract; contractors are contract staff (records only, no login).
 */

const CTXN_KINDS = ['topup', 'expense', 'income', 'adjust'];
const CTXN_METHODS = ['Cash', 'Transfer', 'POS', 'Cheque', 'Other'];
const PROJECT_STATUSES = ['active', 'completed', 'cancelled'];

function company_categories(): array
{
    return array_values(setting('company_expense_categories') ?: ['Inverter installation', 'CCTV installation', 'Procurement',
        'Interactive board installation', 'Transport', 'Fuel / diesel', 'Materials', 'Labour', 'Office', 'Other']);
}

function project_categories(): array
{
    return array_values(setting('project_categories') ?: ['Inverter installation', 'CCTV installation', 'Procurement',
        'Interactive board installation', 'Networking', 'Other']);
}

function company_stats(): array
{
    $t = today();
    $w = date('Y-m-d', strtotime('monday this week'));
    $m = date('Y-m-01');
    $r = q("SELECT
            COALESCE(SUM(effect),0) AS balance,
            COALESCE(SUM(CASE WHEN kind='expense' AND txn_date = ? THEN amount END),0) AS spent_today,
            COALESCE(SUM(CASE WHEN kind='expense' AND txn_date >= ? THEN amount END),0) AS spent_week,
            COALESCE(SUM(CASE WHEN kind='expense' AND txn_date >= ? THEN amount END),0) AS spent_month,
            COALESCE(SUM(CASE WHEN kind='topup' AND txn_date >= ? THEN amount END),0) AS topup_month,
            COALESCE(SUM(CASE WHEN kind='income' AND txn_date >= ? THEN amount END),0) AS income_month
        FROM company_txns WHERE voided_at IS NULL", [$t, $w, $m, $m, $m])->fetch();
    return array_map(fn($v) => round((float)$v, 2), $r);
}

/** Spent, income and profit for each project id. */
function project_totals(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (q("SELECT project_id,
            COALESCE(SUM(CASE WHEN kind='expense' THEN amount END),0) AS spent,
            COALESCE(SUM(CASE WHEN kind='income' THEN amount END),0) AS income,
            COUNT(*) AS n
        FROM company_txns WHERE voided_at IS NULL AND project_id IN ($in) GROUP BY project_id", $ids)->fetchAll() as $r) {
        $out[(int)$r['project_id']] = ['spent' => round((float)$r['spent'], 2), 'income' => round((float)$r['income'], 2), 'n' => (int)$r['n']];
    }
    return $out;
}

function project_row(array $p, array $totals): array
{
    $p['id'] = (int)$p['id'];
    $t = $totals[$p['id']] ?? ['spent' => 0.0, 'income' => 0.0, 'n' => 0];
    $p['spent'] = $t['spent'];
    $p['income'] = $t['income'];
    $p['txns'] = $t['n'];
    $p['contract_value'] = (float)$p['contract_value'];
    $p['budget'] = (float)$p['budget'];
    // Profit against the contract value; falls back to money actually received when no value is set.
    $p['profit'] = round(($p['contract_value'] ?: $p['income']) - $p['spent'], 2);
    $p['outstanding'] = $p['contract_value'] ? round(max(0, $p['contract_value'] - $p['income']), 2) : 0;
    $p['budget_used'] = $p['budget'] > 0 ? (int)round($p['spent'] / $p['budget'] * 100) : null;
    return $p;
}

const PROJECT_SQL = 'SELECT p.*, c.name AS client_name, j.title AS job_title FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id LEFT JOIN jobs j ON j.id = p.job_id';

const CTXN_COLS = 'x.*, p.name AS project_name, ct.name AS contractor_name, j.title AS job_title, u.name AS created_by_name, v.name AS voided_by_name';
const CTXN_JOINS = 'FROM company_txns x LEFT JOIN projects p ON p.id = x.project_id LEFT JOIN contractors ct ON ct.id = x.contractor_id
    LEFT JOIN jobs j ON j.id = x.job_id LEFT JOIN users u ON u.id = x.created_by LEFT JOIN users v ON v.id = x.voided_by';

function company_where(array $in, array &$params): string
{
    $w = ['1=1'];
    foreach (['kind' => 'x.kind = ?', 'category' => 'x.category = ?', 'project_id' => 'x.project_id = ?', 'contractor_id' => 'x.contractor_id = ?'] as $k => $sql) {
        if (!empty($in[$k])) {
            $w[] = $sql;
            $params[] = $in[$k];
        }
    }
    if (empty($in['voided'])) {
        $w[] = 'x.voided_at IS NULL';
    }
    if (valid_date($in['from'] ?? null)) {
        $w[] = 'x.txn_date >= ?';
        $params[] = $in['from'];
    }
    if (valid_date($in['to'] ?? null)) {
        $w[] = 'x.txn_date <= ?';
        $params[] = $in['to'];
    }
    if (!empty($in['q'])) {
        $w[] = '(x.description LIKE ? OR x.payee LIKE ? OR x.category LIKE ? OR p.name LIKE ? OR ct.name LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    return implode(' AND ', $w);
}

function act_company_wallet(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = company_where($in, $params);
    $rows = q('SELECT ' . CTXN_COLS . ' ' . CTXN_JOINS . " WHERE $where ORDER BY x.txn_date DESC, x.id DESC LIMIT 2000", $params)->fetchAll();
    $tot = q("SELECT
            COALESCE(SUM(CASE WHEN x.kind='topup' AND x.voided_at IS NULL THEN x.amount END),0) AS topup,
            COALESCE(SUM(CASE WHEN x.kind='expense' AND x.voided_at IS NULL THEN x.amount END),0) AS spent,
            COALESCE(SUM(CASE WHEN x.kind='income' AND x.voided_at IS NULL THEN x.amount END),0) AS income,
            COALESCE(SUM(CASE WHEN x.kind='adjust' AND x.voided_at IS NULL THEN x.effect END),0) AS adjust
        FROM company_txns x LEFT JOIN projects p ON p.id = x.project_id LEFT JOIN contractors ct ON ct.id = x.contractor_id WHERE $where", $params)->fetch();
    $cats = q("SELECT category, SUM(amount) AS total FROM company_txns WHERE kind = 'expense' AND voided_at IS NULL AND txn_date >= ?
        GROUP BY category ORDER BY total DESC", [date('Y-m-01')])->fetchAll();
    json_out([
        'ok' => true,
        'stats' => company_stats(),
        'items' => with_file_counts($rows, 'ctxn'),
        'totals' => array_map('floatval', $tot),
        'month_categories' => $cats,
    ]);
}

function load_ctxn(int $id): array
{
    $t = q('SELECT ' . CTXN_COLS . ' ' . CTXN_JOINS . ' WHERE x.id = ?', [$id])->fetch();
    if (!$t) {
        fail('Transaction not found.', 404);
    }
    return $t;
}

function act_company_txn_get(array $in, array $me): void
{
    require_admin();
    $t = load_ctxn((int)($in['id'] ?? 0));
    $t['attachments'] = attachments_for('ctxn', [$t['id']])[(int)$t['id']] ?? [];
    $t['history'] = q("SELECT a.action, a.details, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                       WHERE a.entity = 'ctxn' AND a.entity_id = ? ORDER BY a.id", [$t['id']])->fetchAll();
    json_out(['ok' => true, 'txn' => $t]);
}

function act_company_txn_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? load_ctxn($id) : null;
    if ($existing && $existing['voided_at']) {
        fail('This entry was voided, so it can\'t be changed.');
    }
    $kind = $existing ? $existing['kind'] : (string)($in['kind'] ?? '');
    if (!in_array($kind, CTXN_KINDS, true)) {
        fail('Unknown transaction type.');
    }
    $raw = money_in($in['amount'] ?? 0);
    $amount = abs($raw);
    $date = (string)($in['txn_date'] ?? today());
    $category = str_in($in['category'] ?? '', 80);
    $desc = str_in($in['description'] ?? '', 2000);
    $projectId = (int)($in['project_id'] ?? 0) ?: null;
    $jobId = (int)($in['job_id'] ?? 0) ?: null;
    $contractorId = (int)($in['contractor_id'] ?? 0) ?: null;
    $method = in_array($in['method'] ?? '', CTXN_METHODS, true) ? $in['method'] : '';
    $errors = [];
    if ($kind === 'adjust' ? $raw == 0.0 : ($amount <= 0 || $amount > 1000000000)) {
        $errors['amount'] = $kind === 'adjust' ? 'Enter a positive or negative amount.' : 'Enter an amount above 0.';
    }
    if (!valid_date($date) || $date > date('Y-m-d', strtotime('+1 day'))) {
        $errors['txn_date'] = 'Pick a valid date (not in the future).';
    }
    if ($kind === 'expense' && $category === '') {
        $errors['category'] = 'Pick a category.';
    }
    if ($desc === '' && $kind !== 'topup') {
        $errors['description'] = $kind === 'expense' ? 'Say what the money was for.' : 'Add a short note.';
    }
    if ($projectId && !q('SELECT 1 FROM projects WHERE id = ?', [$projectId])->fetchColumn()) {
        $errors['project_id'] = 'That project no longer exists.';
    }
    if ($jobId && !q('SELECT 1 FROM jobs WHERE id = ?', [$jobId])->fetchColumn()) {
        $jobId = null;
    }
    if ($contractorId && !q('SELECT 1 FROM contractors WHERE id = ?', [$contractorId])->fetchColumn()) {
        $contractorId = null;
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $existingFiles = $existing ? (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='ctxn' AND owner_id = ?", [$id])->fetchColumn() : 0;
    if ($err = check_uploads('files', $existingFiles)) {
        fail($err);
    }
    $effect = match ($kind) {
        'topup', 'income' => $amount,
        'expense' => -$amount,
        'adjust' => $raw,
    };
    $vals = [$amount, $effect, $category, $projectId, $jobId, $contractorId,
        str_in($in['payee'] ?? '', 190), $method, $desc, $date];

    db()->beginTransaction();
    if ($existing) {
        q('UPDATE company_txns SET amount = ?, effect = ?, category = ?, project_id = ?, job_id = ?, contractor_id = ?, payee = ?, method = ?,
            description = ?, txn_date = ?, updated_at = NOW() WHERE id = ?', array_merge($vals, [$id]));
        audit('ctxn_update', 'ctxn', $id, ['amount' => $amount, 'was' => (float)$existing['amount']]);
    } else {
        q('INSERT INTO company_txns (amount, effect, category, project_id, job_id, contractor_id, payee, method, description, txn_date, kind, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())', array_merge($vals, [$kind, $me['id']]));
        $id = (int)db()->lastInsertId();
        audit('ctxn_create', 'ctxn', $id, ['kind' => $kind, 'amount' => $amount, 'category' => $category]);
    }
    save_uploads('ctxn', $id, $me['id']);
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'id' => $id, 'stats' => company_stats()]);
}

function act_company_txn_void(array $in, array $me): void
{
    require_admin();
    $t = load_ctxn((int)($in['id'] ?? 0));
    if ($t['voided_at']) {
        fail('This entry is already voided.');
    }
    $reason = str_in($in['reason'] ?? '', 500);
    if ($reason === '') {
        fail('Give a reason for voiding.');
    }
    q('UPDATE company_txns SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?', [$me['id'], $reason, $t['id']]);
    audit('ctxn_void', 'ctxn', (int)$t['id'], ['note' => $reason, 'amount' => (float)$t['amount']]);
    touch_change();
    json_out(['ok' => true, 'stats' => company_stats()]);
}

function act_company_export(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = company_where($in, $params);
    $rows = q('SELECT ' . CTXN_COLS . ' ' . CTXN_JOINS . " WHERE $where ORDER BY x.txn_date, x.id", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="company-wallet-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Date', 'Type', 'Category', 'Project', 'Job', 'Contractor', 'Payee', 'Method', 'Description', 'Amount', 'Balance effect', 'Voided', 'Void reason', 'Entered by', 'Created']);
    while ($r = $rows->fetch()) {
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), [$r['id'], $r['txn_date'], $r['kind'], $r['category'], $r['project_name'],
            $r['job_id'] ? job_ref((int)$r['job_id']) . ' ' . $r['job_title'] : '', $r['contractor_name'], $r['payee'], $r['method'],
            $r['description'], $r['amount'], $r['voided_at'] ? 0 : $r['effect'], $r['voided_at'], $r['void_reason'], $r['created_by_name'], $r['created_at']]));
    }
    exit;
}

/* ---------------------------------------------------------------- projects */

function act_projects_list(array $in, array $me): void
{
    require_admin();
    $w = '1=1';
    $p = [];
    if (in_array($in['status'] ?? '', PROJECT_STATUSES, true)) {
        $w .= ' AND p.status = ?';
        $p[] = $in['status'];
    }
    if (!empty($in['category'])) {
        $w .= ' AND p.category = ?';
        $p[] = $in['category'];
    }
    $rows = q(PROJECT_SQL . " WHERE $w ORDER BY p.status = 'active' DESC, p.id DESC LIMIT 500", $p)->fetchAll();
    $totals = project_totals(array_column($rows, 'id'));
    json_out(['ok' => true, 'items' => array_map(fn($r) => project_row($r, $totals), $rows)]);
}

function act_project_get(array $in, array $me): void
{
    require_admin();
    $p = q(PROJECT_SQL . ' WHERE p.id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$p) {
        fail('Project not found.', 404);
    }
    $p = project_row($p, project_totals([$p['id']]));
    $p['attachments'] = attachments_for('project', [$p['id']])[$p['id']] ?? [];
    $txns = q('SELECT ' . CTXN_COLS . ' ' . CTXN_JOINS . ' WHERE x.project_id = ? ORDER BY x.txn_date DESC, x.id DESC', [$p['id']])->fetchAll();
    $cats = q("SELECT category, SUM(amount) AS total FROM company_txns WHERE project_id = ? AND kind = 'expense' AND voided_at IS NULL
        GROUP BY category ORDER BY total DESC", [$p['id']])->fetchAll();
    json_out(['ok' => true, 'project' => $p, 'txns' => with_file_counts($txns, 'ctxn'), 'categories' => $cats]);
}

function act_project_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    if ($id && !q('SELECT 1 FROM projects WHERE id = ?', [$id])->fetchColumn()) {
        fail('Project not found.', 404);
    }
    $name = str_in($in['name'] ?? '', 190);
    $status = in_array($in['status'] ?? '', PROJECT_STATUSES, true) ? $in['status'] : 'active';
    $clientId = (int)($in['client_id'] ?? 0) ?: null;
    $jobId = (int)($in['job_id'] ?? 0) ?: null;
    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Give the project a name, e.g. "CCTV – Greenfield School".';
    }
    if ($clientId && !q('SELECT 1 FROM clients WHERE id = ?', [$clientId])->fetchColumn()) {
        $errors['client_id'] = 'That client no longer exists.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    if ($jobId && !q('SELECT 1 FROM jobs WHERE id = ?', [$jobId])->fetchColumn()) {
        $jobId = null;
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }
    $vals = [$name, str_in($in['category'] ?? '', 80), $clientId, $jobId, max(0, money_in($in['contract_value'] ?? 0)), max(0, money_in($in['budget'] ?? 0)),
        $status, valid_date($in['start_date'] ?? null) ? $in['start_date'] : null, valid_date($in['end_date'] ?? null) ? $in['end_date'] : null,
        str_in($in['notes'] ?? '', 4000)];
    if ($id) {
        q('UPDATE projects SET name = ?, category = ?, client_id = ?, job_id = ?, contract_value = ?, budget = ?, status = ?, start_date = ?, end_date = ?, notes = ? WHERE id = ?',
            array_merge($vals, [$id]));
        audit('project_update', 'project', $id, ['name' => $name, 'status' => $status]);
    } else {
        q('INSERT INTO projects (name, category, client_id, job_id, contract_value, budget, status, start_date, end_date, notes, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())', array_merge($vals, [$me['id']]));
        $id = (int)db()->lastInsertId();
        audit('project_create', 'project', $id, ['name' => $name]);
    }
    save_uploads('project', $id, $me['id']);
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

/* ------------------------------------------------------------- contractors */

function load_contractor(int $id): array
{
    $c = q('SELECT * FROM contractors WHERE id = ?', [$id])->fetch();
    if (!$c) {
        fail('Contractor not found.', 404);
    }
    $c['id'] = (int)$c['id'];
    return $c;
}

function act_contractors_list(array $in, array $me): void
{
    require_admin();
    $w = empty($in['all']) ? 'c.active = 1' : '1=1';
    $rows = q("SELECT c.*,
            (SELECT COALESCE(SUM(x.amount),0) FROM company_txns x WHERE x.contractor_id = c.id AND x.kind = 'expense' AND x.voided_at IS NULL) AS paid,
            (SELECT COUNT(*) FROM staff_queries s WHERE s.contractor_id = c.id) AS queries,
            (SELECT COUNT(*) FROM staff_queries s WHERE s.contractor_id = c.id AND s.status IN ('open','replied')) AS open_queries
        FROM contractors c WHERE $w ORDER BY c.active DESC, c.name")->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['paid'] = (float)$r['paid'];
        $r['queries'] = (int)$r['queries'];
        $r['open_queries'] = (int)$r['open_queries'];
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function act_contractor_get(array $in, array $me): void
{
    require_admin();
    $c = load_contractor((int)($in['id'] ?? 0));
    $txns = q('SELECT ' . CTXN_COLS . ' ' . CTXN_JOINS . ' WHERE x.contractor_id = ? ORDER BY x.txn_date DESC, x.id DESC LIMIT 300', [$c['id']])->fetchAll();
    $paid = (float)q("SELECT COALESCE(SUM(amount),0) FROM company_txns WHERE contractor_id = ? AND kind = 'expense' AND voided_at IS NULL", [$c['id']])->fetchColumn();
    json_out(['ok' => true, 'contractor' => $c, 'paid' => $paid, 'txns' => with_file_counts($txns, 'ctxn'), 'queries' => person_queries(null, $c['id'])]);
}

function act_contractor_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        load_contractor($id);
    }
    $name = str_in($in['name'] ?? '', 120);
    if ($name === '') {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['name' => 'Enter their name.']], 422);
    }
    $vals = [$name, str_in($in['phone'] ?? '', 40), str_in($in['trade'] ?? '', 120), str_in($in['bank_name'] ?? '', 80),
        preg_replace('/[^0-9]/', '', str_in($in['bank_account'] ?? '', 40)), str_in($in['rate_note'] ?? '', 190), str_in($in['notes'] ?? '', 2000),
        array_key_exists('active', $in) ? (int)!empty($in['active']) : 1];
    if ($id) {
        q('UPDATE contractors SET name = ?, phone = ?, trade = ?, bank_name = ?, bank_account = ?, rate_note = ?, notes = ?, active = ? WHERE id = ?', array_merge($vals, [$id]));
        audit('contractor_update', 'contractor', $id, ['name' => $name]);
    } else {
        q('INSERT INTO contractors (name, phone, trade, bank_name, bank_account, rate_note, notes, active, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())', $vals);
        $id = (int)db()->lastInsertId();
        audit('contractor_create', 'contractor', $id, ['name' => $name]);
    }
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}
