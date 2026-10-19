<?php
/*
 * Job orders: tasks collected from schools and companies, assigned to one or more staff.
 * Flow: requested (logged by staff) → open → in_progress → awaiting_check (staff sent photos) → done,
 * or back to returned when an admin sends it back. Every change is written to the job's timeline.
 * Photos are attachments of timeline entries (owner_type 'jobnote').
 * A job can be split into tasks, each with its own staff and due date/time. Staff can't end their day while a task or
 * job due today is unfinished, unless an admin approves more time (job_extensions). When an admin doesn't answer a
 * request for more time, or check a completed job, within ADMIN_ANSWER_WAIT, the staff member may go ahead themselves.
 */

const JOB_STATUSES = ['requested', 'open', 'in_progress', 'awaiting_check', 'returned', 'done', 'cancelled'];
const JOB_ACTIVE = ['open', 'in_progress', 'returned'];
const JOB_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const ADMIN_ANSWER_WAIT = 3600; // seconds an admin has to answer before staff may go ahead
/** Moving a deadline later is giving more time, which only admins and managers approve; others recommend it instead. */
const LATER_DUE_ADMIN_ONLY = 'Only an admin or manager can give more time. Recommend it on the request for more time instead.';

function job_ref(int $id): string
{
    return 'JO-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** 'HH:MM' from a form as a TIME value, or null. */
function time_in($v): ?string
{
    return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v . ':00' : null;
}

/** 'HH:MM' for the app from a TIME column. */
function hhmm(?string $t): ?string
{
    return $t ? substr($t, 0, 5) : null;
}

/** Past its due date, or due today and past its due time. */
function is_overdue(?string $date, ?string $time): bool
{
    if (!$date) {
        return false;
    }
    return $date < today() || ($date === today() && $time && time() > strtotime("$date $time"));
}

/** "Tue 14 Oct, 2:00pm" for notifications and the timeline. */
function due_label(?string $date, ?string $time): string
{
    if (!$date) {
        return 'today';
    }
    return date('D j M', strtotime($date)) . ($time ? ', ' . date('g:ia', strtotime("$date $time")) : '');
}

function job_assignee_ids(int $jobId): array
{
    return array_map('intval', q('SELECT user_id FROM job_assignees WHERE job_id = ? AND removed_at IS NULL', [$jobId])->fetchAll(PDO::FETCH_COLUMN));
}

function job_can_see(array $job, array $me): bool
{
    return is_management($me) || (int)$job['created_by'] === $me['id'] || in_array($me['id'], job_assignee_ids((int)$job['id']), true);
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
        if ($s = active_suspension($uid)) {
            $name = q('SELECT name FROM users WHERE id = ?', [$uid])->fetchColumn();
            fail("$name is suspended until " . date('j M Y', strtotime($s['to'])) . " ({$s['ref']}), so they can't be put on a job.");
        }
    }
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
    $r['due_time'] = hhmm($r['due_time'] ?? null);
    $r['overdue'] = !in_array($r['status'], ['done', 'cancelled', 'awaiting_check'], true) && is_overdue($r['due_date'], $r['due_time']);
    foreach (['tasks_total', 'tasks_done', 'ext_pending', 'self_closed'] as $k) {
        $r[$k] = (int)($r[$k] ?? 0);
    }
    return $r;
}

const JOB_LIST_SQL = "SELECT j.id, j.title, j.client_type, j.client_name, j.location, j.priority, j.due_date, j.due_time, j.status, j.created_by,
    j.created_at, j.updated_at, j.completed_at, j.self_closed, cu.name AS created_by_name,
    (SELECT GROUP_CONCAT(CONCAT(a.user_id, ':', u.name) ORDER BY u.name SEPARATOR '|') FROM job_assignees a JOIN users u ON u.id = a.user_id
        WHERE a.job_id = j.id AND a.removed_at IS NULL) AS assignee_list,
    (SELECT COUNT(*) FROM job_updates ju JOIN attachments f ON f.owner_type = 'jobnote' AND f.owner_id = ju.id WHERE ju.job_id = j.id) AS photos,
    (SELECT COUNT(*) FROM job_tasks t WHERE t.job_id = j.id) AS tasks_total,
    (SELECT COUNT(*) FROM job_tasks t WHERE t.job_id = j.id AND t.status = 'done') AS tasks_done,
    (SELECT COUNT(*) FROM job_extensions x WHERE x.job_id = j.id AND x.status = 'pending') AS ext_pending
    FROM jobs j LEFT JOIN users cu ON cu.id = j.created_by";

function job_where(array $in, array $me, array &$params): string
{
    $w = ['1=1'];
    if (!is_management($me) || !empty($in['mine'])) {
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
    if (is_management($me) && !empty($in['user_id'])) {
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
    $j['due_time'] = hhmm($j['due_time']);
    $j['overdue'] = in_array($j['status'], ['open', 'in_progress', 'returned', 'requested'], true) && is_overdue($j['due_date'], $j['due_time']);
    $j['is_assignee'] = in_array($me['id'], array_map('intval', array_column($j['assignees'], 'id')), true);
    $j['tasks'] = job_tasks($j['id'], $me);
    $j['pending_extension'] = pending_extensions($j['id'])[0] ?? null;
    $j['self_closed'] = (int)$j['self_closed'];
    // When the job's staff may close it themselves if no admin has checked it.
    $j['self_close_at'] = $j['status'] === 'awaiting_check' && $j['completed_at'] ? date('Y-m-d H:i:s', strtotime($j['completed_at']) + ADMIN_ANSWER_WAIT) : null;
    $j['extensions'] = q('SELECT x.id, x.task_id, x.reason, x.new_due_date, x.new_due_time, x.status, x.decision_note, x.created_at, x.decided_at,
        x.recommendation, x.recommend_note, r.name AS recommended_by_name,
        u.name AS user_name, d.name AS decided_by_name, t.title AS task_title FROM job_extensions x JOIN users u ON u.id = x.user_id
        LEFT JOIN users d ON d.id = x.decided_by LEFT JOIN users r ON r.id = x.recommended_by LEFT JOIN job_tasks t ON t.id = x.task_id WHERE x.job_id = ? ORDER BY x.id DESC', [$j['id']])->fetchAll();
    json_out(['ok' => true, 'job' => $j]);
}

function act_job_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $admin = is_management($me);
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
        'due_time' => time_in($in['due_time'] ?? null),
    ];
    if (array_key_exists('remind_offsets', $in)) {
        $v['remind_offsets'] = offsets_in($in['remind_offsets']);
    }
    $errors = [];
    if ($admin && !$v['due_date'] && !in_array($job['status'] ?? '', ['done', 'cancelled'], true)) {
        $errors['due_date'] = 'Set a due date so staff know the time frame.';
    }
    $tasks = $admin && !$job ? array_values(array_filter((array)($in['tasks'] ?? []), fn($t) => is_array($t) && trim((string)($t['title'] ?? '')) !== '')) : [];
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

    if ($job && !can_approve_time($me) && $job['due_date'] && ($v['due_date'] . ($v['due_time'] ?? '')) > ($job['due_date'] . ($job['due_time'] ?? ''))) {
        fail(LATER_DUE_ADMIN_ONLY, 403);
    }
    db()->beginTransaction();
    $v['client_id'] = client_id_for($v['client_name'], $v['client_type']);
    if ($job) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
        q("UPDATE jobs SET $set, updated_at = NOW() WHERE id = ?", array_merge(array_values($v), [$id]));
        $noteId = job_log($id, 'edit', 'Job details updated.');
        // A later due date set by an admin answers any request for more time on the job.
        if (can_approve_time($me) && $v['due_date'] && ($v['due_date'] . ($v['due_time'] ?? '')) > ($job['due_date'] . ($job['due_time'] ?? ''))) {
            close_extensions($id, null, 'approved', 'Due date moved to ' . due_label($v['due_date'], $v['due_time']) . '.');
        }
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
    $taskPeople = [];
    foreach ($tasks as $t) {
        [$taskId, $people, $joined] = job_task_store($id, $t, $me);
        $added = array_values(array_unique(array_merge($added, $joined)));
        job_log($id, 'task', 'Task added: ' . job_task_line($taskId));
        foreach ($people as $uid) {
            $taskPeople[$uid][] = $taskId;
        }
    }
    db()->commit();
    touch_change();

    $ref = job_ref($id);
    if ($added) {
        notify($added, 'job', "New job $ref: {$v['title']}", $v['client_name'] . ($v['due_date'] ? ' · due ' . due_label($v['due_date'], $v['due_time']) : ''), 'jobs/' . $id, true,
            ['gtasks' => true, 'due' => (string)$v['due_date']]);
    }
    foreach ($taskPeople as $uid => $taskIds) {
        notify([$uid], 'job', "Your tasks on $ref: {$v['title']}", implode("\n", array_map('job_task_line', $taskIds)), 'jobs/' . $id, false);
    }
    if (!$job && !$admin) {
        notify_managers('job', $me['name'] . " logged a job: {$v['title']}", $v['client_name'], 'jobs/' . $id);
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
    require_management();
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
        notify($added, 'job', "New job {$job['ref']}: {$job['title']}", $job['client_name'] . ($job['due_date'] ? ' · due ' . $job['due_date'] : ''), 'jobs/' . $job['id'], true,
            ['gtasks' => true, 'due' => (string)$job['due_date']]);
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
    $noteId = job_log($job['id'], is_management($me) ? 'followup' : 'comment', $body);
    save_uploads('jobnote', $noteId, $me['id']);
    db()->commit();
    touch_change();
    $title = $me['name'] . " on {$job['ref']}: {$job['title']}";
    if (is_management($me)) {
        notify(array_diff(job_assignee_ids($job['id']), [$me['id']]), 'job', $title, $body, 'jobs/' . $job['id'], false);
    } else {
        notify_managers('job', $title, $body, 'jobs/' . $job['id'], false);
    }
    json_out(['ok' => true]);
}

function act_job_status(array $in, array $me): void
{
    $job = load_job((int)($in['id'] ?? 0), $me);
    $action = (string)($in['action'] ?? '');
    $note = str_in($in['note'] ?? '', 5000);
    $admin = is_management($me);
    $assignee = in_array($me['id'], job_assignee_ids($job['id']), true);
    $s = $job['status'];
    $to = null;
    $text = '';
    $notifyTo = [];
    $needPhotos = false;

    if (in_array($action, ['start', 'complete', 'self_close'], true)) {
        refuse_if_suspended($me['id'], 'work on job orders');
    }
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
            $openTasks = (int)q("SELECT COUNT(*) FROM job_tasks WHERE job_id = ? AND status = 'open'", [$job['id']])->fetchColumn();
            [$to, $text] = ['awaiting_check', 'Marked complete: ' . $note . "\n" . $signoff['text']
                . ($openTasks ? "\n$openTasks task" . ($openTasks === 1 ? ' was' : 's were') . ' still open.' : '')];
            break;
        case 'approve':
            require_management();
            if ($s !== 'awaiting_check') {
                fail('Only jobs awaiting a check can be approved.');
            }
            [$to, $text] = ['done', 'Approved and closed.' . ($note ? ' ' . $note : '')];
            $notifyTo = job_assignee_ids($job['id']);
            break;
        case 'self_close':
            // No admin checked the finished job within the hour, so its staff may close it. Admins can still send it back.
            if (!$assignee || $s !== 'awaiting_check') {
                fail('You can only close a job of yours that is waiting for a check.');
            }
            if (strtotime((string)$job['completed_at']) > time() - ADMIN_ANSWER_WAIT) {
                fail('An admin has until ' . date('g:ia', strtotime((string)$job['completed_at']) + ADMIN_ANSWER_WAIT) . ' to check it. Try again then.');
            }
            [$to, $text] = ['done', "Closed by {$me['name']}: no admin checked it within an hour." . ($note ? ' ' . $note : '')];
            break;
        case 'return':
            require_management();
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
            require_management();
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
    // Only "self_close" leaves a job marked as closed without an admin's check; any admin action clears it.
    q("UPDATE jobs SET status = ?$extra, self_closed = ?, updated_at = NOW() WHERE id = ?",
        array_merge([$to], $params, [$action === 'self_close' ? 1 : 0, $job['id']]));
    $noteId = job_log($job['id'], $action === 'complete' ? 'complete' : 'status', $text);
    if (in_array($to, ['awaiting_check', 'done', 'cancelled'], true)) {
        close_extensions($job['id'], false, 'cancelled', 'The job was ' . ($to === 'awaiting_check' ? 'marked complete' : $to) . '.');
    }
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
        notify_managers('job', "{$me['name']} completed {$job['ref']}: {$job['title']}", 'Check the photos and approve it within an hour, '
            . 'or they can close it themselves. ' . $note, 'jobs/' . $job['id']);
    } elseif ($action === 'self_close') {
        notify_managers('job', "{$me['name']} closed {$job['ref']} without a check", $job['title'] . "\nNobody checked it within an hour of it being marked complete. "
            . 'Open it to look at the photos; you can still send it back.', 'jobs/' . $job['id'], true);
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
    if (!is_management($me) && !in_array($me['id'], job_assignee_ids($job['id']), true) && (int)$job['created_by'] !== $me['id']) {
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
    notify_managers('job', $title, $body, 'jobs/' . $job['id'], true);
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
    q('DELETE a FROM job_task_assignees a JOIN job_tasks t ON t.id = a.task_id WHERE t.job_id = ?', [$job['id']]);
    q('DELETE FROM job_tasks WHERE job_id = ?', [$job['id']]);
    q('DELETE FROM job_extensions WHERE job_id = ?', [$job['id']]);
    q('DELETE FROM jobs WHERE id = ?', [$job['id']]);
    audit('job_delete', 'job', $job['id'], ['title' => $job['title']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

function act_jobs_export(array $in, array $me): void
{
    require_management();
    $params = [];
    $where = job_where($in, $me, $params);
    $rows = q(JOB_LIST_SQL . " WHERE $where ORDER BY j.id", $params)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="job-orders-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Ref', 'Title', 'Client type', 'Client', 'Location', 'Priority', 'Due', 'Due time', 'Status', 'Assigned to', 'Logged by', 'Created', 'Completed', 'Photos', 'Tasks done']);
    foreach (array_map('job_row', $rows) as $r) {
        fputcsv($out, array_map(fn($x) => csv_safe((string)$x), [
            $r['ref'], $r['title'], $r['client_type'], $r['client_name'], $r['location'], $r['priority'], $r['due_date'] ?? '', $r['due_time'] ?? '',
            str_replace('_', ' ', $r['status']), implode(', ', array_column($r['assignees'], 'name')), $r['created_by_name'] ?? '',
            $r['created_at'], $r['completed_at'] ?? '', $r['photos'], $r['tasks_total'] ? "{$r['tasks_done']}/{$r['tasks_total']}" : '',
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
        log_job_work($jobId, $status, 'report', 'Worked on this. See the daily report for ' . ($data['report_date'] ?? today()) . '.', $ownerId, $reportId);
    }
}

/** Adds a "worked on this" timeline entry and moves an open or returned job to in progress. */
function log_job_work(int $jobId, string $status, string $kind, string $body, int $userId, ?int $reportId = null): void
{
    job_log($jobId, $kind, $body, $reportId, $userId);
    if (in_array($status, ['open', 'returned'], true)) {
        q("UPDATE jobs SET status = 'in_progress' WHERE id = ?", [$jobId]);
    }
}

/** Badge counts for the live pulse: admins see jobs to check or accept and requests for more time; staff see new and due jobs. */
function job_badges(array $me): array
{
    if (is_management($me)) {
        return ['jobs' => (int)q("SELECT COUNT(*) FROM jobs WHERE status IN ('awaiting_check','requested')")->fetchColumn()
            + (int)q("SELECT COUNT(*) FROM job_extensions WHERE status = 'pending'")->fetchColumn()];
    }
    $ids = q("SELECT j.id FROM jobs j JOIN job_assignees a ON a.job_id = j.id AND a.removed_at IS NULL
        WHERE a.user_id = ? AND j.status IN ('open','returned')", [$me['id']])->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_merge(array_map('intval', $ids), array_column(jobs_due_for($me['id']), 'job_id'));
    return ['jobs' => count(array_unique($ids))];
}

/* --------------------------------------------------------------- tasks */

/** One task as a line for notifications and the timeline: "Fix projector (Ada, Bola) · due Tue 14 Oct, 2:00pm". */
function job_task_line(int $taskId): string
{
    $t = q('SELECT title, due_date, due_time FROM job_tasks WHERE id = ?', [$taskId])->fetch();
    $who = user_names(task_assignee_ids($taskId));
    return $t['title'] . ($who ? " ($who)" : '') . ' · due ' . due_label($t['due_date'], $t['due_time']);
}

function task_assignee_ids(int $taskId): array
{
    return array_map('intval', q('SELECT user_id FROM job_task_assignees WHERE task_id = ?', [$taskId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Pending requests for more time on a job, keyed by task id (0 for the job itself). */
function pending_extensions(int $jobId): array
{
    $out = [];
    foreach (q("SELECT x.id, x.task_id, x.user_id, x.reason, x.new_due_date, x.new_due_time, x.created_at, u.name AS user_name,
        x.recommendation, x.recommend_note, x.recommended_at, r.name AS recommended_by_name
        FROM job_extensions x JOIN users u ON u.id = x.user_id LEFT JOIN users r ON r.id = x.recommended_by WHERE x.job_id = ? AND x.status = 'pending' ORDER BY x.id", [$jobId])->fetchAll() as $x) {
        $x['id'] = (int)$x['id'];
        $x['user_id'] = (int)$x['user_id'];
        $x['new_due_time'] = hhmm($x['new_due_time']);
        // With no admin answer within the hour, the person who asked may go ahead.
        $x['proceed_at'] = date('Y-m-d H:i:s', strtotime($x['created_at']) + ADMIN_ANSWER_WAIT);
        $x['can_proceed'] = time() >= strtotime($x['proceed_at']);
        $out[(int)$x['task_id']] = $x;
    }
    return $out;
}

/**
 * Closes pending requests for more time without an admin decision on the request itself: the due date was moved
 * (approved), or the work was finished (cancelled). $taskId null = the job's own request, false = every request on the job.
 */
function close_extensions(int $jobId, $taskId, string $status, string $note): void
{
    $me = current_user();
    $where = $taskId === false ? '' : ($taskId === null ? ' AND task_id IS NULL' : ' AND task_id = ' . (int)$taskId);
    $waiting = q("SELECT DISTINCT user_id FROM job_extensions WHERE job_id = ? AND status = 'pending'$where", [$jobId])->fetchAll(PDO::FETCH_COLUMN);
    if (!$waiting) {
        return;
    }
    q("UPDATE job_extensions SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE job_id = ? AND status = 'pending'$where",
        [$status, $me['id'] ?? null, mb_substr($note, 0, 500), $jobId]);
    if ($status === 'approved') {
        job_log($jobId, 'extension', 'More time approved. ' . $note);
        notify(array_diff(array_map('intval', $waiting), [$me['id'] ?? 0]), 'job', 'More time approved on ' . job_ref($jobId), $note . ' You can clock out.', 'jobs/' . $jobId, true);
    }
}

/** A job's tasks with their staff, overdue flag and any pending request for more time. */
function job_tasks(int $jobId, array $me): array
{
    $rows = q('SELECT t.*, u.name AS done_by_name FROM job_tasks t LEFT JOIN users u ON u.id = t.done_by WHERE t.job_id = ?
        ORDER BY t.due_date IS NULL, t.due_date, t.due_time IS NULL, t.due_time, t.id', [$jobId])->fetchAll();
    if (!$rows) {
        return [];
    }
    $people = [];
    foreach (q('SELECT a.task_id, u.id, u.name FROM job_task_assignees a JOIN users u ON u.id = a.user_id
        WHERE a.task_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ') ORDER BY u.name')->fetchAll() as $p) {
        $people[(int)$p['task_id']][] = ['id' => (int)$p['id'], 'name' => $p['name']];
    }
    $pending = pending_extensions($jobId);
    foreach ($rows as &$t) {
        $t['id'] = (int)$t['id'];
        $t['assignees'] = $people[$t['id']] ?? [];
        $t['due_time'] = hhmm($t['due_time']);
        $t['overdue'] = $t['status'] === 'open' && is_overdue($t['due_date'], $t['due_time']);
        $t['mine'] = in_array($me['id'], array_column($t['assignees'], 'id'), true);
        $t['pending_extension'] = $pending[$t['id']] ?? null;
    }
    return $rows;
}

/**
 * Creates or updates a task from form input (inside a transaction). Staff given a task who aren't on the job yet are
 * added to it. Returns [task id, newly given the task, newly added to the job].
 */
function job_task_store(int $jobId, array $t, array $me, int $taskId = 0): array
{
    $title = str_in($t['title'] ?? '', 190);
    $due = valid_date($t['due_date'] ?? null) ? $t['due_date'] : null;
    $time = time_in($t['due_time'] ?? null);
    $notes = str_in($t['notes'] ?? '', 2000);
    $who = array_values(array_unique(array_filter(array_map('intval', (array)($t['assignees'] ?? [])))));
    if ($title === '') {
        fail('Give each task a title.');
    }
    if (!$due) {
        fail("Set a due date for \"$title\".");
    }
    if (!$who) {
        fail("Choose who does \"$title\".");
    }
    $in = implode(',', array_fill(0, count($who), '?'));
    if ((int)q("SELECT COUNT(*) FROM users WHERE active = 1 AND id IN ($in)", $who)->fetchColumn() !== count($who)) {
        fail('One of the selected staff can\'t be given this task (inactive or missing).');
    }
    if ($taskId) {
        $old = q('SELECT due_date, due_time FROM job_tasks WHERE id = ?', [$taskId])->fetch();
        if (!can_approve_time($me) && $due . $time > $old['due_date'] . $old['due_time']) {
            fail(LATER_DUE_ADMIN_ONLY, 403);
        }
        q('UPDATE job_tasks SET title = ?, notes = ?, due_date = ?, due_time = ? WHERE id = ?', [$title, $notes, $due, $time, $taskId]);
        if ($due . $time > $old['due_date'] . $old['due_time']) {
            close_extensions($jobId, $taskId, 'approved', 'Due date moved to ' . due_label($due, $time) . '.');
        }
    } else {
        q('INSERT INTO job_tasks (job_id, title, notes, due_date, due_time, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())',
            [$jobId, $title, $notes, $due, $time, $me['id']]);
        $taskId = (int)db()->lastInsertId();
    }
    if (array_key_exists('remind_offsets', $t)) {
        q('UPDATE job_tasks SET remind_offsets = ? WHERE id = ?', [offsets_in($t['remind_offsets']), $taskId]);
    }
    $current = task_assignee_ids($taskId);
    $given = array_values(array_diff($who, $current));
    foreach ($given as $uid) {
        q('INSERT INTO job_task_assignees (task_id, user_id) VALUES (?, ?)', [$taskId, $uid]);
    }
    if ($gone = array_diff($current, $who)) {
        q('DELETE FROM job_task_assignees WHERE task_id = ? AND user_id IN (' . implode(',', array_map('intval', $gone)) . ')', [$taskId]);
    }
    $onJob = job_assignee_ids($jobId);
    $joined = array_values(array_diff($who, $onJob));
    if ($joined) {
        job_set_assignees($jobId, array_merge($onJob, $joined), $me);
        job_log($jobId, 'assign', job_assign_text($joined, []));
    }
    return [$taskId, $given, $joined];
}

function load_task(int $taskId, array $me): array
{
    $t = q('SELECT * FROM job_tasks WHERE id = ?', [$taskId])->fetch();
    if (!$t) {
        fail('Task not found.', 404);
    }
    $t['id'] = (int)$t['id'];
    $t['job'] = load_job((int)$t['job_id'], $me);
    return $t;
}

/** Admin adds or edits a task on a job. */
function act_job_task_save(array $in, array $me): void
{
    require_management();
    $job = load_job((int)($in['id'] ?? 0), $me);
    if (in_array($job['status'], ['done', 'cancelled'], true)) {
        fail('Reopen the job before changing its tasks.');
    }
    $taskId = (int)($in['task_id'] ?? 0);
    $isEdit = $taskId > 0;
    if ($isEdit && (int)load_task($taskId, $me)['job_id'] !== $job['id']) {
        fail('Task not found.', 404);
    }
    db()->beginTransaction();
    [$taskId, $given, $joined] = job_task_store($job['id'], $in, $me, $taskId);
    $line = job_task_line($taskId);
    job_log($job['id'], 'task', ($isEdit ? 'Task updated: ' : 'Task added: ') . $line);
    audit('job_task_save', 'job', $job['id'], ['task' => $taskId]);
    db()->commit();
    touch_change();
    if ($joined) {
        notify($joined, 'job', "New job {$job['ref']}: {$job['title']}", $job['client_name'], 'jobs/' . $job['id'], true, ['gtasks' => true, 'due' => (string)$job['due_date']]);
    }
    if ($given) {
        notify($given, 'job', "New task on {$job['ref']}: " . str_in($in['title'] ?? '', 190), $line . "\n" . $job['client_name'], 'jobs/' . $job['id'], !$joined);
    }
    json_out(['ok' => true, 'task_id' => $taskId]);
}

function act_job_task_delete(array $in, array $me): void
{
    require_management();
    $t = load_task((int)($in['task_id'] ?? 0), $me);
    db()->beginTransaction();
    q('DELETE FROM job_task_assignees WHERE task_id = ?', [$t['id']]);
    q('DELETE FROM job_extensions WHERE task_id = ?', [$t['id']]);
    q('DELETE FROM job_tasks WHERE id = ?', [$t['id']]);
    job_log($t['job']['id'], 'task', 'Task removed: ' . $t['title']);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

/** Only the task's staff (or an admin) can tick it, and only while its job is open. */
function task_tick_guard(array $t, array $me): void
{
    if (!is_management($me) && !in_array($me['id'], task_assignee_ids($t['id']), true)) {
        fail('"' . $t['title'] . '" isn\'t assigned to you.', 403);
    }
    if (!in_array($t['job']['status'], array_merge(JOB_ACTIVE, ['requested']), true)) {
        fail($t['job']['ref'] . ' is ' . str_replace('_', ' ', $t['job']['status']) . ', so its tasks can\'t be changed.');
    }
}

/** Tells the job's staff and admins when the last task is ticked, so the job gets marked complete. */
function all_tasks_done_notice(array $job): void
{
    $title = "All tasks on {$job['ref']} are done";
    notify(job_assignee_ids($job['id']), 'job', $title, 'Mark the job complete with photos and the client\'s sign-off. ' . $job['title'], 'jobs/' . $job['id'], false);
    notify_managers('job', $title, $job['title'] . ' · ' . $job['client_name'], 'jobs/' . $job['id'], false);
}

/** Tick several tasks done at once (the clock-out checklist), across one or more jobs. */
function act_job_tasks_done(array $in, array $me): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['task_ids'] ?? [])))));
    if (!$ids) {
        fail('Tick the tasks you finished.');
    }
    $note = str_in($in['note'] ?? '', 1000);
    $tasks = [];
    foreach ($ids as $id) {
        $t = load_task($id, $me);
        task_tick_guard($t, $me);
        if ($t['status'] === 'open') {
            $tasks[$t['job']['id']][] = $t;
        }
    }
    db()->beginTransaction();
    foreach ($tasks as $list) {
        $job = $list[0]['job'];
        foreach ($list as $t) {
            q("UPDATE job_tasks SET status = 'done', done_by = ?, done_at = NOW(), done_note = ? WHERE id = ?", [$me['id'], $note, $t['id']]);
            close_extensions($job['id'], $t['id'], 'cancelled', 'The task was done.');
        }
        log_job_work($job['id'], $job['status'], 'task', (count($list) === 1 ? 'Task done: ' : 'Tasks done: ')
            . implode(', ', array_column($list, 'title')) . ($note ? "\n" . $note : ''), $me['id']);
    }
    $finished = array_filter(array_map(fn($list) => $list[0]['job'], $tasks),
        fn($job) => !q("SELECT 1 FROM job_tasks WHERE job_id = ? AND status = 'open'", [$job['id']])->fetchColumn());
    db()->commit();
    touch_change();
    array_map('all_tasks_done_notice', $finished);
    json_out(['ok' => true, 'done' => array_sum(array_map('count', $tasks))]);
}

/** The task's staff (or an admin) tick a task done, with an optional note, or untick it. */
function act_job_task_toggle(array $in, array $me): void
{
    $t = load_task((int)($in['task_id'] ?? 0), $me);
    $job = $t['job'];
    task_tick_guard($t, $me);
    $done = !empty($in['done']);
    $note = str_in($in['note'] ?? '', 1000);
    if ($done === ($t['status'] === 'done')) {
        json_out(['ok' => true]);
    }
    db()->beginTransaction();
    if ($done) {
        q("UPDATE job_tasks SET status = 'done', done_by = ?, done_at = NOW(), done_note = ? WHERE id = ?", [$me['id'], $note, $t['id']]);
        close_extensions($job['id'], $t['id'], 'cancelled', 'The task was done.');
        log_job_work($job['id'], $job['status'], 'task', 'Task done: ' . $t['title'] . ($note ? "\n" . $note : ''), $me['id']);
    } else {
        q("UPDATE job_tasks SET status = 'open', done_by = NULL, done_at = NULL, done_note = '' WHERE id = ?", [$t['id']]);
        job_log($job['id'], 'task', 'Task reopened: ' . $t['title']);
    }
    $open = (int)q("SELECT COUNT(*) FROM job_tasks WHERE job_id = ? AND status = 'open'", [$job['id']])->fetchColumn();
    db()->commit();
    touch_change();
    if ($done && !$open) {
        all_tasks_done_notice($job);
    }
    json_out(['ok' => true, 'open' => $open]);
}

/* ---------------------------------------------------- daily accountability */

/**
 * A staff member's job-order checklist: their open tasks due by today (or with no date), and their jobs due by today
 * that have no open tasks left (those need "Mark complete"). With $upcoming, the ones due after today instead.
 * Each: {key, kind, id, job_id, ref, title, job_title, client_name, due_date, due_time, overdue, pending_extension}.
 */
function jobs_due_for(int $uid, bool $upcoming = false): array
{
    $when = $upcoming ? 'x.due_date > ?' : '(x.due_date IS NULL OR x.due_date <= ?)';
    $tasks = q("SELECT x.id, x.job_id, x.title, x.due_date, x.due_time, j.title AS job_title, j.client_name FROM job_tasks x
        JOIN job_task_assignees a ON a.task_id = x.id AND a.user_id = ? JOIN jobs j ON j.id = x.job_id
        WHERE x.status = 'open' AND j.status IN ('open','in_progress','returned') AND $when", [$uid, today()])->fetchAll();
    $jobs = q("SELECT x.id, x.id AS job_id, x.title, x.due_date, x.due_time, x.title AS job_title, x.client_name FROM jobs x
        JOIN job_assignees a ON a.job_id = x.id AND a.user_id = ? AND a.removed_at IS NULL
        WHERE x.status IN ('open','in_progress','returned') AND NOT EXISTS (SELECT 1 FROM job_tasks t WHERE t.job_id = x.id AND t.status = 'open')
        AND $when", [$uid, today()])->fetchAll();
    $items = [];
    $pending = [];
    foreach ([['task', $tasks], ['job', $jobs]] as [$kind, $rows]) {
        foreach ($rows as $r) {
            $jobId = (int)$r['job_id'];
            $pending[$jobId] = $pending[$jobId] ?? pending_extensions($jobId);
            $items[] = ['key' => $kind . ':' . $r['id'], 'kind' => $kind, 'id' => (int)$r['id'], 'job_id' => $jobId, 'ref' => job_ref($jobId),
                'title' => $r['title'], 'job_title' => $r['job_title'], 'client_name' => $r['client_name'], 'due_date' => $r['due_date'],
                'due_time' => hhmm($r['due_time']), 'overdue' => is_overdue($r['due_date'], $r['due_time']),
                'pending_extension' => $pending[$jobId][$kind === 'task' ? (int)$r['id'] : 0] ?? null];
        }
    }
    usort($items, fn($a, $b) => [$a['due_date'] ?? '', $a['due_time'] ?? '99', $a['job_id']] <=> [$b['due_date'] ?? '', $b['due_time'] ?? '99', $b['job_id']]);
    return $items;
}

/** The signed-in staff member's checklist for today and what's coming up. */
function act_jobs_due(array $in, array $me): void
{
    json_out(['ok' => true, 'due' => jobs_due_for($me['id']), 'upcoming' => array_slice(jobs_due_for($me['id'], true), 0, 30)]);
}

/** Staff ask for more time on a job or task that is due. An admin must approve before they can end their day. */
function act_job_extend(array $in, array $me): void
{
    $job = load_job((int)($in['id'] ?? 0), $me);
    $taskId = (int)($in['task_id'] ?? 0);
    if (!in_array($job['status'], JOB_ACTIVE, true)) {
        fail('This job isn\'t active.');
    }
    $task = null;
    if ($taskId) {
        $task = q('SELECT * FROM job_tasks WHERE id = ? AND job_id = ?', [$taskId, $job['id']])->fetch();
        if (!$task || $task['status'] !== 'open') {
            fail('This task is already done.');
        }
        if (!in_array($me['id'], task_assignee_ids($taskId), true)) {
            fail('This task isn\'t assigned to you.', 403);
        }
    } elseif (!in_array($me['id'], job_assignee_ids($job['id']), true)) {
        fail('This job isn\'t assigned to you.', 403);
    }
    $reason = str_in($in['reason'] ?? '', 1000);
    $date = valid_date($in['new_due_date'] ?? null) ? $in['new_due_date'] : null;
    $time = time_in($in['new_due_time'] ?? null);
    $errors = [];
    if ($reason === '') {
        $errors['reason'] = 'Say why it isn\'t finished.';
    }
    if (!$date || $date < today() || ($date === today() && (!$time || strtotime("$date $time") <= time()))) {
        $errors['new_due_date'] = 'Choose a new date (and time) later than now.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $what = $task ? 'task "' . $task['title'] . '"' : 'this job';
    db()->beginTransaction();
    close_extensions($job['id'], $taskId ?: null, 'cancelled', 'Replaced by a newer request.');
    q('INSERT INTO job_extensions (job_id, task_id, user_id, reason, new_due_date, new_due_time, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$job['id'], $taskId ?: null, $me['id'], $reason, $date, $time]);
    job_log($job['id'], 'extension', "Asked for more time on $what, until " . due_label($date, $time) . ': ' . $reason);
    db()->commit();
    touch_change();
    notify_managers('job', "{$me['name']} needs more time on {$job['ref']}", ($task ? 'Task: ' . $task['title'] . "\n" : '') . $job['title'] . "\nNew time: "
        . due_label($date, $time) . "\nWhy: $reason\nThey can't clock out for the day until you answer. If you don't answer within an hour, they can go ahead.",
        'jobs/' . $job['id'], true);
    json_out(['ok' => true]);
}

/** Admin approves (moves the due date) or refuses a request for more time. */
function act_job_extension_decide(array $in, array $me): void
{
    if (!can_approve_time($me)) {
        fail('Only an admin or manager can give more time.', 403);
    }
    $x = q('SELECT * FROM job_extensions WHERE id = ?', [(int)($in['extension_id'] ?? 0)])->fetch();
    if (!$x || $x['status'] !== 'pending') {
        fail('This request has already been answered.');
    }
    if ((int)$x['user_id'] === $me['id'] && !is_admin($me)) {
        fail('Someone else has to answer your own request for more time.', 403);
    }
    $job = load_job((int)$x['job_id'], $me);
    $approve = ($in['decision'] ?? '') === 'approve';
    $note = str_in($in['note'] ?? '', 500);
    if (!$approve && $note === '') {
        fail('Say why more time isn\'t given.');
    }
    $task = $x['task_id'] ? q('SELECT title FROM job_tasks WHERE id = ?', [$x['task_id']])->fetchColumn() : null;
    $what = $task ? 'task "' . $task . '"' : 'the job';
    $until = due_label($x['new_due_date'], $x['new_due_time']);
    db()->beginTransaction();
    q('UPDATE job_extensions SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?',
        [$approve ? 'approved' : 'rejected', $me['id'], $note, $x['id']]);
    if ($approve) {
        apply_extension_dates($x, $job);
    }
    job_log($job['id'], 'extension', ($approve ? "More time approved on $what, until $until." : "More time refused on $what.") . ($note ? ' ' . $note : ''));
    audit('job_extension_' . ($approve ? 'approve' : 'reject'), 'job', $job['id'], ['extension' => (int)$x['id']]);
    db()->commit();
    touch_change();
    notify([(int)$x['user_id']], 'job', ($approve ? 'More time approved' : 'More time refused') . " on {$job['ref']}",
        ($approve ? ucfirst($what) . " is now due $until. You can clock out." : ucfirst($what) . ' must be finished today.' . ($note ? ' ' . $note : '')),
        'jobs/' . $job['id'], true);
    json_out(['ok' => true]);
}

/** Sub admins and supervisors recommend approving or refusing a request for more time. Admins and managers decide. */
function act_job_extension_recommend(array $in, array $me): void
{
    require_management();
    $x = q('SELECT * FROM job_extensions WHERE id = ?', [(int)($in['extension_id'] ?? 0)])->fetch();
    if (!$x || $x['status'] !== 'pending') {
        fail('This request has already been answered.');
    }
    $job = load_job((int)$x['job_id'], $me);
    $approve = ($in['decision'] ?? '') === 'approve';
    $note = str_in($in['note'] ?? '', 500);
    if (!$approve && $note === '') {
        fail('Say why you recommend refusing.');
    }
    $task = $x['task_id'] ? q('SELECT title FROM job_tasks WHERE id = ?', [$x['task_id']])->fetchColumn() : null;
    $what = $task ? 'task "' . $task . '"' : 'the job';
    $until = due_label($x['new_due_date'], $x['new_due_time']);
    $word = $approve ? 'approving' : 'refusing';
    db()->beginTransaction();
    q('UPDATE job_extensions SET recommended_by = ?, recommendation = ?, recommend_note = ?, recommended_at = NOW() WHERE id = ?',
        [$me['id'], $approve ? 'approve' : 'refuse', $note, $x['id']]);
    job_log($job['id'], 'extension', "{$me['name']} recommends $word more time on $what, until $until." . ($note ? ' ' . $note : ''));
    audit('job_extension_recommend', 'job', $job['id'], ['extension' => (int)$x['id'], 'recommend' => $approve ? 'approve' : 'refuse']);
    db()->commit();
    touch_change();
    notify_approvers('job', "{$me['name']} recommends $word more time on {$job['ref']}", ucfirst($what) . " until $until.\nWhy they asked: {$x['reason']}"
        . ($note ? "\n{$me['name']}'s note: $note" : '') . "\nOnly an admin or manager can approve or refuse it.", 'jobs/' . $job['id'], true);
    json_out(['ok' => true]);
}

/** Moves the task's (or job's) due date to the one asked for in a request for more time. */
function apply_extension_dates(array $x, array $job): void
{
    if ($x['task_id']) {
        q('UPDATE job_tasks SET due_date = ?, due_time = ? WHERE id = ?', [$x['new_due_date'], $x['new_due_time'], $x['task_id']]);
    }
    // The job's own deadline moves too when the new time is later, so it isn't left overdue.
    if (!$x['task_id'] || ($job['due_date'] && $job['due_date'] . $job['due_time'] < $x['new_due_date'] . $x['new_due_time'])) {
        q('UPDATE jobs SET due_date = ?, due_time = ? WHERE id = ?', [$x['new_due_date'], $x['new_due_time'], $job['id']]);
    }
}

/** No admin answered a request for more time within the hour: the person who asked moves the due date themselves. */
function act_job_extension_proceed(array $in, array $me): void
{
    $x = q('SELECT * FROM job_extensions WHERE id = ?', [(int)($in['extension_id'] ?? 0)])->fetch();
    if (!$x || $x['status'] !== 'pending') {
        fail('This request has already been answered.');
    }
    if ((int)$x['user_id'] !== $me['id']) {
        fail('Only the person who asked can go ahead without an answer.', 403);
    }
    if (strtotime($x['created_at']) > time() - ADMIN_ANSWER_WAIT) {
        fail('An admin has until ' . date('g:ia', strtotime($x['created_at']) + ADMIN_ANSWER_WAIT) . ' to answer. Try again then.');
    }
    $job = load_job((int)$x['job_id'], $me);
    $task = $x['task_id'] ? q('SELECT title FROM job_tasks WHERE id = ?', [$x['task_id']])->fetchColumn() : null;
    $what = $task ? 'task "' . $task . '"' : 'the job';
    $until = due_label($x['new_due_date'], $x['new_due_time']);
    db()->beginTransaction();
    q("UPDATE job_extensions SET status = 'lapsed', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?",
        [$me['id'], 'No admin answer within an hour.', $x['id']]);
    apply_extension_dates($x, $job);
    job_log($job['id'], 'extension', "No admin answered within an hour, so {$me['name']} moved $what to $until.");
    audit('job_extension_lapsed', 'job', $job['id'], ['extension' => (int)$x['id']]);
    db()->commit();
    touch_change();
    notify_managers('job', "{$me['name']} went ahead without your answer on {$job['ref']}", ucfirst($what) . " is now due $until.\nWhy they asked: {$x['reason']}\n"
        . 'Nobody answered the request within an hour. You can change the due date on the job if needed.', 'jobs/' . $job['id'], true);
    json_out(['ok' => true]);
}
