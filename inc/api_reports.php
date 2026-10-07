<?php
/* Daily work reports */

const REPORT_LIST_COLS = 'r.id, r.user_id, u.name AS user_name, r.report_date, r.location, r.work_type, r.finished, r.lesson,
    r.classes_taught, r.laptops_total, r.laptops_faulty, r.laptop_resolved, r.complaint, r.followup, r.urgent, r.installation,
    r.issues_closed, r.source, r.created_at, r.updated_at';

/** Summary columns stored alongside the JSON so lists and dashboards stay fast. */
function report_summary(array $d): array
{
    return [
        'location'        => mb_substr((string)($d['location'] ?? ''), 0, 120),
        'work_type'       => mb_substr((string)($d['work_type'] ?? ''), 0, 120),
        'finished'        => mb_substr((string)($d['finished'] ?? ''), 0, 60),
        'lesson'          => mb_substr((string)($d['lesson'] ?? ''), 0, 10),
        'classes_taught'  => (int)($d['classes_taught'] ?? 0),
        'laptops_total'   => (int)($d['laptops_total'] ?? 0),
        'laptops_faulty'  => (int)($d['laptops_faulty'] ?? 0),
        'laptop_resolved' => mb_substr((string)($d['laptop_resolved'] ?? ''), 0, 60),
        'complaint'       => mb_substr((string)($d['complaint'] ?? ''), 0, 120),
        'followup'        => mb_substr((string)($d['followup'] ?? ''), 0, 120),
        'urgent'          => mb_substr((string)($d['urgent'] ?? ''), 0, 60),
        'installation'    => mb_substr((string)($d['install'] ?? ''), 0, 10),
    ];
}

function report_where(array $in, array $me, array &$params): string
{
    $w = ['1=1'];
    if (!is_admin($me)) {
        $w[] = 'r.user_id = ?';
        $params[] = $me['id'];
    } elseif (!empty($in['user_id'])) {
        $w[] = 'r.user_id = ?';
        $params[] = (int)$in['user_id'];
    }
    if (valid_date($in['from'] ?? null)) {
        $w[] = 'r.report_date >= ?';
        $params[] = $in['from'];
    }
    if (valid_date($in['to'] ?? null)) {
        $w[] = 'r.report_date <= ?';
        $params[] = $in['to'];
    }
    if (!empty($in['location'])) {
        $w[] = 'r.location = ?';
        $params[] = (string)$in['location'];
    }
    if (!empty($in['finished'])) {
        $w[] = 'r.finished = ?';
        $params[] = (string)$in['finished'];
    }
    if (!empty($in['work_type'])) {
        $w[] = 'r.work_type = ?';
        $params[] = (string)$in['work_type'];
    }
    if (!empty($in['q'])) {
        $w[] = '(r.data LIKE ? OR u.name LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    return implode(' AND ', $w);
}

function act_reports_list(array $in, array $me): void
{
    $params = [];
    $where = report_where($in, $me, $params);
    $limit = min(1000, max(1, (int)($in['limit'] ?? 500)));
    $rows = q('SELECT ' . REPORT_LIST_COLS . " FROM reports r JOIN users u ON u.id = r.user_id WHERE $where
               ORDER BY r.report_date DESC, r.id DESC LIMIT $limit", $params)->fetchAll();
    $files = attachments_for('report', array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['files'] = count($files[(int)$r['id']] ?? []);
    }
    json_out(['ok' => true, 'items' => $rows]);
}

function load_report(int $id, array $me): array
{
    $r = q('SELECT r.*, u.name AS user_name, u.email AS user_email FROM reports r JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$id])->fetch();
    if (!$r) {
        fail('Report not found.', 404);
    }
    if (!is_admin($me) && (int)$r['user_id'] !== $me['id']) {
        fail('You can only view your own reports.', 403);
    }
    $r['data'] = json_decode($r['data'], true) ?: [];
    return $r;
}

function act_report_get(array $in, array $me): void
{
    $r = load_report((int)($in['id'] ?? 0), $me);
    $r['attachments'] = attachments_for('report', [$r['id']])[(int)$r['id']] ?? [];
    $r['expenses'] = q("SELECT id, category, amount, status, txn_date FROM wallet_txns WHERE report_id = ? ORDER BY id", [$r['id']])->fetchAll();
    json_out(['ok' => true, 'report' => $r]);
}

function act_report_save(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $existing = $id ? load_report($id, $me) : null;
    [$data, $errors] = clean_report((array)($in['data'] ?? []));
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Some answers need attention.', 'fields' => $errors], 422);
    }
    $existingFiles = $existing ? (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='report' AND owner_id = ?", [$id])->fetchColumn() : 0;
    if ($err = check_uploads('files', $existingFiles)) {
        fail($err);
    }
    $sum = report_summary($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    db()->beginTransaction();
    if ($existing) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($sum)));
        q("UPDATE reports SET report_date = ?, $set, data = ?, updated_at = NOW() WHERE id = ?",
            array_merge([$data['report_date']], array_values($sum), [$json, $id]));
        audit('report_update', 'report', $id);
        $ownerId = (int)$existing['user_id'];
    } else {
        $cols = implode(', ', array_keys($sum));
        $qs = implode(', ', array_fill(0, count($sum), '?'));
        q("INSERT INTO reports (user_id, report_date, $cols, data, source, created_at) VALUES (?, ?, $qs, ?, 'app', NOW())",
            array_merge([$me['id'], $data['report_date']], array_values($sum), [$json]));
        $id = (int)db()->lastInsertId();
        $ownerId = $me['id'];
    }
    save_uploads('report', $id, $me['id']);
    db()->commit();
    touch_change();

    if (!$existing) {
        $flags = [];
        if (str_starts_with($sum['urgent'], 'Yes')) {
            $flags[] = 'urgent request';
        }
        if ($sum['complaint'] !== '' && $sum['complaint'] !== 'No complaint') {
            $flags[] = 'complaint';
        }
        if ($sum['laptops_faulty'] > 0) {
            $flags[] = $sum['laptops_faulty'] . ' faulty laptop(s)';
        }
        $title = $me['name'] . ' submitted a report' . ($flags ? ' · ' . implode(', ', $flags) : '');
        // Email admins only when something needs attention.
        notify_admins('report', $title, $sum['location'] . ' · ' . $data['report_date'], 'reports/' . $id, (bool)$flags);
    }
    json_out(['ok' => true, 'id' => $id, 'user_id' => $ownerId]);
}

function act_report_delete(array $in, array $me): void
{
    require_admin();
    $r = load_report((int)($in['id'] ?? 0), $me);
    db()->beginTransaction();
    delete_attachments_of('report', (int)$r['id']);
    q('UPDATE wallet_txns SET report_id = NULL WHERE report_id = ?', [$r['id']]);
    q('DELETE FROM reports WHERE id = ?', [$r['id']]);
    audit('report_delete', 'report', (int)$r['id'], ['user' => $r['user_name'], 'date' => $r['report_date']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

function act_report_issue_toggle(array $in, array $me): void
{
    require_admin();
    $id = (int)($in['id'] ?? 0);
    q('UPDATE reports SET issues_closed = ? WHERE id = ?', [!empty($in['closed']) ? 1 : 0, $id]);
    audit(!empty($in['closed']) ? 'issue_close' : 'issue_reopen', 'report', $id);
    touch_change();
    json_out(['ok' => true]);
}

/** Kanban: reports that need follow-up, grouped by issue type. */
function act_issues(array $in, array $me): void
{
    require_admin();
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime('-30 days'));
    $params = [$from];
    $closed = !empty($in['closed']) ? '' : 'AND r.issues_closed = 0';
    $rows = q('SELECT ' . REPORT_LIST_COLS . ", r.data FROM reports r JOIN users u ON u.id = r.user_id
               WHERE r.report_date >= ? $closed ORDER BY r.report_date DESC, r.id DESC", $params)->fetchAll();
    $cols = ['pending' => [], 'laptops' => [], 'people' => [], 'urgent' => []];
    foreach ($rows as $r) {
        $d = json_decode($r['data'], true) ?: [];
        unset($r['data']);
        $base = $r;
        if ($r['finished'] !== '' && $r['finished'] !== 'Yes – completed') {
            $cols['pending'][] = $base + ['note' => $d['pending_reason'] ?? ''];
        }
        if ((int)$r['laptops_faulty'] > 0 && $r['laptop_resolved'] !== 'Yes – fixed') {
            $cols['laptops'][] = $base + ['note' => $d['faulty_details'] ?? ''];
        }
        $hasComplaint = $r['complaint'] !== '' && $r['complaint'] !== 'No complaint';
        $hasFollow = $r['followup'] !== '' && $r['followup'] !== 'No';
        if ($hasComplaint || $hasFollow) {
            $cols['people'][] = $base + ['note' => $hasComplaint ? ($d['complaint_details'] ?? '') : ($d['followup_details'] ?? ''),
                'tag' => $hasComplaint ? $r['complaint'] : $r['followup']];
        }
        if (str_starts_with($r['urgent'], 'Yes')) {
            $cols['urgent'][] = $base + ['note' => $d['urgent_request'] ?? ''];
        }
    }
    json_out(['ok' => true, 'from' => $from, 'columns' => $cols]);
}

function act_reports_export(array $in, array $me): void
{
    require_admin();
    $params = [];
    $where = report_where($in, $me, $params);
    $rows = q("SELECT r.*, u.name AS user_name, u.email AS user_email FROM reports r JOIN users u ON u.id = r.user_id
               WHERE $where ORDER BY r.report_date, r.id", $params);
    $fields = report_fields();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="work-reports-' . today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Timestamp', 'Email address', 'Full Name'], array_map(fn($f) => $f['label'], array_values($fields)), ['Attachments']));
    while ($r = $rows->fetch()) {
        $d = json_decode($r['data'], true) ?: [];
        $line = [$r['created_at'], $r['user_email'], $r['user_name']];
        foreach ($fields as $id => $f) {
            $v = $d[$id] ?? '';
            $line[] = csv_safe(is_array($v) ? implode(', ', $v) : (string)$v);
        }
        $line[] = (int)q("SELECT COUNT(*) FROM attachments WHERE owner_type='report' AND owner_id = ?", [$r['id']])->fetchColumn();
        fputcsv($out, $line);
    }
    exit;
}

/** Stop spreadsheet apps from treating exported text as formulas. */
function csv_safe(string $v): string
{
    return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) ? "'" . $v : $v;
}
