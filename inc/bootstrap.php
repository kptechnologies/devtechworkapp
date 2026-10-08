<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header("Content-Type: text/html; charset=utf-8");
    exit("<div style=\"font-family:Arial,sans-serif;max-width:560px;margin:60px auto;padding:20px;border:1px solid #f0c0c0;border-radius:10px;background:#fff6f6\"><h2 style=\"margin-top:0\">PHP 8.1 or newer is required</h2><p>This server is running PHP " . PHP_VERSION . ". In cPanel, open <b>MultiPHP Manager</b> (or <b>Select PHP Version</b>), choose PHP 8.1 or newer for this domain, then reload this page.</p></div>");
}

define('APP_ROOT', dirname(__DIR__));

if (!is_file(APP_ROOT . '/config.php')) {
    if (PHP_SAPI !== 'cli') {
        header('Location: install.php');
    }
    exit;
}

$GLOBALS['CONFIG'] = require APP_ROOT . '/config.php';
date_default_timezone_set(cfg('timezone', 'Africa/Lagos'));

require_once __DIR__ . '/tables.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/wallet.php';
require __DIR__ . '/notify.php';
require __DIR__ . '/uploads.php';

function cfg(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Runtime settings: values saved in the settings table override config.php. */
function setting(string $key)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) {
                $cache[$r['k']] = json_decode($r['v'], true);
            }
        } catch (Throwable $e) {
            // table may not exist yet during install
        }
    }
    return $cache[$key] ?? cfg($key);
}

function save_setting(string $key, $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('dtportal');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => is_https(),
        ]);
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user !== false && !$refresh) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $r = q('SELECT id, name, email, phone, role, location, job_title, duties, active FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
        if ($r && (int)$r['active'] === 1) {
            $r['id'] = (int)$r['id'];
            $r['duties'] = json_decode((string)$r['duties'], true) ?: [];
            $user = $r;
        }
    }
    return $user;
}

function is_admin(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && $u['role'] === 'admin';
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        fail('Sign in to continue.', 401);
    }
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if (!is_admin($u)) {
        fail('Only admins can do that.', 403);
    }
    return $u;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void
{
    json_out(['ok' => false, 'error' => $message], $code);
}

function audit(string $action, string $entity, ?int $entityId, $details = null): void
{
    $u = current_user();
    q('INSERT INTO audit_log (user_id, action, entity, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [
        $u['id'] ?? null, $action, $entity, $entityId,
        $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
}

function today(): string
{
    return date('Y-m-d');
}

function valid_date(?string $d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function money_in($v): float
{
    $n = round((float)str_replace([',', ' '], '', (string)$v), 2);
    return $n;
}

function str_in($v, int $max = 2000): string
{
    $s = trim((string)($v ?? ''));
    return mb_substr($s, 0, $max);
}

/** Upgrade the database after new code is uploaded: runs once per SCHEMA_VERSION bump. */
function ensure_schema(): void
{
    $from = (int)setting('_schema_version');
    if ($from >= SCHEMA_VERSION) {
        return;
    }
    apply_schema(db());
    if ($from < 3) {
        seed_clients();
    }
    save_setting('_schema_version', SCHEMA_VERSION);
}

/** v3: build the clients directory from the schools list, existing jobs and saved site GPS points. */
function seed_clients(): void
{
    $coords = setting('site_coords');
    $coords = is_array($coords) ? $coords : [];
    $names = array_merge(array_values(setting('locations') ?: []), q("SELECT DISTINCT client_name FROM jobs WHERE client_name <> ''")->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_unique(array_filter(array_map('trim', $names))) as $name) {
        if (preg_match('/^(office|other)\b/i', $name)) {
            continue;
        }
        [$lat, $lng] = $coords[$name] ?? [null, null];
        q('INSERT IGNORE INTO clients (name, type, lat, lng, created_at) VALUES (?, ?, ?, ?, NOW())', [mb_substr($name, 0, 190), 'school', $lat, $lng]);
    }
    q('UPDATE jobs j JOIN clients c ON c.name = j.client_name SET j.client_id = c.id WHERE j.client_id IS NULL');
}
