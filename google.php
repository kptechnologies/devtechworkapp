<?php
declare(strict_types=1);
/*
 * Google Tasks sign-in. Profile → "Link Google Tasks" opens google.php?start=1, which sends the staff member to
 * Google; Google sends them back here with a code, which is swapped for a refresh token and stored.
 */
require __DIR__ . '/inc/bootstrap.php';

start_session();
ensure_schema();
header('Cache-Control: no-store');

$back = function (string $result) {
    header('Location: ./#/profile?google=' . $result);
    exit;
};

$me = current_user();
if (!$me) {
    header('Location: ./');
    exit;
}
if (!google_ready()) {
    $back('off');
}
$state = hash_hmac('sha256', 'google-link:' . $me['id'], $_SESSION['csrf']);

if (isset($_GET['start'])) {
    $g = google_cfg();
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $g['client_id'],
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => GOOGLE_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
        'login_hint' => $me['email'],
        'state' => $state,
    ]));
    exit;
}

if (!hash_equals($state, (string)($_GET['state'] ?? ''))) {
    $back('failed');
}
if (!empty($_GET['error']) || empty($_GET['code'])) {
    $back('cancelled');
}

try {
    $r = google_token_request(['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => google_redirect_uri()]);
    if (empty($r['refresh_token'])) {
        throw new RuntimeException('Google did not give a refresh token.');
    }
    if (!str_contains((string)($r['scope'] ?? ''), 'auth/tasks')) {
        $back('noscope');
    }
    // The ID token comes straight from Google's token endpoint over TLS, so its email claim can be read without re-verifying.
    $email = '';
    if (!empty($r['id_token'])) {
        $claims = json_decode((string)base64_decode(strtr(explode('.', $r['id_token'])[1] ?? '', '-_', '+/')), true);
        $email = (string)($claims['email'] ?? '');
    }
    $list = gtasks_make_list($r['access_token']);
    q('REPLACE INTO google_links (user_id, email, refresh_token, access_token, access_expires, tasklist_id, last_error, linked_at) VALUES (?,?,?,?,?,?,\'\',NOW())',
        [$me['id'], mb_substr($email, 0, 190), seal($r['refresh_token']), seal($r['access_token']), time() + (int)($r['expires_in'] ?? 3600), $list]);
    audit('google_link', 'user', $me['id'], ['email' => $email]);
} catch (Throwable $e) {
    error_log('Google link failed: ' . $e->getMessage());
    $back('failed');
}
$back('linked');
