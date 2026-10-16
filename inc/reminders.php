<?php
/*
 * Follow-up reminders, run hourly by cron.php (or by the live pulse when no cron is set up):
 *  - daily digest after the reminder time: unfinished jobs and attendance problems for admins, own jobs for each staff member
 *  - clock-out reminder an hour after work ends, for anyone still clocked in
 *  - missed clock-out sweep: yesterday's open clock-ins are marked "No clock-out"
 * Each part runs once a day; markers in the settings table (_rem_*) stop repeats.
 */

function reminder_settings(): array
{
    $emails = setting('reminder_emails');
    return [
        'enabled' => setting('reminders_enabled') !== false,
        'time' => hhmm_setting('reminder_time', '08:00'),
        'emails' => is_array($emails) ? $emails : [],
        'stale_days' => max(1, (int)(setting('stale_days') ?: 3)),
    ];
}

function mail_enabled(): bool
{
    return !empty((setting('smtp') ?: [])['enabled']);
}

/** True when some part of run_reminders() has work to do now; cheap enough for every pulse. */
function reminders_due(): bool
{
    $t = today();
    if (setting('_rem_missed') !== $t) {
        return true;
    }
    $rs = reminder_settings();
    if (!$rs['enabled']) {
        return false;
    }
    return (setting('_rem_digest') !== $t && time() >= strtotime("$t {$rs['time']}"))
        || (setting('_rem_clockout') !== $t && time() >= strtotime($t . ' ' . work_end()) + 3600);
}

/** Without a cron job, the first open portal tab after the due time runs the reminders once the response is sent. */
function maybe_run_reminders(): void
{
    if (!reminders_due()) {
        return;
    }
    $lock = (float)(setting('_rem_lock') ?: 0);
    if ($lock > time() - 300) {
        return;
    }
    save_setting('_rem_lock', time());
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            run_reminders();
        } catch (Throwable $e) {
            error_log('[portal] reminders: ' . $e->getMessage());
        }
    });
}

/** Runs whatever is due. $force sends the digest now (Settings test button). Returns a short log. */
function run_reminders(bool $force = false): array
{
    $t = today();
    $rs = reminder_settings();
    $log = [];

    if (setting('_rem_missed') !== $t) {
        save_setting('_rem_missed', $t);
        $n = q('UPDATE attendance SET out_missed = 1 WHERE out_at IS NULL AND out_missed = 0 AND work_date < ?', [$t])->rowCount();
        $log[] = "Marked $n missed clock-out(s).";
    }

    if ($force || ($rs['enabled'] && setting('_rem_digest') !== $t && time() >= strtotime("$t {$rs['time']}"))) {
        save_setting('_rem_digest', $t);
        $log = array_merge($log, send_digests($rs));
    }

    // Lesson plans not yet posted for timetabled classes today and tomorrow (in-app, with the digest).
    if ($rs['enabled'] && setting('_rem_timetable') !== $t && time() >= strtotime("$t {$rs['time']}")) {
        save_setting('_rem_timetable', $t);
        $log[] = 'Lesson plan reminders: ' . timetable_plan_reminders() . '.';
    }

    if ($rs['enabled'] && setting('_rem_clockout') !== $t && time() >= strtotime($t . ' ' . work_end()) + 3600) {
        save_setting('_rem_clockout', $t);
        $open = q('SELECT a.user_id, a.in_at FROM attendance a JOIN users u ON u.id = a.user_id AND u.active = 1
            WHERE a.work_date = ? AND a.out_at IS NULL AND a.out_missed = 0', [$t])->fetchAll();
        foreach ($open as $a) {
            $due = jobs_due_for((int)$a['user_id']);
            $jobs = $due ? "\n\nBefore you can clock out, finish these or ask for more time:\n"
                . implode("\n", array_map(fn($x) => "{$x['ref']} {$x['title']}", $due)) : '';
            notify([(int)$a['user_id']], 'attendance', 'Don\'t forget to clock out',
                'You clocked in at ' . date('g:ia', strtotime($a['in_at'])) . ' and haven\'t clocked out. Clock out with a selfie when you leave.' . $jobs, '', mail_enabled());
        }
        $log[] = 'Clock-out reminders: ' . count($open) . '.';
    }
    if ($log) {
        touch_change();
    }
    return $log;
}

/* ------------------------------------------------------------------ digest */

/** Open jobs that need chasing, grouped for the admin digest. */
function digest_sections(int $staleDays): array
{
    $t = today();
    $active = "j.status IN ('open','in_progress','returned','requested')";
    $list = fn(string $where, array $p = [], string $order = 'j.due_date, j.id') => array_map('job_row', q(JOB_LIST_SQL . " WHERE $where ORDER BY $order LIMIT 100", $p)->fetchAll());
    return [
        'overdue' => ['Overdue jobs', $list("$active AND j.due_date < ?", [$t])],
        'due' => ['Due today or tomorrow', $list("$active AND j.due_date BETWEEN ? AND ?", [$t, date('Y-m-d', strtotime('+1 day'))])],
        'high' => ['High and urgent jobs still open', $list("$active AND j.priority IN ('high','urgent')", [], "FIELD(j.priority,'urgent','high'), j.due_date")],
        'stalled' => ["No update for $staleDays+ days", $list("j.status IN ('open','in_progress','returned') AND COALESCE(j.updated_at, j.created_at) < ?",
            [date('Y-m-d H:i:s', strtotime("-$staleDays days"))], 'j.updated_at')],
        'check' => ['Waiting for your check', $list("j.status = 'awaiting_check'", [], 'j.completed_at')],
        'requested' => ['Logged by staff, not assigned yet', $list("j.status = 'requested'", [], 'j.created_at')],
    ];
}

/** The last working day before today, for yesterday's attendance. */
function last_work_day(): ?string
{
    for ($i = 1; $i <= 7; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        if (in_array((int)date('N', strtotime($d)), work_days(), true)) {
            return $d;
        }
    }
    return null;
}

function digest_job_rows(array $jobs, bool $showStaff = true): string
{
    $base = rtrim((string)cfg('base_url'), '/');
    $rows = '';
    foreach ($jobs as $j) {
        $flag = in_array($j['priority'], ['high', 'urgent'], true) ? ' <b style="color:' . ($j['priority'] === 'urgent' ? '#A32D2D' : '#854F0B') . '">' . strtoupper($j['priority']) . '</b>' : '';
        $due = $j['due_date'] ? 'Due ' . date('j M', strtotime($j['due_date'])) : '';
        $who = $showStaff ? (implode(', ', array_column($j['assignees'], 'name')) ?: '<i>Not assigned</i>') : '';
        $link = $base ? '<a href="' . htmlspecialchars("$base/#jobs/{$j['id']}") . '" style="color:#306090">' . $j['ref'] . '</a>' : $j['ref'];
        $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eee;white-space:nowrap">' . $link . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee">' . htmlspecialchars($j['title']) . $flag . '<br><span style="color:#777">'
            . htmlspecialchars($j['client_name']) . '</span></td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eee;color:#555">' . $who . ($who && $due ? '<br>' : '') . $due . '</td></tr>';
    }
    return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px">' . $rows . '</table>';
}

function digest_section(string $title, string $inner, int $n): string
{
    return '<h3 style="font-size:15px;margin:20px 0 6px">' . htmlspecialchars($title) . ' <span style="color:#777;font-weight:normal">(' . $n . ')</span></h3>' . $inner;
}

/** [subject, html, item count] for the admin digest. */
function build_admin_digest(array $rs): array
{
    $html = '';
    $count = 0;
    $summary = [];
    $short = ['overdue' => 'overdue', 'high' => 'high priority', 'check' => 'to check'];
    foreach (digest_sections($rs['stale_days']) as $key => [$title, $jobs]) {
        if ($jobs) {
            $html .= digest_section($title, digest_job_rows($jobs), count($jobs));
            $count += count($jobs);
            if (isset($short[$key])) {
                $summary[] = count($jobs) . ' ' . $short[$key];
            }
        }
    }

    // Requests for more time: the staff member can't end their day until one is answered.
    $ext = q("SELECT x.job_id, x.reason, x.new_due_date, x.new_due_time, u.name, t.title AS task FROM job_extensions x JOIN users u ON u.id = x.user_id
        LEFT JOIN job_tasks t ON t.id = x.task_id WHERE x.status = 'pending' ORDER BY x.created_at")->fetchAll();
    if ($ext) {
        $items = array_map(fn($x) => '<li>' . job_ref((int)$x['job_id']) . ($x['task'] ? ' · ' . htmlspecialchars($x['task']) : '') . ' · '
            . htmlspecialchars($x['name']) . ' wants until ' . due_label($x['new_due_date'], $x['new_due_time']) . ': ' . htmlspecialchars($x['reason']) . '</li>', $ext);
        $html .= digest_section('Requests for more time', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($ext));
        $count += count($ext);
        $summary[] = count($ext) . ' asking for more time';
    }
    // Where staff went ahead because no admin answered within the hour, since the last digest.
    $since = date('Y-m-d H:i:s', strtotime('-1 day'));
    $selfClosed = q("SELECT j.id, j.title, u.name FROM jobs j LEFT JOIN users u ON u.id = j.verified_by
        WHERE j.self_closed = 1 AND j.status = 'done' AND j.verified_at >= ? ORDER BY j.verified_at", [$since])->fetchAll();
    $lapsed = q("SELECT x.job_id, x.new_due_date, x.new_due_time, u.name, t.title AS task FROM job_extensions x JOIN users u ON u.id = x.user_id
        LEFT JOIN job_tasks t ON t.id = x.task_id WHERE x.status = 'lapsed' AND x.decided_at >= ? ORDER BY x.decided_at", [$since])->fetchAll();
    if ($selfClosed || $lapsed) {
        $items = array_merge(
            array_map(fn($j) => '<li>' . job_ref((int)$j['id']) . ' · ' . htmlspecialchars($j['title']) . ': closed by ' . htmlspecialchars((string)$j['name'])
                . ' without your check</li>', $selfClosed),
            array_map(fn($x) => '<li>' . job_ref((int)$x['job_id']) . ($x['task'] ? ' · ' . htmlspecialchars($x['task']) : '') . ': '
                . htmlspecialchars($x['name']) . ' moved it to ' . due_label($x['new_due_date'], $x['new_due_time']) . ' without an answer</li>', $lapsed));
        $html .= digest_section('Not answered within an hour', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items)
            . '</ul><p style="font-size:13px">You can still send a closed job back, or change a due date.</p>', count($items));
        $count += count($items);
    }
    // Tasks left past their due date with no approved extension.
    $late = q("SELECT t.job_id, t.title, t.due_date, t.due_time, (SELECT GROUP_CONCAT(u.name SEPARATOR ', ') FROM job_task_assignees a JOIN users u ON u.id = a.user_id
        WHERE a.task_id = t.id) AS staff FROM job_tasks t JOIN jobs j ON j.id = t.job_id WHERE t.status = 'open' AND t.due_date < ?
        AND j.status IN ('open','in_progress','returned') ORDER BY t.due_date LIMIT 100", [today()])->fetchAll();
    if ($late) {
        $items = array_map(fn($t) => '<li>' . job_ref((int)$t['job_id']) . ' · ' . htmlspecialchars($t['title']) . ' · ' . htmlspecialchars((string)$t['staff'])
            . ', was due ' . due_label($t['due_date'], $t['due_time']) . '</li>', $late);
        $html .= digest_section('Tasks carried over without approval', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($late));
        $count += count($late);
    }

    // High-priority report issues not yet marked handled.
    $issues = q("SELECT r.id, r.report_date, r.location, r.priority, u.name FROM reports r JOIN users u ON u.id = r.user_id
        WHERE r.priority IN ('High','Urgent') AND r.issues_closed = 0 AND r.report_date >= ? ORDER BY FIELD(r.priority,'Urgent','High'), r.report_date DESC LIMIT 50",
        [date('Y-m-d', strtotime('-14 days'))])->fetchAll();
    if ($issues) {
        $items = array_map(fn($r) => '<li><b>' . strtoupper($r['priority']) . '</b> · ' . htmlspecialchars($r['location']) . ' · '
            . htmlspecialchars($r['name']) . ', ' . date('j M', strtotime($r['report_date'])) . '</li>', $issues);
        $html .= digest_section('High-priority issues from daily reports', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($issues));
        $count += count($issues);
    }

    // Timetabled classes today with no lesson plan posted, and yesterday's with no class logged.
    $line = fn($s) => '<li>' . htmlspecialchars($s['school']) . ' · ' . htmlspecialchars($s['class_name']) . ' ' . htmlspecialchars($s['subject'])
        . ' at ' . $s['start_time'] . ' · ' . htmlspecialchars($s['teacher_name']) . '</li>';
    if ($noPlan = timetable_missing_plans(today())) {
        $html .= digest_section('Classes today with no lesson plan posted', '<ul style="font-size:13px;padding-left:18px;margin:0">'
            . implode('', array_map($line, $noPlan)) . '</ul>', count($noPlan));
        $count += count($noPlan);
        $summary[] = count($noPlan) . ' class' . (count($noPlan) === 1 ? '' : 'es') . ' with no lesson plan';
    }
    if (($day = last_work_day()) && ($unlogged = timetable_unlogged($day))) {
        $html .= digest_section('Timetabled classes not logged on ' . date('D j M', strtotime($day)), '<ul style="font-size:13px;padding-left:18px;margin:0">'
            . implode('', array_map($line, $unlogged)) . '</ul>', count($unlogged));
        $count += count($unlogged);
    }

    // Attendance on the last working day.
    if ($day = last_work_day()) {
        $att = attendance_report($day, $day);
        $late = array_filter($att['rows'], fn($r) => $r['late']);
        $absent = array_filter($att['rows'], fn($r) => $r['absent']);
        $missed = array_filter($att['rows'], fn($r) => $r['missed_out']);
        if ($late || $absent || $missed) {
            $line = fn(string $label, array $rows, callable $fmt) => $rows ? '<p style="font-size:13px;margin:4px 0"><b>' . $label . ':</b> '
                . implode(', ', array_map($fmt, $rows)) . '</p>' : '';
            $html .= digest_section('Attendance on ' . date('D j M', strtotime($day)),
                $line('Late', $late, fn($r) => htmlspecialchars($r['name']) . ' (' . $r['late_min'] . ' min)')
                . $line('Absent', $absent, fn($r) => htmlspecialchars($r['name']))
                . $line('No clock-out', $missed, fn($r) => htmlspecialchars($r['name'])), count($late) + count($absent) + count($missed));
            $count += count($late) + count($absent) + count($missed);
        }
        // Clock-ins at a school the staff member isn't assigned to, or far from the school they chose.
        $odd = q("SELECT a.location, a.flags, a.in_at, u.name FROM attendance a JOIN users u ON u.id = a.user_id
            WHERE a.work_date = ? AND (FIND_IN_SET('far', a.flags) OR FIND_IN_SET('unassigned', a.flags)) ORDER BY u.name, a.in_at", [$day])->fetchAll();
        if ($odd) {
            $items = array_map(fn($r) => '<li>' . htmlspecialchars($r['name']) . ' at ' . htmlspecialchars($r['location']) . ', ' . date('g:ia', strtotime($r['in_at']))
                . ' (' . implode(', ', array_intersect_key(['far' => 'far from the school', 'unassigned' => 'not assigned there'],
                    array_flip(explode(',', $r['flags'])))) . ')</li>', $odd);
            $html .= digest_section('Clock-ins to check', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($odd));
            $count += count($odd);
        }
        // Lesson delivery: teaching visits with no class logged, and classes prepared only after arriving.
        $gaps = teaching_visits_without_evidence($day, $day);
        $late = q("SELECT s.school, s.class_name, u.name FROM lesson_sessions s JOIN users u ON u.id = s.user_id
            WHERE s.session_date = ? AND FIND_IN_SET('prep_late', s.flags) ORDER BY u.name", [$day])->fetchAll();
        if ($gaps || $late) {
            $items = array_merge(
                array_map(fn($g) => '<li>' . htmlspecialchars($g['user_name']) . ' taught at ' . htmlspecialchars($g['location']) . ' but logged no class</li>', $gaps),
                array_map(fn($s) => '<li>' . htmlspecialchars($s['name']) . ': ' . htmlspecialchars($s['class_name']) . ' at ' . htmlspecialchars($s['school'])
                    . ' was prepared only after arriving</li>', $late));
            $html .= digest_section('Lessons without evidence', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($items));
            $count += count($items);
        }
    }

    $train = training_badge(['role' => 'admin']);
    $review = lesson_badge(['role' => 'admin']) - $train;
    if ($review) {
        $html .= digest_section('Lessons to review', '<p style="font-size:13px;margin:4px 0">' . $review . ' lesson plan' . ($review === 1 ? '' : 's')
            . ' or class record' . ($review === 1 ? '' : 's') . ' waiting for you on the Lessons page.</p>', $review);
        $count += $review;
        $summary[] = $review . ' lesson' . ($review === 1 ? '' : 's') . ' to review';
    }
    if ($train) {
        $html .= digest_section('Training to check', '<p style="font-size:13px;margin:4px 0">' . $train . ' staff training submission' . ($train === 1 ? '' : 's')
            . ' waiting for you on the Lessons page, Training tab.</p>', $train);
        $count += $train;
    }
    $unprepared = q("SELECT p.topic, p.school, p.planned_date, u.name FROM lesson_plans p JOIN users u ON u.id = p.user_id
        WHERE p.status = 'assigned' AND p.planned_date < ? AND p.planned_date >= ? ORDER BY p.planned_date, u.name LIMIT 50",
        [today(), date('Y-m-d', strtotime('-14 days'))])->fetchAll();
    if ($unprepared) {
        $items = array_map(fn($p) => '<li>' . htmlspecialchars($p['name']) . ': ' . htmlspecialchars($p['topic']) . ($p['school'] ? ' at ' . htmlspecialchars($p['school']) : '')
            . ', ' . date('D j M', strtotime($p['planned_date'])) . '</li>', $unprepared);
        $html .= digest_section('Posted lessons not prepared', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($unprepared));
        $count += count($unprepared);
    }

    // Overtime claims waiting for the admin's decision before they reach payroll.
    if ($ot = payroll_overtime_pending()) {
        $html .= digest_section('Overtime awaiting approval', '<p style="font-size:13px;margin:4px 0">'
            . implode(', ', array_map(fn($x) => htmlspecialchars($x['name']) . ' (' . date('j M', strtotime($x['date'])) . ', out ' . date('g:ia', strtotime($x['out_at'])) . ')', $ot))
            . '</p><p style="font-size:13px;margin:4px 0">Approve or reject them on the Payroll page.</p>', count($ot));
        $count += count($ot);
        $summary[] = count($ot) . ' overtime to approve';
    }

    $subject = 'Daily follow-up: ' . ($summary ? implode(', ', $summary) : $count . ' item' . ($count === 1 ? '' : 's') . ' to follow up');
    $intro = '<p>Here\'s what needs following up today, so nothing is left for a client to complain about.</p>';
    return [$subject, email_layout($intro . $html), $count];
}

/** [subject, html, item count] for one staff member's own reminder. */
function build_staff_digest(int $uid): array
{
    $t = today();
    $mine = 'EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL)';
    $jobs = array_map('job_row', q(JOB_LIST_SQL . " WHERE $mine AND j.status IN ('open','in_progress','returned')
        AND (j.due_date <= ? OR j.priority IN ('high','urgent') OR j.status = 'returned') ORDER BY j.due_date IS NULL, j.due_date LIMIT 50",
        [$uid, date('Y-m-d', strtotime('+1 day'))])->fetchAll());
    $html = '';
    $groups = [
        'Sent back to you' => array_filter($jobs, fn($j) => $j['status'] === 'returned'),
        'Overdue' => array_filter($jobs, fn($j) => $j['status'] !== 'returned' && $j['due_date'] && $j['due_date'] < $t),
        'Due today or tomorrow' => array_filter($jobs, fn($j) => $j['status'] !== 'returned' && $j['due_date'] && $j['due_date'] >= $t),
        'High priority' => array_filter($jobs, fn($j) => $j['status'] !== 'returned' && !$j['due_date']),
    ];
    $count = 0;
    foreach ($groups as $title => $list) {
        if ($list) {
            $html .= digest_section($title, digest_job_rows($list, false), count($list));
            $count += count($list);
        }
    }
    $tasks = array_filter(jobs_due_for($uid), fn($x) => $x['kind'] === 'task');
    if ($tasks) {
        $items = array_map(fn($x) => '<li>' . $x['ref'] . ' ' . htmlspecialchars($x['title']) . ' <span style="color:#777">(' . htmlspecialchars($x['job_title'])
            . ')</span>, due ' . due_label($x['due_date'], $x['due_time']) . ($x['overdue'] ? ' <b style="color:#A32D2D">overdue</b>' : '') . '</li>', $tasks);
        $html .= digest_section('Tasks due today', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items)
            . '</ul><p style="font-size:13px">Tick each one off before you clock out, or ask for more time.</p>', count($tasks));
        $count += count($tasks);
    }
    $toPost = [];
    foreach ([today() => 'Today', date('Y-m-d', strtotime('+1 day')) => 'Tomorrow'] as $date => $when) {
        foreach (timetable_missing_plans($date) as $s) {
            if ((int)$s['teacher_id'] === $uid) {
                $toPost[] = "<li>$when {$s['start_time']}: " . htmlspecialchars($s['class_name'] . ' ' . $s['subject'] . ' at ' . $s['school']) . '</li>';
            }
        }
    }
    if ($toPost) {
        $html .= digest_section('Lesson plans to post', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $toPost)
            . '</ul><p style="font-size:13px">Open Timetable in the portal and post the topic and lesson plan before each class.</p>', count($toPost));
        $count += count($toPost);
    }
    $returned = lessons_returned_count($uid);
    if ($returned) {
        $html .= digest_section('Lessons returned to you', '<p style="font-size:13px">' . $returned . ' lesson plan or class record'
            . ($returned === 1 ? ' was' : 's were') . ' returned. Open Lessons in the portal, fix them and resubmit.</p>', $returned);
        $count += $returned;
    }
    $prep = q("SELECT topic, school, planned_date FROM lesson_plans WHERE user_id = ? AND status = 'assigned' AND planned_date <= ? ORDER BY planned_date",
        [$uid, date('Y-m-d', strtotime('+1 day'))])->fetchAll();
    if ($prep) {
        $items = array_map(fn($p) => '<li>' . htmlspecialchars($p['topic']) . ($p['school'] ? ' at ' . htmlspecialchars($p['school']) : '') . ', '
            . date('D j M', strtotime($p['planned_date'])) . ($p['planned_date'] < $t ? ' (overdue)' : '') . '</li>', $prep);
        $html .= digest_section('Lessons to prepare', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items)
            . '</ul><p style="font-size:13px">Build each project yourself and upload screenshots before class.</p>', count($prep));
        $count += count($prep);
    }
    $train = q("SELECT t.title, t.due_date, ta.status FROM training_assignees ta JOIN trainings t ON t.id = ta.training_id
        WHERE ta.user_id = ? AND t.status = 'open' AND (ta.status = 'returned' OR (ta.status = 'todo' AND t.due_date <= ?)) ORDER BY t.due_date",
        [$uid, date('Y-m-d', strtotime('+3 days'))])->fetchAll();
    if ($train) {
        $items = array_map(fn($x) => '<li>' . htmlspecialchars($x['title']) . ($x['status'] === 'returned' ? ' (returned to you)'
            : ', due ' . date('D j M', strtotime($x['due_date'])) . ($x['due_date'] < $t ? ' (overdue)' : '')) . '</li>', $train);
        $html .= digest_section('Training to finish', '<ul style="font-size:13px;padding-left:18px;margin:0">' . implode('', $items) . '</ul>', count($train));
        $count += count($train);
    }
    $missed = q('SELECT work_date FROM attendance WHERE user_id = ? AND out_missed = 1 AND work_date >= ? ORDER BY work_date DESC',
        [$uid, date('Y-m-d', strtotime('-3 days'))])->fetchAll(PDO::FETCH_COLUMN);
    if ($missed) {
        $html .= digest_section('You didn\'t clock out', '<p style="font-size:13px">On ' . implode(', ', array_map(fn($d) => date('D j M', strtotime($d)), $missed))
            . '. Remember to clock out with a selfie when you leave work.</p>', count($missed));
        $count += count($missed);
    }
    return ['Your jobs to follow up today', email_layout('<p>Here are your jobs that need attention today.</p>' . $html), $count];
}

function send_digests(array $rs): array
{
    $log = [];
    [$subject, $html, $count] = build_admin_digest($rs);
    $admins = q("SELECT id, name, email FROM users WHERE role = 'admin' AND active = 1")->fetchAll();
    if ($count) {
        notify(array_column($admins, 'id'), 'digest', $subject, 'See the job list and Issues board.', 'jobs', false);
    }
    if (!mail_enabled()) {
        return array_merge($log, ["Admin digest: $count item(s). Email is turned off, so nothing was emailed."]);
    }
    $sent = 0;
    $failed = 0;
    if ($count) {
        $to = [];
        foreach ($admins as $a) {
            $to[strtolower($a['email'])] = $a['name'];
        }
        foreach ($rs['emails'] as $e) {
            $to[strtolower($e)] = $to[strtolower($e)] ?? '';
        }
        foreach ($to as $email => $name) {
            try {
                send_mail($email, $name, $subject, $html) ? $sent++ : $failed++;
            } catch (Throwable $e) {
                $failed++;
                error_log('[portal] digest mail: ' . $e->getMessage());
            }
        }
    }
    $log[] = "Admin digest: $count item(s), sent to $sent address(es)" . ($failed ? ", $failed failed" : '') . '.';
    $staffSent = 0;
    foreach (q("SELECT id, name, email FROM users WHERE role IN ('staff','supervisor','subadmin','manager') AND active = 1")->fetchAll() as $s) {
        [$sSubject, $sHtml, $sCount] = build_staff_digest((int)$s['id']);
        if (!$sCount) {
            continue;
        }
        try {
            if (send_mail($s['email'], $s['name'], $sSubject, $sHtml)) {
                $staffSent++;
            }
        } catch (Throwable $e) {
            error_log('[portal] staff digest mail: ' . $e->getMessage());
        }
    }
    $log[] = "Staff reminders sent: $staffSent.";
    return $log;
}

/** Settings → Reminders → Send digest now. */
function act_reminders_test(array $in, array $me): void
{
    require_admin();
    if (!mail_enabled()) {
        fail('Turn on email notifications first (Settings → Email notifications).');
    }
    $log = run_reminders(true);
    audit('reminders_test', 'settings', null, $log);
    json_out(['ok' => true, 'message' => implode(' ', $log)]);
}
