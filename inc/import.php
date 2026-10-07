<?php
/* CSV importers: Google Form reports sheet + expense console history */

function read_csv_upload(string $field = 'csv'): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        fail('Choose a CSV file to import.');
    }
    if ($_FILES[$field]['size'] > 20 * 1024 * 1024) {
        fail('That file is over 20MB.');
    }
    return read_csv_file($_FILES[$field]['tmp_name']);
}

function read_csv_file(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        fail('Could not read the file.');
    }
    $first = fgets($fh);
    rewind($fh);
    $delim = substr_count((string)$first, "\t") > substr_count((string)$first, ',') ? "\t" : ',';
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) {
            continue;
        }
        $rows[] = $r;
    }
    fclose($fh);
    if (count($rows) < 2) {
        fail('The file has no data rows.');
    }
    $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$rows[0][0]);
    return $rows;
}

/** Decide whether slash dates are day-first or month-first by scanning the column. */
function detect_date_order(array $values, string $default): string
{
    foreach ($values as $v) {
        if (preg_match('#^\s*(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', (string)$v, $m)) {
            if ((int)$m[1] > 12) {
                return 'dmy';
            }
            if ((int)$m[2] > 12) {
                return 'mdy';
            }
        }
    }
    return $default;
}

/** Returns 'Y-m-d H:i:s' (or 'Y-m-d' when $dateOnly) or null. */
function parse_any_date(string $v, string $order, bool $dateOnly = false): ?string
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    $time = '00:00:00';
    if (preg_match('#(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([ap]\.?m\.?)?#i', $v, $t)) {
        $h = (int)$t[1];
        $ap = strtolower(str_replace('.', '', $t[4] ?? ''));
        if ($ap === 'pm' && $h < 12) {
            $h += 12;
        } elseif ($ap === 'am' && $h === 12) {
            $h = 0;
        }
        $time = sprintf('%02d:%02d:%02d', $h, (int)$t[2], (int)($t[3] ?? 0));
    }
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})#', $v, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $v, $m)) {
        [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $y = $y < 100 ? 2000 + $y : $y;
        [$d, $mo] = $order === 'mdy' ? [$b, $a] : [$a, $b];
    } else {
        $ts = strtotime($v);
        if ($ts === false) {
            return null;
        }
        return date($dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s', $ts);
    }
    if (!checkdate($mo, $d, $y)) {
        return null;
    }
    $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
    return $dateOnly ? $date : "$date $time";
}

function parse_time_hm(string $v): string
{
    if (!preg_match('#(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]\.?m\.?)?#i', trim($v), $t)) {
        return '';
    }
    $h = (int)$t[1];
    $ap = strtolower(str_replace('.', '', $t[3] ?? ''));
    if ($ap === 'pm' && $h < 12) {
        $h += 12;
    } elseif ($ap === 'am' && $h === 12) {
        $h = 0;
    }
    return sprintf('%02d:%02d', $h % 24, (int)$t[2]);
}

/** Split a Google Forms checkbox answer, keeping options that contain commas intact. */
function split_checks(string $v, array $opts): array
{
    $rest = $v;
    $found = [];
    $byLen = $opts;
    usort($byLen, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($byLen as $o) {
        if ($o !== '' && str_contains($rest, $o)) {
            $found[$o] = true;
            $rest = str_replace($o, '', $rest);
        }
    }
    $out = array_values(array_filter($opts, fn($o) => isset($found[$o])));
    foreach (explode(',', $rest) as $piece) {
        $piece = trim($piece);
        if ($piece !== '') {
            $out[] = $piece;
        }
    }
    return $out;
}

function find_or_create_staff(string $email, string $name, string $location, array &$cache, array &$created): ?int
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    if (isset($cache[$email])) {
        return $cache[$email];
    }
    $id = q('SELECT id FROM users WHERE email = ?', [$email])->fetchColumn();
    if (!$id) {
        $name = trim($name) !== '' ? mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE) : explode('@', $email)[0];
        q("INSERT INTO users (name, email, role, location, active, created_at) VALUES (?, ?, 'staff', ?, 1, NOW())", [$name, $email, mb_substr($location, 0, 120)]);
        $id = db()->lastInsertId();
        $created[] = "$name <$email>";
    }
    return $cache[$email] = (int)$id;
}

function act_import_reports(array $in, array $me): void
{
    require_admin();
    @set_time_limit(300);
    $rows = read_csv_upload();
    $header = array_shift($rows);
    $fields = report_fields();
    $byNorm = [];
    foreach ($fields as $id => $f) {
        $byNorm[norm_header($f['label'])] = $id;
        if (!empty($f['h'])) {
            $byNorm[norm_header($f['h'])] = $id;
        }
    }
    $map = [];
    $unmatched = [];
    $special = ['timestamp' => null, 'emailaddress' => null, 'fullname' => null];
    foreach ($header as $i => $h) {
        $n = norm_header((string)$h);
        if (array_key_exists($n, $special) && $special[$n] === null) {
            $special[$n] = $i;
        } elseif (isset($byNorm[$n]) && !in_array($byNorm[$n], $map, true)) {
            $map[$i] = $byNorm[$n];
        } elseif (trim((string)$h) !== '') {
            $unmatched[$i] = trim((string)$h);
        }
    }
    if ($special['emailaddress'] === null) {
        fail('No "Email address" column found. Export the "Form responses 1" tab as CSV.');
    }
    $dateCol = array_search('report_date', $map, true);
    $tsOrder = $special['timestamp'] !== null ? detect_date_order(array_column($rows, $special['timestamp']), 'mdy') : 'mdy';
    $dOrder = $dateCol !== false ? detect_date_order(array_column($rows, $dateCol), 'dmy') : 'dmy';

    $cache = [];
    $created = [];
    $added = 0;
    $skipped = 0;
    $problems = [];
    db()->beginTransaction();
    foreach ($rows as $n => $row) {
        $get = fn($i) => $i === null ? '' : trim((string)($row[$i] ?? ''));
        $data = [];
        foreach ($fields as $id => $f) {
            $data[$id] = $f['type'] === 'checks' ? [] : '';
        }
        foreach ($map as $i => $id) {
            $v = $get($i);
            $f = $fields[$id];
            $data[$id] = match ($f['type']) {
                'checks' => $v === '' ? [] : split_checks($v, (array)($f['opts'] ?? [])),
                'number' => is_numeric($v) ? 0 + $v : (preg_match('/\d+/', $v, $m) ? (int)$m[0] : ''),
                'time'   => parse_time_hm($v),
                'date'   => (string)parse_any_date($v, $dOrder, true),
                default  => $v,
            };
        }
        foreach ($unmatched as $i => $h) {
            if ($get($i) !== '') {
                $data['_extra'][$h] = $get($i);
            }
        }
        $created_at = parse_any_date($get($special['timestamp']), $tsOrder) ?? date('Y-m-d H:i:s');
        if ($data['report_date'] === '') {
            $data['report_date'] = substr($created_at, 0, 10);
        }
        $uid = find_or_create_staff($get($special['emailaddress']), $get($special['fullname']), (string)$data['location'], $cache, $created);
        if (!$uid) {
            $skipped++;
            $problems[] = 'Row ' . ($n + 2) . ': missing or invalid email';
            continue;
        }
        if (q('SELECT 1 FROM reports WHERE user_id = ? AND created_at = ?', [$uid, $created_at])->fetchColumn()) {
            $skipped++;
            continue;
        }
        $sum = report_summary($data);
        $cols = implode(', ', array_keys($sum));
        $qs = implode(', ', array_fill(0, count($sum), '?'));
        q("INSERT INTO reports (user_id, report_date, $cols, data, source, created_at) VALUES (?, ?, $qs, ?, 'import', ?)",
            array_merge([$uid, $data['report_date']], array_values($sum), [json_encode($data, JSON_UNESCAPED_UNICODE), $created_at]));
        $added++;
    }
    audit('import_reports', 'report', null, ['added' => $added, 'skipped' => $skipped, 'users_created' => count($created)]);
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'users_created' => $created,
        'unmatched_columns' => array_values($unmatched), 'problems' => array_slice($problems, 0, 30)]);
}

/* ------------------------------------------------- expense console history */

function import_tmp_dir(): string
{
    $dir = APP_ROOT . '/uploads/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    foreach (glob($dir . '/*.csv') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    return $dir;
}

function act_import_expenses_preview(array $in, array $me): void
{
    require_admin();
    $rows = read_csv_upload();
    $token = bin2hex(random_bytes(12));
    move_uploaded_file($_FILES['csv']['tmp_name'], import_tmp_dir() . "/$token.csv");
    $header = array_map(fn($h) => trim((string)$h), $rows[0]);
    $guess = [];
    $rules = [
        'date' => '/date|day|time/i', 'email' => '/e-?mail/i', 'staff' => '/staff|name|person|employee/i',
        'amount' => '/amount|sum|value|naira|cost|₦/i', 'kind' => '/type|kind|transaction|direction|entry/i',
        'category' => '/categor|purpose type|head/i', 'description' => '/purpose|descr|detail|note|narration|remark|item/i',
        'receipt' => '/receipt|proof|evidence|link|attach/i',
    ];
    foreach ($rules as $k => $re) {
        foreach ($header as $i => $h) {
            if (preg_match($re, $h) && !in_array($i, $guess, true)) {
                $guess[$k] = $i;
                break;
            }
        }
    }
    json_out(['ok' => true, 'token' => $token, 'header' => $header, 'rows' => array_slice($rows, 1, 10), 'total' => count($rows) - 1, 'guess' => $guess]);
}

function act_import_expenses_commit(array $in, array $me): void
{
    require_admin();
    @set_time_limit(300);
    $token = preg_replace('/[^a-f0-9]/', '', (string)($in['token'] ?? ''));
    $path = import_tmp_dir() . "/$token.csv";
    if ($token === '' || !is_file($path)) {
        fail('Upload the file again (the preview expired).');
    }
    $map = (array)($in['map'] ?? []);
    $col = fn($k) => (isset($map[$k]) && $map[$k] !== '' && $map[$k] !== null) ? (int)$map[$k] : null;
    if ($col('amount') === null || $col('date') === null || ($col('staff') === null && $col('email') === null)) {
        fail('Map at least the date, amount, and staff (name or email) columns.');
    }
    $fixedKind = in_array($in['fixed_kind'] ?? '', ['credit', 'debit', 'adjust'], true) ? $in['fixed_kind'] : null;
    if ($col('kind') === null && !$fixedKind) {
        fail('Map the type column, or choose what every row is.');
    }
    $rows = read_csv_file($path);
    array_shift($rows);
    $order = detect_date_order(array_column($rows, $col('date')), (string)($in['date_order'] ?? 'dmy'));

    $users = q('SELECT id, name, email FROM users')->fetchAll();
    $findUser = function (string $email, string $name) use ($users): ?int {
        $email = strtolower(trim($email));
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        foreach ($users as $u) {
            if ($email !== '' && strtolower($u['email']) === $email) {
                return (int)$u['id'];
            }
        }
        if ($name === '') {
            return null;
        }
        $hits = [];
        foreach ($users as $u) {
            $un = mb_strtolower($u['name']);
            if ($un === $name) {
                return (int)$u['id'];
            }
            // "Onwe Prince" ↔ "Onwe Prince Chukwuemeka", "Christain" typo-tolerant first-name match
            if (str_starts_with($un, $name) || str_starts_with($name, $un) || in_array($name, explode(' ', $un), true)
                || levenshtein($name, explode(' ', $un)[0]) <= 1) {
                $hits[] = (int)$u['id'];
            }
        }
        return count(array_unique($hits)) === 1 ? $hits[0] : null;
    };

    $added = 0;
    $skipped = 0;
    $unknown = [];
    $problems = [];
    db()->beginTransaction();
    foreach ($rows as $n => $row) {
        $get = fn($k) => $col($k) === null ? '' : trim((string)($row[$col($k)] ?? ''));
        $rawAmount = str_replace([',', ' ', cfg('currency', '₦'), 'NGN', '₦'], '', $get('amount'));
        $neg = str_starts_with($rawAmount, '-') || (str_starts_with($rawAmount, '(') && str_ends_with($rawAmount, ')'));
        $amount = abs((float)trim($rawAmount, '()-'));
        if ($amount == 0.0) {
            $skipped++;
            continue;
        }
        $date = parse_any_date($get('date'), $order, true);
        if (!$date) {
            $skipped++;
            $problems[] = 'Row ' . ($n + 2) . ': unreadable date "' . $get('date') . '"';
            continue;
        }
        $uid = $findUser($get('email'), $get('staff'));
        if (!$uid) {
            $skipped++;
            $unknown[$get('staff') ?: $get('email')] = true;
            continue;
        }
        $kind = $fixedKind;
        if ($col('kind') !== null) {
            $k = strtolower($get('kind'));
            $kind = match (true) {
                (bool)preg_match('/adjust|correct|revers/', $k) => 'adjust',
                (bool)preg_match('/spent|spend|expense|debit|deduct|withdraw|used|purchase|transport/', $k) => 'debit',
                (bool)preg_match('/sent|send|payment|allowance|credit|fund|top|deposit|budget|paid/', $k) => 'credit',
                default => $fixedKind,
            };
            if (!$kind) {
                $skipped++;
                $problems[] = 'Row ' . ($n + 2) . ': unknown type "' . $get('kind') . '"';
                continue;
            }
        }
        $desc = $get('description');
        if ($get('receipt') !== '') {
            $desc .= ($desc !== '' ? "\n" : '') . 'Receipt: ' . $get('receipt');
        }
        $category = $get('category') ?: match ($kind) {
            'credit' => 'Imported payment', 'adjust' => 'Adjustment', default => 'Imported expense',
        };
        $signed = $kind === 'adjust' ? ($neg ? -$amount : $amount) : $amount;
        if (q("SELECT 1 FROM wallet_txns WHERE user_id = ? AND txn_date = ? AND kind = ? AND amount = ? AND description = ? AND source = 'import'",
            [$uid, $date, $kind, $amount, $desc])->fetchColumn()) {
            $skipped++;
            continue;
        }
        wallet_add([
            'user_id' => $uid, 'kind' => $kind, 'category' => mb_substr($category, 0, 80), 'amount' => $signed,
            'description' => $desc, 'txn_date' => $date, 'status' => $kind === 'debit' ? 'approved' : 'posted',
            'created_by' => $me['id'], 'source' => 'import', 'created_at' => $date . ' 12:00:00',
        ]);
        $added++;
    }
    audit('import_expenses', 'txn', null, ['added' => $added, 'skipped' => $skipped]);
    db()->commit();
    @unlink($path);
    touch_change();
    json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'unknown_staff' => array_keys($unknown), 'problems' => array_slice($problems, 0, 30)]);
}
