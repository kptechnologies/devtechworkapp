<?php
declare(strict_types=1);
/* Serves attachments only to their owner or an admin. ?id=123[&thumb=1][&download=1] */
require __DIR__ . '/inc/bootstrap.php';
start_session();
ensure_schema();

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
        'attend', 'attendout' => q('SELECT user_id FROM attendance WHERE id = ?', [$a['owner_id']])->fetchColumn(),
        'tool'    => q('SELECT holder_id FROM tools WHERE id = ?', [$a['owner_id']])->fetchColumn(),
        'avatar'  => $a['owner_id'],
        // Job photos: whoever logged the job and the staff currently on it.
        'jobnote' => q('SELECT ? FROM job_updates ju JOIN jobs j ON j.id = ju.job_id WHERE ju.id = ? AND (j.created_by = ?
                        OR EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL))',
                        [$me['id'], $a['owner_id'], $me['id'], $me['id']])->fetchColumn(),
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
