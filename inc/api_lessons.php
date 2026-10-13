<?php
/**
 * Lesson delivery evidence.
 * A lesson plan is one topic/project, prepared once: the plan plus screenshots of the staff member's own finished
 * project (owner_type 'lprep'; an optional plan document is 'lplan'). It must be submitted before any class is logged.
 * Each class taught is a session against a plan, at any school and on any day, with photos of the class at work
 * ('lclass') and of the children's projects ('lwork'), pupil counts and feedback.
 * status: submitted → approved | returned (fix and resubmit → submitted). A session can also be cancelled with a reason.
 */

const LESSON_OUTCOMES = ['Successful', 'Partly successful', 'Not successful'];
const LESSON_MIN_CLASS_PHOTOS = 2;
const LESSON_MIN_WORK_PHOTOS = 1;
const LESSON_MIN_ACHIEVED = 20; // characters
const LESSON_FLAG_TEXT = ['prep_late' => 'prepared after arriving', 'no_clockin' => 'no clock-in at this school'];

function lesson_subjects(): array
{
    return array_values(setting('lesson_subjects') ?: ['Coding', 'Robotics', 'Other']);
}

function class_levels(): array
{
    return array_values(setting('class_levels') ?: ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3']);
}

function lesson_plan_ref(int $id): string
{
    return 'LP-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** The canonical school/location name, or ''. */
function lesson_school(string $name): string
{
    $want = mb_strtolower(trim($name));
    foreach (location_options() as $n) {
        if (mb_strtolower($n) === $want) {
            return $n;
        }
    }
    return '';
}

function lesson_client_id(string $school): ?int
{
    return $school !== '' ? ((int)q('SELECT id FROM clients WHERE name = ?', [$school])->fetchColumn() ?: null) : null;
}

function lesson_nint($v): ?int
{
    return is_numeric($v) && (int)$v >= 0 ? min(10000, (int)$v) : null;
}

const LESSON_PLAN_SQL = 'SELECT p.*, u.name AS user_name, rb.name AS reviewed_by_name,
    (SELECT COUNT(*) FROM lesson_sessions s WHERE s.plan_id = p.id AND s.status <> \'cancelled\') AS classes
    FROM lesson_plans p JOIN users u ON u.id = p.user_id LEFT JOIN users rb ON rb.id = p.reviewed_by';

const LESSON_SESSION_SQL = 'SELECT s.*, u.name AS user_name, p.topic, p.subject, p.project_title, p.submitted_at AS plan_submitted_at,
    p.status AS plan_status, rb.name AS reviewed_by_name
    FROM lesson_sessions s JOIN lesson_plans p ON p.id = s.plan_id JOIN users u ON u.id = s.user_id LEFT JOIN users rb ON rb.id = s.reviewed_by';

function lesson_plan_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['user_id'] = (int)$r['user_id'];
    $r['ref'] = lesson_plan_ref($r['id']);
    $r['classes'] = (int)($r['classes'] ?? 0);
    return $r;
}

function lesson_session_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['plan_id'] = (int)$r['plan_id'];
    $r['user_id'] = (int)$r['user_id'];
    $r['plan_ref'] = lesson_plan_ref($r['plan_id']);
    foreach (['students_total', 'students_present', 'students_completed'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    }
    return $r;
}

/** Adds photo lists to sessions: class (activity) and work (children's projects). */
function lesson_sessions_with_photos(array $rows): array
{
    $ids = array_column($rows, 'id');
    $class = attachments_for('lclass', $ids);
    $work = attachments_for('lwork', $ids);
    foreach ($rows as &$r) {
        $r['class_photos'] = $class[$r['id']] ?? [];
        $r['work_photos'] = $work[$r['id']] ?? [];
    }
    return $rows;
}

function load_lesson_plan(int $id, array $me): array
{
    $r = q(LESSON_PLAN_SQL . ' WHERE p.id = ?', [$id])->fetch();
    if (!$r) {
        fail('Lesson plan not found.', 404);
    }
    if (!is_admin($me) && (int)$r['user_id'] !== $me['id']) {
        fail('You can only open your own lesson plans.', 403);
    }
    return lesson_plan_row($r);
}

function load_lesson_session(int $id, array $me): array
{
    $r = q(LESSON_SESSION_SQL . ' WHERE s.id = ?', [$id])->fetch();
    if (!$r) {
        fail('Class record not found.', 404);
    }
    if (!is_admin($me) && (int)$r['user_id'] !== $me['id']) {
        fail('You can only open your own classes.', 403);
    }
    return lesson_session_row($r);
}

function lesson_fields_fail(array $errors): void
{
    json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
}

/**
 * The clock-in visit at this school that day, and the flags for preparation:
 * prep_late when the plan was submitted after arriving at the school (or after the class day), no_clockin when the
 * staff member wasn't clocked in there that day.
 */
function lesson_session_checks(int $uid, string $school, string $date, string $planSubmittedAt): array
{
    $visit = q('SELECT id, in_at FROM attendance WHERE user_id = ? AND work_date = ? AND LOWER(location) = LOWER(?) ORDER BY in_at LIMIT 1',
        [$uid, $date, $school])->fetch();
    $flags = [];
    if (!$visit) {
        $flags[] = 'no_clockin';
    }
    if (substr($planSubmittedAt, 0, 10) > $date || ($visit && $planSubmittedAt > $visit['in_at'])) {
        $flags[] = 'prep_late';
    }
    return [$visit ? (int)$visit['id'] : null, implode(',', $flags)];
}

/* --------------------------------------------------------------- lists */

/** Pulse badge: admins see items waiting for review; staff see items returned to them. */
function lesson_badge(array $me): int
{
    if (is_admin($me)) {
        return (int)q("SELECT (SELECT COUNT(*) FROM lesson_plans WHERE status = 'submitted') + (SELECT COUNT(*) FROM lesson_sessions WHERE status = 'submitted')")->fetchColumn();
    }
    return (int)q("SELECT (SELECT COUNT(*) FROM lesson_plans WHERE user_id = ? AND status = 'returned') + (SELECT COUNT(*) FROM lesson_sessions WHERE user_id = ? AND status = 'returned')",
        [$me['id'], $me['id']])->fetchColumn();
}

/** Staff home for lessons: their plans (newest first, with class counts) and their recent classes. Admin: filtered lists. */
function act_lessons_list(array $in, array $me): void
{
    $admin = is_admin($me);
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime('-30 days'));
    $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
    $pw = ['(p.planned_date IS NULL OR p.planned_date BETWEEN ? AND ? OR p.status = \'returned\' OR DATE(p.submitted_at) BETWEEN ? AND ?)'];
    $pp = [$from, $to, $from, $to];
    $sw = ['s.session_date BETWEEN ? AND ?'];
    $sp = [$from, $to];
    $uid = $admin ? (int)($in['user_id'] ?? 0) : $me['id'];
    if ($uid) {
        $pw[] = 'p.user_id = ?';
        $pp[] = $uid;
        $sw[] = 's.user_id = ?';
        $sp[] = $uid;
    }
    if (($in['school'] ?? '') !== '') {
        $pw[] = 'p.school = ?';
        $pp[] = $in['school'];
        $sw[] = 's.school = ?';
        $sp[] = $in['school'];
    }
    if (($in['subject'] ?? '') !== '') {
        $pw[] = 'p.subject = ?';
        $pp[] = $in['subject'];
        $sw[] = 'p.subject = ?';
        $sp[] = $in['subject'];
    }
    $status = (string)($in['status'] ?? '');
    if (in_array($status, ['submitted', 'approved', 'returned', 'cancelled'], true)) {
        $pw[] = 'p.status = ?';
        $pp[] = $status;
        $sw[] = 's.status = ?';
        $sp[] = $status;
    }
    if (isset(LESSON_FLAG_TEXT[$in['flag'] ?? ''])) {
        $sw[] = 'FIND_IN_SET(?, s.flags)';
        $sp[] = $in['flag'];
    }
    $plans = array_map('lesson_plan_row', q(LESSON_PLAN_SQL . ' WHERE ' . implode(' AND ', $pw)
        . " ORDER BY p.status = 'returned' DESC, p.submitted_at DESC LIMIT 300", $pp)->fetchAll());
    $sessions = lesson_sessions_with_photos(array_map('lesson_session_row', q(LESSON_SESSION_SQL . ' WHERE ' . implode(' AND ', $sw)
        . " ORDER BY s.status = 'returned' DESC, s.session_date DESC, s.id DESC LIMIT 500", $sp)->fetchAll()));
    $prep = attachments_for('lprep', array_column($plans, 'id'));
    foreach ($plans as &$p) {
        $p['prep_photos'] = count($prep[$p['id']] ?? []);
        $p['thumb'] = $prep[$p['id']][0]['id'] ?? null;
    }
    unset($p);
    json_out(['ok' => true, 'from' => $from, 'to' => $to, 'plans' => $plans, 'sessions' => $sessions,
        'subjects' => lesson_subjects(), 'class_levels' => class_levels(), 'outcomes' => LESSON_OUTCOMES]);
}

/** Plans a staff member can log classes against: their own, not returned, from the last 120 days. */
function act_lesson_plans_open(array $in, array $me): void
{
    $rows = q(LESSON_PLAN_SQL . " WHERE p.user_id = ? AND p.status IN ('submitted','approved') AND p.submitted_at >= ? ORDER BY p.submitted_at DESC LIMIT 100",
        [$me['id'], date('Y-m-d', strtotime('-120 days'))])->fetchAll();
    json_out(['ok' => true, 'items' => array_map('lesson_plan_row', $rows)]);
}

function act_lesson_plan_get(array $in, array $me): void
{
    $p = load_lesson_plan((int)($in['id'] ?? 0), $me);
    $p['prep_photos'] = attachments_for('lprep', [$p['id']])[$p['id']] ?? [];
    $p['plan_files'] = attachments_for('lplan', [$p['id']])[$p['id']] ?? [];
    $p['sessions'] = lesson_sessions_with_photos(array_map('lesson_session_row',
        q(LESSON_SESSION_SQL . ' WHERE s.plan_id = ? ORDER BY s.session_date, s.id', [$p['id']])->fetchAll()));
    json_out(['ok' => true, 'plan' => $p, 'outcomes' => LESSON_OUTCOMES]);
}

/* ---------------------------------------------------------------- save */

/** Create or fix a lesson plan. New plans need at least one screenshot/photo of the staff member's own finished project. */
function act_lesson_plan_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? load_lesson_plan($id, $me) : null;
    if ($old && (int)$old['user_id'] !== $me['id']) {
        fail('Only the staff member who wrote this plan can change it.', 403);
    }
    if ($old && $old['status'] === 'approved') {
        fail('This plan has been approved, so it can\'t be changed.');
    }
    $school = lesson_school((string)($in['school'] ?? ''));
    $subject = in_array($in['subject'] ?? '', lesson_subjects(), true) ? $in['subject'] : '';
    $topic = str_in($in['topic'] ?? '', 190);
    $project = str_in($in['project_title'] ?? '', 190);
    $objectives = str_in($in['objectives'] ?? '', 4000);
    $activities = str_in($in['activities'] ?? '', 6000);
    $materials = str_in($in['materials'] ?? '', 2000);
    $date = valid_date($in['planned_date'] ?? null) ? $in['planned_date'] : null;
    $time = preg_match('/^\d{2}:\d{2}$/', (string)($in['planned_time'] ?? '')) ? $in['planned_time'] : '';
    $e = [];
    if ($school === '') {
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
        $e['planned_date'] = 'Pick the date you\'ll teach it.';
    }
    $havePrep = $old ? count(attachments_for('lprep', [$old['id']])[$old['id']] ?? []) : 0;
    if ($havePrep + count(uploaded_files('prep')) < 1) {
        $e['prep'] = 'Upload at least one screenshot or photo of the project you built yourself.';
    }
    if ($e) {
        lesson_fields_fail($e);
    }
    if (($err = check_uploads('prep', $havePrep)) || ($err = check_uploads('plan'))) {
        fail($err);
    }
    $clientId = lesson_client_id($school);
    db()->beginTransaction();
    if ($old) {
        q("UPDATE lesson_plans SET school = ?, client_id = ?, subject = ?, topic = ?, project_title = ?, objectives = ?, activities = ?, materials = ?,
            planned_date = ?, planned_time = ?, status = 'submitted', updated_at = NOW() WHERE id = ?",
            [$school, $clientId, $subject, $topic, $project, $objectives, $activities, $materials, $date, $time, $id]);
        audit('lesson_plan_update', 'lesson_plan', $id, ['topic' => $topic, 'was' => $old['status']]);
    } else {
        q("INSERT INTO lesson_plans (user_id, client_id, school, subject, topic, project_title, objectives, activities, materials, planned_date, planned_time,
            status, submitted_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,'submitted',NOW(),NOW(),NOW())",
            [$me['id'], $clientId, $school, $subject, $topic, $project, $objectives, $activities, $materials, $date, $time]);
        $id = (int)db()->lastInsertId();
        audit('lesson_plan_create', 'lesson_plan', $id, ['topic' => $topic]);
    }
    save_uploads('lprep', $id, $me['id'], 'prep');
    save_uploads('lplan', $id, $me['id'], 'plan');
    db()->commit();
    touch_change();
    if ($old && $old['status'] === 'returned') {
        notify_admins('lesson', $me['name'] . ' fixed lesson plan ' . lesson_plan_ref($id), $topic, 'lessons/plan/' . $id, false);
    }
    json_out(['ok' => true, 'id' => $id, 'ref' => lesson_plan_ref($id)]);
}

/**
 * Log one or more classes taught from a plan (several classes in one go), or fix one class.
 * in: plan_id, sessions: [{id?, school, class_name, session_date, students_total, students_present, students_completed,
 *     outcome, achieved, challenges, support_needed, cancelled, cancel_reason}]
 * Photos come in per row as file fields class_{i}[] and work_{i}[].
 */
function act_lesson_sessions_save(array $in, array $me): void
{
    $plan = load_lesson_plan((int)($in['plan_id'] ?? 0), $me);
    if ($plan['user_id'] !== $me['id']) {
        fail('Classes are logged by the staff member who prepared the plan.', 403);
    }
    if (!in_array($plan['status'], ['submitted', 'approved'], true)) {
        fail('This lesson plan was returned to you. Fix and resubmit it before logging classes.');
    }
    $rows = array_values(array_filter((array)($in['sessions'] ?? []), 'is_array'));
    if (!$rows) {
        fail('Add at least one class.');
    }
    if (count($rows) > 12) {
        fail('Log up to 12 classes at a time.');
    }
    $errors = [];
    $clean = [];
    foreach ($rows as $i => $r) {
        $old = !empty($r['id']) ? load_lesson_session((int)$r['id'], $me) : null;
        if ($old && ($old['user_id'] !== $me['id'] || $old['plan_id'] !== $plan['id'])) {
            fail('That class belongs to another plan.', 403);
        }
        if ($old && $old['status'] === 'approved') {
            fail('An approved class can\'t be changed.');
        }
        $cancelled = !empty($r['cancelled']);
        $x = [
            'old' => $old,
            'school' => lesson_school((string)($r['school'] ?? '')),
            'class_name' => str_in($r['class_name'] ?? '', 120),
            'session_date' => valid_date($r['session_date'] ?? null) ? $r['session_date'] : '',
            'students_total' => lesson_nint($r['students_total'] ?? null),
            'students_present' => lesson_nint($r['students_present'] ?? null),
            'students_completed' => lesson_nint($r['students_completed'] ?? null),
            'outcome' => in_array($r['outcome'] ?? '', LESSON_OUTCOMES, true) ? $r['outcome'] : '',
            'achieved' => str_in($r['achieved'] ?? '', 4000),
            'challenges' => str_in($r['challenges'] ?? '', 4000),
            'support_needed' => str_in($r['support_needed'] ?? '', 2000),
            'cancelled' => $cancelled,
            'cancel_reason' => str_in($r['cancel_reason'] ?? '', 500),
        ];
        $e = [];
        if ($x['school'] === '') {
            $e['school'] = 'Choose the school.';
        }
        if ($x['class_name'] === '') {
            $e['class_name'] = 'Enter the class, e.g. Grade 5 or JSS1 A.';
        }
        if ($x['session_date'] === '' || $x['session_date'] > today()) {
            $e['session_date'] = 'Pick the day you taught it (not a future date).';
        }
        if ($cancelled) {
            if ($x['cancel_reason'] === '') {
                $e['cancel_reason'] = 'Say why the class didn\'t hold.';
            }
        } else {
            $nClass = ($old ? count(attachments_for('lclass', [$old['id']])[$old['id']] ?? []) : 0) + count(uploaded_files("class_$i"));
            $nWork = ($old ? count(attachments_for('lwork', [$old['id']])[$old['id']] ?? []) : 0) + count(uploaded_files("work_$i"));
            if ($x['students_present'] === null || $x['students_present'] < 1) {
                $e['students_present'] = 'Enter how many pupils were in class.';
            }
            if ($x['students_total'] !== null && $x['students_present'] !== null && $x['students_present'] > $x['students_total']) {
                $e['students_present'] = 'More pupils present than in the class.';
            }
            if ($x['students_completed'] === null) {
                $e['students_completed'] = 'Enter how many finished the project.';
            } elseif ($x['students_present'] !== null && $x['students_completed'] > $x['students_present']) {
                $e['students_completed'] = 'More pupils finished than were present.';
            }
            if ($x['outcome'] === '') {
                $e['outcome'] = 'How did the lesson go?';
            }
            if (mb_strlen($x['achieved']) < LESSON_MIN_ACHIEVED) {
                $e['achieved'] = 'Describe what the pupils achieved in a sentence or two.';
            }
            if ($nClass < LESSON_MIN_CLASS_PHOTOS) {
                $e['class_photos'] = 'Add at least ' . LESSON_MIN_CLASS_PHOTOS . ' photos of the class at work.';
            }
            if ($nWork < LESSON_MIN_WORK_PHOTOS) {
                $e['work_photos'] = 'Add at least one photo of the pupils\' projects.';
            }
            $existing = $old ? count(attachments_for('lclass', [$old['id']])[$old['id']] ?? []) : 0;
            if ($err = check_uploads("class_$i", $existing)) {
                $e['class_photos'] = $err;
            }
            $existing = $old ? count(attachments_for('lwork', [$old['id']])[$old['id']] ?? []) : 0;
            if ($err = check_uploads("work_$i", $existing)) {
                $e['work_photos'] = $err;
            }
        }
        foreach ($e as $k => $msg) {
            $errors["sessions.$i.$k"] = $msg;
        }
        $clean[$i] = $x;
    }
    if ($errors) {
        lesson_fields_fail($errors);
    }

    db()->beginTransaction();
    $ids = [];
    foreach ($clean as $i => $x) {
        [$attId, $flags] = lesson_session_checks($me['id'], $x['school'], $x['session_date'], (string)$plan['submitted_at']);
        $status = $x['cancelled'] ? 'cancelled' : 'submitted';
        if ($x['cancelled']) {
            $flags = ''; // nothing was taught, so preparation doesn't apply
        }
        $vals = [$x['school'], lesson_client_id($x['school']), $x['class_name'], $x['session_date'], $attId, $x['students_total'], $x['students_present'],
            $x['students_completed'], $x['outcome'], $x['achieved'], $x['challenges'], $x['support_needed'], $flags, $status, $x['cancel_reason']];
        if ($x['old']) {
            $sid = $x['old']['id'];
            q('UPDATE lesson_sessions SET school = ?, client_id = ?, class_name = ?, session_date = ?, attendance_id = ?, students_total = ?, students_present = ?,
                students_completed = ?, outcome = ?, achieved = ?, challenges = ?, support_needed = ?, flags = ?, status = ?, cancel_reason = ? WHERE id = ?',
                array_merge($vals, [$sid]));
            audit('lesson_session_update', 'lesson_session', $sid, ['was' => $x['old']['status']]);
        } else {
            q('INSERT INTO lesson_sessions (school, client_id, class_name, session_date, attendance_id, students_total, students_present, students_completed,
                outcome, achieved, challenges, support_needed, flags, status, cancel_reason, plan_id, user_id, delivered_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())', array_merge($vals, [$plan['id'], $me['id']]));
            $sid = (int)db()->lastInsertId();
            audit('lesson_session_create', 'lesson_session', $sid, ['class' => $x['class_name'], 'flags' => $flags]);
        }
        if (!$x['cancelled']) {
            save_uploads('lclass', $sid, $me['id'], "class_$i");
            save_uploads('lwork', $sid, $me['id'], "work_$i");
        }
        $ids[] = $sid;
    }
    db()->commit();
    touch_change();
    $fixed = array_filter($clean, fn($x) => $x['old'] && $x['old']['status'] === 'returned');
    if ($fixed) {
        notify_admins('lesson', $me['name'] . ' fixed ' . implode(', ', array_map(fn($x) => $x['class_name'], $fixed)) . ' (' . $plan['topic'] . ')',
            'Ready for review again.', 'lessons/plan/' . $plan['id'], false);
    }
    json_out(['ok' => true, 'ids' => $ids]);
}

/* -------------------------------------------------------------- review */

/** Admin approves or returns a plan or a class with a note. Returning needs a note saying what to fix. */
function act_lesson_review(array $in, array $me): void
{
    require_admin();
    $type = ($in['type'] ?? '') === 'plan' ? 'plan' : 'session';
    $decision = ($in['decision'] ?? '') === 'return' ? 'returned' : 'approved';
    $note = str_in($in['note'] ?? '', 2000);
    if ($decision === 'returned' && $note === '') {
        fail('Say what needs fixing.');
    }
    if ($type === 'plan') {
        $r = load_lesson_plan((int)($in['id'] ?? 0), $me);
        $table = 'lesson_plans';
        $title = 'Lesson plan ' . $r['ref'] . ' (' . $r['topic'] . ')';
        $link = 'lessons/plan/' . $r['id'];
    } else {
        $r = load_lesson_session((int)($in['id'] ?? 0), $me);
        if ($r['status'] === 'cancelled' && $decision === 'approved') {
            $decision = 'cancelled'; // accepting a cancellation keeps it cancelled
        }
        $table = 'lesson_sessions';
        $title = 'Class ' . $r['class_name'] . ' at ' . $r['school'] . ', ' . date('j M', strtotime($r['session_date']));
        $link = 'lessons/plan/' . $r['plan_id'];
    }
    q("UPDATE $table SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$decision, $note, $me['id'], $r['id']]);
    audit('lesson_' . ($decision === 'returned' ? 'return' : 'approve'), 'lesson_' . $type, $r['id'], ['note' => $note]);
    touch_change();
    notify([$r['user_id']], 'lesson', $decision === 'returned' ? "$title was returned to you" : "$title was approved",
        $note ?: ($decision === 'returned' ? 'Fix it and resubmit.' : ''), $link, $decision === 'returned');
    json_out(['ok' => true, 'status' => $decision]);
}

/* ---------------------------------------------------------- compliance */

/** Clock-in visits tagged "Class / lesson delivery" with no class logged at that school that day. */
function teaching_visits_without_evidence(string $from, string $to, ?int $uid = null): array
{
    $p = [DUTY_CLASS, $from, $to];
    $w = '';
    if ($uid) {
        $w = ' AND a.user_id = ?';
        $p[] = $uid;
    }
    return q("SELECT a.id, a.user_id, u.name AS user_name, a.work_date, a.location, a.in_at, a.out_at FROM attendance a JOIN users u ON u.id = a.user_id
        WHERE a.work_type = ? AND a.work_date BETWEEN ? AND ?$w AND NOT EXISTS (SELECT 1 FROM lesson_sessions s
            WHERE s.user_id = a.user_id AND s.session_date = a.work_date AND LOWER(s.school) = LOWER(a.location))
        ORDER BY a.work_date DESC, u.name", $p)->fetchAll();
}

/** Per staff member over a range: plans, classes, pupils reached, preparation and evidence, outstanding returns. */
function lesson_compliance(string $from, string $to, ?int $uid = null): array
{
    $w = "u.active = 1 AND u.role = 'staff'";
    $p = [];
    if ($uid) {
        $w = 'u.id = ?';
        $p[] = $uid;
    }
    $staff = q("SELECT u.id, u.name FROM users u WHERE $w ORDER BY u.name", $p)->fetchAll();
    $plans = q("SELECT user_id, COUNT(*) AS n, SUM(status = 'approved') AS approved, SUM(status = 'returned') AS returned
        FROM lesson_plans WHERE DATE(submitted_at) BETWEEN ? AND ? GROUP BY user_id", [$from, $to])->fetchAll(PDO::FETCH_UNIQUE);
    $sess = q("SELECT user_id, SUM(status <> 'cancelled') AS n, SUM(status = 'cancelled') AS cancelled, SUM(status = 'approved') AS approved,
        SUM(status = 'returned') AS returned, SUM(status <> 'cancelled' AND NOT FIND_IN_SET('prep_late', flags)) AS prepared,
        SUM(status <> 'cancelled' AND FIND_IN_SET('no_clockin', flags)) AS no_clockin,
        COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN students_present END), 0) AS pupils,
        COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN students_completed END), 0) AS completed,
        COUNT(DISTINCT CASE WHEN status <> 'cancelled' THEN school END) AS schools
        FROM lesson_sessions WHERE session_date BETWEEN ? AND ? GROUP BY user_id", [$from, $to])->fetchAll(PDO::FETCH_UNIQUE);
    $gaps = [];
    foreach (teaching_visits_without_evidence($from, $to, $uid) as $g) {
        $gaps[(int)$g['user_id']][] = $g;
    }
    $rows = [];
    foreach ($staff as $s) {
        $sid = (int)$s['id'];
        $pl = $plans[$sid] ?? [];
        $se = $sess[$sid] ?? [];
        $n = (int)($se['n'] ?? 0);
        $rows[] = [
            'id' => $sid, 'name' => $s['name'],
            'plans' => (int)($pl['n'] ?? 0), 'plans_approved' => (int)($pl['approved'] ?? 0),
            'classes' => $n, 'classes_approved' => (int)($se['approved'] ?? 0), 'cancelled' => (int)($se['cancelled'] ?? 0),
            'returned' => (int)($pl['returned'] ?? 0) + (int)($se['returned'] ?? 0),
            'pupils' => (int)($se['pupils'] ?? 0), 'completed' => (int)($se['completed'] ?? 0), 'schools' => (int)($se['schools'] ?? 0),
            'prepared_pct' => $n ? (int)round((int)$se['prepared'] / $n * 100) : null,
            'completion_pct' => !empty($se['pupils']) ? (int)round((int)$se['completed'] / (int)$se['pupils'] * 100) : null,
            'no_clockin' => (int)($se['no_clockin'] ?? 0),
            'gaps' => count($gaps[$sid] ?? []),
        ];
    }
    return ['rows' => $rows, 'gaps' => array_merge(...array_values($gaps ?: [[]]))];
}

function act_lessons_compliance(array $in, array $me): void
{
    [$from, $to] = report_range($in);
    $uid = is_admin($me) ? ((int)($in['user_id'] ?? 0) ?: null) : $me['id'];
    json_out(['ok' => true, 'from' => $from, 'to' => $to] + lesson_compliance($from, $to, $uid));
}

/** Per school: classes taught, topics covered, staff and pupils, and the latest photos. */
function act_lessons_schools(array $in, array $me): void
{
    require_admin();
    [$from, $to] = report_range($in);
    $rows = q("SELECT s.school, COUNT(*) AS classes, COUNT(DISTINCT s.class_name) AS class_groups, COALESCE(SUM(s.students_present), 0) AS pupils,
        GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS staff, MAX(s.session_date) AS last_date
        FROM lesson_sessions s JOIN users u ON u.id = s.user_id WHERE s.status <> 'cancelled' AND s.session_date BETWEEN ? AND ?
        GROUP BY s.school ORDER BY s.school", [$from, $to])->fetchAll();
    $topics = q("SELECT s.school, s.session_date, s.class_name, p.topic, p.project_title, p.subject, s.id FROM lesson_sessions s JOIN lesson_plans p ON p.id = s.plan_id
        WHERE s.status <> 'cancelled' AND s.session_date BETWEEN ? AND ? ORDER BY s.session_date DESC, s.id DESC", [$from, $to])->fetchAll();
    $photos = attachments_for('lwork', array_column($topics, 'id'));
    $bySchool = [];
    foreach ($topics as $t) {
        $b = &$bySchool[$t['school']];
        $b['topics'][] = ['date' => $t['session_date'], 'class_name' => $t['class_name'], 'topic' => $t['topic'], 'project' => $t['project_title'], 'subject' => $t['subject']];
        foreach ($photos[(int)$t['id']] ?? [] as $f) {
            if (count($b['photos'] ?? []) < 8) {
                $b['photos'][] = $f;
            }
        }
        unset($b);
    }
    foreach ($rows as &$r) {
        $r += ['topics' => $bySchool[$r['school']]['topics'] ?? [], 'photos' => $bySchool[$r['school']]['photos'] ?? []];
    }
    json_out(['ok' => true, 'from' => $from, 'to' => $to, 'items' => $rows]);
}

/** Today's classes logged by a staff member, for the clock-out notice and the daily report. */
function lessons_today(int $uid): array
{
    return q("SELECT s.id, s.school, s.class_name, s.status, p.topic, p.subject FROM lesson_sessions s JOIN lesson_plans p ON p.id = s.plan_id
        WHERE s.user_id = ? AND s.session_date = ? AND s.status <> 'cancelled' ORDER BY s.id", [$uid, today()])->fetchAll();
}
