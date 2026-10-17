<?php
/**
 * Ideas & plans board: anyone posts an idea, a plan (something the company should do) or a recommendation.
 * Everyone sees every post, votes and comments. Management moves it along:
 * new → review → approved (with an owner and due date) → in_progress → done, or declined with a reason.
 * Status changes are kept in idea_comments with kind 'status', so the thread doubles as the timeline.
 */

const IDEA_KINDS = ['idea', 'plan', 'recommendation'];
const IDEA_STATUSES = ['new', 'review', 'approved', 'in_progress', 'done', 'declined'];
const IDEA_STATUS_LABELS = ['new' => 'New', 'review' => 'Under review', 'approved' => 'Approved', 'in_progress' => 'In progress', 'done' => 'Done', 'declined' => 'Declined'];

function idea_ref(int $id): string
{
    return 'IDEA-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

const IDEA_SELECT = 'SELECT i.*, u.name AS user_name, o.name AS owner_name, d.name AS decided_by_name,
    (SELECT COUNT(*) FROM idea_votes v WHERE v.idea_id = i.id) AS votes,
    (SELECT COUNT(*) FROM idea_comments c WHERE c.idea_id = i.id AND c.kind = \'comment\') AS comments,
    (SELECT COUNT(*) FROM attachments a WHERE a.owner_type = \'idea\' AND a.owner_id = i.id) AS files,
    EXISTS (SELECT 1 FROM idea_votes v WHERE v.idea_id = i.id AND v.user_id = ?) AS voted
    FROM ideas i JOIN users u ON u.id = i.user_id LEFT JOIN users o ON o.id = i.owner_id LEFT JOIN users d ON d.id = i.decided_by';

function idea_row(array $r): array
{
    foreach (['id', 'user_id', 'votes', 'comments', 'files'] as $k) {
        $r[$k] = (int)$r[$k];
    }
    $r['owner_id'] = $r['owner_id'] !== null ? (int)$r['owner_id'] : null;
    $r['voted'] = (bool)$r['voted'];
    $r['ref'] = idea_ref($r['id']);
    $r['overdue'] = $r['due_date'] && in_array($r['status'], ['approved', 'in_progress'], true) && $r['due_date'] < today();
    return $r;
}

function idea_load(int $id, array $me): array
{
    $r = q(IDEA_SELECT . ' WHERE i.id = ?', [$me['id'], $id])->fetch();
    if (!$r) {
        fail('Idea not found.', 404);
    }
    return idea_row($r);
}

/** Management: new posts waiting for a look. Owners: approved items they have not started. */
function idea_badge(array $me): int
{
    $n = (int)q("SELECT COUNT(*) FROM ideas WHERE status = 'approved' AND owner_id = ?", [$me['id']])->fetchColumn();
    if (is_management($me)) {
        $n += (int)q("SELECT COUNT(*) FROM ideas WHERE status = 'new' AND user_id <> ?", [$me['id']])->fetchColumn();
    }
    return $n;
}

/** Everyone who has taken part: the poster, the owner and anyone who commented. */
function idea_people(array $i): array
{
    $ids = array_map('intval', q("SELECT DISTINCT user_id FROM idea_comments WHERE idea_id = ? AND kind = 'comment'", [$i['id']])->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_unique(array_filter(array_merge([$i['user_id'], (int)$i['owner_id']], $ids))));
}

function idea_log(int $ideaId, int $userId, string $kind, string $body): void
{
    q('INSERT INTO idea_comments (idea_id, user_id, kind, body, created_at) VALUES (?,?,?,?,NOW())', [$ideaId, $userId, $kind, $body]);
}

function act_ideas_list(array $in, array $me): void
{
    $w = ['1=1'];
    $p = [$me['id']];
    $tab = (string)($in['tab'] ?? 'open');
    if ($tab === 'mine') {
        $w[] = '(i.user_id = ? OR i.owner_id = ?)';
        array_push($p, $me['id'], $me['id']);
    } elseif ($tab === 'progress') {
        $w[] = "i.status IN ('approved','in_progress')";
    } elseif ($tab === 'done') {
        $w[] = "i.status = 'done'";
    } elseif ($tab === 'declined') {
        $w[] = "i.status = 'declined'";
    } else {
        $w[] = "i.status IN ('new','review')";
    }
    if (in_array($in['kind'] ?? '', IDEA_KINDS, true)) {
        $w[] = 'i.kind = ?';
        $p[] = $in['kind'];
    }
    if (!empty($in['q'])) {
        $w[] = '(i.title LIKE ? OR i.body LIKE ? OR i.category LIKE ?)';
        $like = '%' . str_in($in['q'], 100) . '%';
        array_push($p, $like, $like, $like);
    }
    $order = match ($in['sort'] ?? 'top') {
        'new' => 'i.id DESC',
        'due' => 'i.due_date IS NULL, i.due_date, i.id DESC',
        default => 'votes DESC, i.id DESC',
    };
    $rows = q(IDEA_SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT 300", $p)->fetchAll();
    $counts = q("SELECT SUM(status IN ('new','review')) AS open, SUM(status IN ('approved','in_progress')) AS progress,
        SUM(status = 'done') AS done, SUM(status = 'declined') AS declined FROM ideas")->fetch();
    json_out(['ok' => true, 'items' => array_map('idea_row', $rows), 'counts' => array_map('intval', $counts ?: [])]);
}

function act_idea_get(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    $i['thread'] = q('SELECT c.id, c.kind, c.body, c.created_at, c.user_id, u.name AS user_name FROM idea_comments c
        JOIN users u ON u.id = c.user_id WHERE c.idea_id = ? ORDER BY c.id', [$i['id']])->fetchAll();
    $i['voters'] = q('SELECT u.name FROM idea_votes v JOIN users u ON u.id = v.user_id WHERE v.idea_id = ? ORDER BY v.created_at', [$i['id']])->fetchAll(PDO::FETCH_COLUMN);
    $i['files_list'] = attachments_for('idea', [$i['id']])[$i['id']] ?? [];
    $i['can_edit'] = is_management($me) || ($i['user_id'] === $me['id'] && $i['status'] === 'new');
    $i['can_delete'] = is_admin($me) || ($i['user_id'] === $me['id'] && $i['status'] === 'new');
    json_out(['ok' => true, 'idea' => $i]);
}

/** Title, details, kind and category from a post or edit form, or a 422 with the fields to fix. */
function idea_input(array $in): array
{
    $v = [
        'kind' => in_array($in['kind'] ?? '', IDEA_KINDS, true) ? $in['kind'] : 'idea',
        'title' => str_in($in['title'] ?? '', 190),
        'body' => str_in($in['body'] ?? '', 8000),
        'category' => str_in($in['category'] ?? '', 60),
    ];
    $errors = [];
    if ($v['title'] === '') {
        $errors['title'] = 'Give it a short title.';
    }
    if ($v['body'] === '') {
        $errors['body'] = 'Explain it so others can understand.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    return $v;
}

function act_idea_post(array $in, array $me): void
{
    $v = idea_input($in);
    if ($err = check_uploads('files')) {
        fail($err);
    }
    db()->beginTransaction();
    q('INSERT INTO ideas (user_id, kind, title, body, category, status, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())',
        [$me['id'], $v['kind'], $v['title'], $v['body'], $v['category'], 'new']);
    $id = (int)db()->lastInsertId();
    save_uploads('idea', $id, $me['id']);
    audit('idea_post', 'idea', $id, ['kind' => $v['kind']]);
    db()->commit();
    touch_change();
    $label = ['idea' => 'idea', 'plan' => 'plan', 'recommendation' => 'recommendation'][$v['kind']];
    notify(management_ids([$me['id']]), 'idea', "New $label from {$me['name']}: {$v['title']}", mb_substr($v['body'], 0, 300), 'ideas/' . $id, false);
    json_out(['ok' => true, 'id' => $id]);
}

function act_idea_edit(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    if (!is_management($me) && !($i['user_id'] === $me['id'] && $i['status'] === 'new')) {
        fail('You can only edit your own post before management looks at it.', 403);
    }
    $v = idea_input($in);
    if ($err = check_uploads('files', $i['files'])) {
        fail($err);
    }
    db()->beginTransaction();
    q('UPDATE ideas SET kind = ?, title = ?, body = ?, category = ?, updated_at = NOW() WHERE id = ?',
        [$v['kind'], $v['title'], $v['body'], $v['category'], $i['id']]);
    save_uploads('idea', $i['id'], $me['id']);
    audit('idea_edit', 'idea', $i['id']);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}

function act_idea_vote(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    if ($i['user_id'] === $me['id']) {
        fail('You can\'t vote on your own post.');
    }
    if ($i['voted']) {
        q('DELETE FROM idea_votes WHERE idea_id = ? AND user_id = ?', [$i['id'], $me['id']]);
    } else {
        q('INSERT IGNORE INTO idea_votes (idea_id, user_id, created_at) VALUES (?,?,NOW())', [$i['id'], $me['id']]);
    }
    touch_change();
    $votes = (int)q('SELECT COUNT(*) FROM idea_votes WHERE idea_id = ?', [$i['id']])->fetchColumn();
    json_out(['ok' => true, 'voted' => !$i['voted'], 'votes' => $votes]);
}

function act_idea_comment(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    $body = str_in($in['body'] ?? '', 4000);
    if ($body === '') {
        fail('Write a comment.');
    }
    $to = array_diff(idea_people($i), [$me['id']]);
    idea_log($i['id'], $me['id'], 'comment', $body);
    q('UPDATE ideas SET updated_at = NOW() WHERE id = ?', [$i['id']]);
    touch_change();
    notify($to, 'idea', "{$me['name']} commented on {$i['ref']}: {$i['title']}", mb_substr($body, 0, 300), 'ideas/' . $i['id'], false);
    json_out(['ok' => true]);
}

/** Management sets any status. Approving or starting needs an owner and a due date; declining needs a reason. */
function act_idea_status(array $in, array $me): void
{
    require_management();
    $i = idea_load((int)($in['id'] ?? 0), $me);
    $status = (string)($in['status'] ?? '');
    if (!in_array($status, IDEA_STATUSES, true)) {
        fail('Choose a status.');
    }
    $note = str_in($in['note'] ?? '', 1000);
    $ownerId = (int)($in['owner_id'] ?? ($i['owner_id'] ?? 0));
    $due = valid_date($in['due_date'] ?? null) ? $in['due_date'] : $i['due_date'];
    $errors = [];
    $owner = null;
    if (in_array($status, ['approved', 'in_progress'], true)) {
        $owner = $ownerId ? q('SELECT id, name FROM users WHERE id = ? AND active = 1', [$ownerId])->fetch() : null;
        if (!$owner) {
            $errors['owner_id'] = 'Choose who will carry it out.';
        }
        if (!$due) {
            $errors['due_date'] = 'Set a target date.';
        }
    }
    if ($status === 'declined' && $note === '') {
        $errors['note'] = 'Say why, so the poster understands.';
    }
    if ($errors) {
        json_out(['ok' => false, 'error' => 'Check the highlighted fields.', 'fields' => $errors], 422);
    }
    $newOwner = $owner && (int)$owner['id'] !== (int)$i['owner_id'];
    db()->beginTransaction();
    q('UPDATE ideas SET status = ?, owner_id = ?, due_date = ?, decision_note = ?, decided_by = ?, decided_at = NOW(),
        done_at = ?, updated_at = NOW() WHERE id = ?',
        [$status, $owner ? $owner['id'] : $i['owner_id'], $due, $note !== '' ? $note : $i['decision_note'], $me['id'],
            $status === 'done' ? date('Y-m-d H:i:s') : null, $i['id']]);
    $log = IDEA_STATUS_LABELS[$status] . ($owner ? ' · owner ' . $owner['name'] . ', due ' . $due : '') . ($note !== '' ? "\n" . $note : '');
    idea_log($i['id'], $me['id'], 'status', $log);
    audit('idea_status', 'idea', $i['id'], ['status' => $status, 'owner' => $owner['id'] ?? null]);
    db()->commit();
    touch_change();
    $title = $i['ref'] . ' ' . strtolower(IDEA_STATUS_LABELS[$status]) . ': ' . $i['title'];
    notify(array_diff([$i['user_id']], [$me['id']]), 'idea', $title, $note, 'ideas/' . $i['id'], $status !== 'review');
    if ($newOwner && (int)$owner['id'] !== $me['id']) {
        notify([(int)$owner['id']], 'idea', "You own {$i['ref']}: {$i['title']}", 'Due ' . $due . ($note !== '' ? '. ' . $note : ''),
            'ideas/' . $i['id'], true, ['gtasks' => true, 'due' => (string)$due]);
    }
    json_out(['ok' => true]);
}

/** The owner starts the work or marks it done, with an optional progress note. */
function act_idea_progress(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    if ($i['owner_id'] !== $me['id'] && !is_management($me)) {
        fail('Only the owner or management can update progress.', 403);
    }
    $status = (string)($in['status'] ?? '');
    $allowed = ['approved' => ['in_progress', 'done'], 'in_progress' => ['done', 'in_progress']][$i['status']] ?? [];
    if (!in_array($status, $allowed, true)) {
        fail('This item can\'t move to that step.');
    }
    $note = str_in($in['note'] ?? '', 2000);
    if ($status === $i['status'] && $note === '') {
        fail('Write a progress note.');
    }
    db()->beginTransaction();
    q('UPDATE ideas SET status = ?, done_at = ?, updated_at = NOW() WHERE id = ?', [$status, $status === 'done' ? date('Y-m-d H:i:s') : null, $i['id']]);
    idea_log($i['id'], $me['id'], 'status', ($status === $i['status'] ? 'Progress update' : IDEA_STATUS_LABELS[$status]) . ($note !== '' ? "\n" . $note : ''));
    audit('idea_progress', 'idea', $i['id'], ['status' => $status]);
    db()->commit();
    touch_change();
    $to = array_diff(array_merge(idea_people($i), $status === 'done' ? management_ids() : []), [$me['id']]);
    $verb = $status === 'done' ? 'done' : ($status === $i['status'] ? 'updated' : 'started');
    notify(array_unique($to), 'idea', "{$i['ref']} $verb: {$i['title']}", $note, 'ideas/' . $i['id'], false);
    json_out(['ok' => true]);
}

function act_idea_delete(array $in, array $me): void
{
    $i = idea_load((int)($in['id'] ?? 0), $me);
    if (!is_admin($me) && !($i['user_id'] === $me['id'] && $i['status'] === 'new')) {
        fail('You can only delete your own post before management looks at it.', 403);
    }
    db()->beginTransaction();
    delete_attachments_of('idea', $i['id']);
    q('DELETE FROM idea_votes WHERE idea_id = ?', [$i['id']]);
    q('DELETE FROM idea_comments WHERE idea_id = ?', [$i['id']]);
    q('DELETE FROM ideas WHERE id = ?', [$i['id']]);
    audit('idea_delete', 'idea', $i['id'], ['title' => $i['title']]);
    db()->commit();
    touch_change();
    json_out(['ok' => true]);
}
