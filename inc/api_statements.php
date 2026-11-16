<?php
/* Staff statement of account: salary, earnings, deductions, advances and net pay over one or more months,
   turned into a branded PDF in the browser (assets/js/pdfstatement.js). Admins can send the PDF to the staff member,
   who finds it under "My deductions" and gets a phone alert. Files are attachments with owner_type 'statement'
   and owner_id = the staff member's user id. */

/** Letterhead and sign-off printed on statements. Editable in Settings → Company. */
function letterhead(): array
{
    $s = setting('letterhead');
    $s = is_array($s) ? $s : [];
    return [
        'company' => (string)(setting('company_name') ?: cfg('company')),
        'address' => (string)(setting('company_address') ?: cfg('address', '')),
        'phone' => (string)($s['phone'] ?? '+234 (0) 703 295 0008'),
        'email' => (string)($s['email'] ?? 'samuel@devtech.ng'),
        'signatory' => (string)($s['signatory'] ?? 'Samuel Ezennaya'),
        'signatory_title' => (string)($s['signatory_title'] ?? 'Managing Director'),
    ];
}

function letterhead_save(array $in): void
{
    $cur = letterhead();
    $out = [];
    foreach (['phone' => 60, 'email' => 120, 'signatory' => 120, 'signatory_title' => 120] as $k => $len) {
        $out[$k] = isset($in[$k]) ? str_in($in[$k], $len) : $cur[$k];
    }
    save_setting('letterhead', $out);
}

/** One month's statement lines from the payroll calculation. */
function statement_month(array $c): array
{
    $lines = [];
    foreach ($c['days'] as $d) {
        foreach ($d['items'] as $it) {
            $lines[] = ['date' => $d['date'], 'label' => $it['label'], 'reason' => (string)($it['note'] ?? ''),
                'amount' => (float)$it['charge'], 'waived' => $it['waived'] ? (string)$it['waived'] : null];
        }
    }
    $fines = [];
    $bonuses = [];
    foreach ($c['adjustments'] as $a) {
        $row = ['date' => substr((string)$a['created_at'], 0, 10), 'label' => $a['label'], 'reason' => (string)($a['note'] ?? ''), 'amount' => abs((float)$a['amount'])];
        if ($a['amount'] < 0) {
            $fines[] = $row;
            $lines[] = $row + ['waived' => null];
        } else {
            $bonuses[] = $row;
        }
    }
    usort($lines, fn($a, $b) => [$a['date'], $a['label']] <=> [$b['date'], $b['label']]);
    $totals = $c['totals'] + ['conduct' => 0];
    $counts = $c['counts'] + ['conduct' => 0];
    $finesTotal = round(array_sum(array_column($fines, 'amount')), 2);
    $bonusTotal = round(array_sum(array_column($bonuses, 'amount')), 2);
    return [
        'month' => $c['month'],
        'status' => !empty($c['published_at']) ? 'final' : (!empty($c['locked']) ? 'locked' : 'provisional'),
        'salary' => (float)$c['salary'],
        'overtime' => (float)$c['overtime'],
        'overtime_days' => (int)$counts['overtime'],
        'bonuses' => $bonuses,
        'bonus_total' => $bonusTotal,
        'groups' => [
            ['label' => 'Lateness', 'count' => (int)$counts['late'], 'amount' => (float)$totals['late']],
            ['label' => 'Absence', 'count' => (int)$counts['absence'], 'amount' => (float)$totals['absence']],
            ['label' => 'Daily reports', 'count' => (int)$counts['report'], 'amount' => (float)$totals['report']],
            ['label' => 'Rule breaches (clock-out, GPS, sign-off, overdue, rework)', 'count' => (int)$counts['conduct'], 'amount' => (float)$totals['conduct']],
            ['label' => 'Fines and other charges', 'count' => count($fines), 'amount' => $finesTotal],
        ],
        'deductions' => round((float)$c['deductions'] + $finesTotal, 2),
        'advances' => array_map(fn($a) => ['date' => $a['given_on'], 'amount' => (float)$a['amount'], 'note' => (string)$a['note']], $c['advances']),
        'advances_total' => (float)$c['advances_total'],
        'earnings' => round((float)$c['salary'] + (float)$c['overtime'] + $bonusTotal, 2),
        'net' => (float)$c['net'],
        'lines' => $lines,
    ];
}

/** Statements already sent to a staff member, newest first. */
function statements_sent(int $uid): array
{
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['original_name'], 'size' => (int)$r['size'], 'sent_at' => $r['created_at'], 'by' => $r['by_name']],
        q("SELECT a.id, a.original_name, a.size, a.created_at, u.name AS by_name FROM attachments a LEFT JOIN users u ON u.id = a.user_id
           WHERE a.owner_type = 'statement' AND a.owner_id = ? ORDER BY a.id DESC LIMIT 50", [$uid])->fetchAll());
}

/** GET from, to (YYYY-MM), user_id (admins). Staff get their own published months only. */
function act_staff_statement(array $in, array $me): void
{
    $admin = is_admin($me);
    $uid = $me['id'];
    if (!empty($in['user_id']) && (int)$in['user_id'] !== $me['id']) {
        require_admin();
        $uid = (int)$in['user_id'];
    }
    $to = valid_month($in['to'] ?? null) ? $in['to'] : date('Y-m');
    $from = valid_month($in['from'] ?? null) ? $in['from'] : $to;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $u = q('SELECT id, name, email, phone, job_title, bank_name, bank_account FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        fail('Staff member not found.', 404);
    }
    $months = [];
    $skipped = [];
    for ($m = $from, $n = 0; $m <= $to && $n < 12; $m = date('Y-m', strtotime($m . '-01 +1 month')), $n++) {
        $c = payroll_calc($uid, $m);
        if (!$admin && empty($c['published_at'])) {
            $skipped[] = $m; // staff only get figures the admin has published
            continue;
        }
        $months[] = statement_month($c);
    }
    json_out(['ok' => true, 'from' => $from, 'to' => $to, 'months' => $months, 'skipped' => $skipped,
        'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'job_title' => $u['job_title'],
            'bank_name' => $u['bank_name'], 'bank_account' => $u['bank_account']],
        'letterhead' => letterhead(), 'sent' => statements_sent($uid)]);
}

/** POST user_id, period + one PDF file: save it to the staff member's statements and alert them. */
function act_staff_statement_send(array $in, array $me): void
{
    require_admin();
    $uid = (int)($in['user_id'] ?? 0);
    $u = q('SELECT id, name FROM users WHERE id = ?', [$uid])->fetch();
    if (!$u) {
        fail('Staff member not found.', 404);
    }
    if (count(uploaded_files()) !== 1) {
        fail('Attach the statement PDF.');
    }
    $pdf = ['application/pdf' => 'pdf'];
    if ($err = check_uploads('files', 0, $pdf)) {
        fail($err);
    }
    $ids = save_uploads('statement', $uid, $me['id'], 'files', $pdf);
    if (!$ids) {
        fail('The statement could not be saved. Try again.');
    }
    $period = str_in($in['period'] ?? '', 60);
    $note = str_in($in['note'] ?? '', 500);
    notify([$uid], 'statement', 'Your statement of account' . ($period !== '' ? " for $period" : '') . ' is ready',
        ($note !== '' ? $note . "\n" : '') . 'Open My deductions to download the PDF.', '#/my-statement');
    json_out(['ok' => true, 'id' => $ids[0]]);
}

/** Staff: statements sent to me. */
function act_statements_mine(array $in, array $me): void
{
    json_out(['ok' => true, 'sent' => statements_sent($me['id'])]);
}
