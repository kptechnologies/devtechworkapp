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
    $byWorkType = q("SELECT work_type AS label, COUNT(*) AS value FROM reports WHERE report_date BETWEEN ? AND ? GROUP BY work_type ORDER BY value DESC", [$from, $to])->fetchAll();
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
        queue_mail($email, $name, 'Your ' . cfg('app_name') . ' account',
            '<div style="font-family:Arial,sans-serif;font-size:14px"><p>Hi ' . htmlspecialchars($name) . ',</p><p>Your staff portal account is ready.</p>'
            . '<p>Sign in: <a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($url ?: 'the portal') . '</a><br>Email: <b>' . htmlspecialchars($email) . '</b><br>'
            . 'Password: <b>' . htmlspecialchars($pass) . '</b></p><p>Change your password after your first sign-in (Profile → Change password).</p></div>');
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
        'locations' => setting('locations'),
        'expense_categories' => setting('expense_categories'),
        'credit_types' => setting('credit_types'),
        'daily_allowance_default' => (float)setting('daily_allowance_default'),
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

function act_smtp_test(array $in, array $me): void
{
    require_admin();
    try {
        send_mail($me['email'], $me['name'], 'Test email from ' . cfg('app_name'), '<p>Email notifications are working.</p>');
        json_out(['ok' => true, 'message' => 'Test email sent to ' . $me['email']]);
    } catch (Throwable $e) {
        fail('Email failed: ' . $e->getMessage());
    }
}
