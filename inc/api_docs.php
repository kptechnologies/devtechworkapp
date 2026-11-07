<?php
/*
 * Sheets & docs: spreadsheets and documents staff prepare for the work they do, on a job order or on their own.
 *  - 'builtin' files are edited inside the portal (spreadsheet grid or rich-text editor) and kept in work_docs.content.
 *  - 'google' files are Google Sheets/Docs in the company Google Drive (inc/gdrive.php); the portal keeps the link.
 * Who can open one: management, whoever made it, and anyone who can see its job. The same people can edit it.
 * Templates (is_template = 1) are made by management; everyone can start a new file from one.
 */

require_once __DIR__ . '/gdrive.php';

const DOC_KINDS = ['sheet' => 'spreadsheet', 'doc' => 'document'];
const DOC_ENGINES = ['builtin', 'google'];
const DOC_LIST_COLS = 'd.id, d.job_id, d.engine, d.kind, d.title, d.gfile_id, d.web_url, d.is_template, d.version, d.created_by, d.created_at, d.updated_at,
    u.name AS created_by_name, e.name AS updated_by_name, j.title AS job_title';

/** The largest file the database will take in one go (MySQL's max_allowed_packet, less room for the query). */
function doc_max_bytes(): int
{
    static $max = null;
    if ($max === null) {
        $packet = (int)q('SELECT @@max_allowed_packet')->fetchColumn();
        $max = max(200000, min(16 * 1048576, $packet - 65536));
    }
    return $max;
}

function doc_row(array $r): array
{
    foreach (['id', 'job_id', 'is_template', 'version', 'created_by'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    }
    $r['job_ref'] = $r['job_id'] ? job_ref($r['job_id']) : null;
    return $r;
}

function load_doc(int $id, array $me, bool $withContent = false): array
{
    $d = q('SELECT d.*, u.name AS created_by_name, e.name AS updated_by_name, j.title AS job_title FROM work_docs d
        LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users e ON e.id = d.updated_by LEFT JOIN jobs j ON j.id = d.job_id
        WHERE d.id = ? AND d.deleted_at IS NULL', [$id])->fetch();
    if (!$d) {
        fail('This file was deleted or doesn\'t exist.', 404);
    }
    if (!doc_can_see($d, $me)) {
        fail('This file isn\'t open to you.', 403);
    }
    if (!$withContent) {
        unset($d['content']);
    }
    return doc_row($d);
}

function doc_can_see(array $d, array $me): bool
{
    if (is_management($me) || (int)$d['created_by'] === $me['id'] || (int)$d['is_template'] === 1) {
        return true;
    }
    if ($d['job_id']) {
        $j = q('SELECT id, created_by FROM jobs WHERE id = ?', [(int)$d['job_id']])->fetch();
        return $j && job_can_see($j, $me);
    }
    return false;
}

function doc_can_edit(array $d, array $me): bool
{
    if ((int)$d['is_template'] === 1) {
        return is_management($me);
    }
    return doc_can_see($d, $me);
}

/** Content from the editor: JSON, within the size the database takes. */
function doc_content_in($raw, string $kind): string
{
    $json = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '' || !is_array(json_decode($json, true))) {
        fail('The file content is not valid. Reload and try again.', 422);
    }
    if (strlen($json) > doc_max_bytes()) {
        fail(sprintf('This file is too big to save (%.1f MB, the limit is %.1f MB). Remove large pictures or split it into two files.',
            strlen($json) / 1048576, doc_max_bytes() / 1048576), 413);
    }
    return $json;
}

/** The people who should get access to a job's Google file: its creator and its assignees. */
function doc_job_people(?array $job, int $exclude = 0): array
{
    if (!$job) {
        return [];
    }
    $ids = array_diff(array_unique(array_merge(job_assignee_ids($job['id']), [(int)$job['created_by']])), [$exclude]);
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q("SELECT id, name, email, google_email FROM users WHERE active = 1 AND id IN ($in)", array_values($ids))->fetchAll();
}

/** Share a Google file with the person who made it and the people on its job. */
function doc_share_google(string $fileId, array $me, ?array $job): void
{
    $u = q('SELECT id, email, google_email FROM users WHERE id = ?', [$me['id']])->fetch() ?: $me;
    gd_share($fileId, google_email_of($u));
    if (gdrive_share_mode() === 'people') {
        foreach (doc_job_people($job, $me['id']) as $p) {
            gd_share($fileId, google_email_of($p));
        }
    }
}

/** What the job drawer lists. */
function job_docs(int $jobId): array
{
    $rows = q('SELECT ' . DOC_LIST_COLS . ' FROM work_docs d LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users e ON e.id = d.updated_by
        LEFT JOIN jobs j ON j.id = d.job_id WHERE d.job_id = ? AND d.deleted_at IS NULL AND d.is_template = 0 ORDER BY d.created_at', [$jobId])->fetchAll();
    return array_map('doc_row', $rows);
}

function act_docs_list(array $in, array $me): void
{
    $where = ['d.deleted_at IS NULL'];
    $params = [];
    $templates = !empty($in['templates']);
    $where[] = $templates ? 'd.is_template = 1' : 'd.is_template = 0';
    if (!empty($in['job_id'])) {
        $job = load_job((int)$in['job_id'], $me);
        $where[] = 'd.job_id = ?';
        $params[] = $job['id'];
    } elseif (!$templates) {
        if (!is_management($me) || ($in['scope'] ?? '') === 'mine') {
            // Staff see what they made and the files on jobs they're on.
            $where[] = '(d.created_by = ? OR d.job_id IN (SELECT job_id FROM job_assignees WHERE user_id = ? AND removed_at IS NULL) OR d.job_id IN (SELECT id FROM jobs WHERE created_by = ?))';
            array_push($params, $me['id'], $me['id'], $me['id']);
        }
        if (is_management($me) && !empty($in['user_id'])) {
            $where[] = 'd.created_by = ?';
            $params[] = (int)$in['user_id'];
        }
        if (($in['scope'] ?? '') === 'jobs') {
            $where[] = 'd.job_id IS NOT NULL';
        } elseif (($in['scope'] ?? '') === 'general') {
            $where[] = 'd.job_id IS NULL';
        }
    }
    if (isset(DOC_KINDS[$in['kind'] ?? ''])) {
        $where[] = 'd.kind = ?';
        $params[] = $in['kind'];
    }
    if (in_array($in['engine'] ?? '', DOC_ENGINES, true)) {
        $where[] = 'd.engine = ?';
        $params[] = $in['engine'];
    }
    if (($s = trim((string)($in['q'] ?? ''))) !== '') {
        $where[] = '(d.title LIKE ? OR j.title LIKE ? OR u.name LIKE ? OR CONCAT(\'JO-\', LPAD(d.job_id, 4, \'0\')) LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($s, 0, 100)) . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $rows = q('SELECT ' . DOC_LIST_COLS . ' FROM work_docs d LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users e ON e.id = d.updated_by
        LEFT JOIN jobs j ON j.id = d.job_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.updated_at DESC, d.id DESC LIMIT 300', $params)->fetchAll();
    $me2 = q('SELECT email, google_email FROM users WHERE id = ?', [$me['id']])->fetch();
    json_out(['ok' => true, 'docs' => array_map('doc_row', $rows), 'google' => gdrive_ready(),
        'default_engine' => gdrive_ready() && (gdrive_cfg()['default_engine'] ?? '') === 'google' ? 'google' : 'builtin',
        'share' => gdrive_share_mode(), 'my_google_email' => $me2 ? google_email_of($me2) : '', 'max_bytes' => doc_max_bytes()]);
}

function act_doc_get(array $in, array $me): void
{
    $d = load_doc((int)($in['id'] ?? 0), $me, true);
    // Sent as the exact text the editor saved: decoding it here would turn {"0":…} objects into lists.
    $d['content_json'] = $d['content'];
    unset($d['content']);
    $d['can_edit'] = doc_can_edit($d, $me);
    $d['can_delete'] = is_management($me) || $d['created_by'] === $me['id'];
    json_out(['ok' => true, 'doc' => $d, 'google' => gdrive_ready(), 'max_bytes' => doc_max_bytes()]);
}

/**
 * New file: blank, from a template, or from an imported Excel file (builtin content made in the browser,
 * or for Google an uploaded .xlsx/.docx that Google converts).
 */
function act_doc_create(array $in, array $me): void
{
    $kind = isset(DOC_KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'sheet';
    $engine = in_array($in['engine'] ?? '', DOC_ENGINES, true) ? $in['engine'] : 'builtin';
    $title = str_in($in['title'] ?? '', 190);
    $asTemplate = !empty($in['template']) && is_management($me);
    $job = !$asTemplate && !empty($in['job_id']) ? load_job((int)$in['job_id'], $me) : null;
    $tpl = null;
    if (!empty($in['template_id'])) {
        $tpl = q('SELECT * FROM work_docs WHERE id = ? AND is_template = 1 AND deleted_at IS NULL', [(int)$in['template_id']])->fetch();
        if (!$tpl) {
            fail('That template was deleted. Pick another one.', 404);
        }
        $kind = $tpl['kind'];
        $engine = $tpl['engine'];
    }
    if ($title === '') {
        $title = $tpl ? $tpl['title'] : 'Untitled ' . DOC_KINDS[$kind];
        if ($job) {
            $title .= ' - ' . $job['ref'];
        }
    }
    $content = null;
    $gid = null;
    $url = null;
    if ($engine === 'builtin') {
        if ($tpl) {
            $content = $tpl['content'];
        } elseif (isset($in['content'])) {
            $content = doc_content_in($in['content'], $kind);
        }
    } else {
        if (!gdrive_ready()) {
            fail('Google Drive isn\'t connected. Ask an admin to connect it in Settings, or use the built-in editor.', 409);
        }
        $folder = gd_folder_for($job, $me, $asTemplate);
        try {
            $files = uploaded_files('file');
            if ($files) {
                $f = $files[0];
                if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || $f['size'] > 20 * 1048576) {
                    fail('The file couldn\'t be uploaded. It must be under 20 MB.', 422);
                }
                $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                $mimes = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel', 'csv' => 'text/csv',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword', 'html' => 'text/html'];
                if (!isset($mimes[$ext])) {
                    fail('Upload an Excel (.xlsx, .xls, .csv) or Word (.docx, .doc) file.', 422);
                }
                $kind = in_array($ext, ['xlsx', 'xls', 'csv'], true) ? 'sheet' : 'doc';
                [$gid, $url] = gd_upload($kind, $title, $folder, (string)file_get_contents($f['tmp_name']), $mimes[$ext]);
            } elseif ($tpl && $tpl['gfile_id']) {
                [$gid, $url] = gd_copy($tpl['gfile_id'], $title, $folder);
            } else {
                [$gid, $url] = gd_create($kind, $title, $folder);
            }
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 502);
        }
        if (!$asTemplate) {
            doc_share_google($gid, $me, $job);
        } elseif (gdrive_share_mode() === 'link') {
            gd_share($gid, '');
        }
    }
    q('INSERT INTO work_docs (job_id, engine, kind, title, content, gfile_id, web_url, is_template, created_by, updated_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())', [$job['id'] ?? null, $engine, $kind, $title, $content, $gid, $url, $asTemplate ? 1 : 0, $me['id'], $me['id']]);
    $id = (int)db()->lastInsertId();
    if ($job) {
        job_log($job['id'], 'doc', 'Added ' . DOC_KINDS[$kind] . ' "' . $title . '"' . ($engine === 'google' ? ' (Google)' : ''));
    }
    audit('doc_create', 'work_doc', $id, ['title' => $title, 'kind' => $kind, 'engine' => $engine, 'job_id' => $job['id'] ?? null, 'template' => $asTemplate]);
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

/** Save a built-in file. `version` is the one the editor loaded, so two people can't silently overwrite each other. */
function act_doc_save(array $in, array $me): void
{
    $d = load_doc((int)($in['id'] ?? 0), $me);
    if (!doc_can_edit($d, $me)) {
        fail('You can view this file but not change it.', 403);
    }
    $sets = ['updated_by = ?', 'updated_at = NOW()'];
    $params = [$me['id']];
    if (isset($in['title'])) {
        $title = str_in($in['title'], 190);
        if ($title === '') {
            fail('Give the file a name.', 422);
        }
        $sets[] = 'title = ?';
        $params[] = $title;
    }
    if (array_key_exists('content', $in)) {
        if ($d['engine'] !== 'builtin') {
            fail('This file is edited in Google.', 422);
        }
        $sets[] = 'content = ?';
        $params[] = doc_content_in($in['content'], $d['kind']);
    }
    $sets[] = 'version = version + 1';
    $params[] = $d['id'];
    $sql = 'UPDATE work_docs SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
    if (array_key_exists('content', $in) && empty($in['force'])) {
        $sql .= ' AND version = ?';
        $params[] = (int)($in['version'] ?? 0);
    }
    if (!q($sql, $params)->rowCount()) {
        $cur = q('SELECT u.name, d.updated_at FROM work_docs d LEFT JOIN users u ON u.id = d.updated_by WHERE d.id = ?', [$d['id']])->fetch();
        json_out(['ok' => false, 'conflict' => true, 'error' => ($cur['name'] ?? 'Someone') . ' saved changes to this file after you opened it.'], 409);
    }
    touch_change();
    json_out(['ok' => true, 'version' => (int)q('SELECT version FROM work_docs WHERE id = ?', [$d['id']])->fetchColumn()]);
}

function act_doc_delete(array $in, array $me): void
{
    $d = load_doc((int)($in['id'] ?? 0), $me);
    if (!is_management($me) && $d['created_by'] !== $me['id']) {
        fail('Only the person who made this file or management can delete it.', 403);
    }
    if ($d['is_template'] && !is_management($me)) {
        fail('Only management can delete templates.', 403);
    }
    q('UPDATE work_docs SET deleted_at = NOW(), updated_by = ? WHERE id = ?', [$me['id'], $d['id']]);
    if ($d['engine'] === 'google' && $d['gfile_id'] && gdrive_ready()) {
        gd_trash($d['gfile_id']);
    }
    if ($d['job_id']) {
        job_log($d['job_id'], 'doc', 'Deleted ' . DOC_KINDS[$d['kind']] . ' "' . $d['title'] . '"');
    }
    audit('doc_delete', 'work_doc', $d['id'], ['title' => $d['title']]);
    touch_change();
    json_out(['ok' => true]);
}

/** Management: save a copy of a file as a template everyone can start from. */
function act_doc_make_template(array $in, array $me): void
{
    require_management();
    $d = load_doc((int)($in['id'] ?? 0), $me, true);
    $title = str_in($in['title'] ?? '', 190) ?: $d['title'];
    $gid = null;
    $url = null;
    if ($d['engine'] === 'google') {
        if (!gdrive_ready()) {
            fail('Google Drive isn\'t connected.', 409);
        }
        try {
            [$gid, $url] = gd_copy($d['gfile_id'], $title, gd_folder_for(null, $me, true));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 502);
        }
        if (gdrive_share_mode() === 'link') {
            gd_share($gid, '');
        }
    }
    q('INSERT INTO work_docs (job_id, engine, kind, title, content, gfile_id, web_url, is_template, created_by, updated_by, created_at, updated_at)
        VALUES (NULL,?,?,?,?,?,?,1,?,?,NOW(),NOW())', [$d['engine'], $d['kind'], $title, $d['content'], $gid, $url, $me['id'], $me['id']]);
    $id = (int)db()->lastInsertId();
    audit('doc_template', 'work_doc', $id, ['from' => $d['id'], 'title' => $title]);
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

/**
 * Copy a built-in file into the company Google Drive. The browser sends it as an .xlsx (sheet) or HTML (doc)
 * and Google converts it. The built-in file then points at the Google one.
 */
function act_doc_to_google(array $in, array $me): void
{
    $d = load_doc((int)($in['id'] ?? 0), $me);
    if (!doc_can_edit($d, $me) || $d['engine'] !== 'builtin') {
        fail('This file can\'t be sent to Google Drive.', 403);
    }
    if (!gdrive_ready()) {
        fail('Google Drive isn\'t connected. Ask an admin to connect it in Settings.', 409);
    }
    $files = uploaded_files('file');
    if (count($files) !== 1 || ($files[0]['error'] ?? 0) !== UPLOAD_ERR_OK || $files[0]['size'] > 20 * 1048576) {
        fail('The file couldn\'t be prepared. Try again.', 422);
    }
    $src = $d['kind'] === 'sheet' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/html';
    $job = $d['job_id'] ? q('SELECT id, title, created_by FROM jobs WHERE id = ?', [$d['job_id']])->fetch() : null;
    if ($job) {
        $job['id'] = (int)$job['id'];
        $job['ref'] = job_ref($job['id']);
    }
    $owner = q('SELECT id, name FROM users WHERE id = ?', [$d['created_by']])->fetch() ?: $me;
    try {
        [$gid, $url] = gd_upload($d['kind'], $d['title'], gd_folder_for($job ?: null, $owner, (bool)$d['is_template']),
            (string)file_get_contents($files[0]['tmp_name']), $src);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 502);
    }
    if ($d['is_template']) {
        if (gdrive_share_mode() === 'link') {
            gd_share($gid, '');
        }
    } else {
        doc_share_google($gid, $me, $job ?: null);
    }
    q('INSERT INTO work_docs (job_id, engine, kind, title, content, gfile_id, web_url, is_template, created_by, updated_by, created_at, updated_at)
        VALUES (?,\'google\',?,?,NULL,?,?,?,?,?,NOW(),NOW())', [$d['job_id'], $d['kind'], $d['title'], $gid, $url, $d['is_template'], $me['id'], $me['id']]);
    $id = (int)db()->lastInsertId();
    if ($d['job_id']) {
        job_log($d['job_id'], 'doc', 'Copied ' . DOC_KINDS[$d['kind']] . ' "' . $d['title'] . '" to Google Drive');
    }
    audit('doc_to_google', 'work_doc', $id, ['from' => $d['id']]);
    touch_change();
    json_out(['ok' => true, 'id' => $id, 'url' => $url]);
}

/** The Google account a staff member edits company files with (empty = their portal email). */
function act_doc_google_email(array $in, array $me): void
{
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('That isn\'t an email address.', 422);
    }
    q('UPDATE users SET google_email = ? WHERE id = ?', [mb_substr($email, 0, 190), $me['id']]);
    json_out(['ok' => true]);
}

/** Give the current user access to a Google file again (e.g. after they change their Google email). */
function act_doc_google_access(array $in, array $me): void
{
    $d = load_doc((int)($in['id'] ?? 0), $me);
    if ($d['engine'] !== 'google' || !$d['gfile_id'] || !gdrive_ready()) {
        fail('This isn\'t a Google file.', 422);
    }
    $u = q('SELECT email, google_email FROM users WHERE id = ?', [$me['id']])->fetch();
    if (!gd_share($d['gfile_id'], google_email_of($u), doc_can_edit($d, $me) ? 'writer' : 'reader')) {
        fail('Google wouldn\'t share the file with ' . google_email_of($u) . '. Check it is a Google account.', 502);
    }
    json_out(['ok' => true, 'message' => 'Access given to ' . google_email_of($u) . '.']);
}
