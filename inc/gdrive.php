<?php
/**
 * Google Drive for Sheets & docs: the admin connects one company Google account (google.php?drive=1) and every Google
 * Sheet or Doc staff make from the portal is created in that account's Drive, under "<company> Portal/Jobs/<job>" or
 * "<company> Portal/General/<staff>". The account owns every file; staff are given access to the files they need.
 * Scope is drive.file, so the portal can only see files it made itself. Uses the OAuth client set in Settings.
 */

const GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file openid email';
const GDRIVE_MIME = ['sheet' => 'application/vnd.google-apps.spreadsheet', 'doc' => 'application/vnd.google-apps.document'];
const GDRIVE_FOLDER = 'application/vnd.google-apps.folder';

function gdrive_cfg(): array
{
    $g = setting('gdrive');
    return is_array($g) ? $g : [];
}

/** Connected and usable. */
function gdrive_ready(): bool
{
    return google_ready() && !empty(gdrive_cfg()['refresh_token']);
}

/** 'people' shares each file with the staff member's Google email; 'link' lets anyone with the link edit. */
function gdrive_share_mode(): string
{
    return (gdrive_cfg()['share'] ?? 'people') === 'link' ? 'link' : 'people';
}

/** A working access token for the company account, refreshed when it has run out. */
function gdrive_token(): string
{
    $g = gdrive_cfg();
    if (empty($g['refresh_token'])) {
        fail('Google Drive isn\'t connected. Ask an admin to connect it in Settings.', 409);
    }
    if (!empty($g['access_token']) && (int)($g['access_expires'] ?? 0) > time() + 60) {
        return unseal($g['access_token']);
    }
    try {
        $r = google_token_request(['grant_type' => 'refresh_token', 'refresh_token' => unseal($g['refresh_token'])]);
    } catch (RuntimeException $e) {
        $g['last_error'] = $e->getMessage();
        save_setting('gdrive', $g);
        if ($e->getMessage() === 'invalid_grant') {
            fail('The company Google account was disconnected. Ask an admin to connect Google Drive again in Settings.', 409);
        }
        fail('Couldn\'t sign in to Google Drive. Try again in a minute.', 502);
    }
    $g['access_token'] = seal($r['access_token']);
    $g['access_expires'] = time() + (int)($r['expires_in'] ?? 3600);
    $g['last_error'] = '';
    save_setting('gdrive', $g);
    return $r['access_token'];
}

/** A Drive API call that must succeed. Returns the decoded body. */
function gd_req(string $method, string $url, ?array $body = null): array
{
    [$code, $r] = google_http($method, $url, $body, gdrive_token());
    if ($code < 200 || $code >= 300) {
        error_log("Google Drive $method $url failed ($code): " . json_encode($r));
        $msg = $r['error']['message'] ?? 'unknown error';
        throw new RuntimeException("Google Drive said: $msg");
    }
    return $r;
}

function gd_q(string $s): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
}

/** The id of folder $name inside $parent (or Drive root), made if missing. Folder ids are cached in settings. */
function gd_folder(string $name, string $parent = 'root'): string
{
    $name = trim(preg_replace('/[\\/\x00-\x1f]+/', ' ', $name)) ?: 'Untitled';
    $cache = setting('gdrive_folders');
    $cache = is_array($cache) ? $cache : [];
    $key = $parent . '/' . $name;
    if (!empty($cache[$key])) {
        return $cache[$key];
    }
    $q = "name = '" . gd_q($name) . "' and mimeType = '" . GDRIVE_FOLDER . "' and '" . gd_q($parent) . "' in parents and trashed = false";
    $r = gd_req('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]));
    $id = $r['files'][0]['id'] ?? '';
    if ($id === '') {
        $r = gd_req('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', ['name' => $name, 'mimeType' => GDRIVE_FOLDER, 'parents' => [$parent]]);
        $id = (string)$r['id'];
    }
    $cache[$key] = $id;
    save_setting('gdrive_folders', $cache);
    return $id;
}

/** The portal's own top folder in the company Drive. */
function gd_root(): string
{
    $name = (string)(setting('company_name') ?: cfg('app_name') ?: 'Staff') . ' Portal';
    return gd_folder($name);
}

/** Where a new file goes: the job's folder, the staff member's general folder, or Templates. */
function gd_folder_for(?array $job, array $owner, bool $template = false): string
{
    $root = gd_root();
    if ($template) {
        return gd_folder('Templates', $root);
    }
    if ($job) {
        return gd_folder($job['ref'] . ' - ' . mb_substr($job['title'], 0, 80), gd_folder('Jobs', $root));
    }
    return gd_folder($owner['name'], gd_folder('General', $root));
}

/** [id, webViewLink] of a new blank Google Sheet ('sheet') or Doc ('doc'). */
function gd_create(string $kind, string $title, string $folderId): array
{
    $r = gd_req('POST', 'https://www.googleapis.com/drive/v3/files?fields=id,webViewLink',
        ['name' => $title, 'mimeType' => GDRIVE_MIME[$kind], 'parents' => [$folderId]]);
    return [(string)$r['id'], (string)($r['webViewLink'] ?? '')];
}

function gd_copy(string $fileId, string $title, string $folderId): array
{
    $r = gd_req('POST', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '/copy?fields=id,webViewLink',
        ['name' => $title, 'parents' => [$folderId]]);
    return [(string)$r['id'], (string)($r['webViewLink'] ?? '')];
}

/** Upload a file (an .xlsx or HTML) and let Google convert it into a Sheet or Doc. */
function gd_upload(string $kind, string $title, string $folderId, string $bytes, string $srcMime): array
{
    $boundary = 'b' . bin2hex(random_bytes(12));
    $meta = json_encode(['name' => $title, 'mimeType' => GDRIVE_MIME[$kind], 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
        . "--$boundary\r\nContent-Type: $srcMime\r\n\r\n$bytes\r\n--$boundary--";
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . gdrive_token(), "Content-Type: multipart/related; boundary=$boundary"]]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $r = json_decode((string)$res, true) ?: [];
    if ($code !== 200 || empty($r['id'])) {
        error_log("Google Drive upload failed ($code): " . $res);
        throw new RuntimeException('Google Drive said: ' . ($r['error']['message'] ?? 'the upload failed'));
    }
    return [(string)$r['id'], (string)($r['webViewLink'] ?? '')];
}

/** Give one Google account access, or anyone with the link when sharing by link. Never throws. */
function gd_share(string $fileId, string $email, string $role = 'writer'): bool
{
    $base = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '/permissions';
    try {
        if (gdrive_share_mode() === 'link') {
            gd_req('POST', $base, ['type' => 'anyone', 'role' => 'writer', 'allowFileDiscovery' => false]);
            return true;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strcasecmp($email, (string)(gdrive_cfg()['email'] ?? '')) === 0) {
            return false;
        }
        // Google refuses to share silently with addresses that aren't Google accounts, so retry with an email invite.
        [$code] = google_http('POST', $base . '?sendNotificationEmail=false', ['type' => 'user', 'role' => $role, 'emailAddress' => $email], gdrive_token());
        if ($code >= 200 && $code < 300) {
            return true;
        }
        gd_req('POST', $base . '?sendNotificationEmail=true', ['type' => 'user', 'role' => $role, 'emailAddress' => $email]);
        return true;
    } catch (Throwable $e) {
        error_log('Google Drive share failed: ' . $e->getMessage());
        return false;
    }
}

function gd_trash(string $fileId): void
{
    try {
        gd_req('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId), ['trashed' => true]);
    } catch (Throwable $e) {
        error_log('Google Drive trash failed: ' . $e->getMessage());
    }
}

/** The address a user edits Google files with. */
function google_email_of(array $u): string
{
    return trim((string)($u['google_email'] ?? '')) ?: (string)$u['email'];
}

/* ------------------------------------------------------------------ API */

function act_gdrive_status(array $in, array $me): void
{
    $g = gdrive_cfg();
    json_out(['ok' => true, 'gdrive' => [
        'client_ready' => google_ready(),
        'client_id' => is_admin($me) ? (google_cfg()['client_id'] ?? '') : '',
        'redirect_uri' => is_admin($me) ? google_redirect_uri() : '',
        'connected' => gdrive_ready(),
        'email' => $g['email'] ?? '',
        'connected_at' => $g['connected_at'] ?? null,
        'broken' => !empty($g['last_error']),
        'share' => gdrive_share_mode(),
        'default_engine' => (($g['default_engine'] ?? 'builtin') === 'google') ? 'google' : 'builtin',
        'docs' => (int)q("SELECT COUNT(*) FROM work_docs WHERE engine = 'google' AND deleted_at IS NULL")->fetchColumn(),
    ]]);
}

function act_gdrive_settings(array $in, array $me): void
{
    require_admin();
    $g = gdrive_cfg();
    $g['share'] = ($in['share'] ?? '') === 'link' ? 'link' : 'people';
    $g['default_engine'] = ($in['default_engine'] ?? '') === 'google' ? 'google' : 'builtin';
    save_setting('gdrive', $g);
    audit('gdrive_settings', 'settings', 0, ['share' => $g['share'], 'default_engine' => $g['default_engine']]);
    json_out(['ok' => true]);
}

/** Disconnects the account. Files stay in that Drive; the portal just can't make new ones until it's connected again. */
function act_gdrive_disconnect(array $in, array $me): void
{
    require_admin();
    $g = gdrive_cfg();
    if (!empty($g['refresh_token'])) {
        try {
            google_http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => unseal($g['refresh_token'])], '', true);
        } catch (Throwable $e) {
            // Disconnect here even if Google can't be reached.
        }
    }
    save_setting('gdrive', ['share' => $g['share'] ?? 'people', 'default_engine' => 'builtin']);
    save_setting('gdrive_folders', []);
    audit('gdrive_disconnect', 'settings', 0, ['email' => $g['email'] ?? '']);
    json_out(['ok' => true]);
}
