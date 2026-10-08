<?php
/* Clients directory: schools and companies, their contacts, GPS point, jobs, reports and device fault history. */

const CLIENT_TYPES = ['school', 'company', 'other'];

/** The client with this name, created if it's new, so every job stays linked to the directory. */
function client_id_for(string $name, string $type = 'school'): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $id = q('SELECT id FROM clients WHERE name = ?', [$name])->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    q('INSERT INTO clients (name, type, created_at) VALUES (?, ?, NOW())', [mb_substr($name, 0, 190), in_array($type, CLIENT_TYPES, true) ? $type : 'school']);
    return (int)db()->lastInsertId();
}

/** Names for pickers (job form, report location). */
function client_names(): array
{
    return q('SELECT name FROM clients WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
}

/** Pending device faults from recent reports that haven't been marked handled, grouped by location. */
function pending_faults_by_location(?string $location = null): array
{
    $p = [date('Y-m-d', strtotime('-90 days'))];
    $w = '';
    if ($location !== null) {
        $w = ' AND r.location = ?';
        $p[] = $location;
    }
    $out = [];
    $rows = q("SELECT r.id, r.location, r.report_date, r.data, u.name AS user_name FROM reports r JOIN users u ON u.id = r.user_id
        WHERE r.issues_closed = 0 AND r.report_date >= ? AND r.data LIKE '%\"devices\"%'$w ORDER BY r.report_date DESC", $p)->fetchAll();
    foreach ($rows as $r) {
        foreach (pending_devices(json_decode($r['data'], true) ?: []) as $dev) {
            $out[$r['location']][] = $dev + ['report_id' => (int)$r['id'], 'report_date' => $r['report_date'], 'user_name' => $r['user_name']];
        }
    }
    return $out;
}

function act_clients_list(array $in, array $me): void
{
    require_admin();
    $w = ['1=1'];
    $p = [];
    if (in_array($in['type'] ?? '', CLIENT_TYPES, true)) {
        $w[] = 'c.type = ?';
        $p[] = $in['type'];
    }
    if (empty($in['all'])) {
        $w[] = 'c.active = 1';
    }
    if (!empty($in['q'])) {
        $w[] = '(c.name LIKE ? OR c.area LIKE ? OR c.contact_name LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($p, $like, $like, $like);
    }
    $rows = q("SELECT c.*,
        (SELECT COUNT(*) FROM jobs j WHERE j.client_id = c.id AND j.status NOT IN ('done','cancelled')) AS open_jobs,
        (SELECT COUNT(*) FROM jobs j WHERE j.client_id = c.id AND j.status IN ('open','in_progress','returned','requested') AND j.due_date < CURDATE()) AS overdue,
        (SELECT COUNT(*) FROM jobs j WHERE j.client_id = c.id AND j.status = 'done') AS done_jobs,
        (SELECT MAX(r.report_date) FROM reports r WHERE r.location = c.name) AS last_visit
        FROM clients c WHERE " . implode(' AND ', $w) . ' ORDER BY c.active DESC, c.name', $p)->fetchAll();
    $faults = pending_faults_by_location();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['faults'] = count($faults[$r['name']] ?? []);
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function act_client_get(array $in, array $me): void
{
    require_admin();
    $c = q('SELECT * FROM clients WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
    if (!$c) {
        fail('Client not found.', 404);
    }
    $c['id'] = (int)$c['id'];
    $jobs = array_map('job_row', q(JOB_LIST_SQL . ' WHERE j.client_id = ? ORDER BY j.id DESC LIMIT 200', [$c['id']])->fetchAll());
    $signoffs = q("SELECT id, title, signoff_name, signoff_phone, signoff_at, signoff_skipped, completed_at FROM jobs
        WHERE client_id = ? AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 50", [$c['id']])->fetchAll();
    foreach ($signoffs as &$s) {
        $s['ref'] = job_ref((int)$s['id']);
    }
    unset($s);
    $reports = q('SELECT r.id, r.report_date, r.work_type, r.finished, r.priority, u.name AS user_name FROM reports r JOIN users u ON u.id = r.user_id
        WHERE r.location = ? ORDER BY r.report_date DESC, r.id DESC LIMIT 50', [$c['name']])->fetchAll();
    // Every device entry ever reported at this site, newest first.
    $devices = [];
    foreach (q('SELECT r.id, r.report_date, r.data, u.name AS user_name FROM reports r JOIN users u ON u.id = r.user_id
        WHERE r.location = ? AND r.data LIKE \'%"devices"%\' ORDER BY r.report_date DESC LIMIT 200', [$c['name']])->fetchAll() as $r) {
        foreach ((array)((json_decode($r['data'], true) ?: [])['devices'] ?? []) as $d) {
            $devices[] = $d + ['report_id' => (int)$r['id'], 'report_date' => $r['report_date'], 'user_name' => $r['user_name']];
        }
    }
    json_out(['ok' => true, 'client' => $c, 'jobs' => $jobs, 'signoffs' => $signoffs, 'reports' => $reports, 'devices' => array_slice($devices, 0, 200)]);
}

function act_client_save(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $old = $id ? q('SELECT * FROM clients WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$old) {
        fail('Client not found.', 404);
    }
    $lat = is_numeric($in['lat'] ?? null) && abs((float)$in['lat']) <= 90 ? (float)$in['lat'] : null;
    $lng = is_numeric($in['lng'] ?? null) && abs((float)$in['lng']) <= 180 ? (float)$in['lng'] : null;
    $v = [
        'name' => str_in($in['name'] ?? '', 190),
        'type' => in_array($in['type'] ?? '', CLIENT_TYPES, true) ? $in['type'] : 'school',
        'address' => str_in($in['address'] ?? '', 300),
        'area' => str_in($in['area'] ?? '', 120),
        'contact_name' => str_in($in['contact_name'] ?? '', 120),
        'contact_phone' => str_in($in['contact_phone'] ?? '', 40),
        'contact_email' => str_in($in['contact_email'] ?? '', 190),
        'lat' => $lat !== null && $lng !== null ? $lat : null,
        'lng' => $lat !== null && $lng !== null ? $lng : null,
        'notes' => str_in($in['notes'] ?? '', 5000),
        'active' => !isset($in['active']) || !empty($in['active']) ? 1 : 0,
    ];
    $errors = [];
    if ($v['name'] === '') {
        $errors['name'] = 'Enter the school or company name.';
    } elseif (q('SELECT id FROM clients WHERE name = ? AND id <> ?', [$v['name'], $id])->fetchColumn()) {
        $errors['name'] = 'Another client already has this name.';
    }
    if ($v['contact_email'] !== '' && !filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['contact_email'] = 'Enter a valid email.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    db()->beginTransaction();
    if ($old) {
        q('UPDATE clients SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v))) . ' WHERE id = ?', array_merge(array_values($v), [$id]));
        if ($old['name'] !== $v['name']) {
            // Keep jobs showing the current name.
            q('UPDATE jobs SET client_name = ? WHERE client_id = ?', [$v['name'], $id]);
        }
        audit('client_update', 'client', $id);
    } else {
        q('INSERT INTO clients (' . implode(', ', array_map(fn($k) => "`$k`", array_keys($v))) . ', created_at) VALUES ('
            . implode(',', array_fill(0, count($v), '?')) . ', NOW())', array_values($v));
        $id = (int)db()->lastInsertId();
        audit('client_create', 'client', $id, ['name' => $v['name']]);
    }
    db()->commit();
    touch_change();
    json_out(['ok' => true, 'id' => $id]);
}
