<?php
/**
 * Google Tasks: a staff member links their Google account once (google.php), and notices and new job orders
 * are added to a "DevTech Portal" list in their Google Tasks, which reminds them on their phone.
 * The admin sets the OAuth client in Settings. Refresh tokens are stored encrypted.
 */

const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/tasks openid email';

function google_cfg(): array
{
    $g = setting('google');
    return is_array($g) ? $g : [];
}

function google_ready(): bool
{
    $g = google_cfg();
    return !empty($g['client_id']) && !empty($g['client_secret']);
}

/** Where Google sends people back to: paste this into Google Cloud Console. */
function google_redirect_uri(): string
{
    $base = rtrim((string)cfg('base_url'), '/');
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
        $base = (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    }
    return $base . '/google.php';
}

/** Key for tokens at rest, made on first use. */
function app_key(): string
{
    $k = (string)setting('app_key');
    if ($k === '') {
        $k = bin2hex(random_bytes(32));
        save_setting('app_key', $k);
    }
    return hex2bin($k);
}

function seal(string $plain): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function unseal(string $sealed): string
{
    $raw = (string)base64_decode($sealed);
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? '' : $out;
}

/** JSON request to Google. Returns [status, decoded body]. */
function google_http(string $method, string $url, ?array $body = null, string $token = '', bool $form = false): array
{
    $ch = curl_init($url);
    $headers = $token ? ['Authorization: Bearer ' . $token] : [];
    if ($body !== null) {
        $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('Could not reach Google: ' . $err);
    }
    return [$code, json_decode((string)$res, true) ?: []];
}

function google_token_request(array $params): array
{
    $g = google_cfg();
    [$code, $r] = google_http('POST', 'https://oauth2.googleapis.com/token',
        $params + ['client_id' => $g['client_id'] ?? '', 'client_secret' => $g['client_secret'] ?? ''], '', true);
    if ($code !== 200 || empty($r['access_token'])) {
        throw new RuntimeException($r['error'] ?? 'token_failed');
    }
    return $r;
}

/** A working access token for a linked user, refreshed when it has run out. Null when not linked. */
function gtasks_token(int $uid): ?string
{
    $l = q('SELECT * FROM google_links WHERE user_id = ?', [$uid])->fetch();
    if (!$l) {
        return null;
    }
    if ($l['access_token'] && (int)$l['access_expires'] > time() + 60) {
        return unseal($l['access_token']);
    }
    try {
        $r = google_token_request(['grant_type' => 'refresh_token', 'refresh_token' => unseal($l['refresh_token'])]);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'invalid_grant' && $l['last_error'] === '') {
            // Access was removed from the Google account: tell them once.
            notify([$uid], 'google', 'Google Tasks is no longer linked', 'Link it again from your profile to keep getting tasks.', 'profile', false, ['push' => false]);
        }
        q('UPDATE google_links SET last_error = ? WHERE user_id = ?', [mb_substr($e->getMessage(), 0, 300), $uid]);
        return null;
    }
    q('UPDATE google_links SET access_token = ?, access_expires = ?, last_error = \'\' WHERE user_id = ?',
        [seal($r['access_token']), time() + (int)($r['expires_in'] ?? 3600), $uid]);
    return $r['access_token'];
}

/** Add one task to a user's "DevTech Portal" list. $due is Y-m-d or ''. Google keeps only the date. */
function gtasks_add(int $uid, string $title, string $notes = '', string $due = ''): bool
{
    $token = gtasks_token($uid);
    if (!$token) {
        return false;
    }
    $list = (string)q('SELECT tasklist_id FROM google_links WHERE user_id = ?', [$uid])->fetchColumn();
    $task = ['title' => mb_substr($title, 0, 1000), 'notes' => mb_substr($notes, 0, 8000)];
    if (valid_date($due)) {
        $task['due'] = $due . 'T00:00:00.000Z';
    }
    [$code, $r] = google_http('POST', 'https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($list) . '/tasks', $task, $token);
    if ($code === 404) {
        // The list was deleted in Google Tasks: make it again and retry once.
        $list = gtasks_make_list($token);
        q('UPDATE google_links SET tasklist_id = ? WHERE user_id = ?', [$list, $uid]);
        [$code, $r] = google_http('POST', 'https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($list) . '/tasks', $task, $token);
    }
    if ($code !== 200) {
        error_log("Google Tasks add failed ($code): " . json_encode($r));
        return false;
    }
    return true;
}

function gtasks_make_list(string $token): string
{
    $name = (string)(setting('company_name') ?: cfg('app_name') ?: 'Staff portal');
    [, $lists] = google_http('GET', 'https://tasks.googleapis.com/tasks/v1/users/@me/lists?maxResults=100', null, $token);
    foreach ($lists['items'] ?? [] as $l) {
        if (($l['title'] ?? '') === $name) {
            return $l['id'];
        }
    }
    [$code, $r] = google_http('POST', 'https://tasks.googleapis.com/tasks/v1/users/@me/lists', ['title' => $name], $token);
    if ($code !== 200 || empty($r['id'])) {
        throw new RuntimeException('Could not create the task list in Google Tasks.');
    }
    return $r['id'];
}

/** Send a task to every linked user in the list. */
function gtasks_to_users(array $userIds, string $title, string $notes, string $due, string $link): void
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds || !google_ready()) {
        return;
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $linked = q("SELECT user_id FROM google_links WHERE last_error = '' AND user_id IN ($in)", $userIds)->fetchAll(PDO::FETCH_COLUMN);
    $url = rtrim((string)cfg('base_url'), '/');
    $notes = trim($notes . ($url && $link ? "\n\n$url/#/" . ltrim($link, '#/') : ''));
    foreach ($linked as $uid) {
        try {
            gtasks_add((int)$uid, $title, $notes, $due);
        } catch (Throwable $e) {
            error_log('Google Tasks failed: ' . $e->getMessage());
        }
    }
}

/* ------------------------------------------------------------------ API */

/** What the profile card shows: phone alert devices and the Google link. */
function act_alerts_status(array $in, array $me): void
{
    $l = q('SELECT email, last_error, linked_at FROM google_links WHERE user_id = ?', [$me['id']])->fetch();
    json_out(['ok' => true, 'google' => [
        'ready' => google_ready(),
        'linked' => (bool)$l,
        'email' => $l['email'] ?? '',
        'broken' => $l && $l['last_error'] !== '',
        'linked_at' => $l['linked_at'] ?? null,
    ]]);
}

function act_google_unlink(array $in, array $me): void
{
    $l = q('SELECT refresh_token FROM google_links WHERE user_id = ?', [$me['id']])->fetch();
    if ($l) {
        try {
            google_http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => unseal($l['refresh_token'])], '', true);
        } catch (Throwable $e) {
            // Unlink here even if Google can't be reached.
        }
        q('DELETE FROM google_links WHERE user_id = ?', [$me['id']]);
        audit('google_unlink', 'user', $me['id']);
    }
    json_out(['ok' => true]);
}

function act_google_test(array $in, array $me): void
{
    if (!gtasks_add($me['id'], 'Test task from the staff portal', 'Google Tasks is linked. Notices and new job orders will show up in this list.', today())) {
        fail('The task could not be added. Unlink and link Google Tasks again.');
    }
    json_out(['ok' => true, 'message' => 'Test task added to your Google Tasks.']);
}
