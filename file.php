<?php
declare(strict_types=1);
/* Serves attachments only to their owner or an admin. ?id=123[&thumb=1][&download=1] */
require __DIR__ . '/inc/bootstrap.php';
start_session();

$me = current_user();
if (!$me) {
    http_response_code(401);
    exit('Sign in first.');
}
$a = q('SELECT * FROM attachments WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$a) {
    http_response_code(404);
    exit('Not found.');
}
if (!is_admin($me)) {
    $owner = match ($a['owner_type']) {
        'report'  => q('SELECT user_id FROM reports WHERE id = ?', [$a['owner_id']])->fetchColumn(),
        'txn'     => q('SELECT user_id FROM wallet_txns WHERE id = ?', [$a['owner_id']])->fetchColumn(),
        'request' => q('SELECT user_id FROM fund_requests WHERE id = ?', [$a['owner_id']])->fetchColumn(),
        default   => null,
    };
    if ((int)$owner !== $me['id']) {
        http_response_code(403);
        exit('You don\'t have access to this file.');
    }
}

$thumb = !empty($_GET['thumb']) && $a['thumb_name'];
$rel = $thumb ? $a['thumb_name'] : $a['stored_name'];
$path = realpath(APP_ROOT . '/uploads/' . $rel);
$base = realpath(APP_ROOT . '/uploads');
if (!$path || !$base || !str_starts_with($path, $base) || !is_file($path)) {
    http_response_code(404);
    exit('File missing.');
}
$mime = $thumb ? 'image/jpeg' : $a['mime'];
if (!isset(UPLOAD_TYPES[$mime])) {
    $mime = 'application/octet-stream';
}
$name = preg_replace('/[^\w.\- ]+/u', '_', $a['original_name']);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
readfile($path);
