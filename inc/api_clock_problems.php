<?php
declare(strict_types=1);
/*
 * Clock-in problems.
 * Every failed clock-in/out attempt is kept (location_events) with the reason and the GPS details the phone sent, so
 * admins can check "my location doesn't work" from Attendance → Clock-in problems. Rows older than 180 days are
 * deleted once a day.
 */

const EVENT_KEEP_DAYS = 180;

/** Saves a failed clock attempt. Never throws: a logging problem must not hide the real error. */
function log_location_event(array $me, string $kind, string $code, string $message, ?int $acc = null, ?int $gpsAge = null, ?int $skew = null): void
{
    try {
        q('INSERT INTO location_events (user_id, kind, code, message, acc, gps_age, skew, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
            [$me['id'], substr($kind, 0, 20), substr($code, 0, 30), mb_substr($message, 0, 500), $acc, $gpsAge, $skew,
                str_in($_SERVER['HTTP_USER_AGENT'] ?? '', 400)]);
    } catch (Throwable $e) {
        error_log('[portal] clock problem: ' . $e->getMessage());
    }
}

function date_in($v): string
{
    $v = (string)$v;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : today();
}

/** Admin list of failed clock-in/out attempts, newest first, with a per-staff summary. */
function act_clock_problems(array $in, array $me): void
{
    require_admin();
    $from = date_in($in['from'] ?? '');
    $to = date_in($in['to'] ?? '');
    $w = 'e.created_at >= ? AND e.created_at < ?';
    $p = [$from, date('Y-m-d', strtotime($to) + 86400)];
    if ((int)($in['user_id'] ?? 0)) {
        $w .= ' AND e.user_id = ?';
        $p[] = (int)$in['user_id'];
    }
    $items = q("SELECT e.id, e.user_id, u.name AS user_name, e.kind, e.code, e.message, e.acc, e.gps_age, e.skew, e.user_agent, e.created_at
        FROM location_events e JOIN users u ON u.id = e.user_id WHERE $w ORDER BY e.id DESC LIMIT 500", $p)->fetchAll();
    $summary = [];
    foreach ($items as &$x) {
        $x['user_id'] = (int)$x['user_id'];
        $s = $summary[$x['user_id']] ?? ['user_id' => $x['user_id'], 'name' => $x['user_name'], 'count' => 0, 'codes' => [],
            'last' => $x['created_at'], 'user_agent' => $x['user_agent']];
        $s['count']++;
        $s['codes'][$x['code']] = ($s['codes'][$x['code']] ?? 0) + 1;
        $summary[$x['user_id']] = $s;
    }
    unset($x);
    usort($summary, fn($a, $b) => $b['count'] <=> $a['count']);
    json_out(['ok' => true, 'items' => $items, 'summary' => array_values($summary), 'from' => $from, 'to' => $to]);
}

/** Deletes failure logs older than 180 days; runs at most once a day. */
function maybe_clock_problems_cleanup(): void
{
    if (setting('_clock_problems_cleanup') === today()) {
        return;
    }
    save_setting('_clock_problems_cleanup', today());
    q('DELETE FROM location_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - EVENT_KEEP_DAYS * 86400)]);
}
