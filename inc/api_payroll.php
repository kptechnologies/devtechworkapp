<?php
/* Payroll: monthly salary less attendance charges, plus approved overtime and manual adjustments.
   Charges come from the staff notice of 9 October 2026; amounts are set in Settings → Pay rules. */

const PAY_MARKS = [
    'absence_approved' => 'Approved absence',
    'absence_notified' => 'Notified / forgot to clock in',
    'late_approved' => 'Permission to report late',
    'report_waived' => 'Report charge waived',
    'overtime_approved' => 'Overtime approved',
    'overtime_rejected' => 'Overtime rejected',
];
/** Marks that replace each other on the same day. */
const PAY_MARK_GROUPS = [['absence_approved', 'absence_notified'], ['overtime_approved', 'overtime_rejected']];

function pay_rule_defaults(): array
{
    return [
        'late' => 1500, 'very_late' => 3000, 'very_late_min' => 30,
        'absent' => 5000, 'absent_notified' => 3000,
        'report_missing' => 3000, 'report_deadline' => '22:00',
        'job_failure' => 3000,
        'overtime' => 1500, 'overtime_min' => 30,
        'effective_from' => '2026-10-09',
    ];
}

function pay_rules(): array
{
    $saved = setting('pay_rules');
    return array_merge(pay_rule_defaults(), is_array($saved) ? $saved : []);
}

function valid_month($m): bool
{
    return is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1;
}

function month_in(array $in): string
{
    return valid_month($in['month'] ?? null) ? $in['month'] : date('Y-m');
}

function month_locked(string $month, int $uid): bool
{
    return (bool)q('SELECT 1 FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid])->fetchColumn();
}

/** Staff on the payroll for a month: active staff, plus anyone who clocked in that month. */
function payroll_staff(string $month): array
{
    return q("SELECT id, name, salary, bank_name, bank_account FROM users WHERE role IN ('staff','supervisor','subadmin','manager')
        AND (active = 1 OR id IN (SELECT user_id FROM attendance WHERE work_date BETWEEN ? AND ?)) ORDER BY name",
        [$month . '-01', date('Y-m-t', strtotime($month . '-01'))])->fetchAll();
}

/** Days that qualify for overtime but haven't been approved or rejected yet (unlocked months only). */
function payroll_overtime_pending(?int $uid = null, ?string $month = null): array
{
    $r = pay_rules();
    $w = '';
    $p = [];
    if ($uid) {
        $w .= ' AND a.user_id = ?';
        $p[] = $uid;
    }
    if ($month) {
        $w .= ' AND a.work_date BETWEEN ? AND ?';
        array_push($p, $month . '-01', date('Y-m-t', strtotime($month . '-01')));
    }
    array_push($p, work_end(), (int)$r['overtime_min'], $r['effective_from']);
    $rows = q("SELECT a.user_id, u.name, a.work_date, MAX(a.out_at) AS out_at FROM attendance a
        JOIN users u ON u.id = a.user_id AND u.role IN ('staff','supervisor','subadmin','manager')
        WHERE a.out_at IS NOT NULL $w
        GROUP BY a.user_id, u.name, a.work_date, u.start_date
        HAVING MAX(a.out_at) >= TIMESTAMP(a.work_date, ?) + INTERVAL ? MINUTE
           AND a.work_date >= GREATEST(?, COALESCE(u.start_date, '1970-01-01'))
        ORDER BY a.work_date, u.name", $p)->fetchAll();
    if (!$rows) {
        return [];
    }
    $done = [];
    foreach (q("SELECT user_id, work_date FROM pay_marks WHERE kind IN ('overtime_approved','overtime_rejected')")->fetchAll() as $m) {
        $done[$m['user_id'] . '|' . $m['work_date']] = true;
    }
    $locked = [];
    foreach (q('SELECT month, user_id FROM pay_locks')->fetchAll() as $l) {
        $locked[$l['user_id'] . '|' . $l['month']] = true;
    }
    return array_values(array_filter(array_map(fn($x) => ['user_id' => (int)$x['user_id'], 'name' => $x['name'], 'date' => $x['work_date'], 'out_at' => $x['out_at']], $rows),
        fn($x) => !isset($done[$x['user_id'] . '|' . $x['date']]) && !isset($locked[$x['user_id'] . '|' . substr($x['date'], 0, 7)])));
}

/** One staff member's pay for a month. Locked months return the saved snapshot. */
function payroll_calc(int $uid, string $month, bool $live = false): array
{
    if (!$live) {
        $lock = q('SELECT data, locked_at, published_at FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid])->fetch();
        if ($lock) {
            $data = json_decode($lock['data'], true) ?: [];
            // Snapshots taken before advances existed.
            $data += ['advances' => [], 'advances_total' => 0];
            return ['locked' => true, 'locked_at' => $lock['locked_at'], 'published_at' => $lock['published_at']] + $data;
        }
    }
    $u = q('SELECT id, name, salary, start_date, bank_name, bank_account FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        fail('Staff member not found.', 404);
    }
    $rules = pay_rules();
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $since = max($rules['effective_from'], (string)($u['start_date'] ?: ''));
    $now = time();
    $calendar = attendance_report($from, $to, $uid)['calendar'];

    $reports = [];
    foreach (q('SELECT report_date, created_at, source FROM reports WHERE user_id = ? AND report_date BETWEEN ? AND ?', [$uid, $from, $to])->fetchAll() as $r) {
        // Reports keyed in by an admin or imported count as on time.
        $onTime = $r['source'] !== 'app' || strtotime($r['created_at']) <= strtotime($r['report_date'] . ' ' . $rules['report_deadline']);
        $reports[$r['report_date']] = ($reports[$r['report_date']] ?? false) || $onTime;
    }
    $lastOut = [];
    foreach (q('SELECT work_date, MAX(out_at) AS out_at FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ? GROUP BY work_date', [$uid, $from, $to])->fetchAll() as $r) {
        $lastOut[$r['work_date']] = $r['out_at'];
    }
    $marks = [];
    foreach (q('SELECT work_date, kind, note FROM pay_marks WHERE user_id = ? AND work_date BETWEEN ? AND ?', [$uid, $from, $to])->fetchAll() as $m) {
        $marks[$m['work_date']][$m['kind']] = $m['note'];
    }

    $days = [];
    $totals = ['late' => 0, 'absence' => 0, 'report' => 0, 'overtime' => 0];
    $counts = ['late' => 0, 'absence' => 0, 'report' => 0, 'overtime' => 0, 'overtime_pending' => 0];
    foreach ($calendar as $d) {
        $date = $d['date'];
        if ($date < $since || $date > today()) {
            continue;
        }
        $m = $marks[$date] ?? [];
        $items = [];
        $charge = function (string $group, string $code, string $label, float $amount, ?string $waiveKind = null) use (&$items, &$totals, &$counts, $m) {
            $waived = $waiveKind && array_key_exists($waiveKind, $m);
            $items[] = ['code' => $code, 'label' => $label, 'amount' => $waived ? 0 : -$amount, 'charge' => $amount,
                'waived' => $waived ? (PAY_MARKS[$waiveKind] . ($m[$waiveKind] !== '' ? ': ' . $m[$waiveKind] : '')) : null];
            if (!$waived && $amount > 0) {
                $totals[$group] += $amount;
                $counts[$group]++;
            }
        };
        if ($d['status'] === 'absent') {
            if ($date >= today()) {
                continue; // they may still clock in today
            }
            if (array_key_exists('absence_notified', $m)) {
                $charge('absence', 'absent_notified', 'Failed to clock in (notified)', (float)$rules['absent_notified']);
                $items[count($items) - 1]['note'] = $m['absence_notified'];
            } else {
                $charge('absence', 'absent', 'Absent without approval', (float)$rules['absent'], 'absence_approved');
            }
        } elseif (!empty($d['in'])) {
            if (!empty($d['late'])) {
                $very = $d['late_min'] > (int)$rules['very_late_min'];
                $charge('late', $very ? 'very_late' : 'late', ($very ? 'More than ' . (int)$rules['very_late_min'] . ' min late' : 'Late') . ' (' . $d['late_min'] . ' min)',
                    (float)$rules[$very ? 'very_late' : 'late'], 'late_approved');
            }
            if ($now > strtotime($date . ' ' . $rules['report_deadline']) && empty($reports[$date])) {
                $charge('report', 'report', isset($reports[$date]) ? 'Daily report sent after ' . $rules['report_deadline'] : 'No daily report', (float)$rules['report_missing'], 'report_waived');
            }
        }
        $ot = null;
        $out = $lastOut[$date] ?? null;
        if ($out && strtotime($out) >= strtotime($date . ' ' . work_end()) + (int)$rules['overtime_min'] * 60) {
            $state = array_key_exists('overtime_approved', $m) ? 'approved' : (array_key_exists('overtime_rejected', $m) ? 'rejected' : 'pending');
            $ot = ['out_at' => $out, 'state' => $state, 'amount' => $state === 'approved' ? (float)$rules['overtime'] : 0];
            if ($state === 'approved') {
                $totals['overtime'] += (float)$rules['overtime'];
                $counts['overtime']++;
            } elseif ($state === 'pending') {
                $counts['overtime_pending']++;
            }
        }
        if ($items || $ot) {
            $days[] = ['date' => $date, 'status' => $d['status'], 'in' => $d['in'] ?? null, 'out' => $d['out'] ?? null, 'items' => $items, 'overtime' => $ot,
                'marks' => array_keys($m)];
        }
    }
    $adj = array_map(fn($a) => ['id' => (int)$a['id'], 'label' => $a['label'], 'amount' => (float)$a['amount'], 'note' => $a['note'], 'created_at' => $a['created_at'], 'by' => $a['by_name']],
        q('SELECT a.*, u.name AS by_name FROM pay_adjustments a LEFT JOIN users u ON u.id = a.created_by WHERE a.user_id = ? AND a.month = ? ORDER BY a.id', [$uid, $month])->fetchAll());
    $adjTotal = round(array_sum(array_column($adj, 'amount')), 2);
    $adv = array_map(fn($a) => ['id' => (int)$a['id'], 'amount' => (float)$a['amount'], 'given_on' => $a['given_on'], 'note' => $a['note'], 'created_at' => $a['created_at'], 'by' => $a['by_name']],
        q('SELECT a.*, u.name AS by_name FROM pay_advances a LEFT JOIN users u ON u.id = a.created_by WHERE a.user_id = ? AND a.month = ? ORDER BY a.given_on, a.id', [$uid, $month])->fetchAll());
    $advTotal = round(array_sum(array_column($adv, 'amount')), 2);
    $salary = (float)$u['salary'];
    $deductions = $totals['late'] + $totals['absence'] + $totals['report'];
    return [
        'locked' => false,
        'published_at' => null,
        'month' => $month,
        'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'bank_name' => $u['bank_name'], 'bank_account' => $u['bank_account']],
        'salary' => $salary,
        'rules_from' => $since,
        'days' => $days,
        'adjustments' => $adj,
        'totals' => $totals,
        'counts' => $counts,
        'deductions' => $deductions,
        'overtime' => $totals['overtime'],
        'adjustments_total' => $adjTotal,
        'advances' => $adv,
        'advances_total' => $advTotal,
        'net' => round($salary - $deductions + $totals['overtime'] + $adjTotal - $advTotal, 2),
    ];
}

/** Summary row for the payroll table. */
function payroll_summary(array $c): array
{
    return ['id' => $c['user']['id'], 'name' => $c['user']['name'], 'salary' => $c['salary'], 'deductions' => $c['deductions'], 'overtime' => $c['overtime'],
        'adjustments' => $c['adjustments_total'], 'advances' => $c['advances_total'], 'net' => $c['net'], 'counts' => $c['counts'], 'totals' => $c['totals'],
        'locked' => $c['locked'], 'published' => !empty($c['published_at']),
        'bank_name' => $c['user']['bank_name'], 'bank_account' => $c['user']['bank_account']];
}

/** What a staff member sees of their own month: salary, advances and expected balance, plus the attendance record.
    Charge amounts, adjustments and net pay stay hidden until the admin publishes the month. */
function payroll_staff_view(array $c): array
{
    $published = !empty($c['published_at']);
    $advances = array_map(fn($a) => ['amount' => $a['amount'], 'given_on' => $a['given_on'], 'note' => $a['note']], $c['advances']);
    $base = [
        'locked' => $c['locked'],
        'locked_at' => $c['locked_at'] ?? null,
        'published' => $published,
        'published_at' => $c['published_at'] ?? null,
        'month' => $c['month'],
        'rules_from' => $c['rules_from'],
        'counts' => $c['counts'],
        'salary' => $c['salary'],
        'advances' => $advances,
        'advances_total' => $c['advances_total'],
        'expected' => round($c['salary'] - $c['advances_total'], 2),
    ];
    if ($published) {
        $adj = array_map(fn($a) => ['label' => $a['label'], 'amount' => $a['amount'], 'note' => $a['note']], $c['adjustments']);
        return $base + [
            'days' => array_map(fn($d) => [
                'date' => $d['date'], 'status' => $d['status'], 'in' => $d['in'], 'out' => $d['out'], 'items' => $d['items'], 'overtime' => $d['overtime'],
            ], $c['days']),
            'totals' => $c['totals'],
            'deductions' => $c['deductions'],
            'overtime' => $c['overtime'],
            'adjustments' => $adj,
            'adjustments_total' => $c['adjustments_total'],
            'net' => $c['net'],
        ];
    }
    return $base + [
        'days' => array_map(fn($d) => [
            'date' => $d['date'], 'status' => $d['status'], 'in' => $d['in'], 'out' => $d['out'],
            'items' => array_map(fn($i) => ['code' => $i['code'], 'label' => $i['label'], 'waived' => $i['waived'], 'note' => $i['note'] ?? null], $d['items']),
            'overtime' => $d['overtime'] ? ['out_at' => $d['overtime']['out_at'], 'state' => $d['overtime']['state']] : null,
        ], $c['days']),
    ];
}

/* ----------------------------------------------------------------- actions */

function act_payroll_list(array $in, array $me): void
{
    require_admin();
    $month = month_in($in);
    $rows = array_map(fn($s) => payroll_summary(payroll_calc((int)$s['id'], $month)), payroll_staff($month));
    json_out(['ok' => true, 'month' => $month, 'rows' => $rows, 'rules' => pay_rules(), 'pending' => payroll_overtime_pending()]);
}

function act_payroll_detail(array $in, array $me): void
{
    if (is_admin($me)) {
        $uid = !empty($in['user_id']) ? (int)$in['user_id'] : $me['id'];
        json_out(['ok' => true, 'rules' => pay_rules(), 'marks' => PAY_MARKS] + payroll_calc($uid, month_in($in)));
    }
    // Staff see their record but no amounts; deductions are for admins only.
    $r = pay_rules();
    $rules = ['report_deadline' => $r['report_deadline'], 'very_late_min' => $r['very_late_min'], 'overtime_min' => $r['overtime_min']];
    json_out(['ok' => true, 'rules' => $rules] + payroll_staff_view(payroll_calc($me['id'], month_in($in))));
}

function act_payroll_mark(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $date = (string)($in['date'] ?? '');
    $kind = (string)($in['kind'] ?? '');
    $on = !empty($in['on']);
    $note = str_in($in['note'] ?? '', 500);
    if (!$uid || !valid_date($date) || !isset(PAY_MARKS[$kind])) {
        fail('Choose a staff member, day and action.');
    }
    if (month_locked(substr($date, 0, 7), $uid)) {
        fail('This month is locked. Unlock it on the Payroll page first.');
    }
    if ($on && $note === '' && in_array($kind, ['absence_approved', 'absence_notified', 'late_approved', 'report_waived'], true)) {
        fail('Add a note saying why.');
    }
    foreach (PAY_MARK_GROUPS as $g) {
        if (in_array($kind, $g, true)) {
            q('DELETE FROM pay_marks WHERE user_id = ? AND work_date = ? AND kind IN (' . implode(',', array_fill(0, count($g), '?')) . ')', array_merge([$uid, $date], $g));
        }
    }
    q('DELETE FROM pay_marks WHERE user_id = ? AND work_date = ? AND kind = ?', [$uid, $date, $kind]);
    if ($on) {
        q('INSERT INTO pay_marks (user_id, work_date, kind, note, created_by, created_at) VALUES (?,?,?,?,?,NOW())', [$uid, $date, $kind, $note, $me['id']]);
    }
    audit($on ? 'pay_mark' : 'pay_unmark', 'user', $uid, ['date' => $date, 'kind' => $kind, 'note' => $note]);
    touch_change();
    json_out(['ok' => true]);
}

/** Approve or reject several overtime days at once: items = [{user_id, date}]. */
function act_payroll_overtime(array $in, array $me): void
{
    require_admin();
    $kind = ($in['decision'] ?? '') === 'reject' ? 'overtime_rejected' : 'overtime_approved';
    $n = 0;
    foreach ((array)($in['items'] ?? []) as $it) {
        $uid = (int)($it['user_id'] ?? 0);
        $date = (string)($it['date'] ?? '');
        if (!$uid || !valid_date($date) || month_locked(substr($date, 0, 7), $uid)) {
            continue;
        }
        q("DELETE FROM pay_marks WHERE user_id = ? AND work_date = ? AND kind IN ('overtime_approved','overtime_rejected')", [$uid, $date]);
        q('INSERT INTO pay_marks (user_id, work_date, kind, note, created_by, created_at) VALUES (?,?,?,?,?,NOW())', [$uid, $date, $kind, '', $me['id']]);
        $n++;
    }
    if ($n) {
        audit($kind === 'overtime_approved' ? 'overtime_approve' : 'overtime_reject', 'payroll', null, ['days' => $n]);
        touch_change();
    }
    json_out(['ok' => true, 'count' => $n]);
}

function act_payroll_adjust(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $month = month_in($in);
    $label = str_in($in['label'] ?? '', 120);
    $amount = money_in($in['amount'] ?? 0);
    $errors = [];
    if ($label === '') {
        $errors['label'] = 'Say what this is for.';
    }
    if ($amount == 0) {
        $errors['amount'] = 'Enter an amount: negative for a charge, positive for a bonus.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $id = payroll_add_adjustment($uid, $month, $label, $amount, str_in($in['note'] ?? '', 500), $me);
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

/** Add a salary charge (negative) or bonus (positive) to a month; also used for query fines. */
function payroll_add_adjustment(int $uid, string $month, string $label, float $amount, string $note, array $me): int
{
    if (!q('SELECT 1 FROM users WHERE id = ?', [$uid])->fetchColumn()) {
        fail('Staff member not found.', 404);
    }
    if (month_locked($month, $uid)) {
        fail('Payroll for ' . date('F Y', strtotime($month . '-01')) . ' is locked. Unlock it first.');
    }
    q('INSERT INTO pay_adjustments (user_id, month, label, amount, note, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$uid, $month, $label, $amount, $note, $me['id']]);
    $id = (int)db()->lastInsertId();
    audit('pay_adjust', 'user', $uid, ['month' => $month, 'label' => $label, 'amount' => $amount]);
    // Deductions are admin-only, so staff aren't told about charges; bonuses get a note without the amount.
    if ($amount > 0) {
        notify([$uid], 'payroll', 'Pay addition: ' . $label, 'Added to your ' . date('F Y', strtotime($month . '-01')) . ' pay.', 'my-pay');
    }
    return $id;
}

function act_payroll_adjust_delete(array $in, array $me): void
{
    require_admin();
    $a = q('SELECT * FROM pay_adjustments WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$a) {
        fail('Adjustment not found.', 404);
    }
    if (month_locked($a['month'], (int)$a['user_id'])) {
        fail('This month is locked. Unlock it first.');
    }
    q('DELETE FROM pay_adjustments WHERE id = ?', [$a['id']]);
    audit('pay_adjust_delete', 'user', (int)$a['user_id'], ['month' => $a['month'], 'label' => $a['label'], 'amount' => (float)$a['amount']]);
    touch_change();
    json_out(['ok' => true]);
}

function act_payroll_salary(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $salary = money_in($in['salary'] ?? 0);
    if ($salary < 0) {
        fail('Salary can\'t be negative.');
    }
    $old = q('SELECT salary FROM users WHERE id = ?', [$uid])->fetchColumn();
    if ($old === false) {
        fail('Staff member not found.', 404);
    }
    q('UPDATE users SET salary = ? WHERE id = ?', [$salary, $uid]);
    audit('salary_set', 'user', $uid, ['from' => (float)$old, 'to' => $salary]);
    touch_change();
    json_out(['ok' => true]);
}

/** Freeze (or unfreeze) the month's figures for one staff member, or everyone on the payroll. */
function act_payroll_lock(array $in, array $me): void
{
    require_admin();
    $month = month_in($in);
    $lock = !empty($in['lock']);
    $ids = !empty($in['user_id']) ? [(int)$in['user_id']] : array_map(fn($s) => (int)$s['id'], payroll_staff($month));
    foreach ($ids as $uid) {
        if ($lock) {
            payroll_lock_one($uid, $month, $me);
        } else {
            // Unlocking also unpublishes: the figures may change.
            q('DELETE FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid]);
        }
    }
    audit($lock ? 'payroll_lock' : 'payroll_unlock', 'payroll', null, ['month' => $month, 'staff' => count($ids)]);
    touch_change();
    json_out(['ok' => true]);
}

/** Save a snapshot of one staff member's month; relocking refreshes it and keeps it published if it was. */
function payroll_lock_one(int $uid, string $month, array $me): void
{
    $data = payroll_calc($uid, $month, true);
    unset($data['locked'], $data['published_at']);
    q('INSERT INTO pay_locks (month, user_id, data, locked_by, locked_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), locked_by = VALUES(locked_by), locked_at = NOW()',
        [$month, $uid, json_encode($data, JSON_UNESCAPED_UNICODE), $me['id']]);
}

/** Show (or hide) the final month to staff: their deductions, adjustments, advances and net pay. Publishing locks first. */
function act_payroll_publish(array $in, array $me): void
{
    require_admin();
    $month = month_in($in);
    $publish = !empty($in['publish']);
    $ids = !empty($in['user_id']) ? [(int)$in['user_id']] : array_map(fn($s) => (int)$s['id'], payroll_staff($month));
    $label = date('F Y', strtotime($month . '-01'));
    $told = [];
    foreach ($ids as $uid) {
        $was = q('SELECT published_at FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid])->fetch();
        if ($publish) {
            if (!$was) {
                payroll_lock_one($uid, $month, $me);
            }
            if (!$was || !$was['published_at']) {
                q('UPDATE pay_locks SET published_at = NOW() WHERE month = ? AND user_id = ?', [$month, $uid]);
                $told[] = $uid;
            }
        } elseif ($was) {
            q('UPDATE pay_locks SET published_at = NULL WHERE month = ? AND user_id = ?', [$month, $uid]);
        }
    }
    if ($told) {
        notify($told, 'payroll', 'Your ' . $label . ' pay is ready', 'See your full breakdown and final pay.', 'my-pay');
    }
    audit($publish ? 'payroll_publish' : 'payroll_unpublish', 'payroll', null, ['month' => $month, 'staff' => count($ids)]);
    touch_change();
    json_out(['ok' => true]);
}

/** Record a salary advance; it comes off the pay of the month it's recorded in. Staff can see their advances. */
function act_payroll_advance(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $month = month_in($in);
    $amount = money_in($in['amount'] ?? 0);
    $given = (string)($in['given_on'] ?? '');
    $errors = [];
    if ($amount <= 0) {
        $errors['amount'] = 'Enter the amount given.';
    }
    if (!valid_date($given)) {
        $errors['given_on'] = 'Choose the date it was given.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    if (!q('SELECT 1 FROM users WHERE id = ?', [$uid])->fetchColumn()) {
        fail('Staff member not found.', 404);
    }
    $label = date('F Y', strtotime($month . '-01'));
    if (month_locked($month, $uid)) {
        fail('Payroll for ' . $label . ' is locked. Unlock it first.');
    }
    q('INSERT INTO pay_advances (user_id, month, amount, given_on, note, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$uid, $month, $amount, $given, str_in($in['note'] ?? '', 500), $me['id']]);
    $id = (int)db()->lastInsertId();
    audit('pay_advance', 'user', $uid, ['month' => $month, 'amount' => $amount]);
    notify([$uid], 'payroll', 'Salary advance: ' . cfg('currency', '₦') . number_format($amount), 'It will come off your ' . $label . ' pay.', 'my-pay');
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_payroll_advance_delete(array $in, array $me): void
{
    require_admin();
    $a = q('SELECT * FROM pay_advances WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$a) {
        fail('Advance not found.', 404);
    }
    if (month_locked($a['month'], (int)$a['user_id'])) {
        fail('This month is locked. Unlock it first.');
    }
    q('DELETE FROM pay_advances WHERE id = ?', [$a['id']]);
    audit('pay_advance_delete', 'user', (int)$a['user_id'], ['month' => $a['month'], 'amount' => (float)$a['amount']]);
    touch_change();
    json_out(['ok' => true]);
}

function act_payroll_export(array $in, array $me): void
{
    require_admin();
    $month = month_in($in);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Staff', 'Bank', 'Account number', 'Salary', 'Late days', 'Lateness charges', 'Absences', 'Absence charges', 'Missed reports', 'Report charges',
        'Overtime days', 'Overtime pay', 'Adjustments', 'Salary advances', 'Net pay', 'Locked', 'Published']);
    foreach (payroll_staff($month) as $s) {
        $x = payroll_summary(payroll_calc((int)$s['id'], $month));
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), [$x['name'], $x['bank_name'], $x['bank_account'], $x['salary'], $x['counts']['late'], $x['totals']['late'],
            $x['counts']['absence'], $x['totals']['absence'], $x['counts']['report'], $x['totals']['report'], $x['counts']['overtime'], $x['overtime'],
            $x['adjustments'], $x['advances'], $x['net'], $x['locked'] ? 'Yes' : 'No', $x['published'] ? 'Yes' : 'No']));
    }
    exit;
}
