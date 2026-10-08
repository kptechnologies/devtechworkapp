<?php
/* Admin: dashboard, staff setup, settings */

function act_dashboard(array $in, array $me): void
{
    require_admin();
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime('-29 days'));
    $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
    $byDay = $days <= 62;
    $bucketTxn = $byDay ? 'txn_date' : "DATE_FORMAT(txn_date, '%Y-%m')";
    $bucketRep = $byDay ? 'report_date' : "DATE_FORMAT(report_date, '%Y-%m')";

    // Money
    $m = q("SELECT
            COALESCE(SUM(CASE WHEN kind='credit' THEN amount END),0) AS sent,
            COALESCE(SUM(CASE WHEN kind='debit' THEN amount END),0) AS spent,
            COALESCE(SUM(CASE WHEN kind='adjust' THEN effect END),0) AS adjust,
            SUM(CASE WHEN kind='debit' THEN 1 ELSE 0 END) AS n_expenses
            FROM wallet_txns WHERE status <> 'rejected' AND txn_date BETWEEN ? AND ?", [$from, $to])->fetch();
    $available = (float)q("SELECT COALESCE(SUM(effect),0) FROM wallet_txns WHERE status <> 'rejected'")->fetchColumn();
    $series = q("SELECT $bucketTxn AS b,
            COALESCE(SUM(CASE WHEN kind='credit' THEN amount END),0) AS sent,
            COALESCE(SUM(CASE WHEN kind='debit' THEN amount END),0) AS spent,
            COALESCE(SUM(CASE WHEN kind='adjust' THEN effect END),0) AS adjust
            FROM wallet_txns WHERE status <> 'rejected' AND txn_date BETWEEN ? AND ? GROUP BY b ORDER BY b", [$from, $to])->fetchAll();
    $categories = q("SELECT category AS label, SUM(amount) AS value FROM wallet_txns
            WHERE kind='debit' AND status <> 'rejected' AND txn_date BETWEEN ? AND ? GROUP BY category ORDER BY value DESC", [$from, $to])->fetchAll();
    $topExpenses = q("SELECT w.id, w.amount, w.category, w.description, w.txn_date, u.name AS user_name FROM wallet_txns w JOIN users u ON u.id = w.user_id
            WHERE w.kind='debit' AND w.status <> 'rejected' AND w.txn_date BETWEEN ? AND ? ORDER BY w.amount DESC LIMIT 10", [$from, $to])->fetchAll();
    $topSpenders = q("SELECT u.name AS label, SUM(w.amount) AS value FROM wallet_txns w JOIN users u ON u.id = w.user_id
            WHERE w.kind='debit' AND w.status <> 'rejected' AND w.txn_date BETWEEN ? AND ? GROUP BY u.id, u.name ORDER BY value DESC LIMIT 10", [$from, $to])->fetchAll();

    // Reports
    $r = q("SELECT COUNT(*) AS n,
            SUM(CASE WHEN finished = 'Yes – completed' THEN 1 ELSE 0 END) AS done,
            COALESCE(SUM(classes_taught),0) AS classes,
            SUM(CASE WHEN lesson = 'Yes' THEN 1 ELSE 0 END) AS lesson_days
            FROM reports WHERE report_date BETWEEN ? AND ?", [$from, $to])->fetch();
    $repSeries = q("SELECT $bucketRep AS b, finished, COUNT(*) AS n FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY b, finished ORDER BY b", [$from, $to])->fetchAll();
    $byLocation = q("SELECT location AS label, COUNT(*) AS value FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY location ORDER BY value DESC", [$from, $to])->fetchAll();
    // A report can list several duties ("Installation, Maintenance & repair"): count each one.
    $duties = [];
    foreach (q('SELECT work_type, COUNT(*) AS n FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY work_type', [$from, $to])->fetchAll() as $w) {
        foreach ($w['work_type'] === '' ? [''] : explode(', ', $w['work_type']) as $duty) {
            $duties[$duty] = ($duties[$duty] ?? 0) + (int)$w['n'];
        }
    }
    arsort($duties);
    $byWorkType = array_map(fn($k, $v) => ['label' => (string)$k, 'value' => $v], array_keys($duties), $duties);
    // Faulty laptops: latest report per location in range.
    $faulty = q("SELECT r.location, r.laptops_faulty, r.laptops_total, r.report_date FROM reports r
            JOIN (SELECT location, MAX(id) AS id FROM reports WHERE report_date BETWEEN ? AND ? AND location <> '' GROUP BY location) x ON x.id = r.id
            ORDER BY r.laptops_faulty DESC", [$from, $to])->fetchAll();

    $activeStaff = q("SELECT id, name FROM users WHERE role = 'staff' AND active = 1 ORDER BY name")->fetchAll();
    $reportedToday = array_map('intval', q('SELECT DISTINCT user_id FROM reports WHERE report_date = ?', [today()])->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_filter($activeStaff, fn($s) => !in_array((int)$s['id'], $reportedToday, true)));

    $review = q('SELECT ' . TXN_COLS . ' ' . TXN_JOINS . " WHERE w.kind='debit' AND w.status IN ('posted','queried') ORDER BY w.status = 'posted' DESC, w.id DESC LIMIT 8")->fetchAll();
    $requests = q("SELECT f.id, f.amount, f.purpose, f.needed_by, f.created_at, u.name AS user_name FROM fund_requests f JOIN users u ON u.id = f.user_id WHERE f.status = 'pending' ORDER BY f.id DESC LIMIT 8")->fetchAll();

    json_out([
        'ok' => true, 'from' => $from, 'to' => $to, 'by_day' => $byDay,
        'money' => [
            'sent' => (float)$m['sent'], 'spent' => (float)$m['spent'], 'adjust' => (float)$m['adjust'],
            'available' => round($available, 2), 'n_expenses' => (int)$m['n_expenses'],
            'pending_reviews' => (int)q("SELECT COUNT(*) FROM wallet_txns WHERE kind='debit' AND status IN ('posted','queried')")->fetchColumn(),
            'pending_requests' => (int)q("SELECT COUNT(*) FROM fund_requests WHERE status='pending'")->fetchColumn(),
        ],
        'series' => $series,
        'categories' => $categories,
        'top_expenses' => with_file_counts($topExpenses),
        'top_spenders' => $topSpenders,
        'balances' => wallet_all_balances(),
        'reports' => [
            'n' => (int)$r['n'], 'done' => (int)$r['done'], 'classes' => (int)$r['classes'], 'lesson_days' => (int)$r['lesson_days'],
            'today' => count($reportedToday), 'staff' => count($activeStaff),
            'faulty' => array_sum(array_map(fn($x) => (int)$x['laptops_faulty'], $faulty)),
        ],
        'rep_series' => $repSeries,
        'by_location' => $byLocation,
        'by_work_type' => $byWorkType,
        'faulty_by_location' => $faulty,
        'missing_today' => $missing,
        'attendance' => attendance_today_summary(),
        'jobs' => [
            'active' => (int)q("SELECT COUNT(*) FROM jobs WHERE status IN ('open','in_progress','returned')")->fetchColumn(),
            'to_check' => (int)q("SELECT COUNT(*) FROM jobs WHERE status = 'awaiting_check'")->fetchColumn(),
            'requested' => (int)q("SELECT COUNT(*) FROM jobs WHERE status = 'requested'")->fetchColumn(),
            'overdue' => (int)q("SELECT COUNT(*) FROM jobs WHERE due_date < ? AND status IN ('open','in_progress','returned','requested')", [today()])->fetchColumn(),
            'done' => (int)q("SELECT COUNT(*) FROM jobs WHERE status = 'done' AND DATE(verified_at) BETWEEN ? AND ?", [$from, $to])->fetchColumn(),
        ],
        'review' => $review,
        'requests' => $requests,
    ]);
}

/* ------------------------------------------------------------------ staff */

function act_users_list(array $in, array $me): void
{
    require_admin();
    $rows = q("SELECT u.id, u.name, u.email, u.phone, u.role, u.location, u.active, u.last_login, u.created_at,
               u.password_hash IS NOT NULL AS has_password,
               (SELECT MAX(report_date) FROM reports r WHERE r.user_id = u.id) AS last_report,
               (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id) AS reports,
               (SELECT COALESCE(SUM(effect),0) FROM wallet_txns w WHERE w.user_id = u.id AND w.status <> 'rejected') AS balance
               FROM users u ORDER BY u.active DESC, u.role, u.name")->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['active'] = (int)$r['active'];
        $r['has_password'] = (bool)$r['has_password'];
        $r['balance'] = round((float)$r['balance'], 2);
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function act_user_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $name = str_in($in['name'] ?? '', 120);
    $email = strtolower(str_in($in['email'] ?? '', 190));
    $role = ($in['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
    $active = !empty($in['active']) ? 1 : 0;
    $pass = (string)($in['password'] ?? '');
    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Enter a name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email.';
    } elseif (q('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetchColumn()) {
        $errors['email'] = 'Another account already uses this email.';
    }
    if ($pass !== '' && strlen($pass) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    }
    if (!$id && $pass === '') {
        $errors['password'] = 'Set a starting password.';
    }
    if ($id === $me['id'] && ($role !== 'admin' || !$active)) {
        $errors['role'] = 'You can\'t remove your own admin access.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $vals = [$name, $email, str_in($in['phone'] ?? '', 40), $role, str_in($in['location'] ?? '', 120), $active];
    if ($id) {
        q('UPDATE users SET name = ?, email = ?, phone = ?, role = ?, location = ?, active = ? WHERE id = ?', array_merge($vals, [$id]));
        audit('user_update', 'user', $id, ['email' => $email, 'role' => $role, 'active' => $active]);
    } else {
        q('INSERT INTO users (name, email, phone, role, location, active, created_at) VALUES (?,?,?,?,?,?,NOW())', $vals);
        $id = (int)db()->lastInsertId();
        audit('user_create', 'user', $id, ['email' => $email, 'role' => $role]);
    }
    if ($pass !== '') {
        q('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        audit('password_set', 'user', $id);
    }
    if (!empty($in['send_welcome']) && $pass !== '') {
        $url = rtrim((string)cfg('base_url'), '/');
        queue_mail($email, $name, 'Your ' . cfg('app_name') . ' account', email_layout(
            '<p>Hi ' . htmlspecialchars($name) . ',</p><p>Your staff portal account is ready.</p>'
            . '<p>Sign in: <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url ?: 'the portal') . '</a><br>Email: <b>' . htmlspecialchars($email) . '</b><br>'
            . 'Password: <b>' . htmlspecialchars($pass) . '</b></p><p>Change your password after your first sign-in (Profile → Change password).</p>'));
    }
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

/* --------------------------------------------------------------- settings */

function act_settings_get(array $in, array $me): void
{
    require_admin();
    $smtp = setting('smtp') ?: [];
    $smtp['pass'] = !empty($smtp['pass']) ? '••••••••' : '';
    json_out(['ok' => true, 'settings' => [
        'company_name' => setting('company_name') ?: cfg('company'),
        'company_address' => setting('company_address') ?: cfg('address', ''),
        'locations' => setting('locations'),
        'expense_categories' => setting('expense_categories'),
        'credit_types' => setting('credit_types'),
        'daily_allowance_default' => (float)setting('daily_allowance_default'),
        'work_start' => work_start(),
        'work_end' => work_end(),
        'late_grace' => late_grace(),
        'work_days' => work_days(),
        'fault_types' => fault_types(),
        'require_signoff' => setting('require_signoff') !== false,
        'reminders' => reminder_settings() + ['cron_key' => cron_key(), 'mail_enabled' => mail_enabled(), 'last_run' => setting('_rem_digest')],
        'smtp' => $smtp,
    ]]);
}

function act_settings_save(array $in, array $me): void
{
    require_admin();
    $lists = ['locations', 'expense_categories', 'credit_types'];
    foreach ($lists as $k) {
        if (isset($in[$k])) {
            $vals = array_values(array_unique(array_filter(array_map(fn($x) => str_in($x, 120), (array)$in[$k]), 'strlen')));
            if (!$vals) {
                fail('Each list needs at least one item.');
            }
            save_setting($k, $vals);
        }
    }
    if (isset($in['company_name'])) {
        $name = str_in($in['company_name'], 120);
        if ($name === '') {
            fail('Enter the company name.');
        }
        save_setting('company_name', $name);
    }
    if (isset($in['company_address'])) {
        save_setting('company_address', str_in($in['company_address'], 300));
    }
    foreach (['work_start' => 'work start time', 'work_end' => 'closing time', 'reminder_time' => 'reminder time'] as $k => $label) {
        if (isset($in[$k])) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$in[$k])) {
                fail("Enter the $label as HH:MM.");
            }
            save_setting($k, $in[$k]);
        }
    }
    if (isset($in['late_grace'])) {
        save_setting('late_grace', max(0, min(120, (int)$in['late_grace'])));
    }
    if (isset($in['work_days'])) {
        $days = array_values(array_intersect([1, 2, 3, 4, 5, 6, 7], array_map('intval', (array)$in['work_days'])));
        if (!$days) {
            fail('Tick at least one working day.');
        }
        save_setting('work_days', $days);
    }
    if (isset($in['stale_days'])) {
        save_setting('stale_days', max(1, min(60, (int)$in['stale_days'])));
    }
    foreach (['reminders_enabled', 'require_signoff'] as $k) {
        if (isset($in[$k])) {
            save_setting($k, (bool)$in[$k]);
        }
    }
    if (isset($in['reminder_emails'])) {
        $emails = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(str_in($x, 190)), (array)$in['reminder_emails']), 'strlen')));
        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                fail("\"$e\" isn't a valid email address.");
            }
        }
        save_setting('reminder_emails', $emails);
    }
    if (isset($in['site_coords']) && is_array($in['site_coords'])) {
        $sites = [];
        foreach ($in['site_coords'] as $name => $ll) {
            $name = str_in($name, 120);
            if ($name === '' || !is_array($ll) || count($ll) !== 2 || !is_numeric($ll[0]) || !is_numeric($ll[1]) || abs((float)$ll[0]) > 90 || abs((float)$ll[1]) > 180) {
                fail('Each site needs a name and a valid latitude, longitude.');
            }
            $sites[$name] = [(float)$ll[0], (float)$ll[1]];
        }
        save_setting('site_coords', $sites);
    }
    if (isset($in['fault_types']) && is_array($in['fault_types'])) {
        $types = [];
        foreach ($in['fault_types'] as $device => $faults) {
            $device = str_in($device, 60);
            $faults = array_values(array_unique(array_filter(array_map(fn($x) => str_in($x, 120), (array)$faults), 'strlen')));
            if ($device !== '' && $faults) {
                $types[$device] = $faults;
            }
        }
        if (!$types) {
            fail('Add at least one device with its faults.');
        }
        save_setting('fault_types', $types);
    }
    if (isset($in['daily_allowance_default'])) {
        save_setting('daily_allowance_default', money_in($in['daily_allowance_default']));
    }
    if (isset($in['smtp']) && is_array($in['smtp'])) {
        $old = setting('smtp') ?: [];
        $s = $in['smtp'];
        save_setting('smtp', [
            'enabled' => !empty($s['enabled']),
            'host' => str_in($s['host'] ?? '', 190),
            'port' => (int)($s['port'] ?? 465),
            'secure' => in_array($s['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $s['secure'] : 'ssl',
            'user' => str_in($s['user'] ?? '', 190),
            'pass' => ($s['pass'] ?? '') === '••••••••' ? ($old['pass'] ?? '') : (string)($s['pass'] ?? ''),
            'from_email' => str_in($s['from_email'] ?? '', 190),
            'from_name' => str_in($s['from_name'] ?? '', 120),
        ]);
    }
    audit('settings_save', 'settings', null, array_keys($in));
    json_out(['ok' => true]);
}

/** Secret for calling cron.php from a URL, created on first use. */
function cron_key(): string
{
    $k = (string)setting('cron_key');
    if ($k === '') {
        $k = bin2hex(random_bytes(16));
        save_setting('cron_key', $k);
    }
    return $k;
}

function act_smtp_test(array $in, array $me): void
{
    require_admin();
    try {
        send_mail($me['email'], $me['name'], 'Test email from ' . cfg('app_name'), email_layout('<p>Email notifications are working.</p>'));
        json_out(['ok' => true, 'message' => 'Test email sent to ' . $me['email']]);
    } catch (Throwable $e) {
        fail('Email failed: ' . $e->getMessage());
    }
}
