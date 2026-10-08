<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/api_reports.php';
require __DIR__ . '/inc/api_wallet.php';
require __DIR__ . '/inc/api_admin.php';
require __DIR__ . '/inc/api_jobs.php';
require __DIR__ . '/inc/api_people.php';
require __DIR__ . '/inc/api_clients.php';
require __DIR__ . '/inc/reminders.php';
require __DIR__ . '/inc/import.php';

start_session();
ensure_schema();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$action = preg_replace('/[^a-z_]/', '', (string)($_GET['action'] ?? ''));
$IN = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        fail('Your session expired. Reload the page and try again.', 419);
    }
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $IN = json_decode((string)file_get_contents('php://input'), true) ?: [];
    } elseif (isset($_POST['payload'])) {
        $IN = json_decode((string)$_POST['payload'], true) ?: [];
    } else {
        $IN = $_POST;
    }
} else {
    $IN = $_GET;
}

$public = ['session', 'login'];
$getAllowed = ['session', 'pulse', 'home', 'notifications', 'reports_list', 'report_get', 'issues', 'wallet', 'txn_get',
    'ledger', 'balances', 'requests_list', 'dashboard', 'users_list', 'settings_get', 'reports_export', 'ledger_export',
    'jobs_list', 'job_get', 'jobs_export', 'staff_profile', 'tools_list', 'tool_get', 'attendance_today', 'attendance_list', 'attendance_export',
    'attendance_report', 'attendance_report_export', 'clients_list', 'client_get'];

$fn = 'act_' . $action;
if ($action === '' || !function_exists($fn)) {
    fail('Unknown action.', 404);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, $getAllowed, true)) {
    fail('Use POST for this action.', 405);
}

try {
    $ME = in_array($action, $public, true) ? current_user() : require_user();
    $fn($IN, $ME);
} catch (Throwable $e) {
    error_log('[portal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail(cfg('debug') ? $e->getMessage() : 'Something went wrong on the server. Try again.', 500);
}

/* ---------------------------------------------------------------- auth */

function brand_config(): array
{
    return [
        'app_name' => cfg('app_name'),
        'company'  => setting('company_name') ?: cfg('company'),
        'address'  => setting('company_address') ?: cfg('address', ''),
        'domain'   => cfg('domain', ''),
    ];
}

function public_config(array $me): array
{
    return brand_config() + [
        'currency'      => cfg('currency', '₦'),
        'today'         => today(),
        'poll'          => (int)cfg('poll_seconds', 10),
        'locations'     => location_options(),
        'categories'    => array_values(setting('expense_categories') ?: []),
        'credit_types'  => array_values(setting('credit_types') ?: []),
        'allowance'     => (float)(setting('daily_allowance_default') ?: 0),
        'max_files'     => (int)cfg('upload_max_files', 6),
        'max_mb'        => (int)cfg('upload_max_mb', 8),
        'schema'        => report_schema_resolved(),
        'duties'        => DUTIES,
        'fault_types'   => fault_types(),
        'work_start'    => work_start(),
        'work_end'      => work_end(),
        'late_grace'    => late_grace(),
        'clients'       => client_names(),
    ];
}

function act_session(array $in, ?array $me): void
{
    json_out([
        'ok'     => true,
        'csrf'   => $_SESSION['csrf'],
        'user'   => $me,
        'config' => $me ? public_config($me) : brand_config(),
    ]);
}

function act_login(array $in, ?array $me): void
{
    $email = strtolower(str_in($in['email'] ?? '', 190));
    $pass = (string)($in['password'] ?? '');
    $u = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    $generic = 'That email and password don\'t match.';
    if (!$u) {
        password_verify($pass, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // even out timing
        fail($generic, 401);
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        fail('Too many attempts. Try again in a few minutes.', 429);
    }
    if (!(int)$u['active']) {
        fail('This account is deactivated. Contact your admin.', 403);
    }
    if (!$u['password_hash']) {
        fail('Your account has no password yet. Ask your admin to set one.', 403);
    }
    if (!password_verify($pass, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        q('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [
            $fails >= 5 ? 0 : $fails, $fails >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id'],
        ]);
        fail($generic, 401);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE id = ?', [$u['id']]);
    act_session([], current_user(true));
}

function act_logout(array $in, array $me): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

function act_password_change(array $in, array $me): void
{
    $u = q('SELECT password_hash FROM users WHERE id = ?', [$me['id']])->fetch();
    if (!password_verify((string)($in['current'] ?? ''), (string)$u['password_hash'])) {
        fail('Your current password is wrong.');
    }
    $new = (string)($in['new'] ?? '');
    if (strlen($new) < 8) {
        fail('Use at least 8 characters.');
    }
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
    audit('password_change', 'user', $me['id']);
    json_out(['ok' => true]);
}

function act_profile_save(array $in, array $me): void
{
    q('UPDATE users SET phone = ?, location = ? WHERE id = ?', [str_in($in['phone'] ?? '', 40), str_in($in['location'] ?? '', 120), $me['id']]);
    json_out(['ok' => true, 'user' => current_user(true)]);
}

/* ------------------------------------------------- live pulse + notifications */

function act_pulse(array $in, array $me): void
{
    $out = [
        'ok'      => true,
        'change'  => (float)(json_decode((string)(q("SELECT v FROM settings WHERE k = '_last_change'")->fetchColumn() ?: '0'), true) ?: 0),
        'unread'  => (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$me['id']])->fetchColumn(),
        'wallet'  => wallet_stats($me['id']),
    ] + job_badges($me);
    if (is_admin($me)) {
        $out['pending_reviews'] = (int)q("SELECT COUNT(*) FROM wallet_txns WHERE kind='debit' AND status='posted'")->fetchColumn();
        $out['pending_requests'] = (int)q("SELECT COUNT(*) FROM fund_requests WHERE status='pending'")->fetchColumn();
    }
    maybe_run_reminders(); // fallback for hosts without a cron job
    json_out($out);
}

function act_notifications(array $in, array $me): void
{
    $rows = q('SELECT id, type, title, body, link, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 40', [$me['id']])->fetchAll();
    json_out(['ok' => true, 'items' => $rows]);
}

function act_notifications_read(array $in, array $me): void
{
    q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$me['id']]);
    json_out(['ok' => true]);
}

/* -------------------------------------------------------------- attachments */

function act_attachment_delete(array $in, array $me): void
{
    $a = q('SELECT * FROM attachments WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$a) {
        fail('File not found.', 404);
    }
    if (!is_admin($me)) {
        if ((int)$a['user_id'] !== $me['id']) {
            fail('You can only remove your own files.', 403);
        }
        if ($a['owner_type'] === 'txn') {
            $st = q('SELECT status FROM wallet_txns WHERE id = ?', [$a['owner_id']])->fetchColumn();
            if (!in_array($st, ['posted', 'queried'], true)) {
                fail('This expense has been reviewed, so its receipts are locked.', 403);
            }
        }
        if (in_array($a['owner_type'], ['attend', 'attendout'], true)) {
            fail('Clock-in photos can\'t be removed.', 403);
        }
        if ($a['owner_type'] === 'jobnote') {
            $st = q('SELECT j.status FROM job_updates ju JOIN jobs j ON j.id = ju.job_id WHERE ju.id = ?', [$a['owner_id']])->fetchColumn();
            if (in_array($st, ['awaiting_check', 'done'], true)) {
                fail('This job has been sent for checking, so its photos are locked.', 403);
            }
        }
    }
    delete_attachment_files($a);
    q('DELETE FROM attachments WHERE id = ?', [$a['id']]);
    audit('attachment_delete', $a['owner_type'], (int)$a['owner_id'], ['file' => $a['original_name']]);
    json_out(['ok' => true]);
}
