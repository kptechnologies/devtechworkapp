<?php
/**
 * Memos, notes and requests.
 * kind memo:    any management role → all staff, chosen staff, or management. Staff can also send a memo to management.
 * kind note:    staff → management (admin, manager, sub admin, supervisor).
 * kind request: manager, sub admin or supervisor → the admin, who approves or declines it. status: open → approved | declined
 * Recipients are worked out here from the audience; the browser never picks arbitrary people for staff.
 */

const MEMO_KINDS = ['memo', 'note', 'request'];

function memo_ref(int $id): string
{
    return 'MEM-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** Active user ids for an audience, never including the sender. */
function memo_recipient_ids(string $kind, string $audience, array $in, array $me): array
{
    $ids = match (true) {
        $kind === 'request' => q("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN),
        $audience === 'management' => q("SELECT id FROM users WHERE role IN ('admin','manager','subadmin','supervisor') AND active = 1")->fetchAll(PDO::FETCH_COLUMN),
        $audience === 'all_staff' => q("SELECT id FROM users WHERE role IN ('staff','supervisor','subadmin','manager') AND active = 1")->fetchAll(PDO::FETCH_COLUMN),
        $audience === 'selected' => (function () use ($in) {
            $want = array_values(array_unique(array_filter(array_map('intval', (array)($in['user_ids'] ?? [])))));
            if (!$want) {
                return [];
            }
            $qs = implode(',', array_fill(0, count($want), '?'));
            return q("SELECT id FROM users WHERE active = 1 AND id IN ($qs)", $want)->fetchAll(PDO::FETCH_COLUMN);
        })(),
        default => [],
    };
    return array_values(array_diff(array_map('intval', $ids), [$me['id']]));
}

function memo_load(int $id, array $me): array
{
    $m = q('SELECT m.*, s.name AS sender_name, s.role AS sender_role, d.name AS decided_by_name FROM memos m
        JOIN users s ON s.id = m.sender_id LEFT JOIN users d ON d.id = m.decided_by WHERE m.id = ?', [$id])->fetch();
    if (!$m) {
        fail('Memo not found.', 404);
    }
    $m['id'] = (int)$m['id'];
    $m['sender_id'] = (int)$m['sender_id'];
    $m['ref'] = memo_ref($m['id']);
    $m['is_sender'] = $m['sender_id'] === $me['id'];
    $m['is_recipient'] = (bool)q('SELECT 1 FROM memo_recipients WHERE memo_id = ? AND user_id = ?', [$m['id'], $me['id']])->fetchColumn();
    if (!$m['is_sender'] && !$m['is_recipient'] && !is_admin($me)) {
        fail('You can only open memos sent to you or by you.', 403);
    }
    return $m;
}

/** Unread memos for the sidebar badge, plus open requests for the admin. */
function memo_badge(array $me): int
{
    $n = (int)q('SELECT COUNT(*) FROM memo_recipients WHERE user_id = ? AND read_at IS NULL', [$me['id']])->fetchColumn();
    if (is_admin($me)) {
        $n += (int)q("SELECT COUNT(*) FROM memos m WHERE kind = 'request' AND status = 'open'
            AND NOT EXISTS (SELECT 1 FROM memo_recipients r WHERE r.memo_id = m.id AND r.user_id = ? AND r.read_at IS NULL)", [$me['id']])->fetchColumn();
    }
    return $n;
}

function act_memos_list(array $in, array $me): void
{
    $box = (string)($in['box'] ?? 'inbox');
    $sql = "SELECT m.id, m.kind, m.subject, m.audience, m.status, m.created_at, m.sender_id, s.name AS sender_name,
        r.read_at, (SELECT COUNT(*) FROM memo_replies x WHERE x.memo_id = m.id) AS replies,
        (SELECT COUNT(*) FROM memo_recipients y WHERE y.memo_id = m.id) AS recipients
        FROM memos m JOIN users s ON s.id = m.sender_id
        LEFT JOIN memo_recipients r ON r.memo_id = m.id AND r.user_id = ?";
    $p = [$me['id']];
    if ($box === 'sent') {
        $sql .= ' WHERE m.sender_id = ?';
        $p[] = $me['id'];
    } elseif ($box === 'requests' && is_admin($me)) {
        $sql .= " WHERE m.kind = 'request'";
    } else {
        $sql .= ' WHERE r.user_id IS NOT NULL';
    }
    $rows = q($sql . ' ORDER BY m.id DESC LIMIT 300', $p)->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['ref'] = memo_ref($r['id']);
        $r['unread'] = $box === 'inbox' && $r['read_at'] === null;
    }
    unset($r);
    json_out(['ok' => true, 'items' => $rows]);
}

function act_memo_get(array $in, array $me): void
{
    $m = memo_load((int)($in['id'] ?? 0), $me);
    if ($m['is_recipient']) {
        q('UPDATE memo_recipients SET read_at = NOW() WHERE memo_id = ? AND user_id = ? AND read_at IS NULL', [$m['id'], $me['id']]);
    }
    $replies = q('SELECT x.id, x.user_id, x.body, x.created_at, u.name AS user_name FROM memo_replies x JOIN users u ON u.id = x.user_id
        WHERE x.memo_id = ? ORDER BY x.id', [$m['id']])->fetchAll();
    // On a memo to staff, each staff member sees the sender's replies and their own, not other staff's.
    if (!$m['is_sender'] && !is_management($me) && in_array($m['audience'], ['all_staff', 'selected'], true)) {
        $replies = array_values(array_filter($replies, fn($x) => in_array((int)$x['user_id'], [$m['sender_id'], $me['id']], true)));
    }
    $m['replies'] = $replies;
    // Who has read it: for the sender and managers only.
    $m['recipient_list'] = $m['is_sender'] || is_management($me)
        ? q('SELECT u.id, u.name, r.read_at FROM memo_recipients r JOIN users u ON u.id = r.user_id WHERE r.memo_id = ? ORDER BY u.name', [$m['id']])->fetchAll()
        : [];
    json_out(['ok' => true, 'memo' => $m]);
}

/** Active people a manager can address a memo to. */
function act_memo_people(array $in, array $me): void
{
    require_management();
    $rows = q("SELECT id, name, role FROM users WHERE active = 1 AND id <> ? ORDER BY name", [$me['id']])->fetchAll();
    json_out(['ok' => true, 'items' => $rows]);
}

function act_memo_send(array $in, array $me): void
{
    $kind = in_array($in['kind'] ?? '', MEMO_KINDS, true) ? $in['kind'] : 'memo';
    $audience = (string)($in['audience'] ?? 'management');
    if (is_management($me)) {
        if ($kind === 'note') {
            $kind = 'memo';
        }
        if ($kind === 'request') {
            if (is_admin($me)) {
                fail('Requests go to the admin. Send a memo instead.');
            }
            $audience = 'admin';
        } elseif (!in_array($audience, ['all_staff', 'selected', 'management'], true)) {
            fail('Choose who the memo is for.');
        }
    } else {
        // Staff write to management only.
        if ($kind === 'request') {
            fail('Use a note or memo to reach management.', 403);
        }
        $audience = 'management';
    }
    $subject = str_in($in['subject'] ?? '', 190);
    $body = str_in($in['body'] ?? '', 8000);
    $errors = [];
    if ($subject === '') {
        $errors['subject'] = 'Give it a short subject.';
    }
    if ($body === '') {
        $errors['body'] = 'Write the message.';
    }
    $to = memo_recipient_ids($kind, $audience, $in, $me);
    if (!$errors && !$to) {
        $errors['audience'] = $audience === 'selected' ? 'Pick at least one person.' : 'There is nobody to send this to yet.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    db()->beginTransaction();
    q('INSERT INTO memos (sender_id, kind, subject, body, audience, status, created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$me['id'], $kind, $subject, $body, $audience, 'open']);
    $id = (int)db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO memo_recipients (memo_id, user_id) VALUES (?, ?)');
    foreach ($to as $uid) {
        $ins->execute([$id, $uid]);
    }
    audit('memo_send', 'memo', $id, ['kind' => $kind, 'audience' => $audience, 'recipients' => count($to)]);
    db()->commit();
    touch_change();
    $label = ['memo' => 'Memo', 'note' => 'Note', 'request' => 'Request'][$kind];
    notify($to, 'memo', "$label from {$me['name']}: $subject", mb_substr($body, 0, 300), 'memos/' . $id);
    json_out(['ok' => true, 'id' => $id]);
}

function act_memo_reply(array $in, array $me): void
{
    $m = memo_load((int)($in['id'] ?? 0), $me);
    $body = str_in($in['body'] ?? '', 4000);
    if ($body === '') {
        fail('Write a reply.');
    }
    q('INSERT INTO memo_replies (memo_id, user_id, body, created_at) VALUES (?,?,?,NOW())', [$m['id'], $me['id'], $body]);
    touch_change();
    $recipients = array_map('intval', q('SELECT user_id FROM memo_recipients WHERE memo_id = ?', [$m['id']])->fetchAll(PDO::FETCH_COLUMN));
    // The sender's replies go to everyone; a recipient's reply goes to the sender, and to the rest of management on a note to management.
    $to = $m['is_sender'] || in_array($m['audience'], ['management', 'admin'], true)
        ? array_merge([$m['sender_id']], $recipients)
        : [$m['sender_id']];
    notify(array_diff($to, [$me['id']]), 'memo', "{$me['name']} replied to {$m['ref']}: {$m['subject']}", mb_substr($body, 0, 300), 'memos/' . $m['id'], false);
    json_out(['ok' => true]);
}

/** The admin approves or declines a request from management. */
function act_memo_decide(array $in, array $me): void
{
    require_admin();
    $m = memo_load((int)($in['id'] ?? 0), $me);
    if ($m['kind'] !== 'request') {
        fail('Only requests are approved or declined.');
    }
    if ($m['status'] !== 'open') {
        fail('This request has already been answered.');
    }
    $approve = ($in['decision'] ?? '') === 'approve';
    $note = str_in($in['note'] ?? '', 1000);
    if (!$approve && $note === '') {
        fail('Say why the request is declined.');
    }
    q('UPDATE memos SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?',
        [$approve ? 'approved' : 'declined', $me['id'], $note, $m['id']]);
    audit('memo_' . ($approve ? 'approve' : 'decline'), 'memo', $m['id'], ['note' => $note]);
    touch_change();
    notify([$m['sender_id']], 'memo', 'Request ' . ($approve ? 'approved' : 'declined') . ": {$m['subject']}", $note, 'memos/' . $m['id']);
    json_out(['ok' => true]);
}
