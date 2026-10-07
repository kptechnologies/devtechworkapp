<?php
/**
 * In-app notifications + optional email.
 */

function notify(array $userIds, string $type, string $title, string $body = '', string $link = '', bool $email = true): void
{
    $userIds = array_unique(array_filter(array_map('intval', $userIds)));
    if (!$userIds) {
        return;
    }
    foreach ($userIds as $uid) {
        q('INSERT INTO notifications (user_id, type, title, body, link, created_at) VALUES (?,?,?,?,?,NOW())', [$uid, $type, $title, $body, $link]);
    }
    if ($email && !empty(setting('smtp')['enabled'])) {
        $in = implode(',', array_fill(0, count($userIds), '?'));
        foreach (q("SELECT name, email FROM users WHERE active = 1 AND id IN ($in)", array_values($userIds))->fetchAll() as $u) {
            $url = rtrim((string)cfg('base_url'), '/') . '/' . ($link ? '#' . ltrim($link, '#') : '');
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
                . '<p>Hi ' . htmlspecialchars($u['name']) . ',</p>'
                . '<p style="font-size:16px"><b>' . htmlspecialchars($title) . '</b></p>'
                . ($body ? '<p>' . nl2br(htmlspecialchars($body)) . '</p>' : '')
                . (cfg('base_url') ? '<p><a href="' . htmlspecialchars($url) . '" style="background:#534AB7;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none">Open portal</a></p>' : '')
                . '<p style="color:#888;font-size:12px">' . htmlspecialchars((string)cfg('company')) . '</p></div>';
            queue_mail($u['email'], $u['name'], $title, $html);
        }
    }
}

/** Emails are sent after the HTTP response is flushed so the UI stays fast. */
function queue_mail(string $to, string $name, string $subject, string $html): void
{
    static $registered = false;
    $GLOBALS['MAIL_QUEUE'][] = [$to, $name, $subject, $html];
    if (!$registered) {
        $registered = true;
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            foreach ($GLOBALS['MAIL_QUEUE'] ?? [] as [$to, $name, $subject, $html]) {
                try {
                    send_mail($to, $name, $subject, $html);
                } catch (Throwable $e) {
                    error_log('Mail failed: ' . $e->getMessage());
                }
            }
        });
    }
}

function notify_admins(string $type, string $title, string $body = '', string $link = '', bool $email = true): void
{
    $ids = q("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
    notify($ids, $type, $title, $body, $link, $email);
}

function send_mail(string $to, string $toName, string $subject, string $html): bool
{
    $s = setting('smtp');
    $fromEmail = $s['from_email'] ?: ($s['user'] ?? '');
    $fromName = $s['from_name'] ?: (string)cfg('app_name');
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$fromEmail>",
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . (explode('@', $fromEmail)[1] ?? 'localhost') . '>',
    ];
    $body = chunk_split(base64_encode($html));

    if (empty($s['host'])) {
        return mail($to, $encSubject, $body, implode("\r\n", $headers));
    }
    return smtp_send($s, $fromEmail, $to, array_merge($headers, [
        'To: =?UTF-8?B?' . base64_encode($toName) . "?= <$to>",
        "Subject: $encSubject",
    ]), $body);
}

/** Minimal SMTP client: SSL or STARTTLS, AUTH LOGIN. */
function smtp_send(array $s, string $from, string $to, array $headers, string $body): bool
{
    $secure = $s['secure'] ?? 'ssl';
    $host = ($secure === 'ssl' ? 'ssl://' : '') . $s['host'];
    $fp = @stream_socket_client($host . ':' . (int)$s['port'], $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: $errstr");
    }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP error after "' . explode(' ', $c)[0] . '": ' . trim($r));
        }
        return $r;
    };
    $read();
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS failed');
        }
        $cmd($ehlo, [250]);
    }
    if (!empty($s['user'])) {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($s['user']), [334]);
        $cmd(base64_encode($s['pass']), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);
    $cmd(implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", [250]);
    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}
