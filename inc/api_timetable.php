<?php
/*
 * Class timetables: each school's weekly periods (day, time, class, subject, teacher). Staff keep their own periods up
 * to date and see the whole timetable of the schools they're assigned to; admins edit any school. A period ties into
 * lesson plans (posted before the class, matched by slot_id or by school + subject + class + date) and the classes
 * taught (lesson_sessions), which together make each class's record: taught, this week, coming up.
 */

const TT_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
/** Plan statuses that count as "posted": written by the teacher (an admin-posted lesson not yet prepared doesn't count). */
const TT_PLAN_POSTED = "('submitted','approved','returned')";

/** Schools a person can see timetables for: their own, or every school for admins. */
function tt_schools(array $me): array
{
    if (is_management($me)) {
        return array_values(array_filter(location_options(), fn($n) => !is_office_location($n)));
    }
    return array_values(array_filter(array_map('lesson_school', $me['schools']), fn($n) => $n !== ''));
}

function tt_can_view(string $school, array $me): bool
{
    return in_array(mb_strtolower($school), array_map('mb_strtolower', tt_schools($me)), true);
}

function tt_load_slot(int $id, array $me): array
{
    $s = q('SELECT t.*, u.name AS teacher_name FROM timetable_slots t LEFT JOIN users u ON u.id = t.teacher_id WHERE t.id = ? AND t.active = 1', [$id])->fetch();
    if (!$s) {
        fail('Period not found.', 404);
    }
    if (!tt_can_view($s['school'], $me)) {
        fail('This school\'s timetable isn\'t open to you.', 403);
    }
    return tt_slot_row($s, $me);
}

/** Staff can change only the periods they teach; admins any. */
function tt_can_edit(array $slot, array $me): bool
{
    return is_management($me) || (int)$slot['teacher_id'] === $me['id'];
}

function tt_slot_row(array $s, array $me): array
{
    foreach (['id', 'day', 'teacher_id', 'client_id'] as $k) {
        $s[$k] = $s[$k] === null ? null : (int)$s[$k];
    }
    $s['start_time'] = hhmm($s['start_time']);
    $s['end_time'] = hhmm($s['end_time']);
    $s['day_name'] = TT_DAYS[$s['day']] ?? '';
    $s['mine'] = $s['teacher_id'] === $me['id'];
    $s['can_edit'] = tt_can_edit($s, $me);
    return $s;
}

/** Dates in [$from, $to] that fall on the period's weekday. */
function slot_occurrences(array $slot, string $from, string $to): array
{
    $out = [];
    $t = strtotime($from);
    $t += ((((int)$slot['day'] - (int)date('N', $t)) + 7) % 7) * 86400;
    for (; $t <= strtotime($to); $t += 7 * 86400) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** The period's next class: today if it hasn't ended yet, otherwise its next weekday. */
function slot_next_date(array $slot): string
{
    $today = today();
    if ((int)date('N') === (int)$slot['day'] && time() < strtotime($today . ' ' . $slot['end_time'])) {
        return $today;
    }
    return slot_occurrences($slot, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+7 days')))[0];
}

/**
 * The lesson plan posted for a period on a date: one posted from this period, or the teacher's plan for the same
 * school, subject and date (and class, when the plan names one).
 */
function slot_plan_for(array $slot, string $date): ?array
{
    $p = q('SELECT id, topic, project_title, status, planned_date, planned_time FROM lesson_plans WHERE planned_date = ? AND status IN ' . TT_PLAN_POSTED . '
        AND (slot_id = ? OR (slot_id IS NULL AND user_id = ? AND LOWER(school) = LOWER(?) AND subject = ? AND (class_name = \'\' OR LOWER(class_name) = LOWER(?))))
        ORDER BY slot_id = ? DESC, id DESC LIMIT 1',
        [$date, $slot['id'], (int)$slot['teacher_id'], $slot['school'], $slot['subject'], $slot['class_name'], $slot['id']])->fetch();
    if (!$p) {
        return null;
    }
    $p['id'] = (int)$p['id'];
    $p['ref'] = lesson_plan_ref($p['id']);
    return $p;
}

/** A class was logged for this period on that date (by its teacher, at that school, for that class). */
function slot_logged_on(array $slot, string $date): bool
{
    return (bool)q("SELECT 1 FROM lesson_sessions WHERE user_id = ? AND session_date = ? AND LOWER(school) = LOWER(?) AND LOWER(class_name) = LOWER(?)
        AND status <> 'cancelled'", [(int)$slot['teacher_id'], $date, $slot['school'], $slot['class_name']])->fetchColumn();
}

/** Active periods with their next class and whether its plan is posted. */
function tt_slots(string $where, array $params, array $me): array
{
    $rows = q("SELECT t.*, u.name AS teacher_name FROM timetable_slots t LEFT JOIN users u ON u.id = t.teacher_id
        WHERE t.active = 1 AND $where ORDER BY t.day, t.start_time, t.class_name", $params)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $s = tt_slot_row($r, $me);
        $s['next_date'] = slot_next_date($s);
        $s['next_plan'] = $s['teacher_id'] ? slot_plan_for($s, $s['next_date']) : null;
        $out[] = $s;
    }
    return $out;
}

/** A school's timetable, or with mine=1 the caller's own periods at every school. */
function act_timetable_get(array $in, array $me): void
{
    $schools = tt_schools($me);
    if (!empty($in['mine'])) {
        $slots = tt_slots('t.teacher_id = ?', [$me['id']], $me);
        $school = '';
    } else {
        $school = lesson_school((string)($in['school'] ?? '')) ?: ($schools[0] ?? '');
        if ($school !== '' && !tt_can_view($school, $me)) {
            fail('This school\'s timetable isn\'t open to you.', 403);
        }
        $slots = $school === '' ? [] : tt_slots('LOWER(t.school) = LOWER(?)', [$school], $me);
    }
    $teachers = [];
    if (is_management($me) && $school !== '') {
        foreach (q("SELECT id, name, schools, location FROM users WHERE active = 1 ORDER BY name")->fetchAll() as $u) {
            $teachers[] = ['id' => (int)$u['id'], 'name' => $u['name'],
                'here' => in_array(mb_strtolower($school), array_map('mb_strtolower', user_schools($u)), true)];
        }
    }
    json_out(['ok' => true, 'school' => $school, 'schools' => $schools, 'slots' => $slots, 'teachers' => $teachers, 'days' => TT_DAYS]);
}

/** Add or change a period. Staff add only their own periods at their own schools. */
function act_timetable_slot_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $old = $id ? tt_load_slot($id, $me) : null;
    if ($old && !$old['can_edit']) {
        fail('Only the teacher of this period or an admin can change it.', 403);
    }
    $admin = is_management($me);
    $v = [
        'school' => lesson_school((string)($in['school'] ?? '')),
        'day' => (int)($in['day'] ?? 0),
        'start_time' => time_in($in['start_time'] ?? null),
        'end_time' => time_in($in['end_time'] ?? null),
        'class_name' => str_in($in['class_name'] ?? '', 120),
        'subject' => in_array($in['subject'] ?? '', lesson_subjects(), true) ? $in['subject'] : '',
        'teacher_id' => $admin ? ((int)($in['teacher_id'] ?? 0) ?: null) : $me['id'],
        'room' => str_in($in['room'] ?? '', 60),
        'note' => str_in($in['note'] ?? '', 500),
    ];
    $e = [];
    if ($v['school'] === '' || !tt_can_view($v['school'], $me)) {
        $e['school'] = 'Choose one of your schools.';
    }
    if (!isset(TT_DAYS[$v['day']])) {
        $e['day'] = 'Choose the day.';
    }
    if (!$v['start_time'] || !$v['end_time'] || $v['end_time'] <= $v['start_time']) {
        $e['end_time'] = 'Set a start and an end time (the end after the start).';
    }
    if ($v['class_name'] === '') {
        $e['class_name'] = 'Enter the class, e.g. JSS1 A or Grade 5.';
    }
    if ($v['subject'] === '') {
        $e['subject'] = 'Choose the subject.';
    }
    if ($v['teacher_id'] && !q('SELECT 1 FROM users WHERE id = ? AND active = 1', [$v['teacher_id']])->fetchColumn()) {
        $e['teacher_id'] = 'Choose an active staff member.';
    }
    if (!$e) {
        // Overlaps: the same teacher somewhere else, or the same class at this school.
        $overlap = 'active = 1 AND id <> ? AND day = ? AND start_time < ? AND end_time > ?';
        $op = [$id, $v['day'], $v['end_time'], $v['start_time']];
        if ($v['teacher_id'] && ($c = q("SELECT school, class_name, start_time FROM timetable_slots WHERE $overlap AND teacher_id = ?", array_merge($op, [$v['teacher_id']]))->fetch())) {
            $e['start_time'] = ($admin ? 'This teacher' : 'You') . " already ha" . ($admin ? 's' : 've') . " {$c['class_name']} at {$c['school']} at " . hhmm($c['start_time']) . ' that day.';
        } elseif ($c = q("SELECT start_time, subject FROM timetable_slots WHERE $overlap AND LOWER(school) = LOWER(?) AND LOWER(class_name) = LOWER(?)",
            array_merge($op, [$v['school'], $v['class_name']]))->fetch()) {
            $e['start_time'] = "{$v['class_name']} already has {$c['subject']} at " . hhmm($c['start_time']) . ' that day.';
        }
    }
    if ($e) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $e], 422);
    }
    $v['client_id'] = lesson_client_id($v['school']);
    if ($old) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
        q("UPDATE timetable_slots SET $set, updated_by = ?, updated_at = NOW() WHERE id = ?", array_merge(array_values($v), [$me['id'], $id]));
    } else {
        $cols = implode(', ', array_keys($v));
        q("INSERT INTO timetable_slots ($cols, created_by, updated_by, created_at, updated_at) VALUES (" . implode(',', array_fill(0, count($v), '?')) . ', ?, ?, NOW(), NOW())',
            array_merge(array_values($v), [$me['id'], $me['id']]));
        $id = (int)db()->lastInsertId();
    }
    audit('timetable_save', 'timetable_slot', $id, ['school' => $v['school'], 'class' => $v['class_name']]);
    touch_change();
    // An admin giving a staff member a period tells them.
    if ($admin && $v['teacher_id'] && $v['teacher_id'] !== $me['id'] && (!$old || $old['teacher_id'] !== $v['teacher_id'])) {
        notify([$v['teacher_id']], 'lesson', "New period on your timetable: {$v['class_name']} {$v['subject']}",
            TT_DAYS[$v['day']] . 's ' . hhmm($v['start_time']) . '–' . hhmm($v['end_time']) . " at {$v['school']}. Post your lesson plan before each class.", 'timetable', false);
    }
    json_out(['ok' => true, 'id' => $id]);
}

function act_timetable_slot_delete(array $in, array $me): void
{
    $s = tt_load_slot((int)($in['id'] ?? 0), $me);
    if (!$s['can_edit']) {
        fail('Only the teacher of this period or an admin can remove it.', 403);
    }
    q('UPDATE timetable_slots SET active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?', [$me['id'], $s['id']]);
    audit('timetable_delete', 'timetable_slot', $s['id'], ['school' => $s['school'], 'class' => $s['class_name']]);
    touch_change();
    json_out(['ok' => true]);
}

/** One class's record for a period: topics taught, this week's plan, plans coming up, and next classes with no plan. */
function act_timetable_class(array $in, array $me): void
{
    $s = tt_load_slot((int)($in['id'] ?? 0), $me);
    $monday = date('Y-m-d', strtotime('monday this week'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $taught = q("SELECT s.id, s.session_date, s.class_name, s.outcome, s.achieved, s.challenges, s.status, p.id AS plan_id, p.topic, p.project_title, u.name AS user_name
        FROM lesson_sessions s JOIN lesson_plans p ON p.id = s.plan_id JOIN users u ON u.id = s.user_id
        WHERE LOWER(s.school) = LOWER(?) AND LOWER(s.class_name) = LOWER(?) AND p.subject = ? AND s.status <> 'cancelled'
        ORDER BY s.session_date DESC, s.id DESC LIMIT 40", [$s['school'], $s['class_name'], $s['subject']])->fetchAll();
    $plans = q('SELECT p.id, p.topic, p.project_title, p.objectives, p.planned_date, p.planned_time, p.status, u.name AS user_name FROM lesson_plans p
        JOIN users u ON u.id = p.user_id WHERE p.planned_date >= ? AND p.status IN ' . TT_PLAN_POSTED . " AND (p.slot_id = ? OR (LOWER(p.school) = LOWER(?) AND p.subject = ?
        AND (LOWER(p.class_name) = LOWER(?) OR (p.class_name = '' AND p.user_id = ?)))) ORDER BY p.planned_date, p.id",
        [$monday, $s['id'], $s['school'], $s['subject'], $s['class_name'], (int)$s['teacher_id']])->fetchAll();
    foreach ($plans as &$p) {
        $p['id'] = (int)$p['id'];
        $p['ref'] = lesson_plan_ref($p['id']);
    }
    unset($p);
    $missing = [];
    if ($s['teacher_id']) {
        foreach (array_slice(slot_occurrences($s, slot_next_date($s), date('Y-m-d', strtotime('+21 days'))), 0, 2) as $d) {
            if (!slot_plan_for($s, $d)) {
                $missing[] = $d;
            }
        }
    }
    json_out(['ok' => true, 'slot' => $s + ['next_date' => slot_next_date($s)], 'taught' => $taught,
        'this_week' => array_values(array_filter($plans, fn($p) => $p['planned_date'] <= $sunday)),
        'coming_up' => array_values(array_filter($plans, fn($p) => $p['planned_date'] > $sunday)),
        'missing_plans' => $missing]);
}

/** Periods on a date whose teacher hasn't posted a lesson plan for it. */
function timetable_missing_plans(string $date): array
{
    $out = [];
    foreach (q('SELECT t.*, u.name AS teacher_name FROM timetable_slots t JOIN users u ON u.id = t.teacher_id AND u.active = 1
        WHERE t.active = 1 AND t.day = ? ORDER BY t.start_time', [(int)date('N', strtotime($date))])->fetchAll() as $s) {
        $s['start_time'] = hhmm($s['start_time']);
        $s['end_time'] = hhmm($s['end_time']);
        if (!slot_plan_for($s, $date)) {
            $out[] = $s;
        }
    }
    return $out;
}

/** Periods on a past date with no class logged for them. */
function timetable_unlogged(string $date): array
{
    $out = [];
    foreach (q('SELECT t.*, u.name AS teacher_name FROM timetable_slots t JOIN users u ON u.id = t.teacher_id AND u.active = 1
        WHERE t.active = 1 AND t.day = ? AND t.created_at < ? ORDER BY u.name, t.start_time', [(int)date('N', strtotime($date)), $date . ' 23:59:59'])->fetchAll() as $s) {
        $s['start_time'] = hhmm($s['start_time']);
        if (!slot_logged_on($s, $date)) {
            $out[] = $s;
        }
    }
    return $out;
}

/** Per teacher: timetabled classes in a range (up to today) that had no lesson plan posted for them. */
function timetable_no_plan_counts(string $from, string $to): array
{
    $to = min($to, today());
    $out = [];
    foreach (q('SELECT * FROM timetable_slots WHERE active = 1 AND teacher_id IS NOT NULL')->fetchAll() as $s) {
        foreach (slot_occurrences($s, max($from, substr($s['created_at'], 0, 10)), $to) as $d) {
            if (!slot_plan_for($s, $d)) {
                $out[(int)$s['teacher_id']] = ($out[(int)$s['teacher_id']] ?? 0) + 1;
            }
        }
    }
    return $out;
}

/** Reminders, once a day: periods today and tomorrow whose teacher hasn't posted the lesson plan. */
function timetable_plan_reminders(): int
{
    $n = 0;
    foreach ([today() => 'today', date('Y-m-d', strtotime('+1 day')) => 'tomorrow'] as $date => $when) {
        foreach (timetable_missing_plans($date) as $s) {
            if ($when === 'today' && time() > strtotime("$date {$s['start_time']}")) {
                continue;
            }
            notify([(int)$s['teacher_id']], 'lesson', "Post your lesson plan for {$s['class_name']} {$s['subject']} at {$s['school']}",
                ucfirst($when) . " at {$s['start_time']}. Post the topic and lesson plan before the class.", 'timetable', false);
            $n++;
        }
    }
    return $n;
}
