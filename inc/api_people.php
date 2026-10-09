<?php
/* Staff profiles, work tools register, attendance (clock in/out with GPS + selfie) */

const TOOL_CONDITIONS = ['good', 'fair', 'faulty', 'lost', 'retired'];
const PROFILE_COLS = 'id, name, email, phone, role, location, schools, job_title, duties, address, next_of_kin, next_of_kin_phone,
    bank_name, bank_account, start_date, salary, photo_id, active, last_login, created_at';

/* ---------------------------------------------------------------- profile */

function profile_user(int $id): array
{
    $u = q('SELECT ' . PROFILE_COLS . ' FROM users WHERE id = ?', [$id])->fetch();
    if (!$u) {
        fail('Staff member not found.', 404);
    }
    $u['id'] = (int)$u['id'];
    $u['duties'] = json_decode((string)$u['duties'], true) ?: [];
    $u['schools'] = user_schools($u);
    return $u;
}

function act_staff_profile(array $in, array $me): void
{
    $uid = is_admin($me) && !empty($in['id']) ? (int)$in['id'] : $me['id'];
    $u = profile_user($uid);
    $tools = q('SELECT id, name, category, serial_no, tag_code, `condition`, issued_at FROM tools WHERE holder_id = ? ORDER BY name', [$uid])->fetchAll();
    $jobs = q(JOB_LIST_SQL . " WHERE EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL)
        ORDER BY j.status IN ('done','cancelled'), j.id DESC LIMIT 30", [$uid])->fetchAll();
    $reports = q('SELECT id, report_date, location, work_type, finished FROM reports WHERE user_id = ? ORDER BY report_date DESC, id DESC LIMIT 8', [$uid])->fetchAll();
    $att = q('SELECT id, work_date, in_at, out_at, in_site, in_dist, out_site, note, source, in_reason, out_reason, flags, out_missed FROM attendance
        WHERE user_id = ? ORDER BY in_at DESC LIMIT 31', [$uid])->fetchAll();
    json_out([
        'ok' => true,
        'user' => $u,
        'tools' => $tools,
        'jobs' => array_map('job_row', $jobs),
        'reports' => $reports,
        'attendance' => array_map('attendance_row', $att),
        'month' => attendance_month($uid, date('Y-m-01'), today()),
        'wallet' => wallet_stats($uid),
    ]);
}

function act_staff_profile_save(array $in, array $me): void
{
    $admin = is_admin($me);
    $uid = $admin && !empty($in['id']) ? (int)$in['id'] : $me['id'];
    profile_user($uid);
    // Staff keep their own contact, next-of-kin and bank details up to date; admins also set role details.
    $v = [
        'phone' => str_in($in['phone'] ?? '', 40),
        'address' => str_in($in['address'] ?? '', 300),
        'next_of_kin' => str_in($in['next_of_kin'] ?? '', 120),
        'next_of_kin_phone' => str_in($in['next_of_kin_phone'] ?? '', 40),
        'bank_name' => str_in($in['bank_name'] ?? '', 80),
        'bank_account' => preg_replace('/[^0-9]/', '', str_in($in['bank_account'] ?? '', 40)),
    ];
    if ($admin) {
        $v['job_title'] = str_in($in['job_title'] ?? '', 120);
        $v['start_date'] = valid_date($in['start_date'] ?? null) ? $in['start_date'] : null;
        $v['duties'] = json_encode(array_values(array_intersect(DUTIES, (array)($in['duties'] ?? []))), JSON_UNESCAPED_UNICODE);
        if (isset($in['salary']) && $in['salary'] !== '') {
            $v['salary'] = max(0, money_in($in['salary']));
        }
    }
    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
    q("UPDATE users SET $set WHERE id = ?", array_merge(array_values($v), [$uid]));
    if (isset($in['schools']) || isset($in['location'])) {
        save_user_schools($uid, clean_schools($in['schools'] ?? [$in['location']]));
        $v['schools'] = true;
    }
    audit('profile_update', 'user', $uid, array_keys($v));
    touch_change();
    json_out(['ok' => true, 'user' => profile_user($uid)]);
}

function act_staff_photo(array $in, array $me): void
{
    $uid = is_admin($me) && !empty($in['id']) ? (int)$in['id'] : $me['id'];
    profile_user($uid);
    $files = uploaded_files('files');
    if (count($files) !== 1) {
        fail('Choose one photo.');
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }
    db()->beginTransaction();
    delete_attachments_of('avatar', $uid);
    $ids = save_uploads('avatar', $uid, $me['id']);
    q('UPDATE users SET photo_id = ? WHERE id = ?', [$ids[0] ?? null, $uid]);
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'photo_id' => $ids[0] ?? null]);
}

/* ------------------------------------------------------------------ tools */

function load_tool(int $id): array
{
    $t = q('SELECT t.*, u.name AS holder_name FROM tools t LEFT JOIN users u ON u.id = t.holder_id WHERE t.id = ?', [$id])->fetch();
    if (!$t) {
        fail('Tool not found.', 404);
    }
    $t['id'] = (int)$t['id'];
    return $t;
}

function act_tools_list(array $in, array $me): void
{
    require_admin();
    $w = ['1=1'];
    $p = [];
    if (($in['holder'] ?? '') === 'store') {
        $w[] = 't.holder_id IS NULL';
    } elseif (!empty($in['holder'])) {
        $w[] = 't.holder_id = ?';
        $p[] = (int)$in['holder'];
    }
    if (in_array($in['condition'] ?? '', TOOL_CONDITIONS, true)) {
        $w[] = 't.`condition` = ?';
        $p[] = $in['condition'];
    }
    if (!empty($in['q'])) {
        $w[] = '(t.name LIKE ? OR t.serial_no LIKE ? OR t.tag_code LIKE ? OR t.category LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($p, $like, $like, $like, $like);
    }
    $rows = q('SELECT t.id, t.name, t.category, t.serial_no, t.tag_code, t.`condition`, t.value, t.holder_id, t.issued_at, u.name AS holder_name
        FROM tools t LEFT JOIN users u ON u.id = t.holder_id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.name, t.id', $p)->fetchAll();
    $totals = q("SELECT COUNT(*) AS n, COALESCE(SUM(value),0) AS value, SUM(holder_id IS NOT NULL) AS issued,
        SUM(`condition` IN ('faulty','lost')) AS problems FROM tools WHERE `condition` <> 'retired'")->fetch();
    $cats = q("SELECT DISTINCT category FROM tools WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    json_out(['ok' => true, 'items' => $rows, 'totals' => $totals, 'categories' => $cats]);
}

function act_tool_get(array $in, array $me): void
{
    $t = load_tool((int)($in['id'] ?? 0));
    if (!is_admin($me) && (int)$t['holder_id'] !== $me['id']) {
        fail('This tool isn\'t issued to you.', 403);
    }
    $t['moves'] = q('SELECT m.action, m.`condition`, m.note, m.created_at, u.name AS user_name, b.name AS by_name FROM tool_moves m
        LEFT JOIN users u ON u.id = m.user_id LEFT JOIN users b ON b.id = m.by_user WHERE m.tool_id = ? ORDER BY m.id DESC', [$t['id']])->fetchAll();
    $t['files'] = attachments_for('tool', [$t['id']])[$t['id']] ?? [];
    json_out(['ok' => true, 'tool' => $t]);
}

function act_tool_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? load_tool($id) : null;
    $v = [
        'name' => str_in($in['name'] ?? '', 120),
        'category' => str_in($in['category'] ?? '', 80),
        'serial_no' => str_in($in['serial_no'] ?? '', 120),
        'tag_code' => str_in($in['tag_code'] ?? '', 60),
        'condition' => in_array($in['condition'] ?? '', TOOL_CONDITIONS, true) ? $in['condition'] : 'good',
        'value' => max(0, money_in($in['value'] ?? 0)),
        'purchase_date' => valid_date($in['purchase_date'] ?? null) ? $in['purchase_date'] : null,
        'notes' => str_in($in['notes'] ?? '', 2000),
    ];
    if ($v['name'] === '') {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['name' => 'Enter the tool name.']], 422);
    }
    if ($v['tag_code'] !== '' && q('SELECT id FROM tools WHERE tag_code = ? AND id <> ?', [$v['tag_code'], $id])->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['tag_code' => 'Another tool already has this tag.']], 422);
    }
    $existing = $id ? (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type = 'tool' AND owner_id = ?", [$id])->fetchColumn() : 0;
    if ($err = check_uploads('files', $existing)) {
        fail($err);
    }
    $cols = array_keys($v);
    db()->beginTransaction();
    if ($old) {
        q('UPDATE tools SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", $cols)) . ' WHERE id = ?', array_merge(array_values($v), [$id]));
        if ($old['condition'] !== $v['condition']) {
            q('INSERT INTO tool_moves (tool_id, user_id, action, `condition`, note, by_user, created_at) VALUES (?,?,?,?,?,?,NOW())',
                [$id, $old['holder_id'], 'condition', $v['condition'], 'Condition changed from ' . $old['condition'] . '.', $me['id']]);
        }
        audit('tool_update', 'tool', $id);
    } else {
        q('INSERT INTO tools (' . implode(', ', array_map(fn($k) => "`$k`", $cols)) . ', created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', NOW())', array_values($v));
        $id = (int)db()->lastInsertId();
        audit('tool_create', 'tool', $id, ['name' => $v['name']]);
    }
    save_uploads('tool', $id, $me['id']);
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_tool_issue(array $in, array $me): void
{
    require_admin();
    $t = load_tool((int)($in['id'] ?? 0));
    $uid = (int)($in['user_id'] ?? 0);
    $u = q('SELECT id, name FROM users WHERE id = ? AND active = 1', [$uid])->fetch();
    if (!$u) {
        fail('Choose an active staff member.');
    }
    if (in_array($t['condition'], ['lost', 'retired'], true)) {
        fail('This tool is marked ' . $t['condition'] . '. Change its condition before issuing it.');
    }
    $note = str_in($in['note'] ?? '', 1000);
    db()->beginTransaction();
    if ($t['holder_id'] && (int)$t['holder_id'] !== $uid) {
        q('INSERT INTO tool_moves (tool_id, user_id, action, `condition`, note, by_user, created_at) VALUES (?,?,?,?,?,?,NOW())',
            [$t['id'], $t['holder_id'], 'return', $t['condition'], 'Returned when reissued to ' . $u['name'] . '.', $me['id']]);
    }
    q('UPDATE tools SET holder_id = ?, issued_at = NOW() WHERE id = ?', [$uid, $t['id']]);
    q('INSERT INTO tool_moves (tool_id, user_id, action, `condition`, note, by_user, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$t['id'], $uid, 'issue', $t['condition'], $note, $me['id']]);
    audit('tool_issue', 'tool', $t['id'], ['to' => $uid]);
    db()->commit();
    touch_change();
    notify([$uid], 'tool', 'Tool issued to you: ' . $t['name'], trim(($t['tag_code'] ? 'Tag ' . $t['tag_code'] . '. ' : '') . $note), 'profile', false);
    json_out(['ok' => true]);
}

function act_tool_return(array $in, array $me): void
{
    require_admin();
    $t = load_tool((int)($in['id'] ?? 0));
    if (!$t['holder_id']) {
        fail('This tool is already in the store.');
    }
    $cond = in_array($in['condition'] ?? '', TOOL_CONDITIONS, true) ? $in['condition'] : $t['condition'];
    db()->beginTransaction();
    q('UPDATE tools SET holder_id = NULL, issued_at = NULL, `condition` = ? WHERE id = ?', [$cond, $t['id']]);
    q('INSERT INTO tool_moves (tool_id, user_id, action, `condition`, note, by_user, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$t['id'], $t['holder_id'], 'return', $cond, str_in($in['note'] ?? '', 1000), $me['id']]);
    audit('tool_return', 'tool', $t['id'], ['from' => (int)$t['holder_id'], 'condition' => $cond]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

function act_tool_delete(array $in, array $me): void
{
    require_admin();
    $t = load_tool((int)($in['id'] ?? 0));
    db()->beginTransaction();
    delete_attachments_of('tool', $t['id']);
    q('DELETE FROM tool_moves WHERE tool_id = ?', [$t['id']]);
    q('DELETE FROM tools WHERE id = ?', [$t['id']]);
    audit('tool_delete', 'tool', $t['id'], ['name' => $t['name']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

/* ------------------------------------------------------------- attendance */

/** Anti-cheat limits for clock in/out. */
const GPS_MAX_AGE = 60;       // seconds between the GPS fix and pressing the button (blocks cached locations)
const CLOCK_MAX_SKEW = 300;   // seconds the phone's clock may differ from the server
const GPS_MAX_ACCURACY = 200; // metres
const SELFIE_MAX_AGE = 600;   // seconds since the selfie was taken

function hhmm_setting(string $key, string $default): string
{
    $s = (string)(setting($key) ?: $default);
    return preg_match('/^\d{2}:\d{2}$/', $s) ? $s : $default;
}

function work_start(): string
{
    return hhmm_setting('work_start', '08:00');
}

function work_end(): string
{
    return hhmm_setting('work_end', '17:00');
}

function late_grace(): int
{
    return max(0, min(120, (int)setting('late_grace')));
}

/** ISO weekdays that are working days (1 = Monday … 7 = Sunday). */
function work_days(): array
{
    $d = setting('work_days');
    return is_array($d) && $d ? array_values(array_map('intval', $d)) : [1, 2, 3, 4, 5];
}

/** Known sites: {name: [lat, lng]} from the clients directory, plus older Settings entries. */
function site_coords(): array
{
    $sites = [];
    foreach (q('SELECT name, lat, lng FROM clients WHERE active = 1 AND lat IS NOT NULL AND lng IS NOT NULL')->fetchAll() as $c) {
        $sites[$c['name']] = [(float)$c['lat'], (float)$c['lng']];
    }
    $old = setting('site_coords');
    return $sites + (is_array($old) ? $old : []);
}

/** Nearest known site and the distance to it in metres, or ['', null]. */
function nearest_site(?float $lat, ?float $lng): array
{
    if ($lat === null || $lng === null) {
        return ['', null];
    }
    $best = ['', null];
    foreach (site_coords() as $name => [$slat, $slng]) {
        $dLat = deg2rad($slat - $lat);
        $dLng = deg2rad($slng - $lng);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($slat)) * sin($dLng / 2) ** 2;
        $m = (int)round(6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
        if ($best[1] === null || $m < $best[1]) {
            $best = [$name, $m];
        }
    }
    return $best;
}

/** Adds late (and minutes late), early leave, hours worked and a missed clock-out flag to an attendance row. */
function attendance_row(array $r): array
{
    $r['id'] = (int)$r['id'];
    $start = strtotime($r['work_date'] . ' ' . work_start()) + late_grace() * 60;
    $in = strtotime($r['in_at']);
    $r['late'] = $in >= $start + 60;
    $r['late_min'] = $r['late'] ? (int)floor(($in - strtotime($r['work_date'] . ' ' . work_start())) / 60) : 0;
    $r['early'] = $r['out_at'] && substr($r['out_at'], 0, 10) === $r['work_date'] && strtotime($r['out_at']) < strtotime($r['work_date'] . ' ' . work_end());
    $r['minutes'] = $r['out_at'] ? max(0, (int)round((strtotime($r['out_at']) - $in) / 60)) : null;
    $r['missed_out'] = !$r['out_at'] && ((int)($r['out_missed'] ?? 0) || $r['work_date'] < today());
    return $r;
}

/** Days present, late days and hours worked between two dates. */
function attendance_month(int $uid, string $from, string $to): array
{
    $rows = q('SELECT id, work_date, in_at, out_at, out_missed FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY in_at',
        [$uid, $from, $to])->fetchAll();
    $first = []; // only the first clock-in of a day counts towards lateness
    $minutes = 0;
    foreach (array_map('attendance_row', $rows) as $r) {
        $first[$r['work_date']] = $first[$r['work_date']] ?? $r;
        $minutes += (int)$r['minutes'];
    }
    $days = count($first);
    return ['from' => $from, 'to' => $to, 'days' => $days, 'late' => count(array_filter($first, fn($r) => $r['late'])),
        'hours' => round($minutes / 60, 1), 'avg_hours' => $days ? round($minutes / 60 / $days, 1) : 0];
}

function open_attendance(int $uid): ?array
{
    $r = q('SELECT * FROM attendance WHERE user_id = ? AND out_at IS NULL AND out_missed = 0 AND in_at >= ? ORDER BY in_at DESC LIMIT 1',
        [$uid, date('Y-m-d H:i:s', time() - 20 * 3600)])->fetch();
    return $r ?: null;
}

function act_attendance_today(array $in, array $me): void
{
    $r = q('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? ORDER BY in_at DESC LIMIT 1', [$me['id'], today()])->fetch();
    $open = open_attendance($me['id']);
    json_out(['ok' => true, 'record' => $r ? attendance_row($r) : null, 'open' => $open ? attendance_row($open) : null,
        'work_start' => work_start(), 'work_end' => work_end(), 'late_grace' => late_grace(), 'server_time' => time()]);
}

/** Rejects a clock-in/out with a code the app uses to retry or explain. */
function clock_fail(string $code, string $message): void
{
    json_out(['ok' => false, 'error' => $message, 'code' => $code], 422);
}

/**
 * Clock in or out. Strict: a fresh selfie, a fresh GPS fix taken when the button was pressed, the phone's clock
 * within 5 minutes of the server, and a reason when late or leaving early. Without GPS a reason is required and the
 * record is flagged.
 */
function act_clock(array $in, array $me): void
{
    $action = ($in['action'] ?? '') === 'out' ? 'out' : 'in';
    $now = time();
    $clientTs = is_numeric($in['client_ts'] ?? null) ? (float)$in['client_ts'] / 1000 : null;
    if ($clientTs === null) {
        clock_fail('reload', 'Reload the page and try again.');
    }
    $skew = (int)round($clientTs - $now);
    if (abs($skew) > CLOCK_MAX_SKEW) {
        clock_fail('clock_skew', 'Your phone\'s time is wrong by ' . round(abs($skew) / 60) . ' minutes. Turn on automatic date & time in your phone settings, then try again.');
    }

    $lat = is_numeric($in['lat'] ?? null) ? (float)$in['lat'] : null;
    $lng = is_numeric($in['lng'] ?? null) ? (float)$in['lng'] : null;
    if ($lat !== null && ($lng === null || abs($lat) > 90 || abs($lng) > 180)) {
        $lat = $lng = null;
    }
    $acc = is_numeric($in['acc'] ?? null) ? (int)$in['acc'] : null;
    $reason = str_in($in['reason'] ?? '', 500);
    $flags = [];
    $gpsAt = null;
    if ($lat !== null) {
        // The fix's own timestamp and the button press come from the same phone clock, so their gap is reliable.
        $gpsTs = is_numeric($in['gps_ts'] ?? null) ? (float)$in['gps_ts'] / 1000 : null;
        $age = $gpsTs === null ? null : $clientTs - $gpsTs;
        if ($age === null || $age > GPS_MAX_AGE || $age < -5) {
            clock_fail('stale_gps', 'Your location reading was old (cached). Getting a fresh one…');
        }
        if ($acc === null || $acc > GPS_MAX_ACCURACY) {
            clock_fail('low_accuracy', 'Your location is only accurate to ' . ($acc ?? '?') . 'm. Move outside or near a window and try again.');
        }
        $gpsAt = date('Y-m-d H:i:s', (int)round($gpsTs - $skew));
    } else {
        if ($reason === '') {
            clock_fail('no_gps', 'Your location couldn\'t be read. Turn on location, or say where you are and why.');
        }
        $flags[] = 'no_gps';
    }

    $files = uploaded_files('files');
    if (count($files) !== 1) {
        clock_fail('selfie', 'Take a selfie to clock ' . $action . '.');
    }
    $selfieTs = is_numeric($in['selfie_ts'] ?? null) ? (float)$in['selfie_ts'] / 1000 : null;
    if ($selfieTs === null || $clientTs - $selfieTs > SELFIE_MAX_AGE || $selfieTs - $clientTs > 60) {
        clock_fail('old_selfie', 'Take a new selfie now. Photos from your gallery aren\'t accepted.');
    }
    if ($err = check_uploads('files')) {
        fail($err);
    }

    $open = open_attendance($me['id']);
    if ($action === 'in') {
        if ($open) {
            fail('You\'re already clocked in since ' . date('g:ia', strtotime($open['in_at'])) . '. Clock out first.');
        }
        $late = $now >= strtotime(today() . ' ' . work_start()) + late_grace() * 60 + 60;
        if ($late && $reason === '' && !in_array('no_gps', $flags, true)) {
            clock_fail('late_reason', 'You\'re late (work starts at ' . work_start() . '). Say why.');
        }
    } else {
        if (!$open) {
            fail('You haven\'t clocked in yet.');
        }
        $early = $open['work_date'] === today() && $now < strtotime(today() . ' ' . work_end());
        if ($early && $reason === '' && !in_array('no_gps', $flags, true)) {
            clock_fail('early_reason', 'Work ends at ' . work_end() . '. Say why you\'re leaving early.');
        }
    }

    [$site, $dist] = nearest_site($lat, $lng);
    db()->beginTransaction();
    if ($action === 'in') {
        q('INSERT INTO attendance (user_id, work_date, in_at, in_lat, in_lng, in_acc, in_site, in_dist, in_gps_ts, in_skew, in_reason, flags, source, created_at)
            VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,\'app\',NOW())',
            [$me['id'], today(), $lat, $lng, $acc, $site, $dist, $gpsAt, $skew, $reason, implode(',', $flags)]);
        $id = (int)db()->lastInsertId();
        save_uploads('attend', $id, $me['id']);
    } else {
        $id = (int)$open['id'];
        $allFlags = implode(',', array_unique(array_filter(array_merge(explode(',', (string)$open['flags']), $flags))));
        q('UPDATE attendance SET out_at = NOW(), out_lat = ?, out_lng = ?, out_acc = ?, out_site = ?, out_dist = ?, out_gps_ts = ?, out_skew = ?,
            out_reason = ?, flags = ? WHERE id = ?', [$lat, $lng, $acc, $site, $dist, $gpsAt, $skew, $reason, $allFlags, $id]);
        save_uploads('attendout', $id, $me['id']);
    }
    db()->commit();
    touch_change();
    $r = attendance_row(q('SELECT * FROM attendance WHERE id = ?', [$id])->fetch());
    json_out(['ok' => true, 'record' => $r]);
}

/* ------------------------------------------------------ attendance report */

/** Per-staff attendance over a date range; with one $uid also the day-by-day calendar. */
function attendance_report(string $from, string $to, ?int $uid = null): array
{
    $end = min($to, today());
    $workDays = work_days();
    $dates = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $dates[] = date('Y-m-d', $t);
    }
    $p = [];
    $w = "u.active = 1 AND u.role = 'staff'";
    if ($uid) {
        $w = 'u.id = ?';
        $p[] = $uid;
    }
    $staff = q("SELECT u.id, u.name, u.location, u.start_date, DATE(u.created_at) AS joined FROM users u WHERE $w ORDER BY u.name", $p)->fetchAll();
    $byUser = [];
    $ids = array_map('intval', array_column($staff, 'id'));
    if ($ids) {
        $inIds = implode(',', $ids);
        foreach (q("SELECT * FROM attendance WHERE user_id IN ($inIds) AND work_date BETWEEN ? AND ? ORDER BY in_at", [$from, $to])->fetchAll() as $r) {
            $byUser[(int)$r['user_id']][$r['work_date']][] = attendance_row($r);
        }
    }
    $rows = [];
    $calendar = [];
    // Days before anyone clocked in for the first time aren't absences: the system wasn't in use yet.
    $launch = (string)(q('SELECT MIN(work_date) FROM attendance')->fetchColumn() ?: today());
    foreach ($staff as $s) {
        $sid = (int)$s['id'];
        $since = max($s['start_date'] ?: $s['joined'], $launch);
        $st = ['working_days' => 0, 'present' => 0, 'extra_days' => 0, 'absent' => 0, 'late' => 0, 'late_min' => 0, 'early' => 0,
            'missed_out' => 0, 'minutes' => 0, 'arrivals' => []];
        foreach ($dates as $date) {
            $isWork = in_array((int)date('N', strtotime($date)), $workDays, true);
            $recs = $byUser[$sid][$date] ?? [];
            $day = ['date' => $date, 'work_day' => $isWork, 'status' => $isWork ? '' : 'off'];
            if ($recs) {
                $first = $recs[0];
                $last = $recs[count($recs) - 1];
                $mins = array_sum(array_map(fn($r) => (int)$r['minutes'], $recs));
                $missed = (bool)array_filter($recs, fn($r) => $r['missed_out']);
                // Lateness and early leaving only count on working days; a day off worked is extra.
                $late = $isWork && $first['late'];
                $early = $isWork && $last['early'];
                $isWork ? $st['present']++ : $st['extra_days']++;
                $st['minutes'] += $mins;
                if ($isWork) {
                    $st['arrivals'][] = (int)date('G', strtotime($first['in_at'])) * 60 + (int)date('i', strtotime($first['in_at']));
                }
                if ($late) {
                    $st['late']++;
                    $st['late_min'] += $first['late_min'];
                }
                $st['early'] += $early ? 1 : 0;
                $st['missed_out'] += $missed ? 1 : 0;
                $day += ['in' => $first['in_at'], 'out' => $last['out_at'], 'minutes' => $mins, 'late' => $late, 'late_min' => $late ? $first['late_min'] : 0,
                    'early' => $early, 'missed_out' => $missed, 'in_reason' => $first['in_reason'] ?? '', 'out_reason' => $last['out_reason'] ?? '',
                    'site' => $first['in_site'], 'flags' => $first['flags'] ?? ''];
                $day['status'] = $missed ? 'missed' : (!$isWork ? 'extra' : ($late ? 'late' : 'present'));
            } elseif ($isWork && $date <= $end && $date >= $since) {
                $st['absent']++;
                $day['status'] = 'absent';
            } elseif ($isWork) {
                $day['status'] = $date > $end ? 'future' : 'before_start';
            }
            if ($isWork && $date <= $end && $date >= $since) {
                $st['working_days']++;
            }
            if ($uid) {
                $calendar[] = $day;
            }
        }
        $attended = $st['present'] + $st['extra_days'];
        $avgArr = $st['arrivals'] ? (int)round(array_sum($st['arrivals']) / count($st['arrivals'])) : null;
        unset($st['arrivals']);
        $rows[] = ['id' => $sid, 'name' => $s['name'], 'location' => $s['location']] + $st + [
            'hours' => round($st['minutes'] / 60, 1),
            'avg_hours' => $attended ? round($st['minutes'] / 60 / $attended, 1) : 0,
            'avg_arrival' => $avgArr === null ? null : sprintf('%02d:%02d', intdiv($avgArr, 60), $avgArr % 60),
            'attendance_pct' => $st['working_days'] ? (int)round($st['present'] / $st['working_days'] * 100) : null,
            'punctuality_pct' => $st['present'] ? (int)round(($st['present'] - $st['late']) / $st['present'] * 100) : null,
        ];
    }
    return ['from' => $from, 'to' => $to, 'work_start' => work_start(), 'work_end' => work_end(), 'late_grace' => late_grace(),
        'work_days' => $workDays, 'rows' => $rows, 'calendar' => $calendar];
}

function report_range(array $in): array
{
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-01');
    $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 400) {
        fail('Choose a range of about a year or less.');
    }
    return [$from, $to];
}

function act_attendance_report(array $in, array $me): void
{
    [$from, $to] = report_range($in);
    $uid = is_admin($me) ? ((int)($in['user_id'] ?? 0) ?: null) : $me['id'];
    json_out(['ok' => true] + attendance_report($from, $to, $uid));
}

function act_attendance_report_export(array $in, array $me): void
{
    require_admin();
    [$from, $to] = report_range($in);
    $r = attendance_report($from, $to, (int)($in['user_id'] ?? 0) ?: null);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-report-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Staff', 'Working days', 'Present', 'Absent', 'Attendance %', 'Late days', 'Minutes late', 'Average arrival', 'Punctuality %',
        'Left early', 'Missed clock-outs', 'Extra days (non-working)', 'Total hours', 'Average hours/day']);
    foreach ($r['rows'] as $x) {
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), [$x['name'], $x['working_days'], $x['present'], $x['absent'], $x['attendance_pct'] ?? '',
            $x['late'], $x['late_min'], $x['avg_arrival'] ?? '', $x['punctuality_pct'] ?? '', $x['early'], $x['missed_out'], $x['extra_days'], $x['hours'], $x['avg_hours']]));
    }
    exit;
}

function attendance_with_photos(array $rows): array
{
    $ids = array_column($rows, 'id');
    $in = attachments_for('attend', $ids);
    $out = attachments_for('attendout', $ids);
    foreach ($rows as &$r) {
        $r = attendance_row($r);
        $r['in_photo'] = $in[$r['id']][0]['id'] ?? null;
        $r['out_photo'] = $out[$r['id']][0]['id'] ?? null;
    }
    return $rows;
}

const ATT_COLS = 'a.id, a.user_id, u.name AS user_name, a.work_date, a.in_at, a.in_lat, a.in_lng, a.in_acc, a.in_site, a.in_dist,
    a.out_at, a.out_lat, a.out_lng, a.out_acc, a.out_site, a.out_dist, a.note, a.source, a.in_reason, a.out_reason, a.flags, a.out_missed,
    a.in_gps_ts, a.in_skew';

/** Admin: one day's register (everyone, including who hasn't clocked in) or a date range. Staff: their own history. */
function act_attendance_list(array $in, array $me): void
{
    $from = valid_date($in['from'] ?? null) ? $in['from'] : today();
    $to = valid_date($in['to'] ?? null) ? $in['to'] : $from;
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $p = [$from, $to];
    $w = 'a.work_date BETWEEN ? AND ?';
    if (!is_admin($me)) {
        $w .= ' AND a.user_id = ?';
        $p[] = $me['id'];
    } elseif (!empty($in['user_id'])) {
        $w .= ' AND a.user_id = ?';
        $p[] = (int)$in['user_id'];
    }
    $rows = attendance_with_photos(q('SELECT ' . ATT_COLS . " FROM attendance a JOIN users u ON u.id = a.user_id WHERE $w
        ORDER BY a.work_date DESC, a.in_at", $p)->fetchAll());
    $absent = [];
    if (is_admin($me) && $from === $to && empty($in['user_id'])) {
        $present = array_unique(array_column($rows, 'user_id'));
        $absent = array_values(array_filter(q("SELECT id, name, location FROM users WHERE active = 1 AND role = 'staff' ORDER BY name")->fetchAll(),
            fn($u) => !in_array((int)$u['id'], array_map('intval', $present), true)));
    }
    json_out(['ok' => true, 'from' => $from, 'to' => $to, 'work_start' => work_start(), 'items' => $rows, 'absent' => $absent]);
}

/** Admin correction or manual entry (e.g. a staff member's phone died). */
function act_attendance_edit(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? q('SELECT * FROM attendance WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) {
        fail('Record not found.', 404);
    }
    $uid = $old ? (int)$old['user_id'] : (int)($in['user_id'] ?? 0);
    $date = $old ? $old['work_date'] : ($in['work_date'] ?? '');
    if (!$old && (!valid_date($date) || !q('SELECT 1 FROM users WHERE id = ?', [$uid])->fetchColumn())) {
        fail('Choose the staff member and date.');
    }
    $time = fn($k) => preg_match('/^\d{2}:\d{2}$/', (string)($in[$k] ?? '')) ? "$date {$in[$k]}:00" : null;
    $inAt = $time('in_time');
    $outAt = $time('out_time');
    if (!$inAt) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['in_time' => 'Enter the arrival time.']], 422);
    }
    if ($outAt && $outAt < $inAt) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['out_time' => 'Leaving time is before arrival.']], 422);
    }
    $note = str_in($in['note'] ?? '', 500);
    if ($note === '') {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['note' => 'Say why this is being changed.']], 422);
    }
    if ($old) {
        q('UPDATE attendance SET in_at = ?, out_at = ?, out_missed = 0, flags = TRIM(BOTH \',\' FROM CONCAT(flags, \',admin_edit\')),
            note = TRIM(CONCAT(COALESCE(note, \'\'), ?)), edited_by = ? WHERE id = ?',
            [$inAt, $outAt, "\nAdmin: " . $note, $me['id'], $id]);
        audit('attendance_edit', 'attendance', $id, ['in' => [$old['in_at'], $inAt], 'out' => [$old['out_at'], $outAt], 'note' => $note]);
    } else {
        q('INSERT INTO attendance (user_id, work_date, in_at, out_at, note, flags, source, edited_by, created_at) VALUES (?,?,?,?,?,\'admin_edit\',\'admin\',?,NOW())',
            [$uid, $date, $inAt, $outAt, 'Admin: ' . $note, $me['id']]);
        $id = (int)db()->lastInsertId();
        audit('attendance_add', 'attendance', $id, ['user' => $uid, 'date' => $date, 'note' => $note]);
    }
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_attendance_export(array $in, array $me): void
{
    require_admin();
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-01');
    $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
    $p = [$from, $to];
    $w = 'a.work_date BETWEEN ? AND ?';
    if (!empty($in['user_id'])) {
        $w .= ' AND a.user_id = ?';
        $p[] = (int)$in['user_id'];
    }
    $rows = q('SELECT ' . ATT_COLS . " FROM attendance a JOIN users u ON u.id = a.user_id WHERE $w ORDER BY a.work_date, u.name, a.in_at", $p)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Staff', 'Arrived', 'Left', 'Hours', 'Late', 'Arrival site', 'Distance (m)', 'Arrival GPS', 'Leaving GPS', 'Source', 'Notes']);
    foreach ($rows as $r) {
        $r = attendance_row($r);
        fputcsv($out, array_map(fn($x) => csv_safe((string)$x), [
            $r['work_date'], $r['user_name'], substr($r['in_at'], 11, 5), $r['out_at'] ? substr($r['out_at'], 11, 5) : '',
            $r['minutes'] !== null ? round($r['minutes'] / 60, 2) : '', $r['late'] ? 'Yes' : 'No', $r['in_site'], $r['in_dist'] ?? '',
            $r['in_lat'] !== null ? $r['in_lat'] . ',' . $r['in_lng'] : '', $r['out_lat'] !== null ? $r['out_lat'] . ',' . $r['out_lng'] : '',
            $r['source'], $r['note'],
        ]));
    }
    exit;
}

/** Dashboard tiles: who is in today, who is late, who hasn't clocked in. */
function attendance_today_summary(): array
{
    $rows = array_map('attendance_row', q('SELECT a.id, a.user_id, a.work_date, a.in_at, a.out_at, a.out_missed FROM attendance a
        JOIN users u ON u.id = a.user_id WHERE a.work_date = ? AND u.role = \'staff\' ORDER BY a.in_at', [today()])->fetchAll());
    $first = [];
    foreach ($rows as $r) {
        $first[$r['user_id']] = $first[$r['user_id']] ?? $r;
    }
    $staff = q("SELECT id, name FROM users WHERE active = 1 AND role = 'staff' ORDER BY name")->fetchAll();
    $missing = array_values(array_filter($staff, fn($s) => !isset($first[$s['id']])));
    return [
        'in' => count($first),
        'on_site' => count(array_filter($rows, fn($r) => !$r['out_at'])),
        'late' => count(array_filter($first, fn($r) => $r['late'])),
        'staff' => count($staff),
        'missing' => $missing,
        'work_start' => work_start(),
    ];
}
