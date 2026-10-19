<?php
/*
 * Reminders before a job or task is due, e.g. 1 hr and 30 min before. Run every 5 minutes by cron.php
 * (or by the live pulse at most once a minute when no cron is set up):
 *  - each person on an open task (or on a job with no open tasks) gets a reminder as each offset passes;
 *    a late run sends only the nearest one and skips the rest
 *  - from the reminder they can say Got it, ask again in 10 minutes, snooze, or escalate to the job creator and managers
 *  - an item still open at its due time that nobody acknowledged is escalated once, unless more time was asked for
 * Rows in due_reminders are unique per item, person, due time and offset, so repeated runs never send twice.
 */

/** Minutes before the due time a reminder can be set for. */
const DUE_REMIND_CHOICES = [1440, 120, 60, 30, 15, 10, 5];
const DUE_SNOOZE_CHOICES = [10, 30, 60];

function due_reminder_settings(): array
{
    $s = setting('due_reminders');
    $s = is_array($s) ? $s : [];
    return [
        'enabled' => ($s['enabled'] ?? true) !== false,
        'offsets' => clean_offsets($s['offsets'] ?? [60, 30]),
        'auto_escalate' => ($s['auto_escalate'] ?? true) !== false,
        'email' => !empty($s['email']),
    ];
}

/** Allowed offsets from a list or "60,30", largest first. */
function clean_offsets($v): array
{
    $list = is_array($v) ? $v : explode(',', (string)$v);
    $list = array_values(array_unique(array_intersect(array_map('intval', $list), DUE_REMIND_CHOICES)));
    rsort($list);
    return $list;
}

/** A job or task form's reminder choice as a column value: NULL uses the default, '' is off. */
function offsets_in($v): ?string
{
    if ($v === null || $v === 'default') {
        return null;
    }
    return implode(',', clean_offsets($v === 'off' ? [] : $v));
}

/** "1 hr", "2 hr 30 min", "1 day" for reminder titles and the settings page. */
function minutes_label(int $m): string
{
    if ($m < 60) {
        return $m . ' min';
    }
    if ($m >= 1440 && $m % 1440 === 0) {
        return ($m / 1440) . ' day' . ($m === 1440 ? '' : 's');
    }
    return intdiv($m, 60) . ' hr' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
}

/** When an item is due: its due time, or the end of the working day when it only has a date. */
function due_at(string $date, ?string $time): string
{
    return $date . ' ' . ($time ? substr($time, 0, 5) : work_end()) . ':00';
}

/** One task or job with what reminders need, or null when it's gone. 'open' says reminders still apply. */
function due_item(string $kind, int $id): ?array
{
    $r = $kind === 'task'
        ? q('SELECT t.id, t.job_id, t.title, t.due_date, t.due_time, t.remind_offsets, t.status = \'open\' AND j.status IN (\'open\',\'in_progress\',\'returned\') AS open,
            j.title AS job_title, j.client_name, j.created_by FROM job_tasks t JOIN jobs j ON j.id = t.job_id WHERE t.id = ?', [$id])->fetch()
        : q('SELECT j.id, j.id AS job_id, j.title, j.due_date, j.due_time, j.remind_offsets, j.status IN (\'open\',\'in_progress\',\'returned\')
            AND NOT EXISTS (SELECT 1 FROM job_tasks t WHERE t.job_id = j.id AND t.status = \'open\') AS open,
            j.title AS job_title, j.client_name, j.created_by FROM jobs j WHERE j.id = ?', [$id])->fetch();
    if (!$r || !$r['due_date']) {
        return null;
    }
    $r['kind'] = $kind;
    $r['open'] = (bool)$r['open'];
    $r['due_at'] = due_at($r['due_date'], $r['due_time']);
    return $r;
}

/** Open tasks and jobs due between two dates, one row per person on them. */
function due_items_between(string $from, string $to): array
{
    $tasks = q("SELECT 'task' AS kind, t.id, t.job_id, t.title, t.due_date, t.due_time, t.remind_offsets, j.title AS job_title, j.client_name, j.created_by, a.user_id
        FROM job_tasks t JOIN jobs j ON j.id = t.job_id JOIN job_task_assignees a ON a.task_id = t.id JOIN users u ON u.id = a.user_id AND u.active = 1
        WHERE t.status = 'open' AND j.status IN ('open','in_progress','returned') AND t.due_date BETWEEN ? AND ?", [$from, $to])->fetchAll();
    $jobs = q("SELECT 'job' AS kind, j.id, j.id AS job_id, j.title, j.due_date, j.due_time, j.remind_offsets, j.title AS job_title, j.client_name, j.created_by, a.user_id
        FROM jobs j JOIN job_assignees a ON a.job_id = j.id AND a.removed_at IS NULL JOIN users u ON u.id = a.user_id AND u.active = 1
        WHERE j.status IN ('open','in_progress','returned') AND NOT EXISTS (SELECT 1 FROM job_tasks t WHERE t.job_id = j.id AND t.status = 'open')
        AND j.due_date BETWEEN ? AND ?", [$from, $to])->fetchAll();
    $out = [];
    foreach (array_merge($tasks, $jobs) as $r) {
        $r['due_at'] = due_at($r['due_date'], $r['due_time']);
        $out[] = $r;
    }
    return $out;
}

/** "Due in 30 min", "Due now", "Overdue by 1 hr". */
function due_when(string $dueAt): string
{
    $m = (int)ceil((strtotime($dueAt) - time()) / 60);
    if ($m > 1) {
        return 'Due in ' . minutes_label($m);
    }
    return $m >= -1 ? 'Due now' : 'Overdue by ' . minutes_label(-$m);
}

function due_item_line(array $item): string
{
    $ref = job_ref((int)$item['job_id']);
    return ($item['kind'] === 'task' ? "Task on $ref {$item['job_title']}" : $ref)
        . ($item['client_name'] ? ' · ' . $item['client_name'] : '') . ' · due ' . due_label($item['due_date'], $item['due_time']);
}

/** In-app notification, phone alert with Snooze/Escalate buttons, and email when turned on. */
function send_due_reminder(array $row, array $item): void
{
    $email = due_reminder_settings()['email'] && mail_enabled();
    notify([(int)$row['user_id']], 'due_reminder', due_when($item['due_at']) . ': ' . $item['title'],
        due_item_line($item) . "\nTick it off when it's done, snooze it, or escalate if you need help.", 'jobs/' . $item['job_id'], $email,
        ['push_data' => ['rid' => (int)$row['id'], 'token' => $row['token']]]);
}

function new_due_reminder(array $item, int $uid, int $offset, string $status): ?array
{
    $token = bin2hex(random_bytes(16));
    $n = q('INSERT IGNORE INTO due_reminders (kind, item_id, job_id, user_id, due_at, offset_min, status, sent_at, token, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())', [$item['kind'], $item['id'], $item['job_id'], $uid, $item['due_at'], $offset, $status,
        $status === 'sent' ? date('Y-m-d H:i:s') : null, $token])->rowCount();
    return $n ? ['id' => (int)db()->lastInsertId(), 'user_id' => $uid, 'token' => $token] : null;
}

/** Without a cron job, an open portal tab runs the reminders at most once a minute, after the response is sent. */
function maybe_run_due_reminders(): void
{
    if ((float)(setting('_due_scan') ?: 0) > time() - 60) {
        return;
    }
    save_setting('_due_scan', time());
    after_response(function () {
        try {
            run_due_reminders();
        } catch (Throwable $e) {
            error_log('[portal] due reminders: ' . $e->getMessage());
        }
    });
}

/** Sends whatever is due now. Returns a short log for cron.php. */
function run_due_reminders(): array
{
    $s = due_reminder_settings();
    if (!$s['enabled']) {
        return ['Before-due reminders are off.'];
    }
    // Only items due after reminders were first switched on are escalated, so old overdue work isn't flooded out on day one.
    static $since = null;
    $since = $since ?? (string)(setting('_due_since') ?: '');
    if ($since === '') {
        $since = date('Y-m-d H:i:s');
        save_setting('_due_since', $since);
    }
    $now = time();
    $max = max(DUE_REMIND_CHOICES);
    $items = due_items_between(date('Y-m-d', $now - 86400), date('Y-m-d', $now + $max * 60));
    $have = [];
    foreach (q('SELECT kind, item_id, user_id, due_at, offset_min FROM due_reminders WHERE due_at >= ?', [date('Y-m-d H:i:s', $now - 2 * 86400)])->fetchAll() as $r) {
        $have["{$r['kind']}:{$r['item_id']}:{$r['user_id']}:{$r['due_at']}:{$r['offset_min']}"] = true;
    }
    $sent = 0;
    $byItem = [];
    foreach ($items as $item) {
        $uid = (int)$item['user_id'];
        $byItem["{$item['kind']}:{$item['id']}"] = $item;
        $due = strtotime($item['due_at']);
        if ($now >= $due) {
            continue;
        }
        $offsets = $item['remind_offsets'] === null ? $s['offsets'] : clean_offsets($item['remind_offsets']);
        $passed = array_values(array_filter($offsets, fn($o) => $now >= $due - $o * 60));
        if (!$passed) {
            continue;
        }
        $nearest = min($passed);
        $key = "{$item['kind']}:{$item['id']}:$uid:{$item['due_at']}:";
        if (isset($have[$key . $nearest])) {
            continue;
        }
        foreach ($passed as $o) {
            if ($o !== $nearest && !isset($have[$key . $o])) {
                new_due_reminder($item, $uid, $o, 'skipped');
            }
        }
        if ($row = new_due_reminder($item, $uid, $nearest, 'sent')) {
            // The newer reminder replaces any earlier one still waiting or snoozed.
            q("UPDATE due_reminders SET status = 'cleared' WHERE kind = ? AND item_id = ? AND user_id = ? AND due_at = ? AND id <> ? AND status IN ('sent','snoozed')",
                [$item['kind'], $item['id'], $uid, $item['due_at'], $row['id']]);
            send_due_reminder($row, $item);
            $sent++;
        }
    }

    $resent = 0;
    foreach (q("SELECT * FROM due_reminders WHERE status = 'snoozed' AND snooze_until <= NOW()")->fetchAll() as $r) {
        $item = due_item($r['kind'], (int)$r['item_id']);
        $live = $item && $item['open'] && $item['due_at'] === $r['due_at'];
        $claimed = q("UPDATE due_reminders SET status = ?, snooze_until = NULL, sent_at = IF(? = 'sent', NOW(), sent_at) WHERE id = ? AND status = 'snoozed'",
            [$live ? 'sent' : 'cleared', $live ? 'sent' : 'cleared', $r['id']])->rowCount();
        if ($live && $claimed) {
            send_due_reminder($r, $item);
            $resent++;
        }
    }

    $escalated = 0;
    if ($s['auto_escalate']) {
        foreach ($byItem as $item) {
            if (strtotime($item['due_at']) > $now || $item['due_at'] < $since) {
                continue;
            }
            // Someone said Got it, snoozed or escalated: they know about it.
            $handled = (int)q("SELECT COUNT(*) FROM due_reminders WHERE kind = ? AND item_id = ? AND due_at = ? AND action <> ''",
                [$item['kind'], $item['id'], $item['due_at']])->fetchColumn();
            $asked = (int)q("SELECT COUNT(*) FROM job_extensions WHERE job_id = ? AND COALESCE(task_id, 0) = ? AND status = 'pending'",
                [$item['job_id'], $item['kind'] === 'task' ? $item['id'] : 0])->fetchColumn();
            if ($handled || $asked) {
                continue;
            }
            // User 0, offset 0 marks the one automatic escalation for this due time.
            if (new_due_reminder($item, 0, 0, 'escalated')) {
                escalate_due_item($item, null, '');
                $escalated++;
            }
        }
    }
    if ($sent || $resent || $escalated) {
        touch_change();
    }
    return ["Before-due reminders: $sent sent, $resent snoozed re-sent, $escalated escalated."];
}

/** Tells the job creator and admins/managers that an item needs attention. $actor is null for the automatic escalation. */
function escalate_due_item(array $item, ?array $actor, string $reason): void
{
    $ref = job_ref((int)$item['job_id']);
    $staff = user_names($item['kind'] === 'task' ? task_assignee_ids((int)$item['id']) : job_assignee_ids((int)$item['job_id']));
    $what = ($item['kind'] === 'task' ? 'Task "' . $item['title'] . '"' : 'The job') . ' (due ' . due_label($item['due_date'], $item['due_time']) . ')';
    $text = $actor
        ? $actor['name'] . " escalated a reminder: $what." . ($reason !== '' ? ' ' . $reason : '')
        : "$what is still open at its due time and nobody acknowledged the reminder." . ($staff ? " On it: $staff." : '');
    job_log((int)$item['job_id'], 'reminder', $text, null, $actor['id'] ?? null);
    audit('reminder_escalate', 'job', (int)$item['job_id'], ['kind' => $item['kind'], 'item' => (int)$item['id'], 'auto' => !$actor]);
    $ids = array_map('intval', q("SELECT id FROM users WHERE role IN ('admin','manager') AND active = 1")->fetchAll(PDO::FETCH_COLUMN));
    $ids[] = (int)$item['created_by'];
    $ids = array_diff(array_unique($ids), [$actor['id'] ?? 0]);
    notify($ids, 'job', ($actor ? 'Escalated: ' : 'Not done on time: ') . "$ref {$item['title']}", $text . "\n" . $item['client_name'], 'jobs/' . $item['job_id'], true);
}

/** Reminders waiting for this person's answer, for the in-app sheet. */
function due_reminders_for(int $uid): array
{
    $out = [];
    foreach (q("SELECT id, kind, item_id, due_at, offset_min FROM due_reminders WHERE user_id = ? AND status = 'sent' AND offset_min > 0
        AND sent_at >= ? ORDER BY sent_at DESC LIMIT 5", [$uid, date('Y-m-d H:i:s', time() - 2 * 86400)])->fetchAll() as $r) {
        $item = due_item($r['kind'], (int)$r['item_id']);
        if (!$item || !$item['open'] || $item['due_at'] !== $r['due_at']) {
            q("UPDATE due_reminders SET status = 'cleared' WHERE id = ?", [$r['id']]);
            continue;
        }
        $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'job_id' => (int)$item['job_id'], 'ref' => job_ref((int)$item['job_id']),
            'title' => $item['title'], 'job_title' => $item['job_title'], 'client_name' => $item['client_name'],
            'due_date' => $item['due_date'], 'due_time' => hhmm($item['due_time']), 'when' => due_when($item['due_at']),
            'can_snooze_before' => strtotime($item['due_at']) - 15 * 60 > time() + 5 * 60];
    }
    return $out;
}

function act_reminders_mine(array $in, array $me): void
{
    json_out(['ok' => true, 'items' => due_reminders_for($me['id'])]);
}

/**
 * Got it / snooze / escalate on a reminder. Works from the portal (session and CSRF token), or from a phone alert's
 * buttons with the reminder's own token, since the service worker has no CSRF token.
 */
function act_reminder_act(array $in, ?array $me): void
{
    $r = q('SELECT * FROM due_reminders WHERE id = ? AND offset_min > 0', [(int)($in['id'] ?? 0)])->fetch();
    if (!$r) {
        fail('Reminder not found.', 404);
    }
    $token = (string)($in['token'] ?? '');
    if ($token !== '') {
        if (!hash_equals($r['token'], $token)) {
            fail('This reminder link is not valid.', 403);
        }
        $me = q('SELECT id, name FROM users WHERE id = ? AND active = 1', [$r['user_id']])->fetch() ?: null;
        if (!$me) {
            fail('Sign in to continue.', 401);
        }
        $me['id'] = (int)$me['id'];
    } else {
        if (!$me) {
            fail('Sign in to continue.', 401);
        }
        if (empty($GLOBALS['CSRF_OK'])) {
            fail('Your session expired. Reload the page and try again.', 419);
        }
        if ((int)$r['user_id'] !== $me['id']) {
            fail('This reminder is for someone else.', 403);
        }
    }
    $action = (string)($in['action'] ?? '');
    if (!in_array($action, ['ack', 'snooze', 'escalate'], true)) {
        fail('Choose Got it, Snooze or Escalate.');
    }
    if (!in_array($r['status'], ['sent', 'snoozed'], true)) {
        json_out(['ok' => true, 'message' => 'This reminder was already handled.']);
    }
    $item = due_item($r['kind'], (int)$r['item_id']);
    if (!$item || !$item['open'] || $item['due_at'] !== $r['due_at']) {
        q("UPDATE due_reminders SET status = 'cleared' WHERE id = ?", [$r['id']]);
        json_out(['ok' => true, 'message' => 'This is already done or its due time has changed.']);
    }

    if ($action === 'ack') {
        q("UPDATE due_reminders SET status = 'acked', action = 'ack', acted_at = NOW(), snooze_until = NULL WHERE id = ?", [$r['id']]);
        json_out(['ok' => true, 'message' => 'Got it.']);
    }
    if ($action === 'snooze') {
        $min = $in['minutes'] ?? 10;
        if ($min === 'before') {
            $until = strtotime($item['due_at']) - 15 * 60;
            if ($until <= time() + 5 * 60) {
                fail('It\'s due too soon for that. Snooze for 10 minutes instead.');
            }
            $label = "15 minutes before it's due";
        } else {
            $min = (int)$min;
            if (!in_array($min, DUE_SNOOZE_CHOICES, true)) {
                fail('Choose how long to snooze for.');
            }
            $until = time() + $min * 60;
            $label = 'in ' . minutes_label($min);
        }
        q("UPDATE due_reminders SET status = 'snoozed', action = 'snooze', acted_at = NOW(), snooze_until = ? WHERE id = ?", [date('Y-m-d H:i:s', $until), $r['id']]);
        json_out(['ok' => true, 'message' => "We'll remind you again $label."]);
    }
    $reason = str_in($in['reason'] ?? '', 500);
    if ($reason === '' && $token !== '') {
        $reason = 'Sent from a phone alert.';
    }
    q("UPDATE due_reminders SET status = 'escalated', action = 'escalate', acted_at = NOW(), snooze_until = NULL WHERE id = ?", [$r['id']]);
    escalate_due_item($item, $me, $reason);
    touch_change();
    json_out(['ok' => true, 'message' => 'Escalated to the job creator and managers.']);
}
