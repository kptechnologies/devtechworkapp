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
    return q("SELECT id, name, salary, bank_name, bank_account FROM users WHERE role = 'staff'
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
        JOIN users u ON u.id = a.user_id AND u.role = 'staff'
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
        $lock = q('SELECT data, locked_at FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid])->fetch();
        if ($lock) {
            return ['locked' => true, 'locked_at' => $lock['locked_at']] + (json_decode($lock['data'], true) ?: []);
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
    $salary = (float)$u['salary'];
    $deductions = $totals['late'] + $totals['absence'] + $totals['report'];
    return [
        'locked' => false,
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
        'net' => round($salary - $deductions + $totals['overtime'] + $adjTotal, 2),
    ];
}

/** Summary row for the payroll table. */
function payroll_summary(array $c): array
{
    return ['id' => $c['user']['id'], 'name' => $c['user']['name'], 'salary' => $c['salary'], 'deductions' => $c['deductions'], 'overtime' => $c['overtime'],
        'adjustments' => $c['adjustments_total'], 'net' => $c['net'], 'counts' => $c['counts'], 'totals' => $c['totals'], 'locked' => $c['locked'],
        'bank_name' => $c['user']['bank_name'], 'bank_account' => $c['user']['bank_account']];
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
    $uid = is_admin($me) && !empty($in['user_id']) ? (int)$in['user_id'] : $me['id'];
    json_out(['ok' => true, 'rules' => pay_rules(), 'marks' => PAY_MARKS] + payroll_calc($uid, month_in($in)));
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
    if (!q('SELECT 1 FROM users WHERE id = ?', [$uid])->fetchColumn()) {
        fail('Staff member not found.', 404);
    }
    if (month_locked($month, $uid)) {
        fail('This month is locked. Unlock it first.');
    }
    q('INSERT INTO pay_adjustments (user_id, month, label, amount, note, created_by, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$uid, $month, $label, $amount, str_in($in['note'] ?? '', 500), $me['id']]);
    $id = (int)db()->lastInsertId();
    audit('pay_adjust', 'user', $uid, ['month' => $month, 'label' => $label, 'amount' => $amount]);
    notify([$uid], 'payroll', ($amount < 0 ? 'Salary deduction: ' : 'Salary addition: ') . $label,
        ($amount < 0 ? '-' : '+') . money_fmt(abs($amount)) . ' for ' . date('F Y', strtotime($month . '-01')), 'my-pay');
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
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
            $data = payroll_calc($uid, $month, true);
            unset($data['locked']);
            q('INSERT INTO pay_locks (month, user_id, data, locked_by, locked_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), locked_by = VALUES(locked_by), locked_at = NOW()',
                [$month, $uid, json_encode($data, JSON_UNESCAPED_UNICODE), $me['id']]);
        } else {
            q('DELETE FROM pay_locks WHERE month = ? AND user_id = ?', [$month, $uid]);
        }
    }
    audit($lock ? 'payroll_lock' : 'payroll_unlock', 'payroll', null, ['month' => $month, 'staff' => count($ids)]);
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
        'Overtime days', 'Overtime pay', 'Adjustments', 'Net pay', 'Locked']);
    foreach (payroll_staff($month) as $s) {
        $x = payroll_summary(payroll_calc((int)$s['id'], $month));
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), [$x['name'], $x['bank_name'], $x['bank_account'], $x['salary'], $x['counts']['late'], $x['totals']['late'],
            $x['counts']['absence'], $x['totals']['absence'], $x['counts']['report'], $x['totals']['report'], $x['counts']['overtime'], $x['overtime'],
            $x['adjustments'], $x['net'], $x['locked'] ? 'Yes' : 'No']));
    }
    exit;
}
