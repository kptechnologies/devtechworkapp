<?php
/**
 * Staff queries: written notes on non-performance, tagged on a staff member's or contractor's account.
 * status: open (waiting for a reply) → replied → resolved | warning | withdrawn
 * Staff reply themselves; contractors have no login, so an admin records their reply.
 * A fine on a staff query becomes a payroll deduction when the query is closed with a warning or resolved.
 */

const QUERY_CATEGORIES = ['lateness' => 'Lateness', 'absence' => 'Absence', 'poor_work' => 'Poor work', 'job_failure' => 'Job order failure',
    'no_report' => 'No daily report', 'misconduct' => 'Misconduct', 'other' => 'Other'];
const QUERY_OUTCOMES = ['resolved', 'warning', 'withdrawn'];

function query_ref(int $id): string
{
    return 'QRY-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

const QUERY_SQL = "SELECT s.*, u.name AS user_name, ct.name AS contractor_name, cb.name AS created_by_name, rb.name AS replied_by_name,
    xb.name AS closed_by_name, j.title AS job_title
    FROM staff_queries s LEFT JOIN users u ON u.id = s.user_id LEFT JOIN contractors ct ON ct.id = s.contractor_id
    LEFT JOIN users cb ON cb.id = s.created_by LEFT JOIN users rb ON rb.id = s.replied_by LEFT JOIN users xb ON xb.id = s.closed_by
    LEFT JOIN jobs j ON j.id = s.job_id";

function query_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['ref'] = query_ref($r['id']);
    $r['fine'] = (float)$r['fine'];
    $r['person'] = $r['user_id'] ? $r['user_name'] : $r['contractor_name'];
    $r['person_type'] = $r['user_id'] ? 'staff' : 'contractor';
    $r['category_label'] = QUERY_CATEGORIES[$r['category']] ?? $r['category'];
    $r['overdue'] = $r['status'] === 'open' && $r['reply_by'] && $r['reply_by'] < today();
    // Fines are pay deductions, which only admins see.
    if (!is_admin()) {
        $r['fine'] = 0.0;
        $r['pay_adjustment_id'] = null;
    }
    return $r;
}

/** Query history for one staff member or contractor (profile tabs). */
function person_queries(?int $userId, ?int $contractorId): array
{
    $rows = $userId
        ? q(QUERY_SQL . ' WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 50', [$userId])->fetchAll()
        : q(QUERY_SQL . ' WHERE s.contractor_id = ? ORDER BY s.id DESC LIMIT 50', [$contractorId])->fetchAll();
    return array_map('query_row', $rows);
}

/** Pulse badge: staff see queries waiting for their reply; admins see replies waiting for a decision. */
function query_badge(array $me): int
{
    return is_manager($me)
        ? (int)q("SELECT COUNT(*) FROM staff_queries WHERE status = 'replied'")->fetchColumn()
        : (int)q("SELECT COUNT(*) FROM staff_queries WHERE user_id = ? AND status = 'open'", [$me['id']])->fetchColumn();
}

function load_query(int $id, array $me): array
{
    $r = q(QUERY_SQL . ' WHERE s.id = ?', [$id])->fetch();
    if (!$r) {
        fail('Query not found.', 404);
    }
    if (!is_manager($me) && (int)$r['user_id'] !== $me['id']) {
        fail('You can only view your own queries.', 403);
    }
    return query_row($r);
}

function act_queries_list(array $in, array $me): void
{
    $w = ['1=1'];
    $p = [];
    if (!is_manager($me)) {
        $w[] = 's.user_id = ?';
        $p[] = $me['id'];
    } else {
        if (!empty($in['user_id'])) {
            $w[] = 's.user_id = ?';
            $p[] = (int)$in['user_id'];
        }
        if (!empty($in['contractor_id'])) {
            $w[] = 's.contractor_id = ?';
            $p[] = (int)$in['contractor_id'];
        }
        if (($in['person_type'] ?? '') === 'staff') {
            $w[] = 's.user_id IS NOT NULL';
        } elseif (($in['person_type'] ?? '') === 'contractor') {
            $w[] = 's.contractor_id IS NOT NULL';
        }
    }
    $status = (string)($in['status'] ?? '');
    if ($status === 'pending') {
        $w[] = "s.status IN ('open','replied')";
    } elseif (in_array($status, array_merge(['open', 'replied'], QUERY_OUTCOMES), true)) {
        $w[] = 's.status = ?';
        $p[] = $status;
    }
    if (isset(QUERY_CATEGORIES[$in['category'] ?? ''])) {
        $w[] = 's.category = ?';
        $p[] = $in['category'];
    }
    $rows = q(QUERY_SQL . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(s.status,'replied','open') DESC, s.id DESC LIMIT 500", $p)->fetchAll();
    json_out(['ok' => true, 'items' => array_map('query_row', $rows), 'categories' => QUERY_CATEGORIES]);
}

function act_query_get(array $in, array $me): void
{
    $r = load_query((int)($in['id'] ?? 0), $me);
    $r['attachments'] = attachments_for('query', [$r['id']])[$r['id']] ?? [];
    $r['history'] = q("SELECT a.action, a.details, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                       WHERE a.entity = 'query' AND a.entity_id = ? ORDER BY a.id", [$r['id']])->fetchAll();
    if (!is_admin($me)) {
        foreach ($r['history'] as &$h) {
            $d = json_decode((string)$h['details'], true);
            if (is_array($d)) {
                unset($d['fine'], $d['month']);
                $h['details'] = json_encode($d);
            }
        }
        unset($h);
    }
    $r['counts'] = q('SELECT COUNT(*) AS total, SUM(status = \'warning\') AS warnings FROM staff_queries WHERE ' .
        ($r['user_id'] ? 'user_id = ?' : 'contractor_id = ?'), [$r['user_id'] ?: $r['contractor_id']])->fetch();
    json_out(['ok' => true, 'query' => $r, 'categories' => QUERY_CATEGORIES]);
}

function act_query_save(array $in, array $me): void
{
    require_manager();
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? load_query($id, $me) : null;
    if ($existing && !in_array($existing['status'], ['open', 'replied'], true)) {
        fail('This query is closed, so it can\'t be changed.');
    }
    $userId = $existing ? ((int)$existing['user_id'] ?: null) : ((int)($in['user_id'] ?? 0) ?: null);
    $contractorId = $existing ? ((int)$existing['contractor_id'] ?: null) : ((int)($in['contractor_id'] ?? 0) ?: null);
    $subject = str_in($in['subject'] ?? '', 190);
    $details = str_in($in['details'] ?? '', 4000);
    $category = isset(QUERY_CATEGORIES[$in['category'] ?? '']) ? $in['category'] : 'other';
    $date = (string)($in['query_date'] ?? today());
    $replyBy = valid_date($in['reply_by'] ?? null) ? $in['reply_by'] : null;
    $fine = max(0, money_in($in['fine'] ?? 0));
    if (!is_admin($me)) {
        // Fines are pay deductions: a sub admin's query keeps whatever fine the admin set, and can't add one.
        $fine = $existing ? (float)q('SELECT fine FROM staff_queries WHERE id = ?', [$id])->fetchColumn() : 0.0;
        if ($userId && q("SELECT role FROM users WHERE id = ?", [$userId])->fetchColumn() === 'admin') {
            fail('Only an admin can query an admin.', 403);
        }
    }
    $jobId = (int)($in['job_id'] ?? 0) ?: null;
    $errors = [];
    if (!$userId && !$contractorId) {
        $errors['person'] = 'Pick a staff member or contractor.';
    }
    if ($userId && !q('SELECT 1 FROM users WHERE id = ?', [$userId])->fetchColumn()) {
        $errors['person'] = 'Staff member not found.';
    }
    if ($contractorId && !q('SELECT 1 FROM contractors WHERE id = ?', [$contractorId])->fetchColumn()) {
        $errors['person'] = 'Contractor not found.';
    }
    if ($subject === '') {
        $errors['subject'] = 'Give the query a short subject.';
    }
    if ($details === '') {
        $errors['details'] = 'Explain what happened and what you expect.';
    }
    if (!valid_date($date)) {
        $errors['query_date'] = 'Pick a valid date.';
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
    if ($existing) {
        q('UPDATE staff_queries SET subject = ?, details = ?, category = ?, query_date = ?, reply_by = ?, fine = ?, job_id = ? WHERE id = ?',
            [$subject, $details, $category, $date, $replyBy, $fine, $jobId, $id]);
        audit('query_update', 'query', $id, ['subject' => $subject, 'fine' => $fine]);
    } else {
        q("INSERT INTO staff_queries (user_id, contractor_id, subject, details, category, job_id, query_date, reply_by, fine, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?, 'open', ?, NOW())", [$userId, $userId ? null : $contractorId, $subject, $details, $category, $jobId, $date, $replyBy, $fine, $me['id']]);
        $id = (int)db()->lastInsertId();
        audit('query_create', 'query', $id, ['subject' => $subject, 'fine' => $fine]);
        if ($userId) {
            notify([$userId], 'query', 'Query ' . query_ref($id) . ': ' . $subject,
                $details . ($replyBy ? "\nPlease reply by " . date('j M Y', strtotime($replyBy)) . '.' : "\nPlease reply in the portal."), 'queries/' . $id);
        }
    }
    save_uploads('query', $id, $me['id']);
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_query_reply(array $in, array $me): void
{
    $r = load_query((int)($in['id'] ?? 0), $me);
    $admin = is_admin($me);
    $own = (int)$r['user_id'] === $me['id'];
    // Staff answer their own query; an admin writes down a contractor's answer.
    if (!$own && !($admin && $r['contractor_id'])) {
        fail($admin ? 'Staff reply to their own queries.' : 'You can only reply to your own queries.', 403);
    }
    if (!in_array($r['status'], ['open', 'replied'], true)) {
        fail('This query is closed.');
    }
    $reply = str_in($in['reply'] ?? '', 4000);
    if ($reply === '') {
        fail('Write a reply.');
    }
    $existingFiles = (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='query' AND owner_id = ?", [$r['id']])->fetchColumn();
    if ($err = check_uploads('files', $existingFiles)) {
        fail($err);
    }
    q("UPDATE staff_queries SET reply = ?, replied_at = NOW(), replied_by = ?, status = 'replied' WHERE id = ?", [$reply, $me['id'], $r['id']]);
    save_uploads('query', $r['id'], $me['id']);
    audit('query_reply', 'query', $r['id'], ['reply' => $reply]);
    touch_change();
    if ($own) {
        notify_managers('query', $me['name'] . ' replied to ' . $r['ref'], $r['subject'] . "\n" . $reply, 'queries/' . $r['id'], false);
    }
    json_out(['ok' => true]);
}

function act_query_close(array $in, array $me): void
{
    require_manager();
    $r = load_query((int)($in['id'] ?? 0), $me);
    if (!is_admin($me) && (float)q('SELECT fine FROM staff_queries WHERE id = ?', [$r['id']])->fetchColumn() > 0) {
        fail('This query carries a fine, so only the admin can close it.', 403);
    }
    if (!in_array($r['status'], ['open', 'replied'], true)) {
        fail('This query is already closed.');
    }
    $outcome = (string)($in['outcome'] ?? '');
    if (!in_array($outcome, QUERY_OUTCOMES, true)) {
        fail('Pick an outcome.');
    }
    $note = str_in($in['note'] ?? '', 2000);
    if ($outcome === 'warning' && $note === '') {
        fail('Write the warning so it is on record.');
    }
    // The fine only applies when the query stands; withdrawing it drops the fine.
    $fine = $outcome === 'withdrawn' || !is_admin($me) ? 0.0 : max(0, money_in($in['fine'] ?? $r['fine']));
    $month = valid_month($in['month'] ?? null) ? $in['month'] : date('Y-m');
    db()->beginTransaction();
    $adjId = null;
    if ($fine > 0 && $r['user_id']) {
        $adjId = payroll_add_adjustment((int)$r['user_id'], $month, 'Query ' . $r['ref'] . ': ' . mb_substr($r['subject'], 0, 90), -$fine, $note, $me);
    }
    q('UPDATE staff_queries SET status = ?, outcome_note = ?, fine = ?, pay_adjustment_id = ?, closed_at = NOW(), closed_by = ? WHERE id = ?',
        [$outcome, $note, $fine, $adjId, $me['id'], $r['id']]);
    audit('query_' . $outcome, 'query', $r['id'], ['note' => $note, 'fine' => $fine, 'month' => $adjId ? $month : null]);
    db()->commit();
    touch_change();
    if ($r['user_id']) {
        $title = match ($outcome) {
            'resolved'  => 'Query ' . $r['ref'] . ' closed',
            'warning'   => 'Warning issued on query ' . $r['ref'],
            'withdrawn' => 'Query ' . $r['ref'] . ' withdrawn',
        };
        notify([(int)$r['user_id']], 'query', $title, $note, 'queries/' . $r['id']);
    }
    json_out(['ok' => true, 'adjustment_id' => $adjId]);
}
