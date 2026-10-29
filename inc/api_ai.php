<?php
/* AI assistant endpoints (management only). The AI work itself is in ai.php. */

require_once __DIR__ . '/ai.php';

function ai_or_fail(callable $fn): void
{
    try {
        $run = $fn();
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 503);
    }
    json_out(['ok' => true, 'run' => $run]);
}

/** Provider the page asked for ('claude' / 'openai'), or null for the Settings choice. */
function ai_in_provider(array $in): ?string
{
    return isset(AI_PROVIDERS[$in['provider'] ?? '']) ? $in['provider'] : null;
}

function ai_range(array $in, int $defaultDays = 7): array
{
    $to = valid_date($in['to'] ?? null) ? $in['to'] : today();
    $from = valid_date($in['from'] ?? null) ? $in['from'] : date('Y-m-d', strtotime("$to -" . ($defaultDays - 1) . ' days'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
        fail('Pick a range of three months or less.');
    }
    return [$from, $to];
}

/** Status for the AI page and dashboard card: is it set up, the latest brief and pending suggestions count. */
function act_ai_status(array $in, array $me): void
{
    require_management();
    $brief = q("SELECT id FROM ai_runs WHERE kind = 'brief' ORDER BY id DESC LIMIT 1")->fetchColumn();
    json_out(['ok' => true, 'problem' => ai_problem(), 'brief' => $brief ? ai_run_get((int)$brief) : null,
        'providers' => array_map(fn($p) => ['id' => $p, 'name' => AI_PROVIDERS[$p]], ai_providers_ready()),
        'default_provider' => ai_settings()['provider'],
        'pending' => (int)q("SELECT COUNT(*) FROM ai_suggestions WHERE status = 'pending'")->fetchColumn()]);
}

function act_ai_brief(array $in, array $me): void
{
    require_management();
    ai_or_fail(fn() => ai_brief($me['id'], ai_in_provider($in)));
}

function act_ai_staff_review(array $in, array $me): void
{
    require_management();
    [$from, $to] = ai_range($in, 14);
    ai_or_fail(fn() => ai_staff_review((int)($in['user_id'] ?? 0), $from, $to, $me['id'], ai_in_provider($in)));
}

function act_ai_report_summary(array $in, array $me): void
{
    require_management();
    [$from, $to] = ai_range($in, 7);
    ai_or_fail(fn() => ai_report_summary($from, $to, $me['id'], ai_in_provider($in)));
}

function act_ai_job_next_steps(array $in, array $me): void
{
    require_management();
    $job = load_job((int)($in['id'] ?? 0), $me);
    ai_or_fail(fn() => ai_job_next_steps($job['id'], $me['id'], ai_in_provider($in)));
}

/** Earlier AI answers, newest first. */
function act_ai_runs(array $in, array $me): void
{
    require_management();
    if ($id = (int)($in['id'] ?? 0)) {
        $run = ai_run_get($id) ?? fail('Not found.', 404);
        json_out(['ok' => true, 'run' => $run]);
    }
    $p = [];
    $where = '';
    if (in_array($in['kind'] ?? '', ['brief', 'staff_review', 'report_summary', 'job_next_steps'], true)) {
        $where = 'WHERE r.kind = ?';
        $p[] = $in['kind'];
    }
    $rows = q("SELECT r.id, r.kind, r.scope, r.output, r.provider, r.model, r.created_at, u.name AS created_by_name FROM ai_runs r LEFT JOIN users u ON u.id = r.created_by
        $where ORDER BY r.id DESC LIMIT 50", $p)->fetchAll();
    json_out(['ok' => true, 'runs' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'kind' => $r['kind'], 'scope' => json_decode((string)$r['scope'], true) ?: [],
        'headline' => (json_decode((string)$r['output'], true) ?: [])['headline'] ?? '',
        'provider_name' => AI_PROVIDERS[$r['provider']] ?? 'AI', 'model' => $r['model'],
        'created_by_name' => $r['created_by_name'] ?: 'Automatic', 'created_at' => $r['created_at'],
    ], $rows)]);
}

function act_ai_suggestions(array $in, array $me): void
{
    require_management();
    $status = in_array($in['status'] ?? 'pending', ['pending', 'accepted', 'dismissed'], true) ? ($in['status'] ?? 'pending') : 'pending';
    json_out(['ok' => true, 'suggestions' => ai_suggestions_list(['status' => $status])]);
}

/**
 * Mark a suggestion done or dismissed. Tasks, follow-ups and jobs are created by the browser through the normal
 * job_task_save / job_update / job_save actions first (same checks and alerts); a reminder is sent here.
 */
function act_ai_suggestion_act(array $in, array $me): void
{
    require_management();
    $s = q('SELECT * FROM ai_suggestions WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Suggestion not found.', 404);
    if ($s['status'] !== 'pending') {
        fail('This suggestion was already handled.');
    }
    $status = ($in['status'] ?? '') === 'dismissed' ? 'dismissed' : 'accepted';
    if ($status === 'accepted' && $s['type'] === 'reminder') {
        $title = str_in($in['title'] ?? '', 190);
        $body = str_in($in['text'] ?? '', 2000);
        $to = (int)($in['user_id'] ?? $s['user_id']);
        if ($title === '' || !$to) {
            fail('Give the reminder a title and choose who gets it.');
        }
        notify([$to], 'reminder', $title, $body . "\n— " . $me['name'], $s['job_id'] ? 'jobs/' . $s['job_id'] : '', true);
        if ($s['job_id']) {
            job_log((int)$s['job_id'], 'reminder', "Reminder sent to " . user_names([$to]) . ": $title");
        }
    }
    q('UPDATE ai_suggestions SET status = ?, acted_by = ?, acted_at = NOW() WHERE id = ?', [$status, $me['id'], $s['id']]);
    audit('ai_suggestion_' . $status, 'ai_suggestion', (int)$s['id'], ['type' => $s['type']]);
    json_out(['ok' => true]);
}

/** Settings → AI assistant → Test: a tiny request to the chosen provider with the typed key, or the saved one. */
function act_ai_test(array $in, array $me): void
{
    require_admin();
    $provider = ai_in_provider($in) ?? 'claude';
    $key = trim((string)($in['key'] ?? ''));
    $key = $key === '' || $key === '••••••••' ? ai_api_key($provider) : $key;
    if ($provider === 'openai') {
        if (!function_exists('curl_init')) {
            fail('The PHP curl extension is needed for ChatGPT.');
        }
        if ($key === '') {
            fail('Enter the OpenAI API key first.');
        }
        $model = str_in($in['model'] ?? '', 60) ?: ai_settings()['openai_model'];
        try {
            [$code, $body] = ai_openai_http('chat/completions', ['model' => $model, 'max_completion_tokens' => 300,
                'messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']]], $key, 30);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        if ($code !== 200) {
            fail('OpenAI said: ' . ($body['error']['message'] ?? "HTTP $code"));
        }
        json_out(['ok' => true, 'message' => 'Connected to ChatGPT (' . ($body['model'] ?? $model) . ').']);
    }
    if (!ai_sdk_loaded()) {
        fail('The Claude library is not installed on the server (run composer install).');
    }
    if ($key === '') {
        fail('Enter the Claude API key first.');
    }
    try {
        ai_client($key)->messages->create(
            model: AI_MODEL,
            maxTokens: 1024,
            outputConfig: ['effort' => 'low'],
            messages: [['role' => 'user', 'content' => 'Reply with the single word OK.']],
        );
    } catch (Anthropic\Core\Exceptions\APIStatusException $e) {
        fail('Claude said: ' . $e->getMessage());
    } catch (Throwable $e) {
        fail('Could not reach Claude: ' . $e->getMessage());
    }
    json_out(['ok' => true, 'message' => 'Connected to Claude (' . AI_MODEL . ').']);
}
