<?php
/*
 * Job orders: tasks collected from schools and companies, assigned to one or more staff.
 * Flow: requested (logged by staff) → open → in_progress → awaiting_check (staff sent photos) → done,
 * or back to returned when an admin sends it back. Every change is written to the job's timeline.
 * Photos are attachments of timeline entries (owner_type 'jobnote').
 */

const JOB_STATUSES = ['requested', 'open', 'in_progress', 'awaiting_check', 'returned', 'done', 'cancelled'];
const JOB_ACTIVE = ['open', 'in_progress', 'returned'];
const JOB_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

function job_ref(int $id): string
{
    return 'JO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function job_assignee_ids(int $jobId): array
{
    return array_map('intval', q('SELECT user_id FROM job_assignees WHERE job_id = ? AND removed_at IS NULL', [$jobId])->fetchAll(PDO::FETCH_COLUMN));
}

function job_can_see(array $job, array $me): bool
{
    return is_admin($me) || (int)$job['created_by'] === $me['id'] || in_array($me['id'], job_assignee_ids((int)$job['id']), true);
}

function load_job(int $id, array $me): array
{
    $j = q('SELECT j.*, u.name AS created_by_name FROM jobs j LEFT JOIN users u ON u.id = j.created_by WHERE j.id = ?', [$id])->fetch();
    if (!$j) {
        fail('Job not found.', 404);
    }
    if (!job_can_see($j, $me)) {
        fail('This job isn\'t assigned to you.', 403);
    }
    $j['id'] = (int)$j['id'];
    $j['ref'] = job_ref($j['id']);
    return $j;
}

function job_log(int $jobId, string $kind, string $body, ?int $reportId = null, ?int $userId = null): int
{
    q('INSERT INTO job_updates (job_id, user_id, kind, report_id, body, created_at) VALUES (?,?,?,?,?,NOW())', [
        $jobId, $userId ?? current_user()['id'] ?? null, $kind, $reportId, $body,
    ]);
    $id = (int)db()->lastInsertId();
    q('UPDATE jobs SET updated_at = NOW() WHERE id = ?', [$jobId]);
    return $id;
}

/** Comma list of names for notifications and the timeline. */
function user_names(array $ids): string
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return '';
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return implode(', ', q("SELECT name FROM users WHERE id IN ($in) ORDER BY name", $ids)->fetchAll(PDO::FETCH_COLUMN));
}

/** Sets the full list of people on a job. Returns [added ids, removed ids]. */
function job_set_assignees(int $jobId, array $userIds, array $me): array
{
    $want = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($want) {
        $in = implode(',', array_fill(0, count($want), '?'));
        $valid = array_map('intval', q("SELECT id FROM users WHERE active = 1 AND id IN ($in)", $want)->fetchAll(PDO::FETCH_COLUMN));
        if (count($valid) !== count($want)) {
            fail('One of the selected staff can\'t be assigned (inactive or missing).');
        }
    }
    $current = job_assignee_ids($jobId);
    $added = array_values(array_diff($want, $current));
    $removed = array_values(array_diff($current, $want));
    foreach ($added as $uid) {
        q('INSERT INTO job_assignees (job_id, user_id, assigned_by, assigned_at) VALUES (?,?,?,NOW())', [$jobId, $uid, $me['id']]);
    }
    if ($removed) {
        $in = implode(',', array_fill(0, count($removed), '?'));
        q("UPDATE job_assignees SET removed_at = NOW() WHERE job_id = ? AND removed_at IS NULL AND user_id IN ($in)", array_merge([$jobId], $removed));
    }
    return [$added, $removed];
}

function job_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['ref'] = job_ref($r['id']);
    $r['assignees'] = [];
    foreach (array_filter(explode('|', (string)($r['assignee_list'] ?? ''))) as $pair) {
        [$uid, $name] = explode(':', $pair, 2) + [1 => ''];
        $r['assignees'][] = ['id' => (int)$uid, 'name' => $name];
    }
    unset($r['assignee_list']);
    $r['overdue'] = $r['due_date'] && $r['due_date'] < today() && !in_array($r['status'], ['done', 'cancelled', 'awaiting_check'], true);
    return $r;
}

const JOB_LIST_SQL = "SELECT j.id, j.title, j.client_type, j.client_name, j.location, j.priority, j.due_date, j.status, j.created_by,
    j.created_at, j.updated_at, j.completed_at, cu.name AS created_by_name,
    (SELECT GROUP_CONCAT(CONCAT(a.user_id, ':', u.name) ORDER BY u.name SEPARATOR '|') FROM job_assignees a JOIN users u ON u.id = a.user_id
        WHERE a.job_id = j.id AND a.removed_at IS NULL) AS assignee_list,
    (SELECT COUNT(*) FROM job_updates ju JOIN attachments f ON f.owner_type = 'jobnote' AND f.owner_id = ju.id WHERE ju.job_id = j.id) AS photos
    FROM jobs j LEFT JOIN users cu ON cu.id = j.created_by";

function job_where(array $in, array $me, array &$params): string
{
    $w = ['1=1'];
    if (!is_admin($me) || !empty($in['mine'])) {
        $w[] = '(j.created_by = ? OR EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL))';
        array_push($params, $me['id'], $me['id']);
    }
    if (!empty($in['open'])) {
        $w[] = "j.status IN ('open','in_progress','returned')";
    }
    $status = (string)($in['status'] ?? '');
    if ($status === 'active') {
        $w[] = "j.status NOT IN ('done','cancelled')";
    } elseif (in_array($status, JOB_STATUSES, true)) {
        $w[] = 'j.status = ?';
        $params[] = $status;
    }
    if (is_admin($me) && !empty($in['user_id'])) {
        $w[] = 'EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL)';
        $params[] = (int)$in['user_id'];
    }
    if (!empty($in['overdue'])) {
        $w[] = "j.due_date < ? AND j.status IN ('open','in_progress','returned','requested')";
        $params[] = today();
    }
    if (!empty($in['q'])) {
        $w[] = '(j.title LIKE ? OR j.client_name LIKE ? OR j.location LIKE ? OR j.description LIKE ? OR j.id = ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($params, $like, $like, $like, $like, (int)preg_replace('/\D/', '', (string)$in['q']));
    }
    return implode(' AND ', $w);
}

function act_jobs_list(array $in, array $me): void
{
    $params = [];
    $where = job_where($in, $me, $params);
    $rows = q(JOB_LIST_SQL . " WHERE $where ORDER BY FIELD(j.status,'awaiting_check','requested','returned','in_progress','open','done','cancelled'),
        FIELD(j.priority,'urgent','high','normal','low'), j.due_date IS NULL, j.due_date, j.id DESC LIMIT 500", $params)->fetchAll();
    // Tab counts ignore the status filter.
    $cp = [];
    $cw = job_where(array_diff_key($in, ['status' => 1, 'open' => 1]), $me, $cp);
    $counts = q("SELECT j.status, COUNT(*) AS n FROM jobs j WHERE $cw GROUP BY j.status", $cp)->fetchAll(PDO::FETCH_KEY_PAIR);
    json_out(['ok' => true, 'items' => array_map('job_row', $rows), 'counts' => array_map('intval', $counts)]);
}

function act_job_get(array $in, array $me): void
{
    $j = load_job((int)($in['id'] ?? 0), $me);
    $j['assignees'] = q('SELECT u.id, u.name, u.phone, a.assigned_at FROM job_assignees a JOIN users u ON u.id = a.user_id
        WHERE a.job_id = ? AND a.removed_at IS NULL ORDER BY u.name', [$j['id']])->fetchAll();
    $updates = q('SELECT ju.id, ju.kind, ju.body, ju.report_id, ju.created_at, ju.user_id, u.name AS user_name
        FROM job_updates ju LEFT JOIN users u ON u.id = ju.user_id WHERE ju.job_id = ? ORDER BY ju.id', [$j['id']])->fetchAll();
    $files = attachments_for('jobnote', array_column($updates, 'id'));
    foreach ($updates as &$u) {
        $u['files'] = $files[(int)$u['id']] ?? [];
    }
    unset($u);
    $j['updates'] = $updates;
    $j['overdue'] = $j['due_date'] && $j['due_date'] < today() && in_array($j['status'], ['open', 'in_progress', 'returned', 'requested'], true);
    $j['is_assignee'] = in_array($me['id'], array_map('intval', array_column($j['assignees'], 'id')), true);
    json_out(['ok' => true, 'job' => $j]);
}

function act_job_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $admin = is_admin($me);
    $job = $id ? load_job($id, $me) : null;
    if ($job && !$admin && !((int)$job['created_by'] === $me['id'] && $job['status'] === 'requested')) {
        fail('Only admins can edit a job once it has been accepted.', 403);
    }
    $v = [
        'title' => str_in($in['title'] ?? '', 190),
        'client_type' => in_array($in['client_type'] ?? '', ['school', 'company', 'other'], true) ? $in['client_type'] : 'school',
        'client_name' => str_in($in['client_name'] ?? '', 190),
        'location' => str_in($in['location'] ?? '', 190),
        'contact_name' => str_in($in['contact_name'] ?? '', 120),
        'contact_phone' => str_in($in['contact_phone'] ?? '', 40),
        'description' => str_in($in['description'] ?? '', 5000),
        'priority' => in_array($in['priority'] ?? '', JOB_PRIORITIES, true) ? $in['priority'] : 'normal',
        'due_date' => valid_date($in['due_date'] ?? null) ? $in['due_date'] : null,
    ];
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Say what needs to be done.';
    }
    if ($v['client_name'] === '') {
        $errors['client_name'] = 'Enter the school or company.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }

    db()->beginTransaction();
    $v['client_id'] = client_id_for($v['client_name'], $v['client_type']);
    if ($job) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
        q("UPDATE jobs SET $set, updated_at = NOW() WHERE id = ?", array_merge(array_values($v), [$id]));
        $noteId = job_log($id, 'edit', 'Job details updated.');
        audit('job_update', 'job', $id);
    } else {
        $status = $admin ? 'open' : 'requested';
        $source = $admin && !empty($in['source_report_id']) ? (int)$in['source_report_id'] : null;
        $cols = implode(', ', array_keys($v));
        $qs = implode(', ', array_fill(0, count($v), '?'));
        q("INSERT INTO jobs ($cols, status, created_by, source_report_id, created_at) VALUES ($qs, ?, ?, ?, NOW())",
            array_merge(array_values($v), [$status, $me['id'], $source]));
        $id = (int)db()->lastInsertId();
        $noteId = job_log($id, 'created', $admin ? 'Job created.' : 'Job logged from the field. Waiting for an admin to accept and assign it.');
        audit('job_create', 'job', $id, ['title' => $v['title']]);
    }
    save_uploads('jobnote', $noteId, $me['id']);
    $added = [];
    if ($admin && isset($in['assignees']) && is_array($in['assignees'])) {
        [$added, $removed] = job_set_assignees($id, $in['assignees'], $me);
        if ($added || $removed) {
            job_log($id, 'assign', job_assign_text($added, $removed));
        }
    }
    db()->commit();
    touch_change();

    $ref = job_ref($id);
    if ($added) {
        notify($added, 'job', "New job $ref: {$v['title']}", $v['client_name'] . ($v['due_date'] ? ' · due ' . $v['due_date'] : ''), 'jobs/' . $id);
    }
    if (!$job && !$admin) {
        notify_admins('job', $me['name'] . " logged a job: {$v['title']}", $v['client_name'], 'jobs/' . $id);
    }
    json_out(['ok' => true, 'id' => $id]);
}

function job_assign_text(array $added, array $removed): string
{
    $parts = [];
    if ($added) {
        $parts[] = 'Assigned to ' . user_names($added) . '.';
    }
    if ($removed) {
        $parts[] = 'Removed ' . user_names($removed) . '.';
    }
    return implode(' ', $parts);
}

function act_job_assign(array $in, array $me): void
{
    require_admin();
    $job = load_job((int)($in['id'] ?? 0), $me);
    if (in_array($job['status'], ['done', 'cancelled'], true)) {
        fail('Reopen the job before changing who is on it.');
    }
    $ids = (array)($in['user_ids'] ?? []);
    if (!$ids) {
        fail('Choose at least one staff member.');
    }
    db()->beginTransaction();
    [$added, $removed] = job_set_assignees($job['id'], $ids, $me);
    if ($added || $removed) {
        job_log($job['id'], 'assign', job_assign_text($added, $removed));
    }
    if ($job['status'] === 'requested') {
        q("UPDATE jobs SET status = 'open' WHERE id = ?", [$job['id']]);
        job_log($job['id'], 'status', 'Accepted.');
    }
    audit('job_assign', 'job', $job['id'], ['added' => $added, 'removed' => $removed]);
    db()->commit();
    touch_change();
    if ($added) {
        notify($added, 'job', "New job {$job['ref']}: {$job['title']}", $job['client_name'] . ($job['due_date'] ? ' · due ' . $job['due_date'] : ''), 'jobs/' . $job['id']);
    }
    if ($removed) {
        notify($removed, 'job', "You were taken off {$job['ref']}", $job['title'], '', false);
    }
    json_out(['ok' => true]);
}

/** Comment or follow-up on the timeline, with optional photos. */
function act_job_update(array $in, array $me): void
{
    $job = load_job((int)($in['id'] ?? 0), $me);
    $body = str_in($in['body'] ?? '', 5000);
    $files = count(uploaded_files('files'));
    if ($body === '' && !$files) {
        fail('Write an update or add a photo.');
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }
    db()->beginTransaction();
    $noteId = job_log($job['id'], is_admin($me) ? 'followup' : 'comment', $body);
    save_uploads('jobnote', $noteId, $me['id']);
    db()->commit();
    touch_change();
    $title = $me['name'] . " on {$job['ref']}: {$job['title']}";
    if (is_admin($me)) {
        notify(array_diff(job_assignee_ids($job['id']), [$me['id']]), 'job', $title, $body, 'jobs/' . $job['id'], false);
    } else {
        notify_admins('job', $title, $body, 'jobs/' . $job['id'], false);
    }
    json_out(['ok' => true]);
}

function act_job_status(array $in, array $me): void
{
    $job = load_job((int)($in['id'] ?? 0), $me);
    $action = (string)($in['action'] ?? '');
    $note = str_in($in['note'] ?? '', 5000);
    $admin = is_admin($me);
    $assignee = in_array($me['id'], job_assignee_ids($job['id']), true);
    $s = $job['status'];
    $to = null;
    $text = '';
    $notifyTo = [];
    $needPhotos = false;

    switch ($action) {
        case 'start':
            if (!$assignee || !in_array($s, ['open', 'returned'], true)) {
                fail('You can only start an open job that is assigned to you.');
            }
            [$to, $text] = ['in_progress', 'Started work.'];
            break;
        case 'complete':
            if (!$assignee || !in_array($s, JOB_ACTIVE, true)) {
                fail('You can only complete an active job that is assigned to you.');
            }
            if ($note === '') {
                json_out(['ok' => false, 'error' => 'Say what was done.', 'fields' => ['note' => 'Say what was done.']], 422);
            }
            $needPhotos = true;
            $signoff = job_signoff_input($in);
            [$to, $text] = ['awaiting_check', 'Marked complete: ' . $note . "\n" . $signoff['text']];
            break;
        case 'approve':
            require_admin();
            if ($s !== 'awaiting_check') {
                fail('Only jobs awaiting a check can be approved.');
            }
            [$to, $text] = ['done', 'Approved and closed.' . ($note ? ' ' . $note : '')];
            $notifyTo = job_assignee_ids($job['id']);
            break;
        case 'return':
            require_admin();
            if (!in_array($s, ['awaiting_check', 'done'], true)) {
                fail('Only completed jobs can be sent back.');
            }
            if ($note === '') {
                fail('Say what still needs to be done.');
            }
            [$to, $text] = ['returned', 'Sent back: ' . $note];
            $notifyTo = job_assignee_ids($job['id']);
            break;
        case 'cancel':
            require_admin();
            if (in_array($s, ['done', 'cancelled'], true)) {
                fail('This job is already closed.');
            }
            [$to, $text] = ['cancelled', 'Cancelled.' . ($note ? ' ' . $note : '')];
            $notifyTo = job_assignee_ids($job['id']);
            break;
        case 'reopen':
            require_admin();
            if (!in_array($s, ['done', 'cancelled'], true)) {
                fail('Only closed jobs can be reopened.');
            }
            [$to, $text] = ['open', 'Reopened.' . ($note ? ' ' . $note : '')];
            $notifyTo = job_assignee_ids($job['id']);
            break;
        default:
            fail('Unknown action.');
    }
    if ($needPhotos) {
        if (!count(uploaded_files('files'))) {
            fail('Add at least one photo of the finished work.');
        }
        if ($err = check_uploads('files') ?? check_uploads('signature')) {
            fail($err);
        }
    }

    db()->beginTransaction();
    $extra = match ($to) {
        'awaiting_check' => ', completion_note = ?, completed_by = ?, completed_at = NOW(), signoff_name = ?, signoff_phone = ?, signoff_skipped = ?,
            signoff_at = ' . ($signoff['signed'] ?? false ? 'NOW()' : 'NULL'),
        'done' => ', verified_by = ?, verified_at = NOW()',
        'returned', 'cancelled' => ', review_note = ?',
        default => '',
    };
    $params = match ($to) {
        'awaiting_check' => [$note, $me['id'], $signoff['name'], $signoff['phone'], $signoff['skipped']],
        'done' => [$me['id']],
        'returned', 'cancelled' => [$note],
        default => [],
    };
    q("UPDATE jobs SET status = ?$extra, updated_at = NOW() WHERE id = ?", array_merge([$to], $params, [$job['id']]));
    $noteId = job_log($job['id'], $action === 'complete' ? 'complete' : 'status', $text);
    if ($needPhotos) {
        save_uploads('jobnote', $noteId, $me['id']);
        if ($signoff['signed']) {
            save_uploads('jobnote', $noteId, $me['id'], 'signature');
        }
    }
    audit('job_' . $action, 'job', $job['id']);
    db()->commit();
    touch_change();

    if ($action === 'complete') {
        notify_admins('job', "{$me['name']} completed {$job['ref']}: {$job['title']}", 'Check the photos and approve it. ' . $note, 'jobs/' . $job['id']);
    } elseif ($notifyTo) {
        $label = ['approve' => 'approved', 'return' => 'sent back', 'cancel' => 'cancelled', 'reopen' => 'reopened'][$action];
        notify(array_diff($notifyTo, [$me['id']]), 'job', "{$job['ref']} was $label", $job['title'] . ($note ? "\n" . $note : ''), 'jobs/' . $job['id'], $action === 'return');
    }
    json_out(['ok' => true, 'status' => $to]);
}

/**
 * Client sign-off sent with "Mark complete": the contact's name, phone and a drawn signature (upload field 'signature'),
 * or a reason why the client couldn't sign. Returns name, phone, skipped, signed and a timeline line.
 */
function job_signoff_input(array $in): array
{
    $name = str_in($in['signoff_name'] ?? '', 120);
    $phone = str_in($in['signoff_phone'] ?? '', 40);
    $skipped = str_in($in['signoff_skipped'] ?? '', 500);
    $signed = count(uploaded_files('signature')) === 1;
    if (!empty($in['signoff_unavailable'])) {
        if ($skipped === '' && setting('require_signoff') !== false) {
            json_out(['ok' => false, 'error' => 'Say why the client couldn\'t sign.', 'fields' => ['signoff_skipped' => 'Say why the client couldn\'t sign.']], 422);
        }
        return ['name' => '', 'phone' => '', 'skipped' => $skipped ?: 'Not available', 'signed' => false,
            'text' => 'Client did not sign: ' . ($skipped ?: 'not available') . '.'];
    }
    if ($name === '' || !$signed) {
        json_out(['ok' => false, 'error' => 'Get the client contact\'s name and signature, or tick that they weren\'t available.',
            'fields' => $name === '' ? ['signoff_name' => 'Enter the client contact\'s name.'] : ['signature' => 'Ask the client to sign.']], 422);
    }
    return ['name' => $name, 'phone' => $phone, 'skipped' => '', 'signed' => true,
        'text' => 'Signed off by ' . $name . ($phone ? ' (' . $phone . ')' : '') . '.'];
}

/** Assigned staff (or an admin) raise a job to high or urgent; admins are emailed straight away. */
function act_job_escalate(array $in, array $me): void
{
    $job = load_job((int)($in['id'] ?? 0), $me);
    if (!is_admin($me) && !in_array($me['id'], job_assignee_ids($job['id']), true) && (int)$job['created_by'] !== $me['id']) {
        fail('Only people on this job can change its priority.', 403);
    }
    if (in_array($job['status'], ['done', 'cancelled'], true)) {
        fail('This job is closed.');
    }
    $priority = in_array($in['priority'] ?? '', ['high', 'urgent'], true) ? $in['priority'] : null;
    $reason = str_in($in['reason'] ?? '', 1000);
    if (!$priority || $reason === '') {
        fail('Choose High or Urgent and say why.');
    }
    if (array_search($priority, JOB_PRIORITIES, true) <= array_search($job['priority'], JOB_PRIORITIES, true)) {
        fail('This job is already ' . $job['priority'] . ' priority.');
    }
    db()->beginTransaction();
    q('UPDATE jobs SET priority = ?, updated_at = NOW() WHERE id = ?', [$priority, $job['id']]);
    job_log($job['id'], 'priority', 'Raised to ' . strtoupper($priority) . ': ' . $reason);
    audit('job_escalate', 'job', $job['id'], ['from' => $job['priority'], 'to' => $priority]);
    db()->commit();
    touch_change();
    $title = strtoupper($priority) . ": {$job['ref']} {$job['title']}";
    $body = $me['name'] . ' raised the priority. ' . $reason . "\n" . $job['client_name'];
    notify_admins('job', $title, $body, 'jobs/' . $job['id'], true);
    notify(array_diff(job_assignee_ids($job['id']), [$me['id']]), 'job', $title, $body, 'jobs/' . $job['id'], false);
    json_out(['ok' => true]);
}

function act_job_delete(array $in, array $me): void
{
    require_admin();
    $job = load_job((int)($in['id'] ?? 0), $me);
    db()->beginTransaction();
    foreach (q('SELECT id FROM job_updates WHERE job_id = ?', [$job['id']])->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        delete_attachments_of('jobnote', (int)$uid);
    }
    q('DELETE FROM job_updates WHERE job_id = ?', [$job['id']]);
    q('DELETE FROM job_assignees WHERE job_id = ?', [$job['id']]);
    q('DELETE FROM jobs WHERE id = ?', [$job['id']]);
    audit('job_delete', 'job', $job['id'], ['title' => $job['title']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

function act_jobs_export(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = job_where($in, $me, $params);
    $rows = q(JOB_LIST_SQL . " WHERE $where ORDER BY j.id", $params)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="job-orders-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Ref', 'Title', 'Client type', 'Client', 'Location', 'Priority', 'Due', 'Status', 'Assigned to', 'Logged by', 'Created', 'Completed', 'Photos']);
    foreach (array_map('job_row', $rows) as $r) {
        fputcsv($out, array_map(fn($x) => csv_safe((string)$x), [
            $r['ref'], $r['title'], $r['client_type'], $r['client_name'], $r['location'], $r['priority'], $r['due_date'] ?? '',
            str_replace('_', ' ', $r['status']), implode(', ', array_column($r['assignees'], 'name')), $r['created_by_name'] ?? '',
            $r['created_at'], $r['completed_at'] ?? '', $r['photos'],
        ]));
    }
    exit;
}

/** A daily report that names job orders adds a timeline entry to each and moves open jobs to in progress. */
function link_report_jobs(int $reportId, int $ownerId, array $data): void
{
    foreach ((array)($data['jobs_worked'] ?? []) as $label) {
        if (!preg_match('/^JO-(\d+)/', (string)$label, $m)) {
            continue;
        }
        $jobId = (int)$m[1];
        $status = q('SELECT status FROM jobs WHERE id = ?', [$jobId])->fetchColumn();
        if (!$status || !in_array($ownerId, job_assignee_ids($jobId), true)) {
            continue;
        }
        if (q('SELECT 1 FROM job_updates WHERE job_id = ? AND report_id = ?', [$jobId, $reportId])->fetchColumn()) {
            continue;
        }
        job_log($jobId, 'report', 'Worked on this. See the daily report for ' . ($data['report_date'] ?? today()) . '.', $reportId, $ownerId);
        if (in_array($status, ['open', 'returned'], true)) {
            q("UPDATE jobs SET status = 'in_progress' WHERE id = ?", [$jobId]);
        }
    }
}

/** Badge counts for the live pulse. */
function job_badges(array $me): array
{
    if (is_admin($me)) {
        return ['jobs' => (int)q("SELECT COUNT(*) FROM jobs WHERE status IN ('awaiting_check','requested')")->fetchColumn()];
    }
    return ['jobs' => (int)q("SELECT COUNT(*) FROM jobs j JOIN job_assignees a ON a.job_id = j.id AND a.removed_at IS NULL
        WHERE a.user_id = ? AND j.status IN ('open','returned')", [$me['id']])->fetchColumn()];
}
