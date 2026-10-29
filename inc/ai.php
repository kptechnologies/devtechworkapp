<?php
/*
 * AI assistant for management: daily brief, staff reviews, report summaries and job next steps.
 * Runs on Claude (Anthropic) or ChatGPT (OpenAI), chosen in Settings → AI. Both are called over plain curl, so
 * nothing needs installing on the server.
 * It only suggests. Suggestions are stored in ai_suggestions and a manager creates the real task, follow-up,
 * reminder or job with one click.
 */

const AI_MODEL = 'claude-opus-5-5';
const AI_OPENAI_MODEL = 'gpt-5';
const AI_PROVIDERS = ['claude' => 'Claude', 'openai' => 'ChatGPT'];
const AI_SUGGESTION_TYPES = ['job_task', 'followup', 'reminder', 'job'];

function ai_settings(): array
{
    $s = setting('ai') ?: [];
    return [
        'enabled' => !empty($s['enabled']),
        'daily_brief' => ($s['daily_brief'] ?? true) !== false,
        'provider' => isset(AI_PROVIDERS[$s['provider'] ?? '']) ? $s['provider'] : 'claude',
        'has_key' => ($s['key'] ?? '') !== '',
        'has_openai_key' => ($s['openai_key'] ?? '') !== '',
        'openai_model' => ($s['openai_model'] ?? '') !== '' ? $s['openai_model'] : AI_OPENAI_MODEL,
    ];
}

/** Saved key for 'claude' (field key) or 'openai' (field openai_key), decrypted. */
function ai_api_key(string $provider = 'claude'): string
{
    $sealed = (string)((setting('ai') ?: [])[$provider === 'openai' ? 'openai_key' : 'key'] ?? '');
    return $sealed === '' ? '' : unseal($sealed);
}

/** Why $provider can't be used (library or key missing), or '' when it can. */
function ai_provider_problem(string $provider): string
{
    $s = ai_settings();
    if ($provider === 'openai') {
        if (!function_exists('curl_init')) {
            return 'The PHP curl extension is needed for ChatGPT. Turn it on in cPanel → Select PHP Version.';
        }
        return $s['has_openai_key'] ? '' : 'Add the OpenAI API key in Settings → AI assistant.';
    }
    if (!function_exists('curl_init')) {
        return 'The PHP curl extension is needed for the AI assistant. Turn it on in cPanel → Select PHP Version.';
    }
    return $s['has_key'] ? '' : 'Add the Claude API key in Settings → AI assistant.';
}

/** Providers with everything they need, e.g. ['claude', 'openai']. */
function ai_providers_ready(): array
{
    return array_values(array_filter(array_keys(AI_PROVIDERS), fn($p) => ai_provider_problem($p) === ''));
}

/** The provider asked for when it is ready, else the one chosen in Settings, else any that is ready. */
function ai_pick_provider(?string $wanted = null): string
{
    $default = ai_settings()['provider'];
    foreach (array_unique(array_filter([$wanted, $default, ...array_keys(AI_PROVIDERS)])) as $p) {
        if (isset(AI_PROVIDERS[$p]) && ai_provider_problem($p) === '') {
            return $p;
        }
    }
    return $default;
}

function ai_enabled(): bool
{
    return ai_problem() === '';
}

/** Why AI can't run right now, or '' when it can. */
function ai_problem(?string $provider = null): string
{
    if (!ai_settings()['enabled']) {
        return 'Turn on the AI assistant in Settings.';
    }
    return ai_provider_problem(ai_pick_provider($provider));
}

/* ------------------------------------------------------------------ the call */

const AI_SYSTEM = <<<'TXT'
You are the operations assistant for DevTech, a company whose field staff teach ICT classes in schools, maintain school computer labs and laptops, do installations, and handle job orders for schools and companies.

You help management (admins and managers). You are given data from the staff portal: job orders and their tasks and updates, daily staff reports, attendance (clock-in/out, lateness, missed clock-outs, GPS problems), refused clock-in attempts, and before-due reminders that were escalated or ignored.

How to work:
- Base every statement on the data given. Name the staff member, school/client and job reference (e.g. JOB-0042) you are talking about. If the data does not show something, don't guess; say what is missing only when it matters.
- Look for what needs action: overdue or stalled jobs, urgent requests and complaints in reports, things a staff member keeps reporting as pending, faulty laptops not resolved, lateness or missed clock-outs that repeat, refused clock-ins that suggest a phone/GPS problem, reports that are vague or missing.
- Be fair to staff: separate a pattern (several times) from a one-off, and note good work too when it is clear.
- Recommendations must be concrete actions a manager can take today, with who should do it.
- Suggestions are drafts a manager will approve. Use only these types:
  - job_task: a task on an existing job (needs job_id, title, due_date, assignee_ids).
  - followup: a follow-up note posted on an existing job to its assignees (needs job_id and text).
  - reminder: a direct reminder message to one staff member (needs user_id, title and text).
  - job: a new job order, e.g. from an urgent request or complaint in a report (needs title, client_name, text as the description, priority, due_date; assignee_ids optional).
  Only use job_id and user_id values that appear in the data. Dates are YYYY-MM-DD and must not be in the past. Keep the list short and high value (at most 8).
- Write in plain, short British English sentences. No markdown headings; the summary may use short paragraphs and "- " bullet lines.
TXT;

/** Output shape shared by every AI request. */
function ai_schema(): array
{
    $str = ['type' => 'string'];
    $int = ['type' => 'integer'];
    return [
        'type' => 'object',
        'properties' => [
            'headline' => $str,
            'summary' => $str,
            'findings' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'severity' => ['type' => 'string', 'enum' => ['urgent', 'warning', 'info', 'good']],
                    'staff' => $str,
                    'job_ref' => $str,
                    'text' => $str,
                ],
                'required' => ['severity', 'staff', 'job_ref', 'text'],
                'additionalProperties' => false,
            ]],
            'recommendations' => ['type' => 'array', 'items' => $str],
            'suggestions' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => AI_SUGGESTION_TYPES],
                    'title' => $str,
                    'text' => $str,
                    'reason' => $str,
                    'job_id' => $int,
                    'user_id' => $int,
                    'assignee_ids' => ['type' => 'array', 'items' => $int],
                    'due_date' => $str,
                    'client_name' => $str,
                    'priority' => ['type' => 'string', 'enum' => JOB_PRIORITIES],
                ],
                'required' => ['type', 'title', 'text', 'reason', 'job_id', 'user_id', 'assignee_ids', 'due_date', 'client_name', 'priority'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['headline', 'summary', 'findings', 'recommendations', 'suggestions'],
        'additionalProperties' => false,
    ];
}

/**
 * Ask the AI about $context and store the answer. Returns the run as sent to the browser.
 * Throws RuntimeException with a message fit for the user when the call fails.
 */
function ai_run(string $kind, array $scope, string $task, string $context, ?int $userId, ?string $provider = null): array
{
    $provider = ai_pick_provider($provider);
    if ($p = ai_problem($provider)) {
        throw new RuntimeException($p);
    }
    @set_time_limit(300);
    $prompt = "Today is " . date('l j F Y, H:i') . ".\n\nTask: $task\n\n<portal_data>\n$context\n</portal_data>";
    $res = $provider === 'openai' ? ai_call_openai(AI_SYSTEM, $prompt, ai_schema()) : ai_call_claude(AI_SYSTEM, $prompt, ai_schema());
    $out = json_decode($res['text'], true);
    if (!is_array($out)) {
        throw new RuntimeException('The AI answer could not be read. Try again.');
    }
    q('INSERT INTO ai_runs (kind, scope, output, provider, model, input_tokens, output_tokens, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())', [
        $kind, json_encode($scope, JSON_UNESCAPED_UNICODE), json_encode($out, JSON_UNESCAPED_UNICODE),
        $provider, mb_substr($res['model'], 0, 60), $res['in'], $res['out'], $userId,
    ]);
    $runId = (int)db()->lastInsertId();
    ai_store_suggestions($runId, (array)($out['suggestions'] ?? []));
    return ai_run_get($runId);
}

/** POST JSON to the Claude API. Returns [status, decoded body]. */
function ai_claude_http(array $body, string $key, int $timeout = 300): array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        error_log('[portal] ai claude: ' . $err);
        throw new RuntimeException('Could not reach the AI service. Check the server\'s internet connection and try again.');
    }
    return [$code, json_decode((string)$raw, true) ?: []];
}

/** A Claude API error reply as a message for the user. */
function ai_claude_error(int $code, array $body): RuntimeException
{
    $type = (string)($body['error']['type'] ?? '');
    error_log('[portal] ai claude ' . $code . ': ' . ($body['error']['message'] ?? ''));
    return new RuntimeException(match (true) {
        $code === 401 || $type === 'authentication_error' => 'The Claude API key was rejected. Check it in Settings → AI assistant.',
        $code === 403 || $type === 'permission_error' => 'This Claude API key isn\'t allowed to use ' . AI_MODEL . '. Check the key\'s workspace at console.anthropic.com.',
        $code === 429 || $code === 529 || $code >= 500 => 'The AI service is busy. Try again in a minute.',
        default => 'The AI service returned an error: ' . ai_clip($body['error']['message'] ?? 'unknown', 200),
    });
}

/** Claude through the Messages API with a JSON schema output. Returns ['text', 'in', 'out', 'model']. */
function ai_call_claude(string $system, string $prompt, array $schema): array
{
    [$code, $body] = ai_claude_http([
        'model' => AI_MODEL,
        'max_tokens' => 16000,
        'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        // If Claude's safety checks decline, the API retries on Anthropic's recommended fallback model.
        'fallbacks' => 'default',
    ], ai_api_key('claude'));
    if ($code !== 200) {
        throw ai_claude_error($code, $body);
    }
    $stop = (string)($body['stop_reason'] ?? '');
    if ($stop === 'refusal') {
        throw new RuntimeException('The AI declined this request. Try a smaller date range or different staff member.');
    }
    if ($stop === 'max_tokens') {
        throw new RuntimeException('The AI answer was too long and got cut off. Try a shorter date range.');
    }
    $text = '';
    foreach ((array)($body['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    $u = $body['usage'] ?? [];
    return [
        'text' => $text,
        'in' => (int)($u['input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0),
        'out' => (int)($u['output_tokens'] ?? 0),
        'model' => (string)($body['model'] ?? AI_MODEL),
    ];
}

/** POST JSON to the OpenAI API. Returns [status, decoded body]. */
function ai_openai_http(string $path, array $body, string $key, int $timeout = 180): array
{
    $ch = curl_init('https://api.openai.com/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        error_log('[portal] ai openai: ' . $err);
        throw new RuntimeException('Could not reach the AI service. Check the server\'s internet connection and try again.');
    }
    return [$code, json_decode((string)$raw, true) ?: []];
}

/** An OpenAI error reply as a message for the user. */
function ai_openai_error(int $code, array $body): RuntimeException
{
    error_log('[portal] ai openai ' . $code . ': ' . ($body['error']['message'] ?? ''));
    return new RuntimeException(match (true) {
        $code === 401 => 'The OpenAI API key was rejected. Check it in Settings → AI assistant.',
        $code === 404 => 'OpenAI doesn\'t know the model "' . ai_settings()['openai_model'] . '". Check the model name in Settings → AI assistant.',
        $code === 429 && ($body['error']['code'] ?? '') === 'insufficient_quota' => 'The OpenAI account has run out of credit. Add credit at platform.openai.com.',
        $code === 429 || $code >= 500 => 'The AI service is busy. Try again in a minute.',
        default => 'The AI service returned an error: ' . ai_clip($body['error']['message'] ?? 'unknown', 200),
    });
}

/** ChatGPT through the OpenAI Chat Completions API with a strict JSON schema. Returns ['text', 'in', 'out', 'model']. */
function ai_call_openai(string $system, string $prompt, array $schema): array
{
    $model = ai_settings()['openai_model'];
    [$code, $body] = ai_openai_http('chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
        'max_completion_tokens' => 16000,
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'portal_ai', 'strict' => true, 'schema' => $schema]],
    ], ai_api_key('openai'));
    if ($code !== 200) {
        throw ai_openai_error($code, $body);
    }
    $choice = $body['choices'][0] ?? [];
    if (!empty($choice['message']['refusal'])) {
        throw new RuntimeException('The AI declined this request. Try a smaller date range or different staff member.');
    }
    if (($choice['finish_reason'] ?? '') === 'length') {
        throw new RuntimeException('The AI answer was too long and got cut off. Try a shorter date range.');
    }
    return [
        'text' => (string)($choice['message']['content'] ?? ''),
        'in' => (int)($body['usage']['prompt_tokens'] ?? 0),
        'out' => (int)($body['usage']['completion_tokens'] ?? 0),
        'model' => (string)($body['model'] ?? $model),
    ];
}

/** Keep the suggestions that point at real jobs and people. */
function ai_store_suggestions(int $runId, array $list): void
{
    $users = array_map('intval', q('SELECT id FROM users WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_slice($list, 0, 12) as $s) {
        $type = $s['type'] ?? '';
        if (!in_array($type, AI_SUGGESTION_TYPES, true) || trim((string)($s['title'] ?? '')) === '') {
            continue;
        }
        $jobId = (int)($s['job_id'] ?? 0) ?: null;
        if ($jobId && !q('SELECT 1 FROM jobs WHERE id = ?', [$jobId])->fetchColumn()) {
            $jobId = null;
        }
        if (in_array($type, ['job_task', 'followup'], true) && !$jobId) {
            continue;
        }
        $userId = in_array((int)($s['user_id'] ?? 0), $users, true) ? (int)$s['user_id'] : null;
        if ($type === 'reminder' && !$userId) {
            continue;
        }
        $s['assignee_ids'] = array_values(array_intersect(array_map('intval', (array)($s['assignee_ids'] ?? [])), $users));
        $s['due_date'] = valid_date($s['due_date'] ?? null) && $s['due_date'] >= today() ? $s['due_date'] : '';
        q('INSERT INTO ai_suggestions (run_id, type, job_id, user_id, payload, created_at) VALUES (?,?,?,?,?,NOW())',
            [$runId, $type, $jobId, $userId, json_encode($s, JSON_UNESCAPED_UNICODE)]);
    }
}

function ai_run_get(int $id): ?array
{
    $r = q('SELECT r.*, u.name AS created_by_name FROM ai_runs r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$id])->fetch();
    if (!$r) {
        return null;
    }
    return [
        'id' => (int)$r['id'],
        'kind' => $r['kind'],
        'scope' => json_decode((string)$r['scope'], true) ?: [],
        'output' => json_decode((string)$r['output'], true) ?: [],
        'provider_name' => AI_PROVIDERS[$r['provider'] ?? 'claude'] ?? 'AI',
        'model' => (string)($r['model'] ?? ''),
        'created_by_name' => $r['created_by_name'] ?: 'Automatic',
        'created_at' => $r['created_at'],
        'suggestions' => ai_suggestions_list(['run_id' => $id]),
    ];
}

function ai_suggestions_list(array $f): array
{
    $where = ['1 = 1'];
    $p = [];
    if (!empty($f['run_id'])) {
        $where[] = 's.run_id = ?';
        $p[] = (int)$f['run_id'];
    }
    if (!empty($f['status'])) {
        $where[] = 's.status = ?';
        $p[] = $f['status'];
    }
    $rows = q('SELECT s.*, j.title AS job_title, u.name AS user_name, a.name AS acted_by_name FROM ai_suggestions s
        LEFT JOIN jobs j ON j.id = s.job_id LEFT JOIN users u ON u.id = s.user_id LEFT JOIN users a ON a.id = s.acted_by
        WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC LIMIT 200', $p)->fetchAll();
    return array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'run_id' => (int)$r['run_id'],
            'type' => $r['type'],
            'job_id' => $r['job_id'] ? (int)$r['job_id'] : null,
            'job_ref' => $r['job_id'] ? job_ref((int)$r['job_id']) : '',
            'job_title' => (string)$r['job_title'],
            'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
            'user_name' => (string)$r['user_name'],
            'payload' => json_decode((string)$r['payload'], true) ?: [],
            'status' => $r['status'],
            'acted_by_name' => (string)$r['acted_by_name'],
            'acted_at' => $r['acted_at'],
            'created_at' => $r['created_at'],
        ];
    }, $rows);
}

/* ------------------------------------------------------------------ context for the AI (no phone numbers, GPS or pay) */

function ai_clip($v, int $max = 400): string
{
    $s = trim(preg_replace('/\s+/', ' ', is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}

function ai_staff_list(): string
{
    $rows = q("SELECT id, name, role, job_title, location FROM users WHERE active = 1 AND role IN ('" . implode("','", EMPLOYEE_ROLES) . "') ORDER BY name")->fetchAll();
    $out = "## Staff (user_id: name, role, schools)\n";
    foreach ($rows as $u) {
        $out .= "{$u['id']}: {$u['name']}, " . ROLE_LABELS[$u['role']] . ($u['job_title'] ? " ({$u['job_title']})" : '') . ($u['location'] ? ", {$u['location']}" : '') . "\n";
    }
    return $out;
}

function ai_job_line(array $j): string
{
    $who = implode(', ', array_map(fn($a) => "{$a['name']} [{$a['id']}]", $j['assignees'])) ?: 'not assigned';
    return "{$j['ref']} (job_id {$j['id']}) {$j['title']} — {$j['client_name']}; status {$j['status']}; priority {$j['priority']}; due "
        . ($j['due_date'] ?: 'none') . ($j['overdue'] ? ' (OVERDUE)' : '') . "; tasks {$j['tasks_done']}/{$j['tasks_total']} done"
        . ($j['ext_pending'] ? '; asked for more time' : '') . "; last change " . substr((string)($j['updated_at'] ?: $j['created_at']), 0, 16) . "; assigned: $who";
}

function ai_job_updates(int $jobId, int $limit = 6): string
{
    $rows = q('SELECT ju.kind, ju.body, ju.created_at, u.name FROM job_updates ju LEFT JOIN users u ON u.id = ju.user_id
        WHERE ju.job_id = ? ORDER BY ju.id DESC LIMIT ' . $limit, [$jobId])->fetchAll();
    $out = '';
    foreach (array_reverse($rows) as $r) {
        $out .= '    · ' . substr($r['created_at'], 0, 16) . ' ' . ($r['name'] ?: 'System') . " ({$r['kind']}): " . ai_clip($r['body'], 300) . "\n";
    }
    return $out;
}

function ai_job_tasks(int $jobId): string
{
    $rows = q("SELECT t.title, t.due_date, t.status, GROUP_CONCAT(u.name SEPARATOR ', ') AS who FROM job_tasks t
        LEFT JOIN job_task_assignees ta ON ta.task_id = t.id LEFT JOIN users u ON u.id = ta.user_id
        WHERE t.job_id = ? GROUP BY t.id ORDER BY t.due_date", [$jobId])->fetchAll();
    $out = '';
    foreach ($rows as $t) {
        $late = $t['status'] !== 'done' && $t['due_date'] < today() ? ' OVERDUE' : '';
        $out .= "    - task: {$t['title']} ({$t['status']}, due {$t['due_date']}$late, {$t['who']})\n";
    }
    return $out;
}

/** Open jobs that need attention, with recent updates. */
function ai_ctx_jobs(?int $userId = null): string
{
    $rs = reminder_settings();
    $out = "## Jobs needing attention\n";
    $seen = [];
    foreach (digest_sections($rs['stale_days']) as [$title, $jobs]) {
        if ($userId) {
            $jobs = array_filter($jobs, fn($j) => in_array($userId, array_column($j['assignees'], 'id'), true));
        }
        if (!$jobs) {
            continue;
        }
        $out .= "### $title\n";
        foreach (array_slice($jobs, 0, 25) as $j) {
            $out .= '- ' . ai_job_line($j) . "\n";
            if (!isset($seen[$j['id']]) && count($seen) < 30) {
                $seen[$j['id']] = true;
                $out .= ai_job_tasks($j['id']) . ai_job_updates($j['id'], 4);
            }
        }
    }
    $ext = q("SELECT x.job_id, x.reason, x.created_at, u.name FROM job_extensions x LEFT JOIN users u ON u.id = x.user_id
        WHERE x.status = 'pending' ORDER BY x.id LIMIT 20")->fetchAll();
    if ($ext) {
        $out .= "### Requests for more time waiting for a decision\n";
        foreach ($ext as $x) {
            $out .= '- ' . job_ref((int)$x['job_id']) . " by {$x['name']} on " . substr($x['created_at'], 0, 10) . ': ' . ai_clip($x['reason'] ?? '', 200) . "\n";
        }
    }
    return $out;
}

/** Fields of a saved report that say what happened, flattened to "label: value". */
function ai_report_text(array $r): string
{
    $d = json_decode((string)($r['data'] ?? ''), true) ?: [];
    $skip = '/photo|image|signature|file|lat|lng|gps|phone|selfie/i';
    $parts = [];
    foreach ($d as $k => $v) {
        if (preg_match($skip, (string)$k) || $v === '' || $v === null || $v === [] || $v === false) {
            continue;
        }
        $parts[] = str_replace('_', ' ', (string)$k) . ': ' . ai_clip($v, 350);
    }
    return implode('; ', $parts);
}

function ai_ctx_reports(string $from, string $to, ?int $userId = null, int $limit = 120): string
{
    $p = [$from, $to];
    $where = 'r.report_date BETWEEN ? AND ?';
    if ($userId) {
        $where .= ' AND r.user_id = ?';
        $p[] = $userId;
    }
    $rows = q("SELECT r.id, r.user_id, u.name, r.report_date, r.location, r.work_type, r.priority, r.urgent, r.complaint, r.followup, r.data
        FROM reports r JOIN users u ON u.id = r.user_id WHERE $where ORDER BY r.report_date, u.name LIMIT $limit", $p)->fetchAll();
    $out = "## Daily reports $from to $to (" . count($rows) . ($rows && count($rows) === $limit ? '+, list cut' : '') . ")\n";
    foreach ($rows as $r) {
        $out .= "- {$r['report_date']} {$r['name']} [user {$r['user_id']}] at {$r['location']} ({$r['work_type']}, priority {$r['priority']}): "
            . ai_report_text($r) . "\n";
    }
    // Who didn't send a report on working days in the range.
    $missing = ai_missing_reports($from, $to, $userId);
    if ($missing) {
        $out .= "### Working days with no report\n" . $missing;
    }
    return $out;
}

function ai_missing_reports(string $from, string $to, ?int $userId): string
{
    $days = [];
    for ($d = strtotime($from); $d <= min(strtotime($to), strtotime(today())); $d += 86400) {
        if (in_array((int)date('N', $d), work_days(), true)) {
            $days[] = date('Y-m-d', $d);
        }
    }
    if (!$days || count($days) > 31) {
        return '';
    }
    $p = [];
    $uw = '';
    if ($userId) {
        $uw = ' AND u.id = ?';
        $p[] = $userId;
    }
    $users = q("SELECT u.id, u.name FROM users u WHERE u.active = 1 AND u.role IN ('staff','supervisor')$uw", $p)->fetchAll();
    $out = '';
    foreach ($users as $u) {
        $have = q('SELECT DISTINCT report_date FROM reports WHERE user_id = ? AND report_date BETWEEN ? AND ?', [$u['id'], $from, $to])->fetchAll(PDO::FETCH_COLUMN);
        // Only days they clocked in count, so leave and off days aren't flagged.
        $worked = q('SELECT DISTINCT work_date FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ?', [$u['id'], $from, $to])->fetchAll(PDO::FETCH_COLUMN);
        $gap = array_values(array_diff(array_intersect($days, $worked), $have));
        if ($gap) {
            $out .= "- {$u['name']} [user {$u['id']}]: clocked in but no report on " . implode(', ', $gap) . "\n";
        }
    }
    return $out;
}

function ai_ctx_attendance(string $from, string $to, ?int $userId = null): string
{
    $p = [$from, $to];
    $uw = '';
    if ($userId) {
        $uw = ' AND a.user_id = ?';
        $p[] = $userId;
    }
    $start = work_start();
    $rows = q("SELECT a.user_id, u.name, a.work_date, a.in_at, a.out_at, a.in_site, a.out_site, a.in_reason, a.out_reason, a.flags, a.out_missed, a.location, a.work_note
        FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.work_date BETWEEN ? AND ?$uw ORDER BY u.name, a.work_date, a.in_at LIMIT 400", $p)->fetchAll();
    $out = "## Attendance $from to $to (work starts $start)\n";
    $per = [];
    foreach ($rows as $a) {
        $late = substr($a['in_at'], 11, 5) > $start;
        $flags = array_filter(array_merge(explode(',', (string)$a['flags']), [$a['out_missed'] ? 'missed_clock_out' : '', $late ? 'late' : '']));
        $per[$a['name'] . " [user {$a['user_id']}]"][] = $a['work_date'] . ' in ' . substr($a['in_at'], 11, 5) . ($a['in_site'] ? " at {$a['in_site']}" : '')
            . ' out ' . ($a['out_at'] ? substr($a['out_at'], 11, 5) : '—')
            . ($flags ? ' [' . implode(', ', $flags) . ']' : '')
            . ($a['in_reason'] ? ' reason: ' . ai_clip($a['in_reason'], 120) : '')
            . ($a['out_reason'] ? ' out reason: ' . ai_clip($a['out_reason'], 120) : '');
    }
    foreach ($per as $who => $lines) {
        $out .= "- $who: " . count($lines) . " visit(s)\n    " . implode("\n    ", $lines) . "\n";
    }
    $p2 = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $uw2 = '';
    if ($userId) {
        $uw2 = ' AND e.user_id = ?';
        $p2[] = $userId;
    }
    $ev = q("SELECT e.user_id, u.name, e.kind, e.code, e.message, e.created_at FROM location_events e JOIN users u ON u.id = e.user_id
        WHERE e.created_at BETWEEN ? AND ?$uw2 ORDER BY e.created_at LIMIT 100", $p2)->fetchAll();
    if ($ev) {
        $out .= "### Refused clock-in/out attempts\n";
        foreach ($ev as $e) {
            $out .= '- ' . substr($e['created_at'], 0, 16) . " {$e['name']} [user {$e['user_id']}] {$e['kind']} {$e['code']}: " . ai_clip($e['message'], 150) . "\n";
        }
    }
    return $out;
}

function ai_ctx_reminders(string $from, ?int $userId = null): string
{
    $p = [$from . ' 00:00:00'];
    $uw = '';
    if ($userId) {
        $uw = ' AND d.user_id = ?';
        $p[] = $userId;
    }
    $rows = q("SELECT d.kind, d.job_id, d.status, d.due_at, d.action, u.name, d.user_id FROM due_reminders d JOIN users u ON u.id = d.user_id
        WHERE d.created_at >= ? AND d.status IN ('escalated','snoozed','sent')$uw ORDER BY d.due_at LIMIT 80", $p)->fetchAll();
    $rows = array_filter($rows, fn($r) => $r['status'] !== 'sent' || $r['due_at'] < date('Y-m-d H:i:s'));
    if (!$rows) {
        return '';
    }
    $out = "## Before-due reminders not acted on (escalated, snoozed or ignored past due)\n";
    foreach ($rows as $r) {
        $out .= '- ' . job_ref((int)$r['job_id']) . " {$r['kind']} due " . substr($r['due_at'], 0, 16) . " — {$r['name']} [user {$r['user_id']}]: {$r['status']}\n";
    }
    return $out;
}

/* ------------------------------------------------------------------ the four requests */

function ai_brief(?int $userId, ?string $provider = null): array
{
    $from = date('Y-m-d', strtotime('-2 days'));
    $ctx = ai_staff_list() . "\n" . ai_ctx_jobs() . "\n" . ai_ctx_reports($from, today()) . "\n"
        . ai_ctx_attendance(last_work_day() ?: $from, today()) . "\n" . ai_ctx_reminders(date('Y-m-d', strtotime('-7 days')));
    return ai_run('brief', ['date' => today()],
        'Write this morning\'s brief for management. Headline: the one thing that most needs attention today. Summary: what is happening overall in 4-8 bullet lines. '
        . 'Findings: the specific problems (and any clear good work) from jobs, the last two days of reports and yesterday/today\'s attendance. '
        . 'Recommendations: what management should do today, in order. Suggestions: the follow-ups, tasks, reminders or new jobs worth creating.',
        $ctx, $userId, $provider);
}

function ai_staff_review(int $staffId, string $from, string $to, ?int $userId, ?string $provider = null): array
{
    $u = q('SELECT id, name, role, job_title, location FROM users WHERE id = ?', [$staffId])->fetch();
    if (!$u) {
        throw new RuntimeException('Staff member not found.');
    }
    $jobs = q(JOB_LIST_SQL . " WHERE j.id IN (SELECT job_id FROM job_assignees WHERE user_id = ? AND removed_at IS NULL)
        AND (j.status NOT IN ('done','cancelled') OR j.updated_at >= ?) ORDER BY j.due_date LIMIT 40", [$staffId, $from])->fetchAll();
    $jtxt = "## {$u['name']}'s jobs (open, or changed in the period)\n";
    foreach (array_map('job_row', $jobs) as $j) {
        $jtxt .= '- ' . ai_job_line($j) . "\n" . ai_job_tasks($j['id']) . ai_job_updates($j['id'], 4);
    }
    $ctx = "## Staff member\nuser_id {$u['id']}: {$u['name']}, " . ROLE_LABELS[$u['role']] . ($u['job_title'] ? " ({$u['job_title']})" : '') . ", schools: {$u['location']}\n\n"
        . $jtxt . "\n" . ai_ctx_reports($from, $to, $staffId) . "\n" . ai_ctx_attendance($from, $to, $staffId) . "\n" . ai_ctx_reminders($from, $staffId);
    return ai_run('staff_review', ['staff_id' => $staffId, 'staff_name' => $u['name'], 'from' => $from, 'to' => $to],
        "Review what {$u['name']} has been reporting and doing from $from to $to. Summary: what they worked on and how it went. "
        . 'Findings: patterns (repeated pending items or challenges, unresolved faults, complaints, urgent requests, lateness, missed clock-outs, refused clock-ins, missing or vague reports, slow jobs) and clear good work. '
        . 'Recommendations: what their manager should do or say. Suggestions: follow-ups, tasks, reminders or new jobs.',
        $ctx, $userId, $provider);
}

function ai_report_summary(string $from, string $to, ?int $userId, ?string $provider = null): array
{
    $ctx = ai_staff_list() . "\n" . ai_ctx_reports($from, $to, null, 250) . "\n" . ai_ctx_attendance($from, $to);
    return ai_run('report_summary', ['from' => $from, 'to' => $to],
        "Summarise the staff reports from $from to $to as a report management can share. Headline: one line for the period. "
        . 'Summary: work done (schools visited, classes taught, laptops fixed/faulty, installations), main issues, and what is still pending, as bullet lines with numbers where the data has them. '
        . 'Findings: issues by school or staff member. Recommendations: what to do next. Suggestions: follow-ups or new jobs from unresolved items.',
        $ctx, $userId, $provider);
}

function ai_job_next_steps(int $jobId, ?int $userId, ?string $provider = null): array
{
    $j = q(JOB_LIST_SQL . ' WHERE j.id = ?', [$jobId])->fetch();
    if (!$j) {
        throw new RuntimeException('Job not found.');
    }
    $j = job_row($j);
    $desc = (string)q('SELECT description FROM jobs WHERE id = ?', [$jobId])->fetchColumn();
    $ctx = ai_staff_list() . "\n## The job\n- " . ai_job_line($j) . "\n  description: " . ai_clip($desc, 1500) . "\n"
        . ai_job_tasks($jobId) . "  updates:\n" . ai_job_updates($jobId, 25);
    return ai_run('job_next_steps', ['job_id' => $jobId, 'job_ref' => $j['ref']],
        "Look at job {$j['ref']} and say what should happen next. Summary: where the job stands. Findings: risks or blockers (late, stalled, unclear, waiting on someone). "
        . 'Recommendations: next steps in order. Suggestions: tasks or follow-ups on this job (job_id ' . $jobId . '), or reminders to the people on it.',
        $ctx, $userId, $provider);
}

/** Daily: build the brief once and tell management. Called from run_reminders(). */
function ai_daily_brief(): string
{
    if (!ai_enabled() || !ai_settings()['daily_brief']) {
        return '';
    }
    try {
        $run = ai_brief(null);
    } catch (Throwable $e) {
        return 'AI brief failed: ' . $e->getMessage();
    }
    $n = count($run['suggestions']);
    notify_managers('ai', 'AI morning brief: ' . ai_clip($run['output']['headline'] ?? 'ready', 150),
        ai_clip($run['output']['summary'] ?? '', 600) . ($n ? "\n$n suggested action(s) to review." : ''), 'ai', false);
    return 'AI brief sent.';
}
