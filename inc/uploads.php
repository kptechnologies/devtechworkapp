<?php
/**
 * Attachments: receipts and work photos for reports, expenses, requests and payments.
 * Files live in uploads/YYYY/MM/ (web access denied by .htaccess) and are served by file.php.
 */

const UPLOAD_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];

/** Normalise $_FILES[$field] (single or multiple) into a list. */
function uploaded_files(string $field = 'files'): array
{
    if (empty($_FILES[$field])) {
        return [];
    }
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $list = [];
    foreach ($f['name'] as $i => $name) {
        $list[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $list;
}

/** Validate every file first; returns an error message or null. */
function check_uploads(string $field = 'files', int $existing = 0): ?string
{
    $files = uploaded_files($field);
    $max = (int)cfg('upload_max_files', 6);
    if (count($files) + $existing > $max) {
        return "You can attach up to $max files.";
    }
    $maxBytes = (int)cfg('upload_max_mb', 8) * 1024 * 1024;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return 'Upload failed for ' . $f['name'] . ' (error ' . $f['error'] . ').';
        }
        if ($f['size'] > $maxBytes) {
            return $f['name'] . ' is larger than ' . cfg('upload_max_mb', 8) . 'MB.';
        }
        $mime = $finfo->file($f['tmp_name']);
        if (!isset(UPLOAD_TYPES[$mime])) {
            return $f['name'] . ' isn\'t a supported file. Use JPG, PNG, WEBP, GIF or PDF.';
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($f['tmp_name']) === false) {
            return $f['name'] . ' isn\'t a valid image.';
        }
    }
    return null;
}

function save_uploads(string $ownerType, int $ownerId, int $userId, string $field = 'files'): array
{
    $saved = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $sub = date('Y/m');
    $dir = APP_ROOT . '/uploads/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create upload folder.');
    }
    foreach (uploaded_files($field) as $f) {
        $mime = $finfo->file($f['tmp_name']);
        $ext = UPLOAD_TYPES[$mime] ?? null;
        if (!$ext) {
            continue;
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
            continue;
        }
        $thumb = make_thumb("$dir/$name", $mime) ? "$sub/t_$name.jpg" : null;
        q('INSERT INTO attachments (owner_type, owner_id, user_id, stored_name, thumb_name, original_name, mime, size, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())', [
            $ownerType, $ownerId, $userId, "$sub/$name", $thumb, mb_substr(basename($f['name']), 0, 190), $mime, (int)$f['size'],
        ]);
        $saved[] = (int)db()->lastInsertId();
    }
    return $saved;
}

function make_thumb(string $path, string $mime): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        'image/gif'  => @imagecreatefromgif($path),
        default      => false,
    };
    if (!$src) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $s = min(1, 360 / max($w, $h));
    $tw = max(1, (int)($w * $s));
    $th = max(1, (int)($h * $s));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    $dir = dirname($path);
    $ok = imagejpeg($dst, $dir . '/t_' . basename($path) . '.jpg', 78);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

function attachments_for(string $ownerType, array $ownerIds): array
{
    $ownerIds = array_values(array_filter(array_map('intval', $ownerIds)));
    if (!$ownerIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ownerIds), '?'));
    $rows = q("SELECT id, owner_id, original_name, mime, size, thumb_name IS NOT NULL AS has_thumb, created_at
               FROM attachments WHERE owner_type = ? AND owner_id IN ($in) ORDER BY id", array_merge([$ownerType], $ownerIds))->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['owner_id']][] = [
            'id' => (int)$r['id'], 'name' => $r['original_name'], 'mime' => $r['mime'],
            'size' => (int)$r['size'], 'thumb' => (bool)$r['has_thumb'],
        ];
    }
    return $out;
}

function delete_attachment_files(array $row): void
{
    // A payment receipt can be shared by several recipients' transactions.
    if ((int)q('SELECT COUNT(*) FROM attachments WHERE stored_name = ? AND id <> ?', [$row['stored_name'], $row['id']])->fetchColumn() > 0) {
        return;
    }
    foreach ([$row['stored_name'] ?? null, $row['thumb_name'] ?? null] as $p) {
        if ($p && is_file(APP_ROOT . '/uploads/' . $p)) {
            @unlink(APP_ROOT . '/uploads/' . $p);
        }
    }
}

function delete_attachments_of(string $ownerType, int $ownerId): void
{
    $rows = q('SELECT * FROM attachments WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId])->fetchAll();
    foreach ($rows as $r) {
        delete_attachment_files($r);
    }
    q('DELETE FROM attachments WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
}
