<?php
/**
 * What staff teach and what they learn, planned by management.
 * - Posted lessons: admin writes a lesson brief for a school (every staff member assigned there) or chosen staff. Each
 *   recipient gets their own lesson plan with status 'assigned'; they still build the project and upload proof
 *   (which submits it), then log classes as usual. Reference files on the brief are owner_type 'lbrief'.
 * - Scheme of work: the term's weekly topics per school, class and subject. A week is covered once a class is taught
 *   from a plan linked to it.
 * - Training: things staff must learn themselves, with a due date. Staff submit proof ('trainproof'), admin marks it
 *   done or returns it. Admin materials are 'trainref'.
 */

const TRAINING_STATUS = ['todo', 'submitted', 'done', 'returned'];
const TRAINING_MIN_PROOF = 20; // characters

/** Active staff assigned to this school, as [id => name]. */
function staff_at_school(string $school): array
{
    $out = [];
    foreach (client_staff($school) as $id => $u) {
        if ($u['active'] && $u['role'] === 'staff') {
            $out[$id] = $u['name'];
        }
    }
    return $out;
}

/** Active staff among these ids, as [id => name]. */
function active_staff(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q("SELECT id, name FROM users WHERE active = 1 AND role = 'staff' AND id IN ($in) ORDER BY name", $ids)->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Recipients from a form: send_to 'school' (staff assigned there), 'all' (every active staff member) or 'staff' (user_ids). */
function learning_recipients(array $in, string $school): array
{
    return match ((string)($in['send_to'] ?? 'staff')) {
        'school' => $school !== '' ? staff_at_school($school) : [],
        'all' => q("SELECT id, name FROM users WHERE active = 1 AND role = 'staff' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR),
        default => active_staff((array)($in['user_ids'] ?? [])),
    };
}

/* ------------------------------------------------------- posted lessons */

/** Admin posts a lesson brief; each recipient gets a plan to prepare. */
function act_lesson_assign(array $in, array $me): void
{
    require_admin();
    $sendTo = ($in['send_to'] ?? '') === 'school' ? 'school' : 'staff';
    $school = lesson_school((string)($in['school'] ?? ''));
    $subject = in_array($in['subject'] ?? '', lesson_subjects(), true) ? $in['subject'] : '';
    $topic = str_in($in['topic'] ?? '', 190);
    $project = str_in($in['project_title'] ?? '', 190);
    $objectives = str_in($in['objectives'] ?? '', 4000);
    $activities = str_in($in['activities'] ?? '', 6000);
    $materials = str_in($in['materials'] ?? '', 2000);
    $level = str_in($in['class_level'] ?? '', 60);
    $note = str_in($in['note'] ?? '', 2000);
    $date = valid_date($in['planned_date'] ?? null) ? $in['planned_date'] : null;
    $time = preg_match('/^\d{2}:\d{2}$/', (string)($in['planned_time'] ?? '')) ? $in['planned_time'] : '';
    $schemeItem = (int)($in['scheme_item_id'] ?? 0) ?: null;
    if ($schemeItem && !q('SELECT id FROM lesson_scheme_items WHERE id = ?', [$schemeItem])->fetchColumn()) {
        $schemeItem = null;
    }
    $e = [];
    if ($sendTo === 'school' && $school === '') {
        $e['school'] = 'Choose the school.';
    }
    if ($subject === '') {
        $e['subject'] = 'Choose the subject.';
    }
    if ($topic === '') {
        $e['topic'] = 'Enter the topic.';
    }
    if ($project === '') {
        $e['project_title'] = 'Name the project the pupils will build.';
    }
    if (mb_strlen($objectives) < 15) {
        $e['objectives'] = 'Say what the pupils should be able to do by the end.';
    }
    if (mb_strlen($activities) < 30) {
        $e['activities'] = 'Write the steps of the lesson: introduction, demonstration, practice, wrap-up.';
    }
    if (!$date) {
        $e['planned_date'] = 'Pick the date it should be taught.';
    }
    $to = learning_recipients($in, $school);
    if (!$to) {
        $e[$sendTo === 'school' ? 'school' : 'user_ids'] = $sendTo === 'school' ? 'No active staff are assigned to this school.' : 'Choose at least one staff member.';
    }
    if ($e) {
        lesson_fields_fail($e);
    }
    if ($err = check_uploads('brief')) {
        fail($err);
    }
    db()->beginTransaction();
    q("INSERT INTO lesson_assignments (created_by, target, school, subject, topic, project_title, objectives, activities, materials, class_level,
        planned_date, planned_time, note, scheme_item_id, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'open',NOW())",
        [$me['id'], $sendTo, $school, $subject, $topic, $project, $objectives, $activities, $materials, $level, $date, $time, $note, $schemeItem]);
    $aid = (int)db()->lastInsertId();
    save_uploads('lbrief', $aid, $me['id'], 'brief');
    $clientId = lesson_client_id($school);
    $planIds = [];
    foreach (array_keys($to) as $uid) {
        // submitted_at is a placeholder until the staff member prepares the lesson.
        q("INSERT INTO lesson_plans (user_id, client_id, school, subject, topic, project_title, objectives, activities, materials, planned_date, planned_time,
            status, submitted_at, assignment_id, scheme_item_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'assigned',NOW(),?,?,NOW(),NOW())",
            [$uid, $clientId, $school, $subject, $topic, $project, $objectives, $activities, $materials, $date, $time, $aid, $schemeItem]);
        $planIds[$uid] = (int)db()->lastInsertId();
    }
    audit('lesson_assign', 'lesson_assignment', $aid, ['topic' => $topic, 'staff' => array_values($to)]);
    db()->commit();
    touch_change();
    $when = date('D j M', strtotime($date)) . ($time ? ' at ' . $time : '');
    foreach ($planIds as $uid => $pid) {
        notify([$uid], 'lesson', 'New lesson to teach: ' . $topic, ($school ? $school . ', ' : '') . $when
            . '. Build the project yourself and upload screenshots before class.' . ($note ? "\n" . $note : ''), 'lessons/plan/' . $pid);
    }
    json_out(['ok' => true, 'id' => $aid, 'staff' => count($to)]);
}

/** Posted lessons with each recipient's progress. */
function act_lesson_assignments_list(array $in, array $me): void
{
    require_admin();
    [$from, $to] = report_range($in + ['from' => date('Y-m-d', strtotime('-60 days')), 'to' => date('Y-m-d', strtotime('+60 days'))]);
    $w = ["a.status = 'open'", '(a.planned_date BETWEEN ? AND ? OR DATE(a.created_at) BETWEEN ? AND ?)'];
    $p = [$from, $to, $from, $to];
    if (($in['school'] ?? '') !== '') {
        $w[] = 'a.school = ?';
        $p[] = $in['school'];
    }
    if (($in['subject'] ?? '') !== '') {
        $w[] = 'a.subject = ?';
        $p[] = $in['subject'];
    }
    $rows = q('SELECT a.*, u.name AS created_by_name FROM lesson_assignments a JOIN users u ON u.id = a.created_by WHERE ' . implode(' AND ', $w)
        . ' ORDER BY a.planned_date DESC, a.id DESC LIMIT 200', $p)->fetchAll();
    $ids = array_map('intval', array_column($rows, 'id'));
    $plans = [];
    if ($ids) {
        $inQ = implode(',', array_fill(0, count($ids), '?'));
        foreach (q("SELECT p.id, p.assignment_id, p.user_id, p.status, u.name AS user_name,
                (SELECT COUNT(*) FROM lesson_sessions s WHERE s.plan_id = p.id AND s.status <> 'cancelled') AS classes
                FROM lesson_plans p JOIN users u ON u.id = p.user_id WHERE p.assignment_id IN ($inQ) ORDER BY u.name", $ids)->fetchAll() as $r) {
            $plans[(int)$r['assignment_id']][] = ['id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'user_name' => $r['user_name'],
                'status' => $r['status'], 'classes' => (int)$r['classes']];
        }
    }
    $files = attachments_for('lbrief', $ids);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['plans'] = $plans[$r['id']] ?? [];
        $r['files'] = $files[$r['id']] ?? [];
    }
    json_out(['ok' => true, 'from' => $from, 'to' => $to, 'items' => $rows]);
}

/** Take back a posted lesson: copies nobody has prepared yet are removed; prepared or taught ones stay. */
function act_lesson_assign_withdraw(array $in, array $me): void
{
    require_admin();
    $a = q('SELECT * FROM lesson_assignments WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$a) {
        fail('Posted lesson not found.', 404);
    }
    $open = q("SELECT id, user_id FROM lesson_plans WHERE assignment_id = ? AND status = 'assigned'", [$a['id']])->fetchAll();
    db()->beginTransaction();
    q("DELETE FROM lesson_plans WHERE assignment_id = ? AND status = 'assigned'", [$a['id']]);
    q("UPDATE lesson_assignments SET status = 'withdrawn' WHERE id = ?", [$a['id']]);
    audit('lesson_assign_withdraw', 'lesson_assignment', (int)$a['id'], ['removed' => count($open)]);
    db()->commit();
    touch_change();
    notify(array_column($open, 'user_id'), 'lesson', 'Lesson withdrawn: ' . $a['topic'], 'You no longer need to prepare this lesson.', 'lessons', false);
    json_out(['ok' => true, 'removed' => count($open)]);
}

/* ---------------------------------------------------------- scheme of work */

/** Monday of the week containing this date. */
function week_monday(string $date): string
{
    return date('Y-m-d', strtotime('monday this week', strtotime($date)));
}

/** Schemes with their weeks and coverage. Staff see only their own schools. */
function schemes_load(array $me, string $school = '', int $id = 0): array
{
    $w = ['1=1'];
    $p = [];
    if ($id) {
        $w[] = 's.id = ?';
        $p[] = $id;
    }
    if ($school !== '') {
        $w[] = 's.school = ?';
        $p[] = $school;
    }
    if (!is_admin($me)) {
        $mine = $me['schools'] ?: [''];
        $w[] = 's.school IN (' . implode(',', array_fill(0, count($mine), '?')) . ')';
        array_push($p, ...$mine);
    }
    $schemes = q('SELECT s.*, u.name AS created_by_name FROM lesson_schemes s JOIN users u ON u.id = s.created_by WHERE ' . implode(' AND ', $w)
        . ' ORDER BY s.start_date DESC, s.school, s.class_level LIMIT 200', $p)->fetchAll();
    if (!$schemes) {
        return [];
    }
    $ids = array_map('intval', array_column($schemes, 'id'));
    $inQ = implode(',', array_fill(0, count($ids), '?'));
    $items = q("SELECT * FROM lesson_scheme_items WHERE scheme_id IN ($inQ) ORDER BY scheme_id, sort, week_no, id", $ids)->fetchAll();
    $itemIds = array_map('intval', array_column($items, 'id'));
    $cover = $posted = [];
    if ($itemIds) {
        $inI = implode(',', array_fill(0, count($itemIds), '?'));
        foreach (q("SELECT p.scheme_item_id, MIN(s.session_date) AS first_date, COUNT(*) AS classes,
                GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS staff, MIN(p.id) AS plan_id
                FROM lesson_sessions s JOIN lesson_plans p ON p.id = s.plan_id JOIN users u ON u.id = s.user_id
                WHERE p.scheme_item_id IN ($inI) AND s.status <> 'cancelled' GROUP BY p.scheme_item_id", $itemIds)->fetchAll() as $c) {
            $cover[(int)$c['scheme_item_id']] = ['date' => $c['first_date'], 'classes' => (int)$c['classes'], 'staff' => $c['staff'], 'plan_id' => (int)$c['plan_id']];
        }
        foreach (q("SELECT scheme_item_id, COUNT(*) AS n FROM lesson_plans WHERE scheme_item_id IN ($inI) GROUP BY scheme_item_id", $itemIds)->fetchAll() as $c) {
            $posted[(int)$c['scheme_item_id']] = (int)$c['n'];
        }
    }
    $by = [];
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $by[(int)$it['scheme_id']][] = ['id' => $iid, 'week_no' => (int)$it['week_no'], 'topic' => $it['topic'], 'project_title' => $it['project_title'],
            'objectives' => (string)$it['objectives'], 'covered' => $cover[$iid] ?? null, 'plans' => $posted[$iid] ?? 0];
    }
    $now = strtotime(week_monday(today()));
    foreach ($schemes as &$s) {
        $s['id'] = (int)$s['id'];
        $s['items'] = $by[$s['id']] ?? [];
        $s['current_week'] = (int)floor(($now - strtotime(week_monday($s['start_date']))) / (7 * 86400)) + 1;
        foreach ($s['items'] as &$it) {
            $it['week_start'] = date('Y-m-d', strtotime(week_monday($s['start_date']) . ' +' . ($it['week_no'] - 1) . ' weeks'));
        }
        unset($it);
        $s['covered'] = count(array_filter($s['items'], fn($x) => $x['covered']));
    }
    return $schemes;
}

function act_schemes_list(array $in, array $me): void
{
    json_out(['ok' => true, 'items' => schemes_load($me, (string)($in['school'] ?? ''))]);
}

/** Create or edit a scheme with its weekly topics. Weeks that are taught keep their id so coverage stays linked. */
function act_scheme_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    if ($id && !q('SELECT id FROM lesson_schemes WHERE id = ?', [$id])->fetchColumn()) {
        fail('Scheme not found.', 404);
    }
    $school = lesson_school((string)($in['school'] ?? ''));
    $subject = in_array($in['subject'] ?? '', lesson_subjects(), true) ? $in['subject'] : '';
    $level = str_in($in['class_level'] ?? '', 60);
    $term = str_in($in['term'] ?? '', 120);
    $start = valid_date($in['start_date'] ?? null) ? $in['start_date'] : '';
    $e = [];
    if ($school === '') {
        $e['school'] = 'Choose the school.';
    }
    if ($subject === '') {
        $e['subject'] = 'Choose the subject.';
    }
    if ($level === '') {
        $e['class_level'] = 'Enter the class, e.g. Grade 5 or JSS1.';
    }
    if ($term === '') {
        $e['term'] = 'Name the term, e.g. First term 2026/27.';
    }
    if ($start === '') {
        $e['start_date'] = 'Pick the date week 1 starts.';
    }
    $rows = [];
    foreach (array_values(array_filter((array)($in['items'] ?? []), 'is_array')) as $i => $r) {
        $topic = str_in($r['topic'] ?? '', 190);
        if ($topic === '') {
            continue;
        }
        $rows[] = ['id' => (int)($r['id'] ?? 0), 'week_no' => max(1, min(60, (int)($r['week_no'] ?? $i + 1))), 'topic' => $topic,
            'project_title' => str_in($r['project_title'] ?? '', 190), 'objectives' => str_in($r['objectives'] ?? '', 2000)];
    }
    if (!$rows) {
        $e['items'] = 'Add at least one week with a topic.';
    }
    if (count($rows) > 60) {
        $e['items'] = 'A scheme can have up to 60 weeks.';
    }
    if ($e) {
        lesson_fields_fail($e);
    }
    db()->beginTransaction();
    if ($id) {
        q('UPDATE lesson_schemes SET school = ?, client_id = ?, subject = ?, class_level = ?, term = ?, start_date = ?, updated_at = NOW() WHERE id = ?',
            [$school, lesson_client_id($school), $subject, $level, $term, $start, $id]);
    } else {
        q('INSERT INTO lesson_schemes (school, client_id, subject, class_level, term, start_date, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [$school, lesson_client_id($school), $subject, $level, $term, $start, $me['id']]);
        $id = (int)db()->lastInsertId();
    }
    $keep = [];
    $existing = array_map('intval', q('SELECT id FROM lesson_scheme_items WHERE scheme_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    foreach ($rows as $sort => $r) {
        if ($r['id'] && in_array($r['id'], $existing, true)) {
            q('UPDATE lesson_scheme_items SET week_no = ?, topic = ?, project_title = ?, objectives = ?, sort = ? WHERE id = ?',
                [$r['week_no'], $r['topic'], $r['project_title'], $r['objectives'], $sort, $r['id']]);
            $keep[] = $r['id'];
        } else {
            q('INSERT INTO lesson_scheme_items (scheme_id, week_no, topic, project_title, objectives, sort) VALUES (?,?,?,?,?,?)',
                [$id, $r['week_no'], $r['topic'], $r['project_title'], $r['objectives'], $sort]);
        }
    }
    $drop = array_diff($existing, $keep);
    if ($drop) {
        $inQ = implode(',', array_fill(0, count($drop), '?'));
        if ((int)q("SELECT COUNT(*) FROM lesson_plans WHERE scheme_item_id IN ($inQ)", array_values($drop))->fetchColumn()) {
            db()->rollBack();
            fail('A week you removed already has lessons planned or taught from it. Keep it, or change its topic instead.');
        }
        q("DELETE FROM lesson_scheme_items WHERE id IN ($inQ)", array_values($drop));
    }
    audit('scheme_save', 'lesson_scheme', $id, ['school' => $school, 'weeks' => count($rows)]);
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_scheme_delete(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    if ((int)q('SELECT COUNT(*) FROM lesson_plans p JOIN lesson_scheme_items i ON i.id = p.scheme_item_id WHERE i.scheme_id = ?', [$id])->fetchColumn()) {
        fail('Lessons have been planned or taught from this scheme, so it can\'t be deleted.');
    }
    q('DELETE FROM lesson_scheme_items WHERE scheme_id = ?', [$id]);
    q('DELETE FROM lesson_schemes WHERE id = ?', [$id]);
    audit('scheme_delete', 'lesson_scheme', $id);
    touch_change();
    json_out(['ok' => true]);
}

/** Weeks a staff member can link their own plan to: schemes at this school, from last week onward. */
function act_scheme_weeks(array $in, array $me): void
{
    $school = lesson_school((string)($in['school'] ?? ''));
    $out = [];
    if ($school !== '') {
        $from = date('Y-m-d', strtotime(week_monday(today()) . ' -7 days'));
        foreach (schemes_load(['role' => 'admin'] + $me, $school) as $s) {
            foreach ($s['items'] as $it) {
                if ($it['week_start'] >= $from && !$it['covered']) {
                    $out[] = ['id' => $it['id'], 'label' => $s['class_level'] . ' ' . $s['subject'] . ' · week ' . $it['week_no'] . ': ' . $it['topic'],
                        'subject' => $s['subject'], 'topic' => $it['topic'], 'project_title' => $it['project_title'], 'objectives' => $it['objectives'], 'week_start' => $it['week_start']];
                }
            }
        }
        usort($out, fn($a, $b) => strcmp($a['week_start'], $b['week_start']));
    }
    json_out(['ok' => true, 'items' => array_slice($out, 0, 40)]);
}

/* ---------------------------------------------------------------- training */

function training_badge(array $me): int
{
    if (is_admin($me)) {
        return (int)q("SELECT COUNT(*) FROM training_assignees ta JOIN trainings t ON t.id = ta.training_id WHERE ta.status = 'submitted' AND t.status = 'open'")->fetchColumn();
    }
    return (int)q("SELECT COUNT(*) FROM training_assignees ta JOIN trainings t ON t.id = ta.training_id
        WHERE ta.user_id = ? AND ta.status IN ('todo','returned') AND t.status = 'open'", [$me['id']])->fetchColumn();
}

/** Create or edit a training item; new recipients are added, nobody is removed. */
function act_training_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? q('SELECT * FROM trainings WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) {
        fail('Training not found.', 404);
    }
    $title = str_in($in['title'] ?? '', 190);
    $desc = str_in($in['description'] ?? '', 6000);
    $res = str_in($in['resources'] ?? '', 4000);
    $due = valid_date($in['due_date'] ?? null) ? $in['due_date'] : null;
    $school = lesson_school((string)($in['school'] ?? ''));
    $to = learning_recipients($in, $school);
    $e = [];
    if ($title === '') {
        $e['title'] = 'Say what they should learn.';
    }
    if (mb_strlen($desc) < 15) {
        $e['description'] = 'Describe what they should be able to do, and how they show it.';
    }
    if (!$old && !$to) {
        $e[($in['send_to'] ?? '') === 'school' ? 'school' : 'user_ids'] = ($in['send_to'] ?? '') === 'school' ? 'No active staff are assigned to this school.' : 'Choose at least one staff member.';
    }
    if ($e) {
        lesson_fields_fail($e);
    }
    if ($err = check_uploads('ref', $old ? count(attachments_for('trainref', [$id])[$id] ?? []) : 0)) {
        fail($err);
    }
    db()->beginTransaction();
    if ($old) {
        q('UPDATE trainings SET title = ?, description = ?, resources = ?, due_date = ? WHERE id = ?', [$title, $desc, $res, $due, $id]);
    } else {
        q("INSERT INTO trainings (title, description, resources, due_date, created_by, status, created_at) VALUES (?,?,?,?,?,'open',NOW())",
            [$title, $desc, $res, $due, $me['id']]);
        $id = (int)db()->lastInsertId();
    }
    $have = array_map('intval', q('SELECT user_id FROM training_assignees WHERE training_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    $new = array_values(array_diff(array_keys($to), $have));
    foreach ($new as $uid) {
        q("INSERT INTO training_assignees (training_id, user_id, status, created_at) VALUES (?,?,'todo',NOW())", [$id, $uid]);
    }
    save_uploads('trainref', $id, $me['id'], 'ref');
    audit($old ? 'training_update' : 'training_create', 'training', $id, ['title' => $title, 'added' => count($new)]);
    db()->commit();
    touch_change();
    notify($new, 'training', 'New training: ' . $title, ($due ? 'Due ' . date('D j M', strtotime($due)) . '. ' : '') . 'Learn it, then submit what you did as proof.', 'lessons/training/' . $id);
    json_out(['ok' => true, 'id' => $id, 'added' => count($new)]);
}

function act_trainings_list(array $in, array $me): void
{
    if (is_admin($me)) {
        $closed = !empty($in['closed']);
        $rows = q("SELECT t.*, u.name AS created_by_name, COUNT(ta.id) AS assignees, SUM(ta.status = 'done') AS done, SUM(ta.status = 'submitted') AS submitted,
            SUM(ta.status = 'returned') AS returned, SUM(ta.status = 'todo') AS todo
            FROM trainings t JOIN users u ON u.id = t.created_by LEFT JOIN training_assignees ta ON ta.training_id = t.id
            WHERE t.status = ? GROUP BY t.id ORDER BY SUM(ta.status = 'submitted') > 0 DESC, t.due_date IS NULL, t.due_date DESC, t.id DESC LIMIT 200",
            [$closed ? 'closed' : 'open'])->fetchAll();
        foreach ($rows as &$r) {
            foreach (['id', 'assignees', 'done', 'submitted', 'returned', 'todo'] as $k) {
                $r[$k] = (int)$r[$k];
            }
        }
        json_out(['ok' => true, 'items' => $rows]);
    }
    $rows = q("SELECT t.id, t.title, t.description, t.due_date, t.status AS training_status, ta.status, ta.submitted_at, ta.review_note, ta.reviewed_at
        FROM training_assignees ta JOIN trainings t ON t.id = ta.training_id WHERE ta.user_id = ? AND (t.status = 'open' OR ta.status = 'done')
        ORDER BY ta.status = 'returned' DESC, ta.status = 'todo' DESC, t.due_date IS NULL, t.due_date, t.id DESC LIMIT 200", [$me['id']])->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function load_training(int $id, array $me): array
{
    $t = q('SELECT t.*, u.name AS created_by_name FROM trainings t JOIN users u ON u.id = t.created_by WHERE t.id = ?', [$id])->fetch();
    if (!$t) {
        fail('Training not found.', 404);
    }
    $t['id'] = (int)$t['id'];
    $w = is_admin($me) ? '' : ' AND ta.user_id = ' . (int)$me['id'];
    $t['assignees'] = q("SELECT ta.*, u.name AS user_name, rb.name AS reviewed_by_name FROM training_assignees ta JOIN users u ON u.id = ta.user_id
        LEFT JOIN users rb ON rb.id = ta.reviewed_by WHERE ta.training_id = ?$w ORDER BY u.name", [$id])->fetchAll();
    if (!is_admin($me) && !$t['assignees']) {
        fail('This training wasn\'t given to you.', 403);
    }
    $proof = attachments_for('trainproof', array_column($t['assignees'], 'id'));
    foreach ($t['assignees'] as &$a) {
        $a['id'] = (int)$a['id'];
        $a['user_id'] = (int)$a['user_id'];
        $a['files'] = $proof[$a['id']] ?? [];
    }
    unset($a);
    $t['files'] = attachments_for('trainref', [$id])[$id] ?? [];
    return $t;
}

function act_training_get(array $in, array $me): void
{
    json_out(['ok' => true, 'training' => load_training((int)($in['id'] ?? 0), $me)]);
}

/** Staff submit what they did to learn it: a note plus screenshots, photos or a certificate (or a link in the note). */
function act_training_submit(array $in, array $me): void
{
    $t = load_training((int)($in['id'] ?? 0), $me);
    $a = current(array_filter($t['assignees'], fn($x) => $x['user_id'] === $me['id']));
    if (!$a) {
        fail('This training wasn\'t given to you.', 403);
    }
    if ($t['status'] !== 'open') {
        fail('This training has been closed.');
    }
    if (!in_array($a['status'], ['todo', 'returned'], true)) {
        fail($a['status'] === 'done' ? 'This training is already marked done.' : 'You\'ve already submitted this. Wait for the review.');
    }
    $note = str_in($in['proof_note'] ?? '', 4000);
    $e = [];
    if (mb_strlen($note) < TRAINING_MIN_PROOF) {
        $e['proof_note'] = 'Say what you learned and what you built or practised.';
    }
    if (!$a['files'] && !uploaded_files('proof') && !preg_match('~https?://~i', $note)) {
        $e['proof'] = 'Add a screenshot, photo or certificate, or paste a link to your work.';
    }
    if ($e) {
        lesson_fields_fail($e);
    }
    if ($err = check_uploads('proof', count($a['files']))) {
        fail($err);
    }
    q("UPDATE training_assignees SET status = 'submitted', proof_note = ?, submitted_at = NOW() WHERE id = ?", [$note, $a['id']]);
    save_uploads('trainproof', $a['id'], $me['id'], 'proof');
    audit('training_submit', 'training', $t['id'], ['was' => $a['status']]);
    touch_change();
    notify_admins('training', $me['name'] . ' finished training: ' . $t['title'], mb_substr($note, 0, 200), 'lessons/training/' . $t['id'], false);
    json_out(['ok' => true]);
}

/** Admin marks a staff member's training done, or returns it with a note. */
function act_training_review(array $in, array $me): void
{
    require_admin();
    $a = q('SELECT ta.*, t.title FROM training_assignees ta JOIN trainings t ON t.id = ta.training_id WHERE ta.id = ?', [(int)($in['assignee_id'] ?? 0)])->fetch();
    if (!$a) {
        fail('Not found.', 404);
    }
    $decision = ($in['decision'] ?? '') === 'return' ? 'returned' : 'done';
    $note = str_in($in['note'] ?? '', 2000);
    if ($decision === 'returned' && $note === '') {
        fail('Say what they still need to do.');
    }
    q('UPDATE training_assignees SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$decision, $note, $me['id'], $a['id']]);
    audit('training_' . ($decision === 'done' ? 'done' : 'return'), 'training', (int)$a['training_id'], ['user_id' => (int)$a['user_id'], 'note' => $note]);
    touch_change();
    notify([(int)$a['user_id']], 'training', $decision === 'done' ? 'Training complete: ' . $a['title'] : 'Training returned: ' . $a['title'],
        $note ?: ($decision === 'done' ? '' : 'See what to add and resubmit.'), 'lessons/training/' . $a['training_id'], $decision === 'returned');
    json_out(['ok' => true, 'status' => $decision]);
}

/** Close (or reopen) a training item; closed items leave staff to-do lists. */
function act_training_close(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $status = !empty($in['reopen']) ? 'open' : 'closed';
    q('UPDATE trainings SET status = ? WHERE id = ?', [$status, $id]);
    audit('training_' . ($status === 'open' ? 'reopen' : 'close'), 'training', $id);
    touch_change();
    json_out(['ok' => true, 'status' => $status]);
}
