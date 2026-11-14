<?php
/*
 * Discipline: automatic deductions for broken rules, what staff may see of them, and the weekly performance report.
 *  - Charges (pay_charges) are written when the rule is broken: a missed clock-out (daily sweep), a clock-in/out without GPS,
 *    a job completed without the client's signature, a job sent back, a task left overdue (daily, after closing time).
 *  - payroll_calc() adds them to the month as "conduct" charges; an admin can waive any one with a note.
 *  - portal_visibility (company setting, per-staff override in users.portal_visibility) decides what staff see.
 *  - Every Monday each employee gets last week's report on their phone and on the home page.
 */

/** Automatic charges: code => [label, pay rule key]. The amount is the pay rule; 0 turns the rule off. */
const CHARGE_CODES = [
    'missed_clockout' => 'Did not clock out',
    'no_gps' => 'Clocked in/out without GPS',
    'signoff_skipped' => 'Client sign-off skipped',
    'task_overdue' => 'Job order item left overdue',
    'job_returned' => 'Job sent back for rework',
];

/** Everything a staff member can be charged for, as grouped on the statement and in the visibility switches. */
function statement_categories(): array
{
    return ['late' => 'Lateness', 'absence' => 'Absence', 'report' => 'Daily reports'] + CHARGE_CODES + ['adjustments' => 'Fines and other charges'];
}

/** The statement category of a payroll line code. */
function charge_category(string $code): string
{
    return match ($code) {
        'late', 'very_late' => 'late',
        'absent', 'absent_notified' => 'absence',
        default => $code,
    };
}

function money_text(float $amount): string
{
    return cfg('currency', '₦') . number_format($amount);
}

/* ---------------------------------------------------------------- visibility */

function portal_visibility_defaults(): array
{
    return [
        'statement' => true,       // the "My deductions" page
        'amounts' => true,         // ₦ figures (off = reasons only)
        'before_publish' => true,  // amounts for months the admin hasn't published yet
        'weekly_report' => true,   // the weekly performance report
        'categories' => array_fill_keys(array_keys(statement_categories()), true),
    ];
}

/** Normalise submitted switches onto the defaults; anything missing is off, so unticked boxes save as off. */
function visibility_clean(array $in): array
{
    $out = [];
    foreach (portal_visibility_defaults() as $k => $def) {
        if ($k === 'categories') {
            $cats = is_array($in['categories'] ?? null) ? $in['categories'] : [];
            $out[$k] = [];
            foreach ($def as $c => $_) {
                $out[$k][$c] = !empty($cats[$c]);
            }
        } else {
            $out[$k] = !empty($in[$k]);
        }
    }
    return $out;
}

/** Saved settings merged onto the defaults, so new switches start on. */
function visibility_merge($saved): array
{
    $d = portal_visibility_defaults();
    if (!is_array($saved)) {
        return $d;
    }
    foreach ($d as $k => $def) {
        if ($k === 'categories') {
            foreach ($def as $c => $_) {
                if (isset($saved['categories'][$c])) {
                    $d['categories'][$c] = (bool)$saved['categories'][$c];
                }
            }
        } elseif (isset($saved[$k])) {
            $d[$k] = (bool)$saved[$k];
        }
    }
    return $d;
}

function portal_visibility_company(): array
{
    return visibility_merge(setting('portal_visibility'));
}

/** What one staff member may see: their own override if the admin set one, otherwise the company setting. */
function visibility_for(int $uid): array
{
    $own = q('SELECT portal_visibility FROM users WHERE id = ?', [$uid])->fetchColumn();
    $own = $own ? json_decode((string)$own, true) : null;
    return is_array($own) ? visibility_merge($own) + ['custom' => true] : portal_visibility_company() + ['custom' => false];
}

/* ------------------------------------------------------------------- charges */

/**
 * Record one automatic charge and tell the staff member. Returns false when the rule is off, the date is before the
 * rules start, the month is locked, the person isn't on the payroll, or this event was already charged.
 */
function add_charge(int $uid, string $date, string $code, string $refType, int $refId, string $reason, string $label = ''): bool
{
    $rules = pay_rules();
    $amount = (float)($rules[$code] ?? 0);
    if ($amount <= 0 || !isset(CHARGE_CODES[$code]) || $date < $rules['discipline_from'] || $date < $rules['effective_from']) {
        return false;
    }
    $u = q('SELECT role, start_date FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u || !in_array($u['role'], EMPLOYEE_ROLES, true) || ($u['start_date'] && $date < $u['start_date'])) {
        return false;
    }
    $month = substr($date, 0, 7);
    if (month_locked($month, $uid)) {
        return false;
    }
    $label = $label !== '' ? $label : CHARGE_CODES[$code];
    $n = q('INSERT IGNORE INTO pay_charges (user_id, work_date, month, code, ref_type, ref_id, label, reason, amount, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
        [$uid, $date, $month, $code, $refType, $refId, mb_substr($label, 0, 200), mb_substr($reason, 0, 500), $amount])->rowCount();
    if (!$n) {
        return false;
    }
    $vis = visibility_for($uid);
    if ($vis['categories'][$code] ?? true) {
        $body = $reason . ($vis['amounts'] && $vis['before_publish'] ? "\nDeduction: " . money_text($amount) . '.' : '')
            . "\nIf this is wrong, speak to your admin.";
        notify([$uid], 'deduction', 'Deduction: ' . $label, $body, $vis['statement'] ? 'my-statement' : 'my-pay', false);
    }
    return true;
}

/** Daily sweep: one charge per day with a visit that was never clocked out (out_missed is set by run_reminders). */
function charge_missed_clockouts(): int
{
    $from = pay_rules()['discipline_from'];
    $rows = q("SELECT a.user_id, a.work_date, MIN(a.id) AS id FROM attendance a
        WHERE a.out_missed = 1 AND a.source = 'app' AND a.work_date >= ?
          AND NOT EXISTS (SELECT 1 FROM pay_charges p WHERE p.user_id = a.user_id AND p.code = 'missed_clockout' AND p.work_date = a.work_date)
        GROUP BY a.user_id, a.work_date", [$from])->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        $a = q('SELECT * FROM attendance WHERE id = ?', [$r['id']])->fetch();
        $place = attendance_row($a)['location_label'] ?: 'a site';
        $n += add_charge((int)$r['user_id'], $r['work_date'], 'missed_clockout', 'attendance', (int)$r['id'],
            'You clocked in at ' . $place . ' at ' . date('g:ia', strtotime($a['in_at'])) . ' on ' . date('D j M', strtotime($r['work_date'])) . ' and never clocked out.') ? 1 : 0;
    }
    return $n;
}

/** After a clock-in or clock-out without GPS: at most one charge a day, with the reason they typed. */
function charge_no_gps(int $uid, int $attendanceId, string $action, string $reason): void
{
    $date = (string)q('SELECT work_date FROM attendance WHERE id = ?', [$attendanceId])->fetchColumn();
    if ($date === '' || q("SELECT 1 FROM pay_charges WHERE user_id = ? AND code = 'no_gps' AND work_date = ?", [$uid, $date])->fetchColumn()) {
        return;
    }
    add_charge($uid, $date, 'no_gps', 'attendance', $attendanceId,
        'You clocked ' . $action . ' at ' . date('g:ia') . ' without your location (GPS off or blocked). Your reason: "' . $reason . '"');
}

/** Job orders and tasks past their due time and not done, with no request for more time waiting or lapsed. */
function overdue_items_for(int $uid): array
{
    $t = today();
    $end = strtotime($t . ' ' . work_end());
    return array_values(array_filter(jobs_due_for($uid), function ($x) use ($t, $end) {
        if (!$x['due_date'] || $x['pending_extension']) {
            return false;
        }
        $late = $x['due_date'] < $t || ($x['due_time'] ? time() > strtotime($x['due_date'] . ' ' . $x['due_time']) : time() >= $end);
        if (!$late) {
            return false;
        }
        // The admin didn't answer a request for more time in time, so the staff member was allowed to carry on.
        $lapsed = q("SELECT 1 FROM job_extensions WHERE job_id = ? AND " . ($x['kind'] === 'task' ? 'task_id = ?' : 'task_id IS NULL AND ? > 0') . " AND status = 'lapsed'",
            [$x['job_id'], $x['id']])->fetchColumn();
        return !$lapsed;
    }));
}

/** Daily, after closing time: charge each overdue item once per person. */
function charge_overdue_items(): int
{
    $n = 0;
    $staff = q("SELECT id FROM users WHERE active = 1 AND role IN ('" . implode("','", EMPLOYEE_ROLES) . "')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staff as $uid) {
        foreach (overdue_items_for((int)$uid) as $x) {
            $what = $x['kind'] === 'task' ? "Task \"{$x['title']}\" on {$x['ref']}" : "{$x['ref']} {$x['title']}";
            $n += add_charge((int)$uid, today(), 'task_overdue', $x['kind'], (int)$x['id'],
                "$what was due " . date('D j M', strtotime($x['due_date'])) . ($x['due_time'] ? ' ' . date('g:ia', strtotime($x['due_time'])) : '')
                . ' and isn\'t done, with no approved extra time.') ? 1 : 0;
        }
    }
    return $n;
}

/* --------------------------------------------------------------- weekly report (week_monday() is in api_learning.php) */

/** Everything that happened in one staff member's week (Mon–Sun), with a 0–100 score and tips. */
function weekly_report_build(int $uid, string $from): array
{
    $to = date('Y-m-d', strtotime($from . ' +6 days'));
    $rules = pay_rules();
    $att = attendance_report($from, $to, $uid);
    $row = $att['rows'][0] ?? ['working_days' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'missed_out' => 0, 'hours' => 0];
    $noGps = count(array_filter($att['calendar'], fn($d) => !empty($d['in']) && str_contains((string)($d['flags'] ?? ''), 'no_gps')));
    $workedDays = array_values(array_map(fn($d) => $d['date'], array_filter($att['calendar'], fn($d) => !empty($d['in']) && $d['work_day'])));

    $onTime = 0;
    $sent = 0;
    $reports = [];
    foreach (q('SELECT report_date, created_at, source FROM reports WHERE user_id = ? AND report_date BETWEEN ? AND ?', [$uid, $from, $to])->fetchAll() as $r) {
        $ok = $r['source'] !== 'app' || strtotime($r['created_at']) <= strtotime($r['report_date'] . ' ' . $rules['report_deadline']);
        $reports[$r['report_date']] = ($reports[$r['report_date']] ?? false) || $ok;
    }
    foreach ($workedDays as $d) {
        if (isset($reports[$d])) {
            $sent++;
            $onTime += $reports[$d] ? 1 : 0;
        }
    }

    $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $tasksDone = (int)q("SELECT COUNT(*) FROM job_tasks WHERE done_by = ? AND status = 'done' AND done_at BETWEEN ? AND ?", array_merge([$uid], $range))->fetchColumn();
    $jobs = q('SELECT signoff_at, signoff_skipped FROM jobs WHERE completed_by = ? AND completed_at BETWEEN ? AND ?', array_merge([$uid], $range))->fetchAll();
    $jobsDone = count($jobs);
    $signed = count(array_filter($jobs, fn($j) => $j['signoff_at']));
    $skipped = count(array_filter($jobs, fn($j) => $j['signoff_skipped'] !== ''));
    $returned = (int)q("SELECT COUNT(DISTINCT u.id) FROM job_updates u JOIN job_assignees a ON a.job_id = u.job_id AND a.user_id = ?
        WHERE u.kind = 'status' AND u.body LIKE 'Sent back:%' AND u.created_at BETWEEN ? AND ?", array_merge([$uid], $range))->fetchColumn();
    $overdue = (int)q("SELECT COUNT(*) FROM pay_charges WHERE user_id = ? AND code = 'task_overdue' AND work_date BETWEEN ? AND ?", [$uid, $from, $to])->fetchColumn();
    $stillOverdue = count(overdue_items_for($uid));

    // Every charge in the week (lateness, absence and reports from payroll, plus the automatic ones), waived ones shown as such.
    $lines = [];
    foreach (array_unique([substr($from, 0, 7), substr($to, 0, 7)]) as $m) {
        foreach (payroll_calc($uid, $m)['days'] as $d) {
            if ($d['date'] < $from || $d['date'] > $to) {
                continue;
            }
            foreach ($d['items'] as $i) {
                $lines[] = ['date' => $d['date'], 'category' => charge_category($i['code']), 'label' => $i['label'], 'reason' => $i['note'] ?? '',
                    'amount' => (float)$i['charge'], 'waived' => (bool)$i['waived']];
            }
        }
    }

    // Score: the share of each rule kept, weighted; rules with nothing to measure this week are left out.
    $parts = [];
    $add = function (string $k, string $label, int $weight, int $good, int $of) use (&$parts) {
        if ($of > 0) {
            $parts[$k] = ['label' => $label, 'weight' => $weight, 'good' => max(0, $good), 'of' => $of, 'pct' => (int)round(max(0, $good) / $of * 100)];
        }
    };
    $present = (int)$row['present'];
    $add('attendance', 'Came to work', 20, $present, (int)$row['working_days']);
    $add('punctuality', 'On time', 15, $present - (int)$row['late'], $present);
    $add('clockout', 'Clocked out', 15, $present - (int)$row['missed_out'], $present);
    $add('gps', 'Location on', 10, $present - $noGps, $present);
    $add('reports', 'Daily report on time', 15, $onTime, count($workedDays));
    $add('tasks', 'Tasks done on time', 15, $tasksDone, $tasksDone + $overdue);
    $add('signoff', 'Client signed off', 10, $signed, $jobsDone);
    $w = array_sum(array_column($parts, 'weight'));
    $score = $w ? (int)round(array_sum(array_map(fn($p) => $p['weight'] * $p['good'] / $p['of'], $parts)) / $w * 100) : 0;
    $score = max(0, $score - 5 * $returned);

    $tips = [];
    $tipFor = [
        'clockout' => 'Clock out every time you leave a site. A missed clock-out is a deduction.',
        'gps' => 'Turn on location before you clock in or out. Clocking without GPS is a deduction even with a reason.',
        'punctuality' => 'Arrive before ' . work_start() . '. Lateness is deducted per day.',
        'attendance' => 'If you can\'t come to work, get approval in advance so the day is excused.',
        'reports' => 'Send your daily report before ' . $rules['report_deadline'] . ' every day you work.',
        'tasks' => 'Finish job order tasks by their due time, or ask for more time before it passes.',
        'signoff' => 'Get the client\'s signature when you complete a job. Skipping sign-off is a deduction.',
    ];
    $weak = array_filter($parts, fn($p) => $p['pct'] < 100);
    uasort($weak, fn($a, $b) => $a['pct'] <=> $b['pct']);
    foreach (array_slice(array_keys($weak), 0, 3) as $k) {
        $tips[] = $tipFor[$k];
    }
    if ($returned) {
        $tips[] = 'Check your work against the job order before marking it complete: ' . $returned . ' job' . ($returned === 1 ? ' was' : 's were') . ' sent back.';
    }
    if (!$tips && $parts) {
        $tips[] = 'A clean week. Keep it up.';
    }

    $prev = q('SELECT score FROM weekly_reports WHERE user_id = ? AND week_start = ?', [$uid, date('Y-m-d', strtotime($from . ' -7 days'))])->fetchColumn();
    return [
        'from' => $from, 'to' => $to, 'score' => $score, 'measured' => (bool)$parts, 'prev_score' => $prev === false ? null : (int)$prev,
        'attendance' => ['working_days' => (int)$row['working_days'], 'present' => $present, 'late' => (int)$row['late'], 'absent' => (int)$row['absent'],
            'missed_out' => (int)$row['missed_out'], 'no_gps' => $noGps, 'hours' => (float)$row['hours']],
        'reports' => ['required' => count($workedDays), 'sent' => $sent, 'on_time' => $onTime],
        'work' => ['tasks_done' => $tasksDone, 'tasks_overdue' => $overdue, 'still_overdue' => $stillOverdue, 'jobs_done' => $jobsDone,
            'signed' => $signed, 'signoff_skipped' => $skipped, 'returned' => $returned],
        'parts' => $parts,
        'lines' => $lines,
        'tips' => $tips,
    ];
}

/** A stored report as the staff member may see it: hidden categories dropped, amounts removed if they're off. */
function weekly_report_view(array $row, array $vis): array
{
    $data = json_decode($row['data'], true) ?: [];
    $lines = array_values(array_filter($data['lines'] ?? [], fn($l) => $vis['categories'][$l['category']] ?? true));
    $total = array_sum(array_map(fn($l) => $l['waived'] ? 0 : $l['amount'], $lines));
    if (!$vis['amounts'] || !$vis['before_publish']) {
        $lines = array_map(fn($l) => array_diff_key($l, ['amount' => 1]), $lines);
        $total = null;
    }
    $data['lines'] = $lines;
    $data['deductions'] = $total;
    $data['charged'] = count(array_filter($lines, fn($l) => !$l['waived']));
    return ['id' => (int)$row['id'], 'user_id' => (int)$row['user_id'], 'week_start' => $row['week_start'], 'score' => (int)$row['score'],
        'seen_at' => $row['seen_at'], 'created_at' => $row['created_at']] + $data;
}

/** Build (or rebuild) last week's report for every employee; notify those not told yet. Returns how many were sent. */
function weekly_reports_generate(?string $weekStart = null): int
{
    $from = $weekStart ?: date('Y-m-d', strtotime(week_monday(today()) . ' -7 days'));
    $staff = q("SELECT id, name FROM users WHERE active = 1 AND role IN ('" . implode("','", EMPLOYEE_ROLES) . "') ORDER BY name")->fetchAll();
    $sent = 0;
    $scores = [];
    $missed = 0;
    foreach ($staff as $s) {
        $uid = (int)$s['id'];
        $data = weekly_report_build($uid, $from);
        q('INSERT INTO weekly_reports (user_id, week_start, data, score, created_at) VALUES (?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE data = VALUES(data), score = VALUES(score)', [$uid, $from, json_encode($data, JSON_UNESCAPED_UNICODE), $data['score']]);
        if ($data['measured']) {
            $scores[] = $data['score'];
        }
        $missed += $data['attendance']['missed_out'];
        $row = q('SELECT * FROM weekly_reports WHERE user_id = ? AND week_start = ?', [$uid, $from])->fetch();
        $vis = visibility_for($uid);
        // Nothing to report (on leave, or before they started): keep the row but don't alert them.
        if ($row['notified_at'] || !$vis['weekly_report'] || (!$data['measured'] && !$data['lines'])) {
            continue;
        }
        $v = weekly_report_view($row, $vis);
        $bits = ['score ' . $v['score'] . '/100'];
        if ($v['attendance']['missed_out']) {
            $bits[] = $v['attendance']['missed_out'] . ' missed clock-out' . ($v['attendance']['missed_out'] === 1 ? '' : 's');
        }
        if ($v['deductions']) {
            $bits[] = money_text($v['deductions']) . ' deducted';
        } elseif ($v['charged']) {
            $bits[] = $v['charged'] . ' deduction' . ($v['charged'] === 1 ? '' : 's');
        }
        notify([$uid], 'weekly', 'Your week: ' . implode(' · ', $bits),
            ($v['tips'][0] ?? '') . "\nOpen the portal to see your full weekly report.", 'my-week', false);
        q('UPDATE weekly_reports SET notified_at = NOW() WHERE id = ?', [$row['id']]);
        $sent++;
    }
    if ($sent) {
        $avg = $scores ? (int)round(array_sum($scores) / count($scores)) : 0;
        notify_admins('weekly', 'Team weekly report: average score ' . $avg . '/100', count($scores) . ' staff · ' . $missed . ' missed clock-outs last week.', 'weekly', false);
    }
    return $sent;
}

/** True once this week's reports are due: Monday (or later in the week) after the report time, not yet sent. */
function weekly_reports_due(): bool
{
    $monday = week_monday(today());
    return setting('_rem_weekly') !== $monday && time() >= strtotime($monday . ' ' . hhmm_setting('weekly_report_time', '07:00'));
}

/* ------------------------------------------------------------------- actions */

/** Admin: waive (or restore) one automatic charge. */
function act_pay_charge_waive(array $in, array $me): void
{
    if (!can_approve_time($me)) {
        fail('Only an admin or manager can waive a deduction.', 403);
    }
    $c = q('SELECT * FROM pay_charges WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$c) {
        fail('Deduction not found.', 404);
    }
    if (month_locked($c['month'], (int)$c['user_id'])) {
        fail('This month is locked. Unlock it on the Payroll page first.');
    }
    $waive = !empty($in['waive']);
    $note = str_in($in['note'] ?? '', 500);
    if ($waive && $note === '') {
        fail('Say why you are waiving it.');
    }
    q('UPDATE pay_charges SET waived_by = ?, waived_note = ?, waived_at = ' . ($waive ? 'NOW()' : 'NULL') . ' WHERE id = ?',
        [$waive ? $me['id'] : null, $waive ? $note : '', $c['id']]);
    audit($waive ? 'charge_waive' : 'charge_restore', 'user', (int)$c['user_id'], ['charge' => (int)$c['id'], 'code' => $c['code'], 'note' => $note]);
    if ($waive) {
        notify([(int)$c['user_id']], 'deduction', 'Deduction waived: ' . $c['label'], 'For ' . date('D j M', strtotime($c['work_date'])) . '. ' . $note, 'my-statement', false);
    }
    touch_change();
    json_out(['ok' => true]);
}

/** Statement of account: every deduction over the last few months, filtered by what this person may see. */
function act_my_statement(array $in, array $me): void
{
    $uid = $me['id'];
    if (!empty($in['user_id']) && (int)$in['user_id'] !== $me['id']) {
        require_admin();
        $uid = (int)$in['user_id'];
    }
    $vis = visibility_for($uid);
    if (!$vis['statement'] && !is_admin($me)) {
        json_out(['ok' => true, 'hidden' => true, 'months' => []]);
    }
    $count = max(1, min(12, (int)($in['months'] ?? 6)));
    $cats = statement_categories();
    $months = [];
    $grand = 0.0;
    $byCat = [];
    for ($i = 0; $i < $count; $i++) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $c = payroll_calc($uid, $m);
        $published = !empty($c['published_at']);
        $showAmounts = $vis['amounts'] && ($published || $vis['before_publish']);
        $lines = [];
        foreach ($c['days'] as $d) {
            foreach ($d['items'] as $it) {
                $lines[] = ['date' => $d['date'], 'category' => charge_category($it['code']), 'label' => $it['label'], 'reason' => $it['note'] ?? '',
                    'amount' => (float)$it['charge'], 'waived' => $it['waived']];
            }
        }
        foreach ($c['adjustments'] as $a) {
            if ($a['amount'] < 0) {
                $lines[] = ['date' => substr((string)$a['created_at'], 0, 10), 'category' => 'adjustments', 'label' => $a['label'], 'reason' => $a['note'] ?? '',
                    'amount' => -(float)$a['amount'], 'waived' => null];
            }
        }
        $lines = array_values(array_filter($lines, fn($l) => $vis['categories'][$l['category']] ?? true));
        usort($lines, fn($a, $b) => [$b['date'], $a['label']] <=> [$a['date'], $b['label']]);
        $total = 0.0;
        foreach ($lines as &$l) {
            $l['category_label'] = $cats[$l['category']] ?? $l['category'];
            if (!$l['waived']) {
                $total += $l['amount'];
                $byCat[$l['category']] = ($byCat[$l['category']] ?? 0) + 1;
            }
            if (!$showAmounts) {
                unset($l['amount']);
            }
        }
        unset($l);
        if ($showAmounts) {
            $grand += $total;
        }
        $months[] = ['month' => $m, 'published' => $published, 'locked' => !empty($c['locked']), 'show_amounts' => $showAmounts,
            'total' => $showAmounts ? round($total, 2) : null, 'count' => count(array_filter($lines, fn($l) => !$l['waived'])), 'lines' => $lines];
    }
    arsort($byCat);
    $top = array_key_first($byCat);
    json_out(['ok' => true, 'hidden' => false, 'months' => $months, 'total' => round($grand, 2), 'by_category' => $byCat,
        'top' => $top ? ['category' => $top, 'label' => $cats[$top] ?? $top, 'count' => $byCat[$top]] : null,
        'amounts' => $vis['amounts'], 'before_publish' => $vis['before_publish'], 'categories' => $cats]);
}

/** Admin: one staff member's visibility (their own override or the company setting). */
function act_staff_visibility(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    json_out(['ok' => true, 'visibility' => visibility_for($uid), 'company' => portal_visibility_company(), 'categories' => statement_categories()]);
}

/** Admin: give one staff member their own visibility switches, or put them back on the company setting (custom = false). */
function act_staff_visibility_save(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    if (!q('SELECT 1 FROM users WHERE id = ?', [$uid])->fetchColumn()) {
        fail('Staff member not found.', 404);
    }
    $vis = !empty($in['custom']) && is_array($in['visibility'] ?? null) ? visibility_clean($in['visibility']) : null;
    q('UPDATE users SET portal_visibility = ? WHERE id = ?', [$vis ? json_encode($vis) : null, $uid]);
    audit('visibility_set', 'user', $uid, ['custom' => (bool)$vis]);
    touch_change();
    json_out(['ok' => true]);
}

/** The signed-in staff member's latest weekly report (or one by id), plus their history. */
function act_weekly_report_mine(array $in, array $me): void
{
    $vis = visibility_for($me['id']);
    if (!$vis['weekly_report']) {
        json_out(['ok' => true, 'report' => null, 'history' => []]);
    }
    $row = !empty($in['id'])
        ? q('SELECT * FROM weekly_reports WHERE id = ? AND user_id = ?', [(int)$in['id'], $me['id']])->fetch()
        : q('SELECT * FROM weekly_reports WHERE user_id = ? ORDER BY week_start DESC LIMIT 1', [$me['id']])->fetch();
    $history = q('SELECT id, week_start, score, seen_at FROM weekly_reports WHERE user_id = ? ORDER BY week_start DESC LIMIT 26', [$me['id']])->fetchAll();
    json_out(['ok' => true, 'report' => $row ? weekly_report_view($row, $vis) : null,
        'history' => array_map(fn($h) => ['id' => (int)$h['id'], 'week_start' => $h['week_start'], 'score' => (int)$h['score'], 'seen' => (bool)$h['seen_at']], $history)]);
}

function act_weekly_report_seen(array $in, array $me): void
{
    q('UPDATE weekly_reports SET seen_at = COALESCE(seen_at, NOW()) WHERE id = ? AND user_id = ?', [(int)($in['id'] ?? 0), $me['id']]);
    json_out(['ok' => true]);
}

/** Management: everyone's report for one week, ranked, with who has read theirs. */
function act_weekly_reports(array $in, array $me): void
{
    require_management();
    $weeks = q('SELECT DISTINCT week_start FROM weekly_reports ORDER BY week_start DESC LIMIT 26')->fetchAll(PDO::FETCH_COLUMN);
    $week = valid_date($in['week'] ?? null) ? $in['week'] : ($weeks[0] ?? null);
    $rows = [];
    if ($week) {
        foreach (q('SELECT w.*, u.name FROM weekly_reports w JOIN users u ON u.id = w.user_id WHERE w.week_start = ? ORDER BY w.score DESC, u.name', [$week])->fetchAll() as $r) {
            $all = visibility_merge(null);
            $rows[] = ['name' => $r['name']] + weekly_report_view($r, $all);
        }
    }
    json_out(['ok' => true, 'week' => $week, 'weeks' => $weeks, 'rows' => $rows, 'can_generate' => is_admin($me)]);
}

/** Admin: build last week's reports now (sends them to anyone not told yet). */
function act_weekly_generate(array $in, array $me): void
{
    require_admin();
    $week = valid_date($in['week'] ?? null) ? week_monday($in['week']) : null;
    $n = weekly_reports_generate($week);
    if (!$week) {
        save_setting('_rem_weekly', week_monday(today()));
    }
    audit('weekly_generate', 'payroll', null, ['sent' => $n]);
    touch_change();
    json_out(['ok' => true, 'sent' => $n]);
}
