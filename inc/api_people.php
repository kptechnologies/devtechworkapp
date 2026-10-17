<?php
/* Staff profiles, work tools register, attendance (clock in/out with GPS + selfie) */

const TOOL_CONDITIONS = ['good', 'fair', 'faulty', 'repair', 'lost', 'retired'];
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
    $uid = is_management($me) && !empty($in['id']) ? (int)$in['id'] : $me['id'];
    $u = profile_user($uid);
    if (!is_admin($me)) {
        unset($u['salary']); // pay figures are admin-only
        if ($uid !== $me['id']) {
            unset($u['bank_name'], $u['bank_account']); // a sub admin sees work, not another person's money
        }
    }
    $tools = q('SELECT t.id, t.name, t.category, t.serial_no, t.tag_code, t.`condition`, t.issued_at, t.brand, t.model, t.ram, t.storage,
        ' . TOOL_PHOTO_SQL . ' AS photo_id, ' . TOOL_REPAIR_SQL . ' AS repair_status FROM tools t WHERE t.holder_id = ? ORDER BY t.name', [$uid])->fetchAll();
    $jobs = q(JOB_LIST_SQL . " WHERE EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL)
        ORDER BY j.status IN ('done','cancelled'), j.id DESC LIMIT 30", [$uid])->fetchAll();
    $reports = q('SELECT id, report_date, location, work_type, finished FROM reports WHERE user_id = ? ORDER BY report_date DESC, id DESC LIMIT 8', [$uid])->fetchAll();
    $att = q('SELECT id, user_id, work_date, in_at, out_at, in_site, in_dist, out_site, note, source, in_reason, out_reason, flags, out_missed,
        location, work_type, work_note FROM attendance WHERE user_id = ? ORDER BY in_at DESC LIMIT 31', [$uid])->fetchAll();
    json_out([
        'ok' => true,
        'user' => $u,
        'tools' => $tools,
        'jobs' => array_map('job_row', $jobs),
        'reports' => $reports,
        'attendance' => attendance_visits(array_map('attendance_row', $att)),
        'month' => attendance_month($uid, date('Y-m-01'), today()),
        'wallet' => is_admin($me) || $uid === $me['id'] ? wallet_stats($uid) : null,
        'queries' => person_queries($uid, null),
        'suspension' => active_suspension($uid),
    ]);
}

function act_staff_profile_save(array $in, array $me): void
{
    $admin = is_admin($me);
    $uid = can_manage_accounts($me) && !empty($in['id']) ? (int)$in['id'] : $me['id'];
    $target = profile_user($uid);
    // A manager edits work details of the people below them, never their pay or bank details.
    $manager = !$admin && $uid !== $me['id'];
    if ($manager && !in_array($target['role'], assignable_roles($me), true)) {
        fail('Managers can only edit staff, supervisor and sub admin details.', 403);
    }
    // Staff keep their own contact, next-of-kin and bank details up to date; admins and managers also set role details.
    $v = [
        'phone' => str_in($in['phone'] ?? '', 40),
        'address' => str_in($in['address'] ?? '', 300),
        'next_of_kin' => str_in($in['next_of_kin'] ?? '', 120),
        'next_of_kin_phone' => str_in($in['next_of_kin_phone'] ?? '', 40),
    ];
    if (!$manager) {
        $v['bank_name'] = str_in($in['bank_name'] ?? '', 80);
        $v['bank_account'] = preg_replace('/[^0-9]/', '', str_in($in['bank_account'] ?? '', 40));
    }
    if ($manager) {
        $v['job_title'] = str_in($in['job_title'] ?? '', 120);
        $v['start_date'] = valid_date($in['start_date'] ?? null) ? $in['start_date'] : null;
        $v['duties'] = json_encode(array_values(array_intersect(DUTIES, (array)($in['duties'] ?? []))), JSON_UNESCAPED_UNICODE);
    }
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

const TOOL_SPEC_FIELDS = ['brand' => 60, 'model' => 120, 'processor' => 80, 'ram' => 30, 'storage' => 40, 'os' => 60, 'screen' => 20,
    'accessories' => 300, 'supplier' => 120];
const TOOL_PHOTO_SQL = "(SELECT MIN(a.id) FROM attachments a WHERE a.owner_type = 'tool' AND a.owner_id = t.id AND a.mime LIKE 'image/%' AND a.thumb_name IS NOT NULL)";
const TOOL_REPAIR_SQL = "(SELECT r.status FROM tool_repairs r WHERE r.tool_id = t.id AND r.status IN ('open','at_repair') ORDER BY r.id DESC LIMIT 1)";

function load_tool(int $id): array
{
    $t = q('SELECT t.*, u.name AS holder_name FROM tools t LEFT JOIN users u ON u.id = t.holder_id WHERE t.id = ?', [$id])->fetch();
    if (!$t) {
        fail('Tool not found.', 404);
    }
    $t['id'] = (int)$t['id'];
    return $t;
}

function is_laptop(array $t): bool
{
    return stripos((string)$t['category'], 'laptop') !== false;
}

function tool_move(int $toolId, ?int $userId, string $action, string $cond, string $note, int $by, string $items = ''): void
{
    q('INSERT INTO tool_moves (tool_id, user_id, action, `condition`, note, items, by_user, created_at) VALUES (?,?,?,?,?,?,?,NOW())',
        [$toolId, $userId ?: null, $action, $cond, $note, $items, $by]);
}

/** The accessories ticked on an issue or return form, kept as a comma list. */
function tool_items_in($items): string
{
    $items = array_filter(array_map(fn($x) => str_in((string)$x, 40), (array)$items));
    return str_in(implode(', ', array_unique($items)), 300);
}

function open_repair(int $toolId): ?array
{
    return q("SELECT * FROM tool_repairs WHERE tool_id = ? AND status IN ('open','at_repair') ORDER BY id DESC LIMIT 1", [$toolId])->fetch() ?: null;
}

function management_ids(array $except = []): array
{
    $in = implode(',', array_fill(0, count(MANAGEMENT_ROLES), '?'));
    $ids = array_map('intval', q("SELECT id FROM users WHERE active = 1 AND role IN ($in)", MANAGEMENT_ROLES)->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_diff($ids, $except));
}

function tool_where(array $in, array &$p): string
{
    $w = ['1=1'];
    if (($in['holder'] ?? '') === 'store') {
        $w[] = 't.holder_id IS NULL';
    } elseif (!empty($in['holder'])) {
        $w[] = 't.holder_id = ?';
        $p[] = (int)$in['holder'];
    }
    if (($in['condition'] ?? '') === 'issues') {
        $w[] = "(t.`condition` IN ('faulty','repair') OR " . TOOL_REPAIR_SQL . ' IS NOT NULL)';
    } elseif (in_array($in['condition'] ?? '', TOOL_CONDITIONS, true)) {
        $w[] = 't.`condition` = ?';
        $p[] = $in['condition'];
    }
    if (!empty($in['category'])) {
        $w[] = 't.category = ?';
        $p[] = str_in($in['category'], 80);
    }
    if (!empty($in['q'])) {
        $w[] = '(t.name LIKE ? OR t.serial_no LIKE ? OR t.tag_code LIKE ? OR t.category LIKE ? OR t.brand LIKE ? OR t.model LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($p, $like, $like, $like, $like, $like, $like);
    }
    return implode(' AND ', $w);
}

function act_tools_list(array $in, array $me): void
{
    require_management();
    $p = [];
    $rows = q('SELECT t.id, t.name, t.category, t.serial_no, t.tag_code, t.`condition`, t.value, t.holder_id, t.issued_at, t.brand, t.model,
        t.processor, t.ram, t.storage, u.name AS holder_name, ' . TOOL_PHOTO_SQL . ' AS photo_id, ' . TOOL_REPAIR_SQL . ' AS repair_status
        FROM tools t LEFT JOIN users u ON u.id = t.holder_id WHERE ' . tool_where($in, $p) . ' ORDER BY t.name, t.id', $p)->fetchAll();
    $totals = q("SELECT COUNT(*) AS n, COALESCE(SUM(value),0) AS value, SUM(holder_id IS NOT NULL) AS issued,
        SUM(`condition` = 'faulty') AS faulty, SUM(`condition` = 'repair') AS repair, SUM(`condition` = 'lost') AS lost,
        SUM(category LIKE '%laptop%') AS laptops FROM tools WHERE `condition` <> 'retired'")->fetch();
    $cats = q("SELECT DISTINCT category FROM tools WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    // Who holds what: one row per staff member, the biggest laptop holders first.
    $byHolder = q("SELECT u.id, u.name, COUNT(*) AS n, SUM(t.category LIKE '%laptop%') AS laptops, SUM(t.`condition` = 'faulty') AS faulty
        FROM tools t JOIN users u ON u.id = t.holder_id GROUP BY u.id, u.name ORDER BY laptops DESC, n DESC, u.name")->fetchAll();
    json_out(['ok' => true, 'items' => $rows, 'totals' => $totals, 'categories' => $cats, 'by_holder' => $byHolder]);
}

function act_tools_export(array $in, array $me): void
{
    require_management();
    $p = [];
    $rows = q('SELECT t.*, u.name AS holder_name, ' . TOOL_REPAIR_SQL . ' AS repair_status,
        (SELECT COUNT(*) FROM tool_repairs r WHERE r.tool_id = t.id) AS repairs, (SELECT COALESCE(SUM(r.cost),0) FROM tool_repairs r WHERE r.tool_id = t.id) AS repair_cost
        FROM tools t LEFT JOIN users u ON u.id = t.holder_id WHERE ' . tool_where($in, $p) . ' ORDER BY t.category, t.name, t.id', $p)->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="work-tools-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tag', 'Name', 'Category', 'Brand', 'Model', 'Serial no.', 'Processor', 'RAM', 'Storage', 'OS', 'Screen', 'Accessories',
        'Condition', 'Repair status', 'Held by', 'Issued', 'Value', 'Purchase date', 'Supplier', 'Warranty until', 'Times repaired', 'Repair cost', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($x) => csv_safe((string)$x), [
            $r['tag_code'], $r['name'], $r['category'], $r['brand'], $r['model'], $r['serial_no'], $r['processor'], $r['ram'], $r['storage'], $r['os'],
            $r['screen'], $r['accessories'], $r['condition'], str_replace('_', ' ', (string)$r['repair_status']), $r['holder_name'] ?? 'In store',
            $r['issued_at'] ?? '', $r['value'], $r['purchase_date'] ?? '', $r['supplier'], $r['warranty_until'] ?? '', $r['repairs'], $r['repair_cost'], $r['notes'],
        ]));
    }
    exit;
}

function act_tool_get(array $in, array $me): void
{
    $t = load_tool((int)($in['id'] ?? 0));
    if (!is_management($me) && (int)$t['holder_id'] !== $me['id']) {
        fail('This tool isn\'t issued to you.', 403);
    }
    $t['moves'] = q('SELECT m.action, m.`condition`, m.note, m.items, m.created_at, u.name AS user_name, b.name AS by_name FROM tool_moves m
        LEFT JOIN users u ON u.id = m.user_id LEFT JOIN users b ON b.id = m.by_user WHERE m.tool_id = ? ORDER BY m.id DESC', [$t['id']])->fetchAll();
    $t['repairs'] = q('SELECT r.*, h.name AS holder_name, b.name AS reported_by_name FROM tool_repairs r
        LEFT JOIN users h ON h.id = r.holder_id LEFT JOIN users b ON b.id = r.reported_by WHERE r.tool_id = ? ORDER BY r.id DESC', [$t['id']])->fetchAll();
    $t['files'] = attachments_for('tool', [$t['id']])[$t['id']] ?? [];
    $t['laptop'] = is_laptop($t);
    json_out(['ok' => true, 'tool' => $t]);
}

function act_tool_save(array $in, array $me): void
{
    require_management();
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
        'warranty_until' => valid_date($in['warranty_until'] ?? null) ? $in['warranty_until'] : null,
        'notes' => str_in($in['notes'] ?? '', 2000),
    ];
    foreach (TOOL_SPEC_FIELDS as $k => $len) {
        $v[$k] = str_in($in[$k] ?? '', $len);
    }
    if ($v['name'] === '') {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['name' => 'Enter the tool name.']], 422);
    }
    if ($v['tag_code'] !== '' && q('SELECT id FROM tools WHERE tag_code = ? AND id <> ?', [$v['tag_code'], $id])->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['tag_code' => 'Another tool already has this tag.']], 422);
    }
    if ($v['serial_no'] !== '' && q('SELECT id FROM tools WHERE serial_no = ? AND id <> ?', [$v['serial_no'], $id])->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['serial_no' => 'Another tool already has this serial number.']], 422);
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
            tool_move($id, (int)$old['holder_id'], 'condition', $v['condition'], 'Condition changed from ' . $old['condition'] . '.', $me['id']);
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

/** What a laptop record still needs before it can be handed to staff. */
function laptop_missing(array $t): array
{
    $need = ['brand' => 'brand', 'model' => 'model', 'serial_no' => 'serial number', 'ram' => 'RAM', 'storage' => 'storage'];
    $missing = array_values(array_map(fn($k) => $need[$k], array_filter(array_keys($need), fn($k) => trim((string)$t[$k]) === '')));
    if (!q("SELECT 1 FROM attachments WHERE owner_type = 'tool' AND owner_id = ? AND mime LIKE 'image/%' LIMIT 1", [$t['id']])->fetchColumn()) {
        $missing[] = 'a photo';
    }
    return $missing;
}

function act_tool_issue(array $in, array $me): void
{
    require_management();
    $t = load_tool((int)($in['id'] ?? 0));
    $uid = (int)($in['user_id'] ?? 0);
    $u = q('SELECT id, name FROM users WHERE id = ? AND active = 1', [$uid])->fetch();
    if (!$u) {
        fail('Choose an active staff member.');
    }
    if (in_array($t['condition'], ['lost', 'retired', 'repair'], true)) {
        fail('This tool is marked ' . ($t['condition'] === 'repair' ? 'at repair' : $t['condition']) . '. Change its condition before issuing it.');
    }
    if (is_laptop($t) && ($missing = laptop_missing($t))) {
        fail('Record this laptop\'s ' . implode(', ', $missing) . ' before issuing it. Press Edit to add them.');
    }
    $note = str_in($in['note'] ?? '', 1000);
    $items = tool_items_in($in['items'] ?? []);
    db()->beginTransaction();
    if ($t['holder_id'] && (int)$t['holder_id'] !== $uid) {
        tool_move($t['id'], (int)$t['holder_id'], 'return', $t['condition'], 'Returned when reissued to ' . $u['name'] . '.', $me['id']);
    }
    q('UPDATE tools SET holder_id = ?, issued_at = NOW() WHERE id = ?', [$uid, $t['id']]);
    tool_move($t['id'], $uid, 'issue', $t['condition'], $note, $me['id'], $items);
    audit('tool_issue', 'tool', $t['id'], ['to' => $uid]);
    db()->commit();
    touch_change();
    $body = trim(($t['tag_code'] ? 'Tag ' . $t['tag_code'] . '. ' : '') . ($items ? 'With: ' . $items . '. ' : '') . $note);
    notify([$uid], 'tool', 'Tool issued to you: ' . $t['name'], $body, 'profile', false);
    json_out(['ok' => true]);
}

function act_tool_return(array $in, array $me): void
{
    require_management();
    $t = load_tool((int)($in['id'] ?? 0));
    if (!$t['holder_id']) {
        fail('This tool is already in the store.');
    }
    $cond = in_array($in['condition'] ?? '', TOOL_CONDITIONS, true) ? $in['condition'] : $t['condition'];
    db()->beginTransaction();
    q('UPDATE tools SET holder_id = NULL, issued_at = NULL, `condition` = ? WHERE id = ?', [$cond, $t['id']]);
    tool_move($t['id'], (int)$t['holder_id'], 'return', $cond, str_in($in['note'] ?? '', 1000), $me['id'], tool_items_in($in['items'] ?? []));
    audit('tool_return', 'tool', $t['id'], ['from' => (int)$t['holder_id'], 'condition' => $cond]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

/** Staff report a problem with a tool they hold; management can log one for any tool. */
function act_tool_report_issue(array $in, array $me): void
{
    $t = load_tool((int)($in['id'] ?? 0));
    if (!is_management($me) && (int)$t['holder_id'] !== $me['id']) {
        fail('You can only report issues with tools issued to you.', 403);
    }
    $issue = str_in($in['issue'] ?? '', 2000);
    if ($issue === '') {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => ['issue' => 'Describe the problem.']], 422);
    }
    if (open_repair($t['id'])) {
        fail('This tool already has an open issue. Management will update it.');
    }
    $existing = (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type = 'tool' AND owner_id = ?", [$t['id']])->fetchColumn();
    if ($err = check_uploads('files', $existing)) {
        fail($err);
    }
    $cond = $t['condition'] === 'repair' ? 'repair' : 'faulty';
    db()->beginTransaction();
    q("INSERT INTO tool_repairs (tool_id, holder_id, issue, reported_by, reported_at, status, updated_at) VALUES (?,?,?,?,NOW(),'open',NOW())",
        [$t['id'], $t['holder_id'] ?: null, $issue, $me['id']]);
    q('UPDATE tools SET `condition` = ? WHERE id = ?', [$cond, $t['id']]);
    tool_move($t['id'], (int)$t['holder_id'], 'problem', $cond, $issue, $me['id']);
    save_uploads('tool', $t['id'], $me['id']);
    audit('tool_issue_report', 'tool', $t['id']);
    db()->commit();
    touch_change();
    notify(management_ids([$me['id']]), 'tool', 'Issue reported: ' . $t['name'] . ($t['tag_code'] ? ' (' . $t['tag_code'] . ')' : ''),
        $me['name'] . ': ' . $issue, 'tools', false);
    json_out(['ok' => true]);
}

/** Send a tool out for repair. Logs the issue first if nobody reported one. */
function act_tool_repair_send(array $in, array $me): void
{
    require_management();
    $t = load_tool((int)($in['id'] ?? 0));
    $rep = open_repair($t['id']);
    if ($rep && $rep['status'] === 'at_repair') {
        fail('This tool is already at repair.');
    }
    $repairer = str_in($in['repairer'] ?? '', 120);
    $issue = str_in($in['issue'] ?? '', 2000);
    $errs = [];
    if ($repairer === '') {
        $errs['repairer'] = 'Enter who is repairing it.';
    }
    if (!$rep && $issue === '') {
        $errs['issue'] = 'Describe the problem.';
    }
    if ($errs) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errs], 422);
    }
    $sent = valid_date($in['sent_at'] ?? null) ? $in['sent_at'] : today();
    $expected = valid_date($in['expected_at'] ?? null) ? $in['expected_at'] : null;
    $phone = str_in($in['repairer_phone'] ?? '', 40);
    db()->beginTransaction();
    if ($rep) {
        q("UPDATE tool_repairs SET status = 'at_repair', repairer = ?, repairer_phone = ?, sent_at = ?, expected_at = ?,
            issue = CONCAT(COALESCE(issue, ''), ?), updated_at = NOW() WHERE id = ?",
            [$repairer, $phone, $sent, $expected, $issue !== '' ? "\n" . $issue : '', $rep['id']]);
    } else {
        q("INSERT INTO tool_repairs (tool_id, holder_id, issue, reported_by, reported_at, status, repairer, repairer_phone, sent_at, expected_at, updated_at)
            VALUES (?,?,?,?,NOW(),'at_repair',?,?,?,?,NOW())", [$t['id'], $t['holder_id'] ?: null, $issue, $me['id'], $repairer, $phone, $sent, $expected]);
    }
    if ($t['holder_id']) {
        tool_move($t['id'], (int)$t['holder_id'], 'return', 'faulty', 'Collected to send for repair.', $me['id']);
    }
    q("UPDATE tools SET `condition` = 'repair', holder_id = NULL, issued_at = NULL WHERE id = ?", [$t['id']]);
    tool_move($t['id'], null, 'repair', 'repair', 'Sent to ' . $repairer . ($expected ? ', expected back ' . $expected : '') . '.', $me['id']);
    audit('tool_repair_send', 'tool', $t['id'], ['repairer' => $repairer]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

/** Close an open issue: back from the repairer (fixed or not), or sorted without a repair. */
function act_tool_repair_close(array $in, array $me): void
{
    require_management();
    $t = load_tool((int)($in['id'] ?? 0));
    $rep = open_repair($t['id']);
    if (!$rep) {
        fail('This tool has no open issue.');
    }
    $outcome = ($in['outcome'] ?? '') === 'unfixable' ? 'unfixable' : 'fixed';
    $cond = in_array($in['condition'] ?? '', TOOL_CONDITIONS, true) && $in['condition'] !== 'repair'
        ? $in['condition'] : ($outcome === 'fixed' ? 'good' : 'faulty');
    $returned = valid_date($in['returned_at'] ?? null) ? $in['returned_at'] : today();
    $cost = max(0, money_in($in['cost'] ?? 0));
    $resolution = str_in($in['resolution'] ?? '', 2000);
    $giveBack = !empty($in['give_back']) && $rep['holder_id'] && !$t['holder_id'] && !in_array($cond, ['lost', 'retired'], true)
        ? q('SELECT id, name FROM users WHERE id = ? AND active = 1', [$rep['holder_id']])->fetch() : null;
    db()->beginTransaction();
    q('UPDATE tool_repairs SET status = ?, returned_at = ?, cost = ?, resolution = ?, closed_by = ?, updated_at = NOW() WHERE id = ?',
        [$outcome, $returned, $cost, $resolution, $me['id'], $rep['id']]);
    q('UPDATE tools SET `condition` = ? WHERE id = ?', [$cond, $t['id']]);
    $note = ($outcome === 'fixed' ? 'Fixed' : 'Could not be fixed') . ($rep['repairer'] ? ' by ' . $rep['repairer'] : '')
        . ($cost ? ' · cost ' . number_format($cost, 2) : '') . ($resolution !== '' ? ' · ' . $resolution : '');
    tool_move($t['id'], null, 'repaired', $cond, $note, $me['id']);
    if ($giveBack) {
        q('UPDATE tools SET holder_id = ?, issued_at = NOW() WHERE id = ?', [$giveBack['id'], $t['id']]);
        tool_move($t['id'], (int)$giveBack['id'], 'issue', $cond, 'Given back after repair.', $me['id']);
    }
    audit('tool_repair_close', 'tool', $t['id'], ['outcome' => $outcome, 'cost' => $cost]);
    db()->commit();
    touch_change();
    if ($giveBack) {
        notify([(int)$giveBack['id']], 'tool', 'Your ' . $t['name'] . ' is back from repair', $resolution, 'profile', false);
    }
    json_out(['ok' => true]);
}

function act_tool_delete(array $in, array $me): void
{
    require_admin();
    $t = load_tool((int)($in['id'] ?? 0));
    db()->beginTransaction();
    delete_attachments_of('tool', $t['id']);
    q('DELETE FROM tool_moves WHERE tool_id = ?', [$t['id']]);
    q('DELETE FROM tool_repairs WHERE tool_id = ?', [$t['id']]);
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
const SITE_RADIUS = 300;      // metres from a school's GPS point before a clock-in there is flagged "far"
const WORK_NOTE_MIN = 10;     // characters in the "what did you do here?" note at clock-out

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

/** Suspensions (from queries) per staff member that overlap a period: [uid => [[from, to], ...]]. Suspended days aren't absences. */
function suspension_ranges(array $uids, string $from, string $to): array
{
    $uids = array_filter(array_map('intval', $uids));
    if (!$uids) {
        return [];
    }
    $out = [];
    foreach (q("SELECT user_id, suspend_from, suspend_to FROM staff_queries WHERE status = 'suspended' AND user_id IN (" . implode(',', $uids) . ")
                AND suspend_from <= ? AND suspend_to >= ? AND suspend_to >= suspend_from", [$to, $from])->fetchAll() as $r) {
        $out[(int)$r['user_id']][] = [$r['suspend_from'], $r['suspend_to']];
    }
    return $out;
}

function is_suspended_on(array $ranges, string $date): bool
{
    foreach ($ranges as [$a, $b]) {
        if ($date >= $a && $date <= $b) {
            return true;
        }
    }
    return false;
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
        $m = metres_between($lat, $lng, $slat, $slng);
        if ($best[1] === null || $m < $best[1]) {
            $best = [$name, $m];
        }
    }
    return $best;
}

/** Great-circle distance in metres. */
function metres_between(float $lat, float $lng, float $lat2, float $lng2): int
{
    $dLat = deg2rad($lat2 - $lat);
    $dLng = deg2rad($lng2 - $lng);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int)round(6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/** Work types a staff member can tag at clock-out. */
function work_types(): array
{
    return array_merge(DUTIES, ['Other']);
}

/** "Office"/"Other" are never anyone's assigned school, so visits there aren't flagged. */
function is_office_location(string $name): bool
{
    return (bool)preg_match('/^(office|other)\b/i', $name);
}

/** Job orders tagged at each clock-out: {attendance id: [{id, ref, title}]}. */
function attendance_jobs_for(array $ids): array
{
    $ids = array_filter(array_map('intval', $ids));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (q('SELECT aj.attendance_id, j.id, j.title FROM attendance_jobs aj JOIN jobs j ON j.id = aj.job_id
        WHERE aj.attendance_id IN (' . implode(',', $ids) . ') ORDER BY j.id')->fetchAll() as $r) {
        $out[(int)$r['attendance_id']][] = ['id' => (int)$r['id'], 'ref' => job_ref((int)$r['id']), 'title' => $r['title']];
    }
    return $out;
}

/**
 * A day can have several visits (one clock-in/out per location). Only the day's first clock-in can be late and only
 * its last clock-out can be early, so clear those flags on the visits in between, and add the tagged jobs.
 */
function attendance_visits(array $rows): array
{
    $first = $last = [];
    foreach ($rows as $r) {
        $k = $r['user_id'] . '|' . $r['work_date'];
        $first[$k] = isset($first[$k]) && $first[$k] <= $r['in_at'] ? $first[$k] : $r['in_at'];
        $last[$k] = isset($last[$k]) && $last[$k] >= $r['in_at'] ? $last[$k] : $r['in_at'];
    }
    $jobs = attendance_jobs_for(array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $k = $r['user_id'] . '|' . $r['work_date'];
        $r['first_visit'] = $r['in_at'] === $first[$k];
        if (!$r['first_visit']) {
            $r['late'] = false;
            $r['late_min'] = 0;
        }
        if ($r['in_at'] !== $last[$k]) {
            $r['early'] = false;
        }
        $r['jobs'] = $jobs[$r['id']] ?? [];
    }
    return $rows;
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
    $r['location_label'] = ($r['location'] ?? '') !== '' ? $r['location'] : ($r['in_site'] ?? '');
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
    $visits = attendance_visits(array_map('attendance_row',
        q('SELECT * FROM attendance WHERE user_id = ? AND work_date = ? ORDER BY in_at', [$me['id'], today()])->fetchAll()));
    $open = open_attendance($me['id']);
    $sites = [];
    foreach (site_coords() as $name => [$lat, $lng]) {
        $sites[] = ['name' => $name, 'lat' => $lat, 'lng' => $lng];
    }
    json_out(['ok' => true, 'record' => $visits ? $visits[count($visits) - 1] : null, 'open' => $open ? attendance_row($open) : null,
        'visits' => $visits, 'my_schools' => $me['schools'], 'sites' => $sites, 'site_radius' => SITE_RADIUS,
        'work_types' => work_types(), 'work_note_min' => WORK_NOTE_MIN, 'lessons' => lessons_today($me['id']), 'jobs_due' => jobs_due_for($me['id']),
        'work_start' => work_start(), 'work_end' => work_end(), 'late_grace' => late_grace(), 'server_time' => time(),
        'suspension' => active_suspension($me['id'])]);
}

/** Rejects a clock-in/out with a code the app uses to retry or explain. */
function clock_fail(string $code, string $message, array $extra = []): void
{
    json_out(['ok' => false, 'error' => $message, 'code' => $code] + $extra, 422);
}

/**
 * Clock in or out. Strict: a fresh selfie, a fresh GPS fix taken when the button was pressed, the phone's clock
 * within 5 minutes of the server, and a reason when late or leaving early. Without GPS a reason is required and the
 * record is flagged.
 */
function act_clock(array $in, array $me): void
{
    $action = ($in['action'] ?? '') === 'out' ? 'out' : 'in';
    if ($action === 'in') {
        refuse_if_suspended($me['id'], 'clock in');
    }
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
    $location = '';
    $clientId = null;
    $jobs = [];
    $workType = $workNote = '';
    $lastStop = 0;
    if ($action === 'in') {
        if ($open) {
            fail('You\'re already clocked in at ' . (attendance_row($open)['location_label'] ?: 'a location') . ' since '
                . date('g:ia', strtotime($open['in_at'])) . '. Clock out there first.');
        }
        $want = mb_strtolower(trim((string)($in['location'] ?? '')));
        foreach (location_options() as $name) {
            if (mb_strtolower($name) === $want) {
                $location = $name;
            }
        }
        if ($location === '') {
            clock_fail('location', 'Choose the school or location you\'re at.');
        }
        $clientId = q('SELECT id FROM clients WHERE name = ?', [$location])->fetchColumn() ?: null;
        // Lateness is judged on the day's first clock-in only; later visits to other schools are never "late".
        $firstToday = !q('SELECT 1 FROM attendance WHERE user_id = ? AND work_date = ?', [$me['id'], today()])->fetchColumn();
        $late = $firstToday && $now >= strtotime(today() . ' ' . work_start()) + late_grace() * 60 + 60;
        if ($late && $reason === '' && !in_array('no_gps', $flags, true)) {
            clock_fail('late_reason', 'You\'re late (work starts at ' . work_start() . '). Say why.');
        }
        $coords = site_coords()[$location] ?? null;
        if ($coords && $lat !== null && metres_between($lat, $lng, $coords[0], $coords[1]) > SITE_RADIUS) {
            $flags[] = 'far';
        }
        if (!is_office_location($location) && !in_array(mb_strtolower($location), array_map('mb_strtolower', $me['schools']), true)) {
            $flags[] = 'unassigned';
        }
    } else {
        if (!$open) {
            fail('You haven\'t clocked in yet.');
        }
        $workType = in_array($in['work_type'] ?? '', work_types(), true) ? $in['work_type'] : '';
        $workNote = str_in($in['work_note'] ?? '', 2000);
        if ($workType === '' || mb_strlen($workNote) < WORK_NOTE_MIN) {
            clock_fail('work_tag', 'Say what work you did here: choose the type of work and write a short note.');
        }
        foreach (array_unique(array_map('intval', (array)($in['jobs'] ?? []))) as $jobId) {
            $status = $jobId > 0 ? q('SELECT status FROM jobs WHERE id = ?', [$jobId])->fetchColumn() : false;
            if (!$status || !in_array($me['id'], job_assignee_ids($jobId), true)) {
                fail(job_ref($jobId) . ' isn\'t one of your job orders.');
            }
            $jobs[$jobId] = $status;
        }
        // Leaving one school for the next isn't leaving early; only the day's last clock-out is.
        $lastStop = !empty($in['last_stop']) ? 1 : 0;
        $early = $lastStop && $open['work_date'] === today() && $now < strtotime(today() . ' ' . work_end());
        if ($early && $reason === '' && !in_array('no_gps', $flags, true)) {
            clock_fail('early_reason', 'Work ends at ' . work_end() . '. Say why you\'re leaving early.');
        }
        // The day can't end with job orders or tasks due today left unfinished, unless an admin gave more time.
        if ($lastStop && ($due = jobs_due_for($me['id']))) {
            $n = count($due);
            clock_fail('jobs_due', "You can't end your day yet: $n job order item" . ($n === 1 ? ' is' : 's are') . ' due and not finished. '
                . 'Mark each one done, or ask for more time and wait for an admin to approve.', ['items' => $due]);
        }
        // Leaving a school for the next one: what's due at this school must be ticked done, or have more time asked for.
        $place = attendance_row($open)['location_label'];
        $here = array_values(array_filter(jobs_due_for($me['id']),
            fn($x) => !$x['pending_extension'] && $place !== '' && mb_strtolower(trim($x['client_name'])) === mb_strtolower(trim($place))));
        if (!$lastStop && $here) {
            $n = count($here);
            clock_fail('jobs_due_here', "Before you leave $place, tick off what you finished here or ask for more time: $n item"
                . ($n === 1 ? ' is' : 's are') . ' due.', ['items' => $here]);
        }
    }

    [$site, $dist] = nearest_site($lat, $lng);
    db()->beginTransaction();
    if ($action === 'in') {
        q('INSERT INTO attendance (user_id, work_date, in_at, in_lat, in_lng, in_acc, in_site, in_dist, in_gps_ts, in_skew, in_reason, flags,
            client_id, location, source, created_at) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,\'app\',NOW())',
            [$me['id'], today(), $lat, $lng, $acc, $site, $dist, $gpsAt, $skew, $reason, implode(',', $flags), $clientId, $location]);
        $id = (int)db()->lastInsertId();
        save_uploads('attend', $id, $me['id']);
    } else {
        $id = (int)$open['id'];
        $allFlags = implode(',', array_unique(array_filter(array_merge(explode(',', (string)$open['flags']), $flags))));
        q('UPDATE attendance SET out_at = NOW(), out_lat = ?, out_lng = ?, out_acc = ?, out_site = ?, out_dist = ?, out_gps_ts = ?, out_skew = ?,
            out_reason = ?, flags = ?, work_type = ?, work_note = ?, last_stop = ? WHERE id = ?',
            [$lat, $lng, $acc, $site, $dist, $gpsAt, $skew, $reason, $allFlags, $workType, $workNote, $lastStop, $id]);
        save_uploads('attendout', $id, $me['id']);
        $place = attendance_row($open)['location_label'] ?: 'site';
        foreach ($jobs as $jobId => $status) {
            q('INSERT IGNORE INTO attendance_jobs (attendance_id, job_id) VALUES (?, ?)', [$id, $jobId]);
            log_job_work($jobId, $status, 'visit', "Worked on this at $place ($workType): $workNote", $me['id']);
        }
    }
    db()->commit();
    touch_change();
    if ($action === 'out') {
        clock_out_pending_notice($me['id'], (bool)$lastStop);
    }
    $r = attendance_row(q('SELECT * FROM attendance WHERE id = ?', [$id])->fetch());
    json_out(['ok' => true, 'record' => $r]);
}

/**
 * "Pending assignment" notice after a clock-out: mid-day, the items still due today; at the end of the day, the items
 * an admin gave more time on today (carried to a later day).
 */
function clock_out_pending_notice(int $uid, bool $lastStop): void
{
    $carried = fn($x) => (bool)q("SELECT 1 FROM job_extensions WHERE job_id = ? AND " . ($x['kind'] === 'task' ? 'task_id = ?' : 'task_id IS NULL AND ? > 0')
        . " AND status IN ('approved','lapsed') AND decided_at >= ?", [$x['job_id'], $x['id'], today()])->fetchColumn();
    $items = $lastStop ? array_values(array_filter(jobs_due_for($uid, true), $carried)) : jobs_due_for($uid);
    if (!$items) {
        return;
    }
    $n = count($items);
    $lines = array_map(fn($x) => "{$x['ref']} " . ($x['kind'] === 'task' ? "{$x['title']} ({$x['job_title']})" : $x['title'])
        . ' · due ' . due_label($x['due_date'], $x['due_time']), $items);
    $title = $lastStop ? "$n job order item" . ($n === 1 ? '' : 's') . ' carried over with admin approval'
        : "You have $n pending job order item" . ($n === 1 ? '' : 's') . ' due today';
    notify([$uid], 'job', $title, implode("\n", $lines), 'jobs', $lastStop);
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
    $w = "u.active = 1 AND u.role IN ('staff','supervisor','subadmin','manager')";
    if ($uid) {
        $w = 'u.id = ?';
        $p[] = $uid;
    }
    $staff = q("SELECT u.id, u.name, u.location, u.start_date, DATE(u.created_at) AS joined FROM users u WHERE $w ORDER BY u.name", $p)->fetchAll();
    $byUser = [];
    $ids = array_map('intval', array_column($staff, 'id'));
    $suspended = suspension_ranges($ids, $from, $to);
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
        $st = ['working_days' => 0, 'present' => 0, 'extra_days' => 0, 'absent' => 0, 'suspended' => 0, 'late' => 0, 'late_min' => 0, 'early' => 0,
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
                    'site' => $first['location_label'],
                    'flags' => implode(',', array_unique(array_filter(explode(',', implode(',', array_column($recs, 'flags')))))),
                    'visits' => count($recs), 'locations' => array_values(array_unique(array_filter(array_column($recs, 'location_label'))))];
                $day['status'] = $missed ? 'missed' : (!$isWork ? 'extra' : ($late ? 'late' : 'present'));
            } elseif ($isWork && is_suspended_on($suspended[$sid] ?? [], $date)) {
                // A suspension is served, not missed: it doesn't count against attendance.
                $day['status'] = 'suspended';
                $st['suspended'] += $date <= $end ? 1 : 0;
            } elseif ($isWork && $date <= $end && $date >= $since) {
                $st['absent']++;
                $day['status'] = 'absent';
            } elseif ($isWork) {
                $day['status'] = $date > $end ? 'future' : 'before_start';
            }
            if ($isWork && $date <= $end && $date >= $since && $day['status'] !== 'suspended') {
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
    $uid = is_management($me) ? ((int)($in['user_id'] ?? 0) ?: null) : $me['id'];
    json_out(['ok' => true] + attendance_report($from, $to, $uid));
}

function act_attendance_report_export(array $in, array $me): void
{
    require_management();
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
    $rows = attendance_visits(array_map('attendance_row', $rows));
    foreach ($rows as &$r) {
        $r['in_photo'] = $in[$r['id']][0]['id'] ?? null;
        $r['out_photo'] = $out[$r['id']][0]['id'] ?? null;
    }
    return $rows;
}

const ATT_COLS = 'a.id, a.user_id, u.name AS user_name, a.work_date, a.in_at, a.in_lat, a.in_lng, a.in_acc, a.in_site, a.in_dist,
    a.out_at, a.out_lat, a.out_lng, a.out_acc, a.out_site, a.out_dist, a.note, a.source, a.in_reason, a.out_reason, a.flags, a.out_missed,
    a.in_gps_ts, a.in_skew, a.location, a.client_id, a.work_type, a.work_note, a.last_stop';

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
    if (!is_management($me)) {
        $w .= ' AND a.user_id = ?';
        $p[] = $me['id'];
    } elseif (!empty($in['user_id'])) {
        $w .= ' AND a.user_id = ?';
        $p[] = (int)$in['user_id'];
    }
    $rows = attendance_with_photos(q('SELECT ' . ATT_COLS . " FROM attendance a JOIN users u ON u.id = a.user_id WHERE $w
        ORDER BY a.work_date DESC, a.in_at", $p)->fetchAll());
    $absent = [];
    if (is_management($me) && $from === $to && empty($in['user_id'])) {
        $present = array_unique(array_column($rows, 'user_id'));
        $absent = array_values(array_filter(q("SELECT id, name, location FROM users WHERE active = 1 AND role IN ('staff','supervisor','subadmin','manager') ORDER BY name")->fetchAll(),
            fn($u) => !in_array((int)$u['id'], array_map('intval', $present), true)));
        $susp = suspension_ranges(array_column($absent, 'id'), $from, $from);
        foreach ($absent as &$a) {
            $a['suspended'] = isset($susp[(int)$a['id']]);
        }
        unset($a);
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
    $location = str_in($in['location'] ?? ($old['location'] ?? ''), 120);
    $clientId = $location !== '' ? (q('SELECT id FROM clients WHERE name = ?', [$location])->fetchColumn() ?: null) : null;
    $workType = in_array($in['work_type'] ?? '', work_types(), true) ? $in['work_type'] : (string)($old['work_type'] ?? '');
    $workNote = array_key_exists('work_note', $in) ? str_in($in['work_note'], 2000) : (string)($old['work_note'] ?? '');
    if ($old) {
        q('UPDATE attendance SET in_at = ?, out_at = ?, out_missed = 0, flags = TRIM(BOTH \',\' FROM CONCAT(flags, \',admin_edit\')),
            note = TRIM(CONCAT(COALESCE(note, \'\'), ?)), edited_by = ?, location = ?, client_id = ?, work_type = ?, work_note = ? WHERE id = ?',
            [$inAt, $outAt, "\nAdmin: " . $note, $me['id'], $location, $clientId, $workType, $workNote, $id]);
        audit('attendance_edit', 'attendance', $id, ['in' => [$old['in_at'], $inAt], 'out' => [$old['out_at'], $outAt],
            'location' => [$old['location'], $location], 'note' => $note]);
    } else {
        q('INSERT INTO attendance (user_id, work_date, in_at, out_at, note, flags, source, edited_by, location, client_id, work_type, work_note, created_at)
            VALUES (?,?,?,?,?,\'admin_edit\',\'admin\',?,?,?,?,?,NOW())',
            [$uid, $date, $inAt, $outAt, 'Admin: ' . $note, $me['id'], $location, $clientId, $workType, $workNote]);
        $id = (int)db()->lastInsertId();
        audit('attendance_add', 'attendance', $id, ['user' => $uid, 'date' => $date, 'note' => $note]);
    }
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}

function act_attendance_export(array $in, array $me): void
{
    require_management();
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
    fputcsv($out, ['Date', 'Staff', 'Location', 'Arrived', 'Left', 'Hours', 'Late', 'Left early', 'Work type', 'Work done', 'Job orders', 'Flags',
        'Nearest GPS site', 'Distance (m)', 'Arrival GPS', 'Leaving GPS', 'Source', 'Notes']);
    foreach (attendance_visits(array_map('attendance_row', $rows)) as $r) {
        fputcsv($out, array_map(fn($x) => csv_safe((string)$x), [
            $r['work_date'], $r['user_name'], $r['location_label'], substr($r['in_at'], 11, 5), $r['out_at'] ? substr($r['out_at'], 11, 5) : '',
            $r['minutes'] !== null ? round($r['minutes'] / 60, 2) : '', $r['late'] ? 'Yes' : 'No', $r['early'] ? 'Yes' : 'No',
            $r['work_type'], $r['work_note'] ?? '', implode(' ', array_column($r['jobs'], 'ref')), $r['flags'], $r['in_site'], $r['in_dist'] ?? '',
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
    $staff = q("SELECT id, name FROM users WHERE active = 1 AND role IN ('staff','supervisor','subadmin','manager') ORDER BY name")->fetchAll();
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
