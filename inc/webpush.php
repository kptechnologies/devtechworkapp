<?php
/**
 * Web push to staff phones and browsers (RFC 8030 / 8291 / 8292), with no libraries.
 * The VAPID key pair is made once and kept in settings. Each phone that turns alerts on saves a row in push_subscriptions.
 */

/** Push services browsers use. Subscriptions pointing anywhere else are refused. */
const PUSH_HOSTS = ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

function b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** OpenSSL options for P-256 keys. Windows builds (XAMPP) need openssl.cnf pointed at explicitly. */
function ec_opts(): array
{
    static $opts = null;
    if ($opts !== null) {
        return $opts;
    }
    $base = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if (@openssl_pkey_new($base)) {
        return $opts = $base;
    }
    $dir = dirname(PHP_BINARY);
    $apache = dirname(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''))) . '/apache/conf/openssl.cnf';
    foreach ([getenv('OPENSSL_CONF') ?: '', "$dir/extras/ssl/openssl.cnf", dirname($dir) . '/php/extras/ssl/openssl.cnf', dirname($dir) . '/apache/conf/openssl.cnf', dirname($dir) . '/conf/openssl.cnf', $apache] as $cnf) {
        if ($cnf !== '' && is_file($cnf) && @openssl_pkey_new($base + ['config' => $cnf])) {
            return $opts = $base + ['config' => $cnf];
        }
    }
    throw new RuntimeException('This server cannot make encryption keys (OpenSSL EC support is missing).');
}

function ec_key_new()
{
    return openssl_pkey_new(ec_opts());
}

/** Raw uncompressed public point: 0x04 || X || Y. */
function ec_public_raw($key): string
{
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** An OpenSSL key from a raw 65-byte P-256 public point. */
function ec_public_from_raw(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $k = openssl_pkey_get_public($pem);
    if (!$k) {
        throw new RuntimeException('Bad push key.');
    }
    return $k;
}

/** The server's VAPID keys: ['private' => PEM, 'public' => base64url point]. Made on first use. */
function vapid_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $saved = setting('vapid');
    if (is_array($saved) && !empty($saved['private']) && !empty($saved['public'])) {
        return $keys = $saved;
    }
    $k = ec_key_new();
    if (!openssl_pkey_export($k, $pem, null, ec_opts())) {
        throw new RuntimeException('Could not save the alert keys.');
    }
    $keys = ['private' => $pem, 'public' => b64u_enc(ec_public_raw($k))];
    save_setting('vapid', $keys);
    return $keys;
}

/** ES256 signature in JOSE form (r || s, 64 bytes) from OpenSSL's DER. */
function der_to_jose(string $der): string
{
    $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $p = parse_url($endpoint);
    $sub = (string)(setting('smtp')['from_email'] ?? '') ?: 'admin@' . (cfg('domain') ?: 'localhost');
    $jwt = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
        . b64u_enc(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . $sub]));
    if (!openssl_sign($jwt, $der, $keys['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the push request.');
    }
    return 'vapid t=' . $jwt . '.' . b64u_enc(der_to_jose($der)) . ', k=' . $keys['public'];
}

/** Encrypt a payload for one subscription (aes128gcm, RFC 8291). */
function webpush_encrypt(string $payload, string $p256dh, string $auth): string
{
    $uaPublic = b64u_dec($p256dh);
    $authSecret = b64u_dec($auth);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
        throw new RuntimeException('Bad push subscription keys.');
    }
    $local = ec_key_new();
    $asPublic = ec_public_raw($local);
    $shared = openssl_pkey_derive(ec_public_from_raw($uaPublic), $local, 32);
    if ($shared === false) {
        throw new RuntimeException('Push key exchange failed.');
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** Send to one subscription. Returns the HTTP status (0 when the request never got out). */
function webpush_send(array $sub, array $message): int
{
    $body = webpush_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . vapid_header($sub['endpoint']),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: high',
        ],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($res === false) {
        error_log('Push failed: ' . curl_error($ch));
    } elseif ($code >= 400 && !in_array($code, [404, 410], true)) {
        error_log("Push failed ($code): " . mb_substr((string)$res, 0, 300));
    }
    curl_close($ch);
    return $code;
}

/** Push one alert to every phone these people turned alerts on for. Gone subscriptions are removed. */
function push_to_users(array $userIds, string $title, string $body = '', string $link = ''): int
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $subs = q("SELECT s.* FROM push_subscriptions s JOIN users u ON u.id = s.user_id WHERE u.active = 1 AND s.user_id IN ($in)", $userIds)->fetchAll();
    $sent = 0;
    foreach ($subs as $s) {
        try {
            $code = webpush_send($s, ['title' => $title, 'body' => mb_substr($body, 0, 240), 'link' => $link, 'tag' => $link ?: 'portal']);
            if (in_array($code, [404, 410], true)) {
                q('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]);
            } elseif ($code >= 200 && $code < 300) {
                q('UPDATE push_subscriptions SET last_ok_at = NOW() WHERE id = ?', [$s['id']]);
                $sent++;
            }
        } catch (Throwable $e) {
            error_log('Push failed: ' . $e->getMessage());
        }
    }
    return $sent;
}

function push_endpoint_ok(string $url): bool
{
    $p = parse_url($url);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        return false;
    }
    foreach (PUSH_HOSTS as $h) {
        if ($p['host'] === $h || str_ends_with($p['host'], '.' . $h)) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ API */

function act_push_key(array $in, array $me): void
{
    json_out(['ok' => true, 'key' => vapid_keys()['public'],
        'devices' => (int)q('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$me['id']])->fetchColumn()]);
}

function act_push_subscribe(array $in, array $me): void
{
    $endpoint = str_in($in['endpoint'] ?? '', 500);
    $p256dh = str_in($in['keys']['p256dh'] ?? '', 120);
    $auth = str_in($in['keys']['auth'] ?? '', 60);
    if (!push_endpoint_ok($endpoint) || $p256dh === '' || $auth === '') {
        fail('This browser gave an alert address the portal does not recognise.');
    }
    // One phone, one row: a re-subscribe (or a different person signing in on it) takes the row over.
    q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at) VALUES (?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)',
        [$me['id'], $endpoint, hash('sha256', $endpoint), $p256dh, $auth, str_in($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
    json_out(['ok' => true]);
}

function act_push_unsubscribe(array $in, array $me): void
{
    q('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$me['id'], hash('sha256', (string)($in['endpoint'] ?? ''))]);
    json_out(['ok' => true]);
}

function act_push_test(array $in, array $me): void
{
    $n = push_to_users([$me['id']], 'Phone alerts are on', 'You will get portal alerts on this phone.', 'profile');
    if (!$n) {
        fail('The alert could not be sent. Turn alerts off and on again on this phone.');
    }
    json_out(['ok' => true, 'message' => "Test alert sent to $n device" . ($n === 1 ? '' : 's') . '.']);
}
