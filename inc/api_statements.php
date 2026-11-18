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
        'letterhead' => letterhead(), 'sent' => statements_sent($uid),
        'ack' => q('SELECT signed_name, signature, signed_at FROM statement_acks WHERE user_id = ? AND period = ? AND signed_at IS NOT NULL',
            [$uid, $from === $to ? $from : $from . '_' . $to])->fetch() ?: null]);
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
    $key = (string)($in['period_key'] ?? '');
    $ack = !empty($in['require_ack']) && valid_period_key($key);
    if ($ack) {
        // A re-sent statement replaces the old one and has to be signed again.
        q("INSERT INTO statement_acks (user_id, period, attachment_id, requested_by, requested_at) VALUES (?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE attachment_id = VALUES(attachment_id), requested_by = VALUES(requested_by), requested_at = NOW(),
             signed_at = NULL, signed_name = '', signature = NULL, signed_ip = ''", [$uid, $key, $ids[0], $me['id']]);
        $body = "Please review, sign and acknowledge it. You won't be able to continue in the portal until you do.";
    } else {
        $body = 'Open My deductions to download the PDF.';
    }
    notify([$uid], 'statement', 'Your statement of account' . ($period !== '' ? " for $period" : '') . ' is ready',
        ($note !== '' ? $note . "\n" : '') . $body, 'my-statement');
    json_out(['ok' => true, 'id' => $ids[0], 'ack' => $ack]);
}

/** Staff: statements sent to me. */
function act_statements_mine(array $in, array $me): void
{
    json_out(['ok' => true, 'sent' => statements_sent($me['id'])]);
}

/* ------------------------------------------------------------------ sign-off of a sent statement */

/** "2026-10" or "2026-09_2026-10". */
function valid_period_key(string $key): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}(_\d{4}-\d{2})?$/', $key);
}

/** "2026-10" → "October 2026"; "2026-09_2026-10" → "September 2026 – October 2026". */
function statement_period_label(string $key): string
{
    return implode(' – ', array_map(fn($m) => date('F Y', strtotime($m . '-01')), explode('_', $key)));
}

/** The oldest statement this user still has to sign, or null. */
function statement_ack_pending_row(int $uid): ?array
{
    $r = q('SELECT k.id, k.period, k.attachment_id, k.requested_at, a.original_name FROM statement_acks k
            LEFT JOIN attachments a ON a.id = k.attachment_id
            WHERE k.user_id = ? AND k.signed_at IS NULL ORDER BY k.requested_at, k.id LIMIT 1', [$uid])->fetch();
    return $r ?: null;
}

/** GET: the statement I must sign before I can use the portal (null when none). */
function act_statement_ack_pending(array $in, array $me): void
{
    $r = statement_ack_pending_row($me['id']);
    json_out(['ok' => true, 'pending' => $r ? ['id' => (int)$r['id'], 'period' => $r['period'], 'label' => statement_period_label($r['period']),
        'file_id' => $r['attachment_id'] ? (int)$r['attachment_id'] : null, 'file_name' => $r['original_name'], 'requested_at' => $r['requested_at']] : null]);
}

/** POST id, name, agree, signature (PNG data URL): sign and acknowledge a statement. */
function act_statement_ack_sign(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $r = q('SELECT id, period FROM statement_acks WHERE id = ? AND user_id = ?', [$id, $me['id']])->fetch();
    if (!$r) {
        fail('Statement not found.', 404);
    }
    $name = str_in($in['name'] ?? '', 120);
    if (mb_strlen($name) < 3) {
        fail('Type your full name.');
    }
    if (empty($in['agree'])) {
        fail('Tick the box to confirm you have read and acknowledge the statement.');
    }
    $sig = (string)($in['signature'] ?? '');
    if (strlen($sig) < 200 || strlen($sig) > 200000 || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig)) {
        fail('Draw your signature in the box.');
    }
    $done = q('UPDATE statement_acks SET signed_at = NOW(), signed_name = ?, signature = ?, signed_ip = ? WHERE id = ? AND signed_at IS NULL',
        [$name, $sig, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $id])->rowCount();
    if ($done) {
        notify_admins('statement_signed', $me['name'] . ' signed their statement for ' . statement_period_label($r['period']),
            'Signed as "' . $name . '".', 'payroll', false);
    }
    json_out(['ok' => true, 'pending' => (bool)statement_ack_pending_row($me['id'])]);
}

/** GET period (YYYY-MM or YYYY-MM_YYYY-MM), admin: who has signed that statement and who has not. */
function act_statement_acks(array $in, array $me): void
{
    require_admin();
    $key = (string)($in['period'] ?? '');
    if (!valid_period_key($key)) {
        fail('Pick a month.');
    }
    $rows = q('SELECT k.user_id, u.name, k.requested_at, k.signed_at, k.signed_name, k.signature, k.attachment_id
               FROM statement_acks k JOIN users u ON u.id = k.user_id WHERE k.period = ? ORDER BY k.signed_at IS NOT NULL, u.name', [$key])->fetchAll();
    json_out(['ok' => true, 'period' => $key, 'label' => statement_period_label($key), 'rows' => array_map(fn($x) => [
        'user_id' => (int)$x['user_id'], 'name' => $x['name'], 'requested_at' => $x['requested_at'], 'signed_at' => $x['signed_at'],
        'signed_name' => $x['signed_name'], 'signature' => $x['signature'], 'file_id' => $x['attachment_id'] ? (int)$x['attachment_id'] : null], $rows)]);
}
