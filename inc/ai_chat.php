<?php
/*
 * "Ask AI": a chat where management asks the AI about anything in the portal.
 * The AI looks things up with read-only tools (jobs, reports, attendance, clients, notices, memos, queries, ideas) and
 * can draft things to post (notice, memo, job, task, follow-up, reminder). Drafts become ai_suggestions and nothing is
 * posted until a manager clicks Create, which goes through the normal actions (same checks and alerts).
 * Conversations are kept in ai_chats in the provider's own message format so the AI sees its earlier tool calls.
 */

require_once __DIR__ . '/ai.php';

const AI_CHAT_MAX_STEPS = 12;
const AI_DRAFT_TYPES = ['job_task', 'followup', 'reminder', 'job', 'notice', 'memo'];

const AI_CHAT_SYSTEM = <<<'TXT'
You are the operations assistant inside the DevTech staff portal. DevTech's field staff teach ICT classes in schools, maintain school computer labs and laptops, do installations, and handle job orders for schools and companies. You are talking to a member of management (admin or manager).

You can look things up in the portal with your tools: staff, jobs (with tasks and timeline), daily staff reports, attendance and refused clock-ins, ignored reminders, clients/schools, notices, memos, staff queries and the ideas board. Look things up before answering; do not guess. Several lookups in one go are fine. If a tool returns nothing, say so plainly.

You can also draft things to post: a notice to all staff, a memo, a new job order, a task on a job, a follow-up note on a job, or a reminder to one staff member. A draft is NOT posted: the manager sees it under your reply with a Create button and can edit it first. So when the manager asks you to post or send something, draft it with the right tool and then tell them it is ready for them to check and create. Never claim something was posted or sent. Use only job_id and user_id values you have seen in tool results; look them up first if needed. Dates are YYYY-MM-DD and not in the past.

When asked to review something (a staff member, a job, a school, reports, a draft text), look up the facts first, then give a fair, specific review: name the staff member, school and job reference (e.g. JOB-0042), separate patterns from one-offs, note good work, and end with concrete next steps. Offer to draft follow-ups or reminders when they would help.

You never see phone numbers, GPS positions or pay. Write in plain, short British English. Keep answers tight; use "- " bullet lines for lists and no markdown headings or tables.
TXT;

/* ------------------------------------------------------------------ tool definitions */

function ai_chat_tools(): array
{
    $s = fn(string $d) => ['type' => 'string', 'description' => $d];
    $i = fn(string $d) => ['type' => 'integer', 'description' => $d];
    $ids = fn(string $d) => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $d];
    $date = fn(string $d) => ['type' => 'string', 'description' => "$d (YYYY-MM-DD)"];
    $t = fn(string $name, string $desc, array $props = [], array $req = []) => [
        'name' => $name, 'description' => $desc,
        'input_schema' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req],
    ];
    return [
        // Look-ups
        $t('list_staff', 'Active staff with user_id, role, job title and schools they cover.'),
        $t('jobs_overview', 'Jobs that need attention now: overdue, due soon, high priority, stalled, waiting for check, unassigned, plus pending requests for more time.'),
        $t('search_jobs', 'Find job orders. All filters optional; returns up to 40 jobs, newest due first.', [
            'text' => $s('Words in the title, client, location or description'),
            'status' => ['type' => 'string', 'enum' => array_merge(['active', 'any'], JOB_STATUSES), 'description' => "'active' = not done or cancelled (default)"],
            'client' => $s('School or company name (part of it is fine)'),
            'assignee_id' => $i('Only jobs assigned to this user_id'),
            'overdue_only' => ['type' => 'boolean'],
            'from' => $date('Created on or after'),
            'to' => $date('Created on or before'),
        ]),
        $t('get_job', 'One job in full: details, description, tasks, and its timeline of updates.', ['job_id' => $i('The job_id')], ['job_id']),
        $t('search_reports', 'Daily staff reports in a date range (max 92 days), with who missed reports on days they clocked in.', [
            'from' => $date('Start'), 'to' => $date('End'),
            'user_id' => $i('Only this staff member'),
            'location' => $s('School/site name (part of it is fine)'),
            'text' => $s('Only reports mentioning these words'),
        ], ['from', 'to']),
        $t('attendance', 'Clock-ins/outs, lateness, missed clock-outs and refused clock-in attempts in a date range (max 92 days).', [
            'from' => $date('Start'), 'to' => $date('End'), 'user_id' => $i('Only this staff member'),
        ], ['from', 'to']),
        $t('ignored_reminders', 'Before-due reminders that were escalated, snoozed or ignored past due since a date.', [
            'from' => $date('Since'), 'user_id' => $i('Only this staff member'),
        ], ['from']),
        $t('list_clients', 'Schools and companies, with area, notes, staff who cover them, open jobs and unresolved device faults.', [
            'text' => $s('Part of the name or area'),
        ]),
        $t('list_notices', 'Recent notices posted to staff, with how many have acknowledged.', ['days' => $i('How far back (default 30)')]),
        $t('list_memos', 'Recent memos, staff notes and requests with replies count and status.', ['days' => $i('How far back (default 30)'), 'text' => $s('Words in subject or body')]),
        $t('list_queries', 'Staff queries (disciplinary) and their status/outcome.', ['user_id' => $i('Only this staff member'), 'days' => $i('How far back (default 90)')]),
        $t('list_ideas', 'Ideas, plans and recommendations on the ideas board, with votes and status.', ['text' => $s('Words in title or body')]),
        // Drafts (need approval)
        $t('draft_notice', 'Draft a notice for the home page of every staff member. Not posted until the manager clicks Create.', [
            'title' => $s('Short title'), 'body' => $s('The notice text'),
            'urgent' => ['type' => 'boolean'],
            'audience' => ['type' => 'string', 'enum' => NOTICE_AUDIENCES, 'description' => "'all_staff' (default) or 'everyone' including management"],
            'expires' => $date('Last day it shows, or empty'),
            'reason' => $s('One line on why'),
        ], ['title', 'body']),
        $t('draft_memo', 'Draft a memo. Not sent until the manager clicks Create.', [
            'subject' => $s('Subject'), 'body' => $s('Memo text'),
            'audience' => ['type' => 'string', 'enum' => ['all_staff', 'selected', 'management']],
            'user_ids' => $ids("Recipients when audience is 'selected'"),
            'reason' => $s('One line on why'),
        ], ['subject', 'body', 'audience']),
        $t('draft_job', 'Draft a new job order. Not created until the manager clicks Create.', [
            'title' => $s('Job title'), 'client_name' => $s('School or company'), 'description' => $s('What needs doing'),
            'priority' => ['type' => 'string', 'enum' => JOB_PRIORITIES], 'due_date' => $date('Due date'),
            'assignee_ids' => $ids('Staff user_ids to assign'), 'reason' => $s('One line on why'),
        ], ['title', 'client_name', 'description']),
        $t('draft_job_task', 'Draft a task on an existing job. Not added until the manager clicks Create.', [
            'job_id' => $i('The job_id'), 'title' => $s('Task'), 'notes' => $s('Notes'), 'due_date' => $date('Due date'),
            'assignee_ids' => $ids('Staff user_ids'), 'reason' => $s('One line on why'),
        ], ['job_id', 'title', 'due_date']),
        $t('draft_followup', 'Draft a follow-up note on a job timeline (its assignees are alerted). Not posted until the manager clicks Create.', [
            'job_id' => $i('The job_id'), 'text' => $s('The note'), 'reason' => $s('One line on why'),
        ], ['job_id', 'text']),
        $t('draft_reminder', 'Draft a direct reminder to one staff member. Not sent until the manager clicks Create.', [
            'user_id' => $i('Staff user_id'), 'title' => $s('Short title'), 'text' => $s('Message'),
            'job_id' => $i('Related job_id, if any'), 'reason' => $s('One line on why'),
        ], ['user_id', 'title', 'text']),
    ];
}

/* ------------------------------------------------------------------ running a tool */

/** Run one tool call. Returns text for the AI; throws InvalidArgumentException for bad input. */
function ai_chat_run_tool(string $name, array $in, int $chatId): string
{
    $int = fn($k) => (int)($in[$k] ?? 0) ?: null;
    $str = fn($k, $max = 200) => trim(mb_substr((string)($in[$k] ?? ''), 0, $max));
    $range = function () use ($in) {
        $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
        $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime("$to -6 days"));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
            throw new InvalidArgumentException('Range is more than 92 days. Use a shorter range.');
        }
        return [$from, $to];
    };
    $like = fn(string $v) => '%' . addcslashes($v, '%_\\') . '%';

    switch ($name) {
        case 'list_staff':
            return ai_staff_list();
        case 'jobs_overview':
            return ai_ctx_jobs();
        case 'search_jobs':
            $w = ['1=1'];
            $p = [];
            $status = (string)($in['status'] ?? 'active');
            if ($status === 'active') {
                $w[] = "j.status NOT IN ('done','cancelled')";
            } elseif (in_array($status, JOB_STATUSES, true)) {
                $w[] = 'j.status = ?';
                $p[] = $status;
            }
            if ($v = $str('text')) {
                $w[] = '(j.title LIKE ? OR j.client_name LIKE ? OR j.location LIKE ? OR j.description LIKE ?)';
                array_push($p, $like($v), $like($v), $like($v), $like($v));
            }
            if ($v = $str('client')) {
                $w[] = 'j.client_name LIKE ?';
                $p[] = $like($v);
            }
            if ($v = $int('assignee_id')) {
                $w[] = 'EXISTS (SELECT 1 FROM job_assignees a WHERE a.job_id = j.id AND a.user_id = ? AND a.removed_at IS NULL)';
                $p[] = $v;
            }
            if (valid_date($in['from'] ?? null)) {
                $w[] = 'j.created_at >= ?';
                $p[] = $in['from'] . ' 00:00:00';
            }
            if (valid_date($in['to'] ?? null)) {
                $w[] = 'j.created_at <= ?';
                $p[] = $in['to'] . ' 23:59:59';
            }
            $jobs = array_map('job_row', q(JOB_LIST_SQL . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY j.due_date IS NULL, j.due_date DESC, j.id DESC LIMIT 40', $p)->fetchAll());
            if (!empty($in['overdue_only'])) {
                $jobs = array_values(array_filter($jobs, fn($j) => $j['overdue']));
            }
            return $jobs ? count($jobs) . " job(s):\n" . implode("\n", array_map(fn($j) => '- ' . ai_job_line($j), $jobs)) : 'No jobs match.';
        case 'get_job':
            $id = $int('job_id') ?? throw new InvalidArgumentException('job_id is needed.');
            $j = q(JOB_LIST_SQL . ' WHERE j.id = ?', [$id])->fetch() ?: throw new InvalidArgumentException("No job with job_id $id.");
            $j = job_row($j);
            $x = q('SELECT description, completion_note, review_note FROM jobs WHERE id = ?', [$id])->fetch();
            return ai_job_line($j) . "\ndescription: " . ai_clip($x['description'] ?? '', 2000)
                . ($x['completion_note'] ? "\ncompletion note: " . ai_clip($x['completion_note'], 600) : '')
                . ($x['review_note'] ? "\nreview note: " . ai_clip($x['review_note'], 600) : '')
                . "\ntasks:\n" . (ai_job_tasks($id) ?: "    (none)\n") . "timeline (latest 30):\n" . (ai_job_updates($id, 30) ?: "    (none)\n");
        case 'search_reports':
            [$from, $to] = $range();
            $out = ai_ctx_reports($from, $to, $int('user_id'), 250);
            $loc = mb_strtolower($str('location'));
            $text = mb_strtolower($str('text'));
            if ($loc !== '' || $text !== '') {
                // Filter report lines; keep headers and the missing-report section.
                $lines = explode("\n", $out);
                $keep = [];
                $inMissing = false;
                foreach ($lines as $l) {
                    if (str_starts_with($l, '###')) {
                        $inMissing = true;
                    }
                    $lc = mb_strtolower($l);
                    if ($inMissing || str_starts_with($l, '##') || (($loc === '' || str_contains($lc, $loc)) && ($text === '' || str_contains($lc, $text)))) {
                        $keep[] = $l;
                    }
                }
                $out = implode("\n", $keep);
            }
            return $out;
        case 'attendance':
            [$from, $to] = $range();
            return ai_ctx_attendance($from, $to, $int('user_id'));
        case 'ignored_reminders':
            $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime('-14 days'));
            return ai_ctx_reminders($from, $int('user_id')) ?: 'No ignored or escalated reminders since ' . $from . '.';
        case 'list_clients':
            $p = [];
            $w = 'active = 1';
            if ($v = $str('text')) {
                $w .= ' AND (name LIKE ? OR area LIKE ?)';
                array_push($p, $like($v), $like($v));
            }
            $rows = q("SELECT id, name, type, area, notes FROM clients WHERE $w ORDER BY name LIMIT 60", $p)->fetchAll();
            if (!$rows) {
                return 'No clients match.';
            }
            $out = count($rows) . " client(s):\n";
            foreach ($rows as $c) {
                $open = (int)q("SELECT COUNT(*) FROM jobs WHERE client_name = ? AND status NOT IN ('done','cancelled')", [$c['name']])->fetchColumn();
                $staff = implode(', ', array_column(array_filter(client_staff($c['name']), fn($u) => $u['active']), 'name'));
                $faults = count(pending_faults_by_location($c['name'])[$c['name']] ?? []);
                $out .= "- {$c['name']} ({$c['type']}" . ($c['area'] ? ", {$c['area']}" : '') . "); open jobs $open; staff: " . ($staff ?: 'none')
                    . ($faults ? "; $faults unresolved device fault(s) in the last 90 days of reports" : '') . ($c['notes'] ? '; notes: ' . ai_clip($c['notes'], 200) : '') . "\n";
            }
            return $out;
        case 'list_notices':
            $days = min(365, max(1, $int('days') ?? 30));
            $rows = q('SELECT n.id, n.title, n.body, n.urgent, n.audience, n.created_at, n.archived_at, u.name,
                (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id) AS sent, (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id AND r.ack_at IS NOT NULL) AS acked
                FROM notices n LEFT JOIN users u ON u.id = n.sender_id WHERE n.created_at >= ? ORDER BY n.id DESC LIMIT 40', [date('Y-m-d', strtotime("-$days days"))])->fetchAll();
            return $rows ? implode("\n", array_map(fn($n) => '- ' . substr($n['created_at'], 0, 10) . " by {$n['name']}: {$n['title']}" . ($n['urgent'] ? ' [urgent]' : '')
                . ($n['archived_at'] ? ' [archived]' : '') . "; acknowledged {$n['acked']}/{$n['sent']}; " . ai_clip($n['body'], 250), $rows)) : "No notices in the last $days days.";
        case 'list_memos':
            $days = min(365, max(1, $int('days') ?? 30));
            $p = [date('Y-m-d', strtotime("-$days days"))];
            $w = 'm.created_at >= ?';
            if ($v = $str('text')) {
                $w .= ' AND (m.subject LIKE ? OR m.body LIKE ?)';
                array_push($p, $like($v), $like($v));
            }
            $rows = q("SELECT m.kind, m.subject, m.body, m.audience, m.status, m.created_at, u.name, (SELECT COUNT(*) FROM memo_replies r WHERE r.memo_id = m.id) AS replies
                FROM memos m LEFT JOIN users u ON u.id = m.sender_id WHERE $w ORDER BY m.id DESC LIMIT 40", $p)->fetchAll();
            return $rows ? implode("\n", array_map(fn($m) => '- ' . substr($m['created_at'], 0, 10) . " {$m['kind']} from {$m['name']} to {$m['audience']} ({$m['status']}, {$m['replies']} replies): {$m['subject']} — " . ai_clip($m['body'], 250), $rows))
                : 'No memos match.';
        case 'list_queries':
            $days = min(730, max(1, $int('days') ?? 90));
            $p = [date('Y-m-d', strtotime("-$days days"))];
            $w = 's.created_at >= ?';
            if ($v = $int('user_id')) {
                $w .= ' AND s.user_id = ?';
                $p[] = $v;
            }
            $rows = q("SELECT s.*, u.name AS user_name FROM staff_queries s LEFT JOIN users u ON u.id = s.user_id WHERE $w ORDER BY s.id DESC LIMIT 40", $p)->fetchAll();
            // No fines: pay stays out of the AI's view.
            return $rows ? implode("\n", array_map(fn($r) => '- ' . query_ref((int)$r['id']) . ' ' . $r['query_date'] . ' ' . ($r['user_name'] ?: 'contractor') . " [user {$r['user_id']}]: "
                . (QUERY_CATEGORIES[$r['category']] ?? $r['category']) . "; status {$r['status']}; {$r['subject']} — " . ai_clip($r['details'] ?? '', 200)
                . ($r['reply'] ? '; reply: ' . ai_clip($r['reply'], 200) : '') . ($r['outcome_note'] ? '; outcome: ' . ai_clip($r['outcome_note'], 150) : ''), $rows))
                : "No staff queries in the last $days days.";
        case 'list_ideas':
            $p = [0]; // IDEA_SELECT's "voted by me" placeholder
            $w = '1=1';
            if ($v = $str('text')) {
                $w = '(i.title LIKE ? OR i.body LIKE ?)';
                array_push($p, $like($v), $like($v));
            }
            $rows = q(IDEA_SELECT . " WHERE $w ORDER BY i.id DESC LIMIT 40", $p)->fetchAll();
            return $rows ? implode("\n", array_map(function ($r) {
                $r = idea_row($r);
                return '- ' . $r['ref'] . " {$r['kind']} by {$r['user_name']} (" . (IDEA_STATUS_LABELS[$r['status']] ?? $r['status']) . ", votes {$r['votes']}"
                    . (!empty($r['owner_name']) ? ", owner {$r['owner_name']}" : '') . "): {$r['title']} — " . ai_clip($r['body'] ?? '', 200);
            }, $rows)) : 'No ideas match.';
    }

    if (str_starts_with($name, 'draft_')) {
        return ai_chat_draft(substr($name, 6), $in, $chatId);
    }
    throw new InvalidArgumentException("Unknown tool $name.");
}

/** Store a draft as a pending suggestion. Returns the text the AI sees. */
function ai_chat_draft(string $kind, array $in, int $chatId): string
{
    $type = $kind === 'job_task' ? 'job_task' : $kind;
    if (!in_array($type, AI_DRAFT_TYPES, true)) {
        throw new InvalidArgumentException("Unknown draft type $kind.");
    }
    $users = array_map('intval', q('SELECT id FROM users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
    $okUsers = fn($list) => array_values(array_intersect(array_map('intval', (array)$list), $users));
    $jobId = (int)($in['job_id'] ?? 0) ?: null;
    if ($jobId && !q('SELECT 1 FROM jobs WHERE id = ?', [$jobId])->fetchColumn()) {
        throw new InvalidArgumentException("No job with job_id $jobId. Look the job up first.");
    }
    if (in_array($type, ['job_task', 'followup'], true) && !$jobId) {
        throw new InvalidArgumentException('job_id is needed.');
    }
    $userId = null;
    if ($type === 'reminder') {
        $userId = in_array((int)($in['user_id'] ?? 0), $users, true) ? (int)$in['user_id'] : throw new InvalidArgumentException('user_id is not an active staff member. Use list_staff.');
    }
    $due = valid_date($in['due_date'] ?? null) && $in['due_date'] >= today() ? $in['due_date'] : '';
    $p = [
        'title' => str_in($in['title'] ?? ($in['subject'] ?? ''), 190),
        'text' => str_in($in['text'] ?? ($in['body'] ?? ($in['description'] ?? ($in['notes'] ?? ''))), 8000),
        'reason' => str_in($in['reason'] ?? '', 400),
        'job_id' => $jobId ?? 0,
        'user_id' => $userId ?? 0,
        'assignee_ids' => $okUsers($in['assignee_ids'] ?? []),
        'due_date' => $due,
        'client_name' => str_in($in['client_name'] ?? '', 190),
        'priority' => in_array($in['priority'] ?? '', JOB_PRIORITIES, true) ? $in['priority'] : 'normal',
    ];
    if ($type === 'followup' && $p['title'] === '') {
        $p['title'] = 'Follow-up on ' . job_ref($jobId);
    }
    if ($type === 'notice') {
        $p['urgent'] = !empty($in['urgent']);
        $p['audience'] = in_array($in['audience'] ?? '', NOTICE_AUDIENCES, true) ? $in['audience'] : 'all_staff';
        $p['expires'] = valid_date($in['expires'] ?? null) && $in['expires'] >= today() ? $in['expires'] : '';
    }
    if ($type === 'memo') {
        $p['audience'] = in_array($in['audience'] ?? '', ['all_staff', 'selected', 'management'], true) ? $in['audience'] : 'all_staff';
        $p['user_ids'] = $okUsers($in['user_ids'] ?? []);
        if ($p['audience'] === 'selected' && !$p['user_ids']) {
            throw new InvalidArgumentException("Give user_ids for a memo to 'selected' people.");
        }
    }
    if ($p['title'] === '' || ($p['text'] === '' && $type !== 'job_task')) {
        throw new InvalidArgumentException('A title and text are needed.');
    }
    if ($type === 'job' && $p['client_name'] === '') {
        throw new InvalidArgumentException('client_name is needed for a job.');
    }
    q('INSERT INTO ai_suggestions (run_id, chat_id, type, job_id, user_id, payload, created_at) VALUES (0,?,?,?,?,?,NOW())',
        [$chatId, $type, $jobId, $userId, json_encode($p, JSON_UNESCAPED_UNICODE)]);
    return 'Draft #' . db()->lastInsertId() . ' saved. It is NOT posted yet: the manager will see it under your reply and can edit it, then click Create.';
}

/* ------------------------------------------------------------------ the conversation */

function ai_chat_load(int $id, array $me): array
{
    $c = q('SELECT * FROM ai_chats WHERE id = ?', [$id])->fetch() ?: throw new RuntimeException('Chat not found.');
    if ((int)$c['user_id'] !== (int)$me['id'] && !is_admin($me)) {
        throw new RuntimeException('Chat not found.');
    }
    $c['messages'] = json_decode((string)$c['messages'], true) ?: [];
    return $c;
}

/**
 * Add the manager's message to chat $chatId (0 = new chat) and let the AI answer, using tools as needed.
 * Returns the chat as sent to the browser.
 */
function ai_chat_send(int $chatId, string $text, array $me, ?string $provider = null): array
{
    if ($chatId) {
        $chat = ai_chat_load($chatId, $me);
        $provider = $chat['provider'];
    } else {
        $provider = ai_pick_provider($provider);
    }
    if ($p = ai_problem($provider)) {
        throw new RuntimeException($p);
    }
    if (!$chatId) {
        q('INSERT INTO ai_chats (user_id, provider, title, messages, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())',
            [$me['id'], $provider, mb_substr($text, 0, 120), '[]']);
        $chatId = (int)db()->lastInsertId();
        $chat = ai_chat_load($chatId, $me);
    }
    @set_time_limit(600);
    $before = $messages = $chat['messages'];
    $stamp = '[' . date('D j M Y, H:i') . ' — ' . $me['name'] . '] ';
    $messages[] = ['role' => 'user', 'content' => $stamp . $text];
    $in = $out = 0;
    $model = '';
    $error = null;
    try {
        $provider === 'openai'
            ? ai_chat_loop_openai($messages, $chatId, $in, $out, $model)
            : ai_chat_loop_claude($messages, $chatId, $in, $out, $model);
    } catch (RuntimeException $e) {
        // Drop the unfinished turn so the stored conversation stays valid; the browser puts the text back to retry.
        $error = $e;
        $messages = $before;
    }
    q('UPDATE ai_chats SET messages = ?, model = ?, input_tokens = input_tokens + ?, output_tokens = output_tokens + ?, updated_at = NOW() WHERE id = ?',
        [json_encode($messages, JSON_UNESCAPED_UNICODE), mb_substr($model, 0, 60), $in, $out, $chatId]);
    if ($error) {
        throw $error;
    }
    return ai_chat_get($chatId, $me);
}

/** Claude: call, run any tool_use blocks, send all results back in one user message, repeat. Appends to $messages. */
function ai_chat_loop_claude(array &$messages, int $chatId, int &$in, int &$out, string &$model): void
{
    $tools = ai_chat_tools();
    $tools[count($tools) - 1]['cache_control'] = ['type' => 'ephemeral'];
    for ($step = 0; $step < AI_CHAT_MAX_STEPS; $step++) {
        [$code, $body] = ai_claude_http([
            'model' => AI_MODEL,
            'max_tokens' => 16000,
            'system' => [['type' => 'text', 'text' => AI_CHAT_SYSTEM, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => $tools,
            'messages' => $messages,
            'output_config' => ['effort' => 'medium'],
            'fallbacks' => 'default',
        ], ai_api_key('claude'));
        if ($code !== 200) {
            throw ai_claude_error($code, $body);
        }
        $u = $body['usage'] ?? [];
        $in += (int)($u['input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0);
        $out += (int)($u['output_tokens'] ?? 0);
        $model = (string)($body['model'] ?? AI_MODEL);
        $stop = (string)($body['stop_reason'] ?? '');
        $content = (array)($body['content'] ?? []);
        if ($stop === 'refusal') {
            throw new RuntimeException('The AI declined this request. Try asking in a different way.');
        }
        // Keep every block as returned (thinking included) so the next request carries it unchanged.
        $messages[] = ['role' => 'assistant', 'content' => $content];
        if ($stop === 'max_tokens') {
            throw new RuntimeException('The AI answer was too long and got cut off. Ask for something smaller.');
        }
        if ($stop === 'pause_turn') {
            continue;
        }
        $calls = array_values(array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use'));
        if ($stop !== 'tool_use' || !$calls) {
            return;
        }
        $results = [];
        foreach ($calls as $c) {
            [$text, $isError] = ai_chat_safe_tool((string)$c['name'], (array)($c['input'] ?? []), $chatId);
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $c['id'], 'content' => $text] + ($isError ? ['is_error' => true] : []);
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    throw new RuntimeException('The AI took too many steps. Ask again, or ask for something narrower.');
}

/** ChatGPT: same loop over Chat Completions function calling. Messages are stored in OpenAI's format. */
function ai_chat_loop_openai(array &$messages, int $chatId, int &$in, int &$out, string &$model): void
{
    $tools = array_map(fn($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema']]], ai_chat_tools());
    $want = ai_settings()['openai_model'];
    for ($step = 0; $step < AI_CHAT_MAX_STEPS; $step++) {
        [$code, $body] = ai_openai_http('chat/completions', [
            'model' => $want,
            'messages' => array_merge([['role' => 'system', 'content' => AI_CHAT_SYSTEM]], $messages),
            'tools' => $tools,
            'max_completion_tokens' => 16000,
        ], ai_api_key('openai'), 300);
        if ($code !== 200) {
            throw ai_openai_error($code, $body);
        }
        $in += (int)($body['usage']['prompt_tokens'] ?? 0);
        $out += (int)($body['usage']['completion_tokens'] ?? 0);
        $model = (string)($body['model'] ?? $want);
        $choice = $body['choices'][0] ?? [];
        $msg = $choice['message'] ?? [];
        if (!empty($msg['refusal'])) {
            throw new RuntimeException('The AI declined this request. Try asking in a different way.');
        }
        $keep = ['role' => 'assistant', 'content' => $msg['content'] ?? null];
        if (!empty($msg['tool_calls'])) {
            $keep['tool_calls'] = $msg['tool_calls'];
        }
        $messages[] = $keep;
        if (($choice['finish_reason'] ?? '') === 'length') {
            throw new RuntimeException('The AI answer was too long and got cut off. Ask for something smaller.');
        }
        if (empty($msg['tool_calls'])) {
            return;
        }
        foreach ($msg['tool_calls'] as $c) {
            $args = json_decode((string)($c['function']['arguments'] ?? ''), true);
            [$text] = is_array($args) ? ai_chat_safe_tool((string)$c['function']['name'], $args, $chatId) : ['Error: the arguments were not valid JSON.'];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => $text];
        }
    }
    throw new RuntimeException('The AI took too many steps. Ask again, or ask for something narrower.');
}

/** [result text, is_error]. Bad input goes back to the AI as an error it can fix; server faults are logged. */
function ai_chat_safe_tool(string $name, array $input, int $chatId): array
{
    try {
        $text = ai_chat_run_tool($name, $input, $chatId);
        return [mb_strlen($text) > 60000 ? mb_substr($text, 0, 60000) . "\n…(cut; narrow the search)" : $text, false];
    } catch (InvalidArgumentException $e) {
        return ['Error: ' . $e->getMessage(), true];
    } catch (Throwable $e) {
        error_log('[portal] ai tool ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return ['Error: this lookup failed on the server.', true];
    }
}

/* ------------------------------------------------------------------ for the browser */

/** Tool names as short words the manager sees ("Looked at jobs"). */
const AI_TOOL_LABELS = [
    'list_staff' => 'staff list', 'jobs_overview' => 'jobs needing attention', 'search_jobs' => 'jobs', 'get_job' => 'a job',
    'search_reports' => 'daily reports', 'attendance' => 'attendance', 'ignored_reminders' => 'ignored reminders', 'list_clients' => 'clients',
    'list_notices' => 'notices', 'list_memos' => 'memos', 'list_queries' => 'staff queries', 'list_ideas' => 'ideas board',
];

/** The chat as display turns: [{role: user|assistant, text, looked: [...], drafts: [...]}]. */
function ai_chat_get(int $id, array $me): array
{
    $c = ai_chat_load($id, $me);
    $turns = [];
    $cur = null;
    $push = function () use (&$turns, &$cur) {
        if ($cur && ($cur['text'] !== '' || $cur['looked'])) {
            $cur['looked'] = array_values(array_unique($cur['looked']));
            $turns[] = $cur;
        }
        $cur = null;
    };
    foreach ($c['messages'] as $m) {
        $role = $m['role'] ?? '';
        $content = $m['content'] ?? '';
        if ($role === 'user' && is_string($content)) {
            $push();
            $turns[] = ['role' => 'user', 'text' => preg_replace('/^\[[^\]]*\]\s*/', '', $content), 'looked' => []];
            continue;
        }
        if ($role !== 'assistant') {
            continue;
        }
        $cur ??= ['role' => 'assistant', 'text' => '', 'looked' => []];
        if (is_array($content)) {
            foreach ($content as $b) {
                if (($b['type'] ?? '') === 'text') {
                    $cur['text'] .= ($cur['text'] !== '' ? "\n\n" : '') . trim($b['text']);
                } elseif (($b['type'] ?? '') === 'tool_use' && isset(AI_TOOL_LABELS[$b['name']])) {
                    $cur['looked'][] = AI_TOOL_LABELS[$b['name']];
                }
            }
        } else {
            if (is_string($content) && trim($content) !== '') {
                $cur['text'] .= ($cur['text'] !== '' ? "\n\n" : '') . trim($content);
            }
            foreach ((array)($m['tool_calls'] ?? []) as $tc) {
                if (isset(AI_TOOL_LABELS[$tc['function']['name'] ?? ''])) {
                    $cur['looked'][] = AI_TOOL_LABELS[$tc['function']['name']];
                }
            }
        }
    }
    $push();
    return [
        'id' => (int)$c['id'],
        'title' => $c['title'],
        'provider_name' => AI_PROVIDERS[$c['provider']] ?? 'AI',
        'model' => (string)$c['model'],
        'turns' => $turns,
        'suggestions' => ai_suggestions_list(['chat_id' => (int)$c['id']]),
        'updated_at' => $c['updated_at'],
    ];
}
