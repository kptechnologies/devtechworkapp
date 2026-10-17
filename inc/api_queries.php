<?php
/**
 * Staff queries: written notes on non-performance, tagged on a staff member's or contractor's account.
 * status: proposed (by a supervisor, hidden from the staff member until issued) → open (waiting for a reply) → replied → resolved | warning | suspended | withdrawn
 * Staff reply themselves; contractors have no login, so an admin records their reply.
 * A fine on a staff query becomes a payroll deduction when the query is closed with a warning or resolved.
 * Only the admin suspends (also escalating a warning). While suspended, staff can't clock in or work job orders;
 * a suspension without pay deducts the suspended working days' salary in payroll.
 */

const QUERY_CATEGORIES = ['lateness' => 'Lateness', 'absence' => 'Absence', 'poor_work' => 'Poor work', 'job_failure' => 'Job order failure',
    'no_report' => 'No daily report', 'misconduct' => 'Misconduct', 'other' => 'Other'];
const QUERY_OUTCOMES = ['resolved', 'warning', 'suspended', 'withdrawn'];

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
    $r['suspend_unpaid'] = (int)($r['suspend_unpaid'] ?? 0);
    $r['suspension_active'] = $r['status'] === 'suspended' && $r['suspend_to'] && $r['suspend_to'] >= today();
    // Fines are pay deductions, which only admins see.
    if (!is_admin()) {
        $r['fine'] = 0.0;
        $r['pay_adjustment_id'] = null;
    }
    return $r;
}

/** The suspension covering a date (default today) for a staff member, or null. */
function active_suspension(int $uid, ?string $date = null): ?array
{
    $date = $date ?? today();
    $r = q("SELECT id, suspend_from, suspend_to FROM staff_queries WHERE user_id = ? AND status = 'suspended' AND suspend_from <= ? AND suspend_to >= ?
            ORDER BY suspend_to DESC LIMIT 1", [$uid, $date, $date])->fetch();
    return $r ? ['id' => (int)$r['id'], 'ref' => query_ref((int)$r['id']), 'from' => $r['suspend_from'], 'to' => $r['suspend_to']] : null;
}

/** Stops a suspended staff member doing an action. */
function refuse_if_suspended(int $uid, string $what): void
{
    if ($s = active_suspension($uid)) {
        fail("You are suspended until " . date('j M Y', strtotime($s['to'])) . " ({$s['ref']}), so you can't $what.", 403);
    }
}

/** Salary for the suspended working days, one payroll deduction per month the range touches. */
function suspension_deductions(int $uid, string $from, string $to, string $ref, string $note, array $me): array
{
    $salary = (float)q('SELECT salary FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($salary <= 0) {
        return [];
    }
    $workDays = work_days();
    $ids = [];
    for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $monthDays = $suspended = 0;
        for ($t = strtotime($m . '-01'), $end = strtotime(date('Y-m-t', $t)); $t <= $end; $t += 86400) {
            if (in_array((int)date('N', $t), $workDays, true)) {
                $monthDays++;
                $d = date('Y-m-d', $t);
                $suspended += $d >= $from && $d <= $to ? 1 : 0;
            }
        }
        if ($suspended && $monthDays) {
            $amount = round($salary / $monthDays * $suspended, 2);
            $ids[] = payroll_add_adjustment($uid, $m, "Query $ref: suspension, $suspended day" . ($suspended === 1 ? '' : 's') . ' without pay', -$amount, $note, $me);
        }
    }
    return $ids;
}

/** Query history for one staff member or contractor (profile tabs). */
function person_queries(?int $userId, ?int $contractorId): array
{
    // Proposed queries haven't been issued yet, so only management sees them.
    $hide = is_management() ? '' : " AND s.status <> 'proposed'";
    $rows = $userId
        ? q(QUERY_SQL . " WHERE s.user_id = ?$hide ORDER BY s.id DESC LIMIT 50", [$userId])->fetchAll()
        : q(QUERY_SQL . " WHERE s.contractor_id = ?$hide ORDER BY s.id DESC LIMIT 50", [$contractorId])->fetchAll();
    return array_map('query_row', $rows);
}

/** Pulse badge: staff see queries waiting for their reply; admins see replies waiting for a decision. */
function query_badge(array $me): int
{
    return is_management($me)
        ? (int)q("SELECT COUNT(*) FROM staff_queries WHERE status = 'replied'" . (can_issue_queries($me) ? " OR status = 'proposed'" : ''))->fetchColumn()
        : (int)q("SELECT COUNT(*) FROM staff_queries WHERE user_id = ? AND status = 'open'", [$me['id']])->fetchColumn();
}

function load_query(int $id, array $me): array
{
    $r = q(QUERY_SQL . ' WHERE s.id = ?', [$id])->fetch();
    if (!$r) {
        fail('Query not found.', 404);
    }
    if (!is_management($me) && ((int)$r['user_id'] !== $me['id'] || $r['status'] === 'proposed')) {
        fail('You can only view your own queries.', 403);
    }
    return query_row($r);
}

function act_queries_list(array $in, array $me): void
{
    $w = ['1=1'];
    $p = [];
    if (!is_management($me)) {
        $w[] = "s.user_id = ? AND s.status <> 'proposed'";
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
        $w[] = "s.status IN ('proposed','open','replied')";
    } elseif (in_array($status, array_merge(['open', 'replied'], QUERY_OUTCOMES), true)) {
        $w[] = 's.status = ?';
        $p[] = $status;
    }
    if (isset(QUERY_CATEGORIES[$in['category'] ?? ''])) {
        $w[] = 's.category = ?';
        $p[] = $in['category'];
    }
    $rows = q(QUERY_SQL . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(s.status,'open','replied','proposed') DESC, s.id DESC LIMIT 500", $p)->fetchAll();
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
    $r['counts'] = q('SELECT COUNT(*) AS total, SUM(status = \'warning\') AS warnings, SUM(status = \'suspended\') AS suspensions FROM staff_queries WHERE ' .
        ($r['user_id'] ? 'user_id = ?' : 'contractor_id = ?'), [$r['user_id'] ?: $r['contractor_id']])->fetch();
    json_out(['ok' => true, 'query' => $r, 'categories' => QUERY_CATEGORIES]);
}

function act_query_save(array $in, array $me): void
{
    require_management();
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? load_query($id, $me) : null;
    if ($existing && !in_array($existing['status'], ['proposed', 'open', 'replied'], true)) {
        fail('This query is closed, so it can\'t be changed.');
    }
    if ($existing && !can_issue_queries($me) && ($existing['status'] !== 'proposed' || (int)$existing['created_by'] !== $me['id'])) {
        fail('Supervisors can only change queries they proposed that haven\'t been issued yet.', 403);
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
        // A supervisor's query waits as a proposal until management issues it.
        $status = can_issue_queries($me) ? 'open' : 'proposed';
        q("INSERT INTO staff_queries (user_id, contractor_id, subject, details, category, job_id, query_date, reply_by, fine, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())", [$userId, $userId ? null : $contractorId, $subject, $details, $category, $jobId, $date, $replyBy, $fine, $status, $me['id']]);
        $id = (int)db()->lastInsertId();
        audit($status === 'open' ? 'query_create' : 'query_propose', 'query', $id, ['subject' => $subject, 'fine' => $fine]);
        if ($status === 'proposed') {
            $person = $userId ? q('SELECT name FROM users WHERE id = ?', [$userId])->fetchColumn() : 'a contractor';
            notify_issuers('query', "{$me['name']} proposes a query on $person: $subject", $details, 'queries/' . $id, $me['id']);
        } elseif ($userId) {
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

/** Issues and closes queries: everyone in management except supervisors. */
function notify_issuers(string $type, string $title, string $body, string $link, int $except): void
{
    $ids = q("SELECT id FROM users WHERE role IN ('admin','manager','subadmin') AND active = 1 AND id <> ?", [$except])->fetchAll(PDO::FETCH_COLUMN);
    notify($ids, $type, $title, $body, $link, false);
}

/** Issue a supervisor's proposed query to the staff member, or discard it. */
function act_query_issue(array $in, array $me): void
{
    if (!can_issue_queries($me)) {
        fail('Only admins, managers and sub admins can issue queries.', 403);
    }
    $r = load_query((int)($in['id'] ?? 0), $me);
    if ($r['status'] !== 'proposed') {
        fail('This query has already been issued.');
    }
    $proposer = (int)q('SELECT created_by FROM staff_queries WHERE id = ?', [$r['id']])->fetchColumn();
    if (($in['decision'] ?? '') === 'discard') {
        $note = str_in($in['note'] ?? '', 1000);
        db()->beginTransaction();
        delete_attachments_of('query', $r['id']);
        q('DELETE FROM staff_queries WHERE id = ?', [$r['id']]);
        audit('query_discard', 'query', $r['id'], ['subject' => $r['subject'], 'note' => $note]);
        db()->commit();
        touch_change();
        notify([$proposer], 'query', "Your proposed query wasn't issued: {$r['subject']}", $note, 'queries', false);
        json_out(['ok' => true]);
    }
    q("UPDATE staff_queries SET status = 'open', query_date = ? WHERE id = ?", [today(), $r['id']]);
    audit('query_issue', 'query', $r['id'], ['proposed_by' => $proposer]);
    touch_change();
    if ($r['user_id']) {
        notify([(int)$r['user_id']], 'query', 'Query ' . $r['ref'] . ': ' . $r['subject'],
            $r['details'] . ($r['reply_by'] ? "\nPlease reply by " . date('j M Y', strtotime($r['reply_by'])) . '.' : "\nPlease reply in the portal."), 'queries/' . $r['id']);
    }
    notify([$proposer], 'query', "Your proposed query was issued: {$r['subject']}", '', 'queries/' . $r['id'], false);
    json_out(['ok' => true]);
}

function act_query_close(array $in, array $me): void
{
    if (!can_issue_queries($me)) {
        fail('Only admins, managers and sub admins can close queries.', 403);
    }
    $r = load_query((int)($in['id'] ?? 0), $me);
    if (!is_admin($me) && (float)q('SELECT fine FROM staff_queries WHERE id = ?', [$r['id']])->fetchColumn() > 0) {
        fail('This query carries a fine, so only the admin can close it.', 403);
    }
    $outcome = (string)($in['outcome'] ?? '');
    if (!in_array($outcome, QUERY_OUTCOMES, true)) {
        fail('Pick an outcome.');
    }
    $suspend = $outcome === 'suspended';
    // A warning can still be escalated to a suspension later.
    $escalate = $suspend && $r['status'] === 'warning';
    if (!$escalate && !in_array($r['status'], ['open', 'replied'], true)) {
        fail('This query is already closed.');
    }
    if ($suspend && !is_admin($me)) {
        fail('Only the admin can suspend.', 403);
    }
    $note = str_in($in['note'] ?? '', 2000);
    if (in_array($outcome, ['warning', 'suspended'], true) && $note === '') {
        fail($suspend ? 'Write the reason for the suspension so it is on record.' : 'Write the warning so it is on record.');
    }
    $sFrom = $sTo = null;
    $unpaid = 0;
    if ($suspend) {
        $sFrom = (string)($in['suspend_from'] ?? '');
        $sTo = (string)($in['suspend_to'] ?? '');
        if (!valid_date($sFrom) || !valid_date($sTo) || $sTo < $sFrom) {
            fail('Pick the first and last day of the suspension.');
        }
        $unpaid = !empty($in['suspend_unpaid']) && $r['user_id'] ? 1 : 0;
    }
    // The fine only applies when the query stands; withdrawing it drops the fine. An escalated warning already settled its fine.
    $fine = $escalate ? (float)$r['fine'] : ($outcome === 'withdrawn' || !is_admin($me) ? 0.0 : max(0, money_in($in['fine'] ?? $r['fine'])));
    $month = valid_month($in['month'] ?? null) ? $in['month'] : date('Y-m');
    db()->beginTransaction();
    $adjId = $escalate ? ($r['pay_adjustment_id'] ?: null) : null;
    if (!$escalate && $fine > 0 && $r['user_id']) {
        $adjId = payroll_add_adjustment((int)$r['user_id'], $month, 'Query ' . $r['ref'] . ': ' . mb_substr($r['subject'], 0, 90), -$fine, $note, $me);
    }
    $unpaidIds = $unpaid ? suspension_deductions((int)$r['user_id'], $sFrom, $sTo, $r['ref'], $note, $me) : [];
    $fullNote = $escalate && $r['outcome_note'] ? $r['outcome_note'] . "\n\nSuspended: " . $note : $note;
    q('UPDATE staff_queries SET status = ?, outcome_note = ?, fine = ?, pay_adjustment_id = ?, suspend_from = ?, suspend_to = ?, suspend_unpaid = ?,
        closed_at = NOW(), closed_by = ? WHERE id = ?',
        [$outcome, $fullNote, $fine, $adjId, $sFrom, $sTo, $unpaid, $me['id'], $r['id']]);
    audit('query_' . $outcome, 'query', $r['id'], ['note' => $note, 'fine' => $escalate ? 0 : $fine, 'month' => $adjId && !$escalate ? $month : null]
        + ($suspend ? ['from' => $sFrom, 'to' => $sTo, 'unpaid' => $unpaid] : []));
    db()->commit();
    touch_change();
    if ($r['user_id']) {
        $title = match ($outcome) {
            'resolved'  => 'Query ' . $r['ref'] . ' closed',
            'warning'   => 'Warning issued on query ' . $r['ref'],
            'suspended' => 'Suspended ' . date('j M', strtotime($sFrom)) . ' – ' . date('j M Y', strtotime($sTo)) . ' (query ' . $r['ref'] . ')',
            'withdrawn' => 'Query ' . $r['ref'] . ' withdrawn',
        };
        $body = $suspend ? $note . "\nYou can't clock in or work on job orders during the suspension" . ($unpaid ? ', and it is without pay.' : '.') : $note;
        notify([(int)$r['user_id']], 'query', $title, $body, 'queries/' . $r['id']);
    }
    json_out(['ok' => true, 'adjustment_id' => $adjId, 'unpaid_adjustments' => count($unpaidIds)]);
}

/** End a suspension early (or cancel one that hasn't started). Pay deductions already added stay; remove them in Payroll if needed. */
function act_query_lift(array $in, array $me): void
{
    require_admin();
    $r = load_query((int)($in['id'] ?? 0), $me);
    if ($r['status'] !== 'suspended' || !$r['suspension_active']) {
        fail('This suspension has already ended.');
    }
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    // Not started yet: there is nothing left to serve, so the range collapses to before it began.
    $to = $r['suspend_from'] > $yesterday ? date('Y-m-d', strtotime($r['suspend_from'] . ' -1 day')) : $yesterday;
    $note = str_in($in['note'] ?? '', 1000);
    q('UPDATE staff_queries SET suspend_to = ? WHERE id = ?', [$to, $r['id']]);
    audit('query_lift', 'query', $r['id'], ['was_to' => $r['suspend_to'], 'note' => $note]);
    touch_change();
    if ($r['user_id']) {
        notify([(int)$r['user_id']], 'query', 'Suspension lifted (query ' . $r['ref'] . ')', $note ?: 'You can clock in and work on job orders again.', 'queries/' . $r['id']);
    }
    json_out(['ok' => true]);
}
