<?php
/**
 * General notices: management posts one notice to all staff (or everyone, management included). It shows as a banner on
 * each person's home page until they press "Got it", it expires or it is taken down. Posting also alerts phones and
 * adds a task to linked Google Tasks lists.
 */

const NOTICE_AUDIENCES = ['all_staff', 'everyone'];

function notice_ref(int $id): string
{
    return 'NOT-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** SQL for notices still showing: not taken down and not past their expiry. */
const NOTICE_LIVE = '(n.archived_at IS NULL AND (n.expires_at IS NULL OR n.expires_at > NOW()))';

function notice_recipient_ids(string $audience, array $me): array
{
    $roles = $audience === 'everyone' ? MANAGEMENT_ROLES : [];
    $roles = array_values(array_unique(array_merge(EMPLOYEE_ROLES, $roles)));
    $in = implode(',', array_fill(0, count($roles), '?'));
    $ids = q("SELECT id FROM users WHERE active = 1 AND role IN ($in)", $roles)->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff(array_map('intval', $ids), [$me['id']]));
}

function notice_shape(array $n): array
{
    $n['id'] = (int)$n['id'];
    $n['ref'] = notice_ref($n['id']);
    $n['urgent'] = (bool)$n['urgent'];
    $n['live'] = $n['archived_at'] === null && ($n['expires_at'] === null || strtotime($n['expires_at']) > time());
    return $n;
}

/** Unacknowledged live notices, for the sidebar badge. */
function notice_badge(array $me): int
{
    return (int)q('SELECT COUNT(*) FROM notice_reads r JOIN notices n ON n.id = r.notice_id
        WHERE r.user_id = ? AND r.ack_at IS NULL AND ' . NOTICE_LIVE, [$me['id']])->fetchColumn();
}

/** The banners on the home page: live notices sent to me that I have not acknowledged. */
function act_notices_active(array $in, array $me): void
{
    $rows = q('SELECT n.id, n.title, n.body, n.urgent, n.expires_at, n.archived_at, n.created_at, s.name AS sender_name
        FROM notice_reads r JOIN notices n ON n.id = r.notice_id JOIN users s ON s.id = n.sender_id
        WHERE r.user_id = ? AND r.ack_at IS NULL AND ' . NOTICE_LIVE . ' ORDER BY n.urgent DESC, n.id DESC LIMIT 10', [$me['id']])->fetchAll();
    json_out(['ok' => true, 'items' => array_map('notice_shape', $rows)]);
}

/** Management sees every notice with how many have acknowledged it; staff see the ones sent to them. */
function act_notices_list(array $in, array $me): void
{
    $sql = 'SELECT n.*, s.name AS sender_name, mine.ack_at AS my_ack,
        (SELECT COUNT(*) FROM notice_reads x WHERE x.notice_id = n.id) AS recipients,
        (SELECT COUNT(*) FROM notice_reads x WHERE x.notice_id = n.id AND x.ack_at IS NOT NULL) AS acked
        FROM notices n JOIN users s ON s.id = n.sender_id
        LEFT JOIN notice_reads mine ON mine.notice_id = n.id AND mine.user_id = ?';
    $p = [$me['id']];
    if (!is_management($me)) {
        $sql .= ' WHERE mine.user_id IS NOT NULL';
    }
    $rows = q($sql . ' ORDER BY n.id DESC LIMIT 200', $p)->fetchAll();
    json_out(['ok' => true, 'items' => array_map('notice_shape', $rows)]);
}

function notice_load(int $id, array $me): array
{
    $n = q('SELECT n.*, s.name AS sender_name FROM notices n JOIN users s ON s.id = n.sender_id WHERE n.id = ?', [$id])->fetch();
    if (!$n) {
        fail('Notice not found.', 404);
    }
    $n = notice_shape($n);
    $mine = q('SELECT read_at, ack_at FROM notice_reads WHERE notice_id = ? AND user_id = ?', [$id, $me['id']])->fetch();
    if (!$mine && !is_management($me)) {
        fail('This notice was not sent to you.', 403);
    }
    $n['is_recipient'] = (bool)$mine;
    $n['my_ack'] = $mine['ack_at'] ?? null;
    return $n;
}

function act_notice_get(array $in, array $me): void
{
    $n = notice_load((int)($in['id'] ?? 0), $me);
    if ($n['is_recipient']) {
        q('UPDATE notice_reads SET read_at = NOW() WHERE notice_id = ? AND user_id = ? AND read_at IS NULL', [$n['id'], $me['id']]);
    }
    $n['recipient_list'] = is_management($me)
        ? q('SELECT u.id, u.name, r.read_at, r.ack_at FROM notice_reads r JOIN users u ON u.id = r.user_id WHERE r.notice_id = ? ORDER BY r.ack_at IS NULL DESC, u.name', [$n['id']])->fetchAll()
        : [];
    json_out(['ok' => true, 'notice' => $n]);
}

function act_notice_post(array $in, array $me): void
{
    require_management();
    $title = str_in($in['title'] ?? '', 190);
    $body = str_in($in['body'] ?? '', 8000);
    $audience = in_array($in['audience'] ?? '', NOTICE_AUDIENCES, true) ? $in['audience'] : 'all_staff';
    $expires = (string)($in['expires'] ?? '');
    $errors = [];
    if ($title === '') {
        $errors['title'] = 'Give the notice a title.';
    }
    if ($body === '') {
        $errors['body'] = 'Write the notice.';
    }
    if ($expires !== '' && (!valid_date($expires) || $expires < today())) {
        $errors['expires'] = 'Pick today or a later date, or leave it empty.';
    }
    $to = notice_recipient_ids($audience, $me);
    if (!$errors && !$to) {
        $errors['audience'] = 'There is nobody to send this to yet.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $urgent = !empty($in['urgent']);
    db()->beginTransaction();
    q('INSERT INTO notices (sender_id, title, body, urgent, audience, expires_at, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$me['id'], $title, $body, $urgent ? 1 : 0, $audience, $expires !== '' ? "$expires 23:59:59" : null]);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO notice_reads (notice_id, user_id) VALUES (?, ?)');
    foreach ($to as $uid) {
        $ins->execute([$id, $uid]);
    }
    audit('notice_post', 'notice', $id, ['audience' => $audience, 'recipients' => count($to), 'urgent' => $urgent]);
    db()->commit();
    touch_change();
    notify($to, 'notice', ($urgent ? 'Urgent notice: ' : 'Notice: ') . $title, mb_substr($body, 0, 300), 'notices/' . $id, true,
        ['gtasks' => true, 'due' => $expires]);
    json_out(['ok' => true, 'id' => $id]);
}

function act_notice_ack(array $in, array $me): void
{
    $id = (int)($in['id'] ?? 0);
    $n = q('UPDATE notice_reads SET ack_at = NOW(), read_at = COALESCE(read_at, NOW()) WHERE notice_id = ? AND user_id = ? AND ack_at IS NULL', [$id, $me['id']])->rowCount();
    if ($n) {
        touch_change();
    }
    json_out(['ok' => true]);
}

/** Take a notice down early, or put it back up. */
function act_notice_archive(array $in, array $me): void
{
    require_management();
    $n = notice_load((int)($in['id'] ?? 0), $me);
    $restore = !empty($in['restore']);
    q('UPDATE notices SET archived_at = ' . ($restore ? 'NULL' : 'NOW()') . ' WHERE id = ?', [$n['id']]);
    audit($restore ? 'notice_restore' : 'notice_archive', 'notice', $n['id']);
    touch_change();
    json_out(['ok' => true]);
}
