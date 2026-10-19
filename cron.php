<?php
declare(strict_types=1);
/*
 * Scheduled reminders. Run every 5 minutes from cPanel → Cron Jobs (before-due reminders need it; the daily parts run once a day):
 *   php /home/USER/public_html/cron.php
 * or call https://yourdomain.com/cron.php?key=YOUR_KEY (the key is shown in Settings → Reminders).
 * Add --force (or &force=1) to send today's digest again.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/api_reports.php';
require __DIR__ . '/inc/api_wallet.php';
require __DIR__ . '/inc/api_jobs.php';
require __DIR__ . '/inc/api_people.php';
require __DIR__ . '/inc/api_clients.php';
require __DIR__ . '/inc/api_payroll.php';
require __DIR__ . '/inc/api_lessons.php';
require __DIR__ . '/inc/api_learning.php';
require __DIR__ . '/inc/api_timetable.php';
require __DIR__ . '/inc/reminders.php';
require __DIR__ . '/inc/due_reminders.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)setting('cron_key');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
ensure_schema();
$force = $cli ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);
$log = array_merge(run_due_reminders(), run_reminders($force));
echo date('Y-m-d H:i') . ' ' . ($log ? implode(' ', $log) : 'Nothing due.') . "\n";
