<?php
declare(strict_types=1);
/*
 * Google sign-in, for two things:
 *  - Google Tasks: Profile → "Link Google Tasks" opens google.php?start=1, which sends the staff member to Google; Google
 *    sends them back here with a code, which is swapped for a refresh token and stored.
 *  - Google Drive: Settings → "Connect Google Drive" opens google.php?start=1&drive=1 (admin only) and links the company
 *    account that owns every Google Sheet and Doc made from the portal (inc/gdrive.php).
 * Both come back to this same address (the one redirect URI in Google Cloud); the state says which one it was.
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
$driveState = 'drive.' . hash_hmac('sha256', 'gdrive-link:' . $me['id'], $_SESSION['csrf']);
$isDrive = isset($_GET['drive']) || str_starts_with((string)($_GET['state'] ?? ''), 'drive.');

if ($isDrive) {
    require __DIR__ . '/inc/gdrive.php';
    $backDrive = function (string $result) {
        header('Location: ./#/settings?gdrive=' . $result);
        exit;
    };
    if (!is_admin($me)) {
        $backDrive('denied');
    }
    if (isset($_GET['start'])) {
        $g = google_cfg();
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $g['client_id'],
            'redirect_uri' => google_redirect_uri(),
            'response_type' => 'code',
            'scope' => GDRIVE_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $driveState,
        ]));
        exit;
    }
    if (!hash_equals($driveState, (string)($_GET['state'] ?? ''))) {
        $backDrive('failed');
    }
    if (!empty($_GET['error']) || empty($_GET['code'])) {
        $backDrive('cancelled');
    }
    try {
        $r = google_token_request(['grant_type' => 'authorization_code', 'code' => (string)$_GET['code'], 'redirect_uri' => google_redirect_uri()]);
        if (empty($r['refresh_token'])) {
            throw new RuntimeException('Google did not give a refresh token.');
        }
        if (!str_contains((string)($r['scope'] ?? ''), 'auth/drive.file')) {
            $backDrive('noscope');
        }
        $email = '';
        if (!empty($r['id_token'])) {
            $claims = json_decode((string)base64_decode(strtr(explode('.', $r['id_token'])[1] ?? '', '-_', '+/')), true);
            $email = (string)($claims['email'] ?? '');
        }
        $old = gdrive_cfg();
        // A different account means different folders.
        if (($old['email'] ?? '') !== $email) {
            save_setting('gdrive_folders', []);
        }
        save_setting('gdrive', [
            'refresh_token' => seal($r['refresh_token']),
            'access_token' => seal($r['access_token']),
            'access_expires' => time() + (int)($r['expires_in'] ?? 3600),
            'email' => mb_substr($email, 0, 190),
            'connected_at' => date('Y-m-d H:i:s'),
            'connected_by' => $me['id'],
            'share' => $old['share'] ?? 'people',
            'default_engine' => $old['default_engine'] ?? 'builtin',
            'last_error' => '',
        ]);
        gd_root();
        audit('gdrive_connect', 'settings', 0, ['email' => $email]);
    } catch (Throwable $e) {
        error_log('Google Drive connect failed: ' . $e->getMessage());
        $backDrive('failed');
    }
    $backDrive('linked');
}

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
