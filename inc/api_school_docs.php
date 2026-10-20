<?php
/*
 * School documents: each school's timetable, calendar, scheme of work, agreement and so on, as PDF, Word, Excel or photo.
 * Management uploads and removes them; staff assigned to the school can open them. The file is an attachment of type
 * 'schooldoc' owned by its school_docs row.
 */

const SCHOOL_DOC_CATEGORIES = ['Timetable', 'School calendar', 'Scheme of work', 'Contract / agreement', 'Other'];

/** Management sees every school's documents; other staff only those of the schools they're assigned to. */
function school_doc_can_view(string $school, array $me): bool
{
    return is_management($me) || in_array(mb_strtolower($school), array_map('mb_strtolower', $me['schools'] ?? []), true);
}

/** The client a request is about, by id or by school name. */
function school_doc_client(array $in): array
{
    if (!empty($in['client_id'])) {
        $c = q('SELECT id, name FROM clients WHERE id = ?', [(int)$in['client_id']])->fetch();
    } else {
        // A school from the Settings list may not be in the clients directory yet.
        $school = lesson_school((string)($in['school'] ?? ''));
        $c = $school === '' ? false : ['id' => client_id_for($school), 'name' => $school];
    }
    if (!$c) {
        fail('School not found.', 404);
    }
    return ['id' => (int)$c['id'], 'name' => $c['name']];
}

function act_school_docs_list(array $in, array $me): void
{
    $c = school_doc_client($in);
    if (!school_doc_can_view($c['name'], $me)) {
        fail('This school\'s documents aren\'t open to you.', 403);
    }
    $rows = q('SELECT d.id, d.title, d.category, d.created_at, u.name AS user_name FROM school_docs d LEFT JOIN users u ON u.id = d.user_id
        WHERE d.client_id = ? ORDER BY d.created_at DESC, d.id DESC', [$c['id']])->fetchAll();
    $files = attachments_for('schooldoc', array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['file'] = $files[$r['id']][0] ?? null;
    }
    unset($r);
    json_out(['ok' => true, 'school' => $c['name'], 'client_id' => $c['id'], 'docs' => $rows,
        'categories' => SCHOOL_DOC_CATEGORIES, 'can_edit' => is_management($me)]);
}

function act_school_doc_save(array $in, array $me): void
{
    if (!is_management($me)) {
        fail('Only management can upload school documents.', 403);
    }
    $c = school_doc_client($in);
    $files = uploaded_files('doc');
    if (count($files) !== 1) {
        fail('Choose one file to upload.');
    }
    if ($err = check_uploads('doc', 0, DOC_TYPES)) {
        fail($err, 422);
    }
    $title = str_in($in['title'] ?? '', 190) ?: mb_substr(pathinfo((string)$files[0]['name'], PATHINFO_FILENAME), 0, 190);
    $category = in_array($in['category'] ?? '', SCHOOL_DOC_CATEGORIES, true) ? $in['category'] : 'Other';
    db()->beginTransaction();
    q('INSERT INTO school_docs (client_id, title, category, user_id, created_at) VALUES (?,?,?,?,NOW())', [$c['id'], $title, $category, $me['id']]);
    $id = (int)db()->lastInsertId();
    if (!save_uploads('schooldoc', $id, $me['id'], 'doc', DOC_TYPES)) {
        db()->rollBack();
        fail('The file couldn\'t be saved. Try again.', 500);
    }
    audit('school_doc_add', 'client', $c['id'], ['title' => $title, 'category' => $category]);
    db()->commit();
    $staff = array_diff(array_keys(client_staff($c['name'])), [$me['id']]);
    if ($staff) {
        notify(array_values($staff), 'lesson', "New $category for {$c['name']}: $title", 'Open it from the school\'s timetable.', 'timetable', false);
    }
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_school_doc_delete(array $in, array $me): void
{
    if (!is_management($me)) {
        fail('Only management can remove school documents.', 403);
    }
    $d = q('SELECT * FROM school_docs WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$d) {
        fail('Document not found.', 404);
    }
    db()->beginTransaction();
    delete_attachments_of('schooldoc', (int)$d['id']);
    q('DELETE FROM school_docs WHERE id = ?', [$d['id']]);
    audit('school_doc_delete', 'client', (int)$d['client_id'], ['title' => $d['title']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}
