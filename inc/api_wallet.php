<?php
/* Wallet: payments, expenses, adjustments, reviews and funding requests */

const TXN_COLS = 'w.id, w.user_id, u.name AS user_name, w.kind, w.category, w.amount, w.effect, w.description, w.route, w.txn_date,
    w.status, w.review_note, w.staff_reply, w.reviewed_at, w.request_id, w.report_id, w.source, w.created_at, w.updated_at,
    c.name AS created_by_name, rv.name AS reviewed_by_name';
const TXN_JOINS = 'FROM wallet_txns w JOIN users u ON u.id = w.user_id
    LEFT JOIN users c ON c.id = w.created_by LEFT JOIN users rv ON rv.id = w.reviewed_by';

function money_fmt(float $n): string
{
    return cfg('currency', '₦') . number_format($n, 2);
}

function with_file_counts(array $rows, string $type = 'txn'): array
{
    $files = attachments_for($type, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['files'] = count($files[(int)$r['id']] ?? []);
    }
    return $rows;
}

function staff_user(int $id): array
{
    $u = q('SELECT id, name, email, phone, role, location, active FROM users WHERE id = ?', [$id])->fetch();
    if (!$u) {
        fail('Staff member not found.', 404);
    }
    $u['id'] = (int)$u['id'];
    return $u;
}

function act_home(array $in, array $me): void
{
    $todayReport = q('SELECT id FROM reports WHERE user_id = ? AND report_date = ? ORDER BY id DESC LIMIT 1', [$me['id'], today()])->fetchColumn();
    $txns = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . ' WHERE w.user_id = ? ORDER BY w.txn_date DESC, w.id DESC LIMIT 8', [$me['id']])->fetchAll();
    $reports = q('SELECT id, report_date, location, work_type, finished FROM reports WHERE user_id = ? ORDER BY report_date DESC, id DESC LIMIT 5', [$me['id']])->fetchAll();
    $requests = q("SELECT id, amount, purpose, status, created_at FROM fund_requests WHERE user_id = ? ORDER BY id DESC LIMIT 5", [$me['id']])->fetchAll();
    json_out([
        'ok' => true,
        'wallet' => wallet_stats($me['id']),
        'today_report' => $todayReport ? (int)$todayReport : null,
        'txns' => with_file_counts($txns),
        'reports' => $reports,
        'requests' => $requests,
    ]);
}

function act_wallet(array $in, array $me): void
{
    $uid = is_admin($me) && !empty($in['user_id']) ? (int)$in['user_id'] : $me['id'];
    $user = staff_user($uid);
    $params = [$uid];
    $w = 'w.user_id = ?';
    if (valid_date($in['from'] ?? null)) {
        $w .= ' AND w.txn_date >= ?';
        $params[] = $in['from'];
    }
    if (valid_date($in['to'] ?? null)) {
        $w .= ' AND w.txn_date <= ?';
        $params[] = $in['to'];
    }
    if (in_array($in['kind'] ?? '', ['credit', 'debit', 'adjust'], true)) {
        $w .= ' AND w.kind = ?';
        $params[] = $in['kind'];
    }
    $txns = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . " WHERE $w ORDER BY w.txn_date DESC, w.id DESC LIMIT 300", $params)->fetchAll();
    $requests = q('SELECT id, amount, purpose, needed_by, status, admin_note, created_at, decided_at FROM fund_requests WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$uid])->fetchAll();
    json_out(['ok' => true, 'user' => $user, 'wallet' => wallet_stats($uid), 'txns' => with_file_counts($txns), 'requests' => with_file_counts($requests, 'request')]);
}

function load_txn(int $id, array $me): array
{
    $t = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . ' WHERE w.id = ?', [$id])->fetch();
    if (!$t) {
        fail('Transaction not found.', 404);
    }
    if (!is_admin($me) && (int)$t['user_id'] !== $me['id']) {
        fail('You can only view your own transactions.', 403);
    }
    return $t;
}

function act_txn_get(array $in, array $me): void
{
    $t = load_txn((int)($in['id'] ?? 0), $me);
    $t['attachments'] = attachments_for('txn', [$t['id']])[(int)$t['id']] ?? [];
    if ($t['report_id']) {
        $t['report'] = q('SELECT id, report_date, location FROM reports WHERE id = ?', [$t['report_id']])->fetch() ?: null;
    }
    if ($t['request_id']) {
        $t['request'] = q('SELECT id, purpose, amount FROM fund_requests WHERE id = ?', [$t['request_id']])->fetch() ?: null;
        $t['request_attachments'] = attachments_for('request', [$t['request_id']])[(int)$t['request_id']] ?? [];
    }
    $t['history'] = q("SELECT a.action, a.details, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                       WHERE a.entity = 'txn' AND a.entity_id = ? ORDER BY a.id", [$t['id']])->fetchAll();
    json_out(['ok' => true, 'txn' => $t]);
}

/* ---------------------------------------------------------------- expenses */

function act_expense_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? load_txn($id, $me) : null;
    if ($existing) {
        if ($existing['kind'] !== 'debit') {
            fail('Only expenses can be edited here.');
        }
        if (!is_admin($me) && !in_array($existing['status'], ['posted', 'queried'], true)) {
            fail('This expense was already reviewed, so it can\'t be changed.', 403);
        }
    }
    $amount = money_in($in['amount'] ?? 0);
    $date = (string)($in['txn_date'] ?? today());
    $category = str_in($in['category'] ?? '', 80);
    $desc = str_in($in['description'] ?? '', 2000);
    $errors = [];
    if ($amount <= 0 || $amount > 100000000) {
        $errors['amount'] = 'Enter an amount above 0.';
    }
    if (!valid_date($date) || $date > date('Y-m-d', strtotime('+1 day'))) {
        $errors['txn_date'] = 'Pick a valid date (not in the future).';
    }
    $cats = setting('expense_categories') ?: [];
    if ($category === '' || (!in_array($category, $cats, true) && $category !== ($existing['category'] ?? null))) {
        $errors['category'] = 'Pick a category.';
    }
    if ($desc === '') {
        $errors['description'] = 'Say what the money was for.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $existingFiles = $existing ? (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='txn' AND owner_id = ?", [$id])->fetchColumn() : 0;
    if ($err = check_uploads('files', $existingFiles)) {
        fail($err);
    }
    $reportId = (int)($in['report_id'] ?? 0) ?: null;
    $ownerId = $existing ? (int)$existing['user_id'] : $me['id'];
    if ($reportId && !q('SELECT 1 FROM reports WHERE id = ? AND user_id = ?', [$reportId, $ownerId])->fetchColumn()) {
        $reportId = null;
    }

    db()->beginTransaction();
    if ($existing) {
        $status = $existing['status'] === 'queried' && !is_admin($me) ? 'posted' : $existing['status'];
        q('UPDATE wallet_txns SET amount = ?, effect = ?, category = ?, description = ?, route = ?, txn_date = ?, report_id = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$amount, -$amount, $category, $desc, str_in($in['route'] ?? '', 190), $date, $reportId, $status, $id]);
        audit('expense_update', 'txn', $id, ['amount' => $amount, 'was' => (float)$existing['amount']]);
    } else {
        $id = wallet_add([
            'user_id' => $me['id'], 'kind' => 'debit', 'category' => $category, 'amount' => $amount,
            'description' => $desc, 'route' => str_in($in['route'] ?? '', 190), 'txn_date' => $date,
            'report_id' => $reportId, 'created_by' => $me['id'],
        ]);
        audit('expense_create', 'txn', $id, ['amount' => $amount, 'category' => $category]);
    }
    save_uploads('txn', $id, $me['id']);
    db()->commit();
    touch_change();

    if (!is_admin($me)) {
        notify_admins('expense', ($existing ? 'Updated expense: ' : 'New expense: ') . $me['name'] . ' · ' . money_fmt($amount),
            $category . ' — ' . $desc, 'txn/' . $id, false);
    }
    json_out(['ok' => true, 'id' => $id, 'wallet' => wallet_stats($ownerId)]);
}

function act_expense_reply(array $in, array $me): void
{
    $t = load_txn((int)($in['id'] ?? 0), $me);
    if ((int)$t['user_id'] !== $me['id'] || $t['status'] !== 'queried') {
        fail('Only queried expenses can be answered.');
    }
    $reply = str_in($in['reply'] ?? '', 2000);
    if ($reply === '') {
        fail('Write a reply.');
    }
    $existingFiles = (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='txn' AND owner_id = ?", [$t['id']])->fetchColumn();
    if ($err = check_uploads('files', $existingFiles)) {
        fail($err);
    }
    q("UPDATE wallet_txns SET staff_reply = ?, status = 'posted', updated_at = NOW() WHERE id = ?", [$reply, $t['id']]);
    save_uploads('txn', (int)$t['id'], $me['id']);
    audit('expense_reply', 'txn', (int)$t['id'], ['reply' => $reply]);
    touch_change();
    notify_admins('expense', $me['name'] . ' answered your query · ' . money_fmt((float)$t['amount']), $reply, 'txn/' . $t['id'], false);
    json_out(['ok' => true]);
}

function act_expense_delete(array $in, array $me): void
{
    $t = load_txn((int)($in['id'] ?? 0), $me);
    if (!is_admin($me) && ($t['kind'] !== 'debit' || !in_array($t['status'], ['posted', 'queried'], true))) {
        fail('Only unreviewed expenses can be deleted.', 403);
    }
    delete_txn($t);
    json_out(['ok' => true, 'wallet' => wallet_stats((int)$t['user_id'])]);
}

function delete_txn(array $t): void
{
    db()->beginTransaction();
    delete_attachments_of('txn', (int)$t['id']);
    q('UPDATE fund_requests SET txn_id = NULL WHERE txn_id = ?', [$t['id']]);
    q('DELETE FROM wallet_txns WHERE id = ?', [$t['id']]);
    audit('txn_delete', 'txn', (int)$t['id'], ['user' => $t['user_name'], 'kind' => $t['kind'], 'amount' => (float)$t['amount'], 'desc' => $t['description']]);
    db()->commit();
    touch_change();
}

function act_txn_delete(array $in, array $me): void
{
    require_admin();
    $t = load_txn((int)($in['id'] ?? 0), $me);
    delete_txn($t);
    json_out(['ok' => true]);
}

/* -------------------------------------------------------------- admin money */

function act_credit_send(array $in, array $me): void
{
    require_admin();
    $ids = array_values(array_unique(array_map('intval', (array)($in['user_ids'] ?? []))));
    if (!empty($in['all'])) {
        $ids = array_map('intval', q("SELECT id FROM users WHERE role = 'staff' AND active = 1")->fetchAll(PDO::FETCH_COLUMN));
    }
    $amount = money_in($in['amount'] ?? 0);
    $date = (string)($in['txn_date'] ?? today());
    $type = str_in($in['category'] ?? '', 80) ?: 'Daily allowance';
    $desc = str_in($in['description'] ?? '', 2000);
    if (!$ids) {
        fail('Pick at least one staff member.');
    }
    if ($amount <= 0) {
        fail('Enter an amount above 0.');
    }
    if (!valid_date($date)) {
        fail('Pick a valid date.');
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }
    $in_ = implode(',', array_fill(0, count($ids), '?'));
    $valid = array_map('intval', q("SELECT id FROM users WHERE active = 1 AND id IN ($in_)", $ids)->fetchAll(PDO::FETCH_COLUMN));

    db()->beginTransaction();
    $txnIds = [];
    foreach ($valid as $uid) {
        $txnIds[$uid] = wallet_add([
            'user_id' => $uid, 'kind' => 'credit', 'category' => $type, 'amount' => $amount,
            'description' => $desc, 'txn_date' => $date, 'created_by' => $me['id'],
        ]);
        audit('credit_send', 'txn', $txnIds[$uid], ['amount' => $amount, 'type' => $type]);
    }
    // One upload, shared by every recipient's transaction.
    if ($txnIds) {
        $first = reset($txnIds);
        $saved = save_uploads('txn', $first, $me['id']);
        foreach (array_slice($txnIds, 1, null, true) as $tid) {
            foreach ($saved as $aid) {
                q("INSERT INTO attachments (owner_type, owner_id, user_id, stored_name, thumb_name, original_name, mime, size, created_at)
                   SELECT 'txn', ?, user_id, stored_name, thumb_name, original_name, mime, size, NOW() FROM attachments WHERE id = ?", [$tid, $aid]);
            }
        }
    }
    db()->commit();
    touch_change();
    foreach ($txnIds as $uid => $tid) {
        notify([$uid], 'credit', money_fmt($amount) . ' ' . strtolower($type) . ' received',
            ($desc ? $desc . "\n" : '') . 'New balance: ' . money_fmt(wallet_balance($uid)), 'txn/' . $tid);
    }
    json_out(['ok' => true, 'count' => count($txnIds)]);
}

function act_adjust(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $user = staff_user($uid);
    $amount = money_in($in['amount'] ?? 0);
    $desc = str_in($in['description'] ?? '', 2000);
    $date = (string)($in['txn_date'] ?? today());
    if ($amount == 0.0) {
        fail('Enter a positive or negative amount.');
    }
    if ($desc === '') {
        fail('Give a reason for the adjustment.');
    }
    if (!valid_date($date)) {
        fail('Pick a valid date.');
    }
    $id = wallet_add([
        'user_id' => $uid, 'kind' => 'adjust', 'category' => 'Adjustment', 'amount' => $amount,
        'description' => $desc, 'txn_date' => $date, 'created_by' => $me['id'],
    ]);
    audit('adjust', 'txn', $id, ['amount' => $amount, 'reason' => $desc]);
    touch_change();
    notify([$uid], 'adjust', 'Balance adjusted by ' . ($amount > 0 ? '+' : '-') . money_fmt(abs($amount)),
        $desc . "\nNew balance: " . money_fmt(wallet_balance($uid)), 'txn/' . $id);
    json_out(['ok' => true, 'id' => $id, 'user' => $user['name']]);
}

function act_txn_review(array $in, array $me): void
{
    require_admin();
    $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? [$in['id'] ?? 0]))));
    $status = (string)($in['status'] ?? '');
    $note = str_in($in['note'] ?? '', 2000);
    if (!in_array($status, ['approved', 'queried', 'rejected', 'posted'], true)) {
        fail('Unknown review status.');
    }
    if (in_array($status, ['queried', 'rejected'], true) && $note === '') {
        fail($status === 'queried' ? 'Write the question for the staff member.' : 'Give a reason for rejecting.');
    }
    $done = 0;
    foreach ($ids as $id) {
        $t = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . ' WHERE w.id = ?', [$id])->fetch();
        if (!$t || $t['kind'] !== 'debit') {
            continue;
        }
        q('UPDATE wallet_txns SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, $note !== '' ? $note : $t['review_note'], $me['id'], $id]);
        audit('review_' . $status, 'txn', $id, ['note' => $note, 'from' => $t['status']]);
        $done++;
        $amt = money_fmt((float)$t['amount']);
        $msg = match ($status) {
            'approved' => ["Expense approved · $amt", $t['category'], false],
            'queried'  => ["Question on your expense · $amt", $note, true],
            'rejected' => ["Expense rejected · $amt (refunded to balance)", $note, true],
            default    => ["Expense moved back to pending · $amt", $note, false],
        };
        notify([(int)$t['user_id']], 'review', $msg[0], $msg[1], 'txn/' . $id, $msg[2]);
    }
    touch_change();
    json_out(['ok' => true, 'count' => $done]);
}

function ledger_where(array $in, array &$params): string
{
    $w = ['1=1'];
    foreach (['user_id' => 'w.user_id = ?', 'kind' => 'w.kind = ?', 'category' => 'w.category = ?'] as $k => $sql) {
        if (!empty($in[$k])) {
            $w[] = $sql;
            $params[] = $in[$k];
        }
    }
    if (!empty($in['status'])) {
        if ($in['status'] === 'review') {
            $w[] = "w.kind = 'debit' AND w.status IN ('posted','queried')";
        } else {
            $w[] = 'w.status = ?';
            $params[] = $in['status'];
        }
    }
    if (valid_date($in['from'] ?? null)) {
        $w[] = 'w.txn_date >= ?';
        $params[] = $in['from'];
    }
    if (valid_date($in['to'] ?? null)) {
        $w[] = 'w.txn_date <= ?';
        $params[] = $in['to'];
    }
    if (!empty($in['q'])) {
        $w[] = '(w.description LIKE ? OR w.route LIKE ? OR u.name LIKE ? OR w.category LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($params, $like, $like, $like, $like);
    }
    return implode(' AND ', $w);
}

function act_ledger(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = ledger_where($in, $params);
    $rows = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . " WHERE $where ORDER BY w.txn_date DESC, w.id DESC LIMIT 2000", $params)->fetchAll();
    $tot = q("SELECT
              COALESCE(SUM(CASE WHEN w.kind='credit' AND w.status<>'rejected' THEN w.amount END),0) AS sent,
              COALESCE(SUM(CASE WHEN w.kind='debit' AND w.status<>'rejected' THEN w.amount END),0) AS spent,
              COALESCE(SUM(CASE WHEN w.kind='adjust' THEN w.effect END),0) AS adjust,
              COUNT(*) AS n
              FROM wallet_txns w JOIN users u ON u.id = w.user_id WHERE $where", $params)->fetch();
    json_out(['ok' => true, 'items' => with_file_counts($rows), 'totals' => array_map('floatval', $tot)]);
}

function act_ledger_export(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = ledger_where($in, $params);
    $rows = q('SELECT ' . TXN_COLS . ', u.email ' . TXN_JOINS . " WHERE $where ORDER BY w.txn_date, w.id", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wallet-ledger-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Date', 'Staff', 'Email', 'Type', 'Category', 'Amount', 'Balance effect', 'Description', 'Route', 'Status', 'Review note', 'Staff reply', 'Entered by', 'Reviewed by', 'Created']);
    while ($r = $rows->fetch()) {
        fputcsv($out, [$r['id'], $r['txn_date'], $r['user_name'], $r['email'], $r['kind'], csv_safe($r['category']), $r['amount'],
            $r['status'] === 'rejected' ? 0 : $r['effect'], csv_safe((string)$r['description']), csv_safe($r['route']), $r['status'],
            csv_safe((string)$r['review_note']), csv_safe((string)$r['staff_reply']), $r['created_by_name'], $r['reviewed_by_name'], $r['created_at']]);
    }
    exit;
}

function act_balances(array $in, array $me): void
{
    require_admin();
    json_out(['ok' => true, 'items' => wallet_all_balances()]);
}

/* -------------------------------------------------------- funding requests */

function act_requests_list(array $in, array $me): void
{
    $params = [];
    $w = '1=1';
    if (!is_admin($me)) {
        $w .= ' AND f.user_id = ?';
        $params[] = $me['id'];
    }
    if (!empty($in['status'])) {
        $w .= ' AND f.status = ?';
        $params[] = $in['status'];
    }
    $rows = q("SELECT f.*, u.name AS user_name, d.name AS decided_by_name FROM fund_requests f JOIN users u ON u.id = f.user_id
               LEFT JOIN users d ON d.id = f.decided_by WHERE $w ORDER BY (f.status = 'pending') DESC, f.id DESC LIMIT 300", $params)->fetchAll();
    $files = attachments_for('request', array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['attachments'] = $files[(int)$r['id']] ?? [];
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function act_request_save(array $in, array $me): void
{
    $amount = money_in($in['amount'] ?? 0);
    $purpose = str_in($in['purpose'] ?? '', 2000);
    $needed = (string)($in['needed_by'] ?? '');
    if ($amount <= 0) {
        fail('Enter an amount above 0.');
    }
    if ($purpose === '') {
        fail('Say what the money is for.');
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }
    q("INSERT INTO fund_requests (user_id, amount, purpose, needed_by, status, created_at) VALUES (?,?,?,?, 'pending', NOW())",
        [$me['id'], $amount, $purpose, valid_date($needed) ? $needed : null]);
    $id = (int)db()->lastInsertId();
    save_uploads('request', $id, $me['id']);
    audit('request_create', 'request', $id, ['amount' => $amount]);
    touch_change();
    notify_admins('request', 'Funding request: ' . $me['name'] . ' · ' . money_fmt($amount), $purpose, 'requests');
    json_out(['ok' => true, 'id' => $id]);
}

function act_request_cancel(array $in, array $me): void
{
    $r = q('SELECT * FROM fund_requests WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$r || ((int)$r['user_id'] !== $me['id'] && !is_admin($me))) {
        fail('Request not found.', 404);
    }
    if ($r['status'] !== 'pending') {
        fail('Only pending requests can be cancelled.');
    }
    q("UPDATE fund_requests SET status = 'cancelled', decided_at = NOW() WHERE id = ?", [$r['id']]);
    audit('request_cancel', 'request', (int)$r['id']);
    touch_change();
    json_out(['ok' => true]);
}

function act_request_decide(array $in, array $me): void
{
    require_admin();
    $r = q('SELECT f.*, u.name AS user_name FROM fund_requests f JOIN users u ON u.id = f.user_id WHERE f.id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$r) {
        fail('Request not found.', 404);
    }
    if ($r['status'] !== 'pending') {
        fail('This request was already decided.');
    }
    $note = str_in($in['note'] ?? '', 2000);
    if (empty($in['approve'])) {
        if ($note === '') {
            fail('Give a reason for declining.');
        }
        q("UPDATE fund_requests SET status = 'declined', admin_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [$note, $me['id'], $r['id']]);
        audit('request_decline', 'request', (int)$r['id'], ['note' => $note]);
        touch_change();
        notify([(int)$r['user_id']], 'request', 'Funding request declined · ' . money_fmt((float)$r['amount']), $note, 'requests');
        json_out(['ok' => true]);
    }
    $amount = money_in($in['amount'] ?? $r['amount']);
    if ($amount <= 0) {
        fail('Enter an amount above 0.');
    }
    db()->beginTransaction();
    $tid = wallet_add([
        'user_id' => (int)$r['user_id'], 'kind' => 'credit', 'category' => str_in($in['category'] ?? '', 80) ?: 'Work budget',
        'amount' => $amount, 'description' => 'Funding request: ' . $r['purpose'], 'txn_date' => today(),
        'request_id' => (int)$r['id'], 'created_by' => $me['id'],
    ]);
    q("UPDATE fund_requests SET status = 'approved', admin_note = ?, txn_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [$note, $tid, $me['id'], $r['id']]);
    audit('request_approve', 'request', (int)$r['id'], ['amount' => $amount, 'txn' => $tid]);
    db()->commit();
    touch_change();
    notify([(int)$r['user_id']], 'credit', 'Funding request approved · ' . money_fmt($amount) . ' sent',
        ($note ? $note . "\n" : '') . 'New balance: ' . money_fmt(wallet_balance((int)$r['user_id'])), 'txn/' . $tid);
    json_out(['ok' => true, 'txn_id' => $tid]);
}
