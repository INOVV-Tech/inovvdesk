<?php

$root = dirname(__DIR__);
$settings = [
    'pseudo_cron_enabled' => '1',
    'pseudo_cron_last_email' => '0',
    'pseudo_cron_last_email_attempt' => '0',
];

function get_setting($key, $default = '')
{
    global $settings;
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function save_setting($key, $value)
{
    global $settings;
    $settings[$key] = (string) $value;
}

function db_fetch_one($sql, $params = [])
{
    return null;
}

function db_insert($table, $data)
{
    return true;
}

require_once $root . '/includes/pseudo-cron.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

$now = 1000;
$assert(pseudo_cron_email_is_due($now), 'Email ingest should be due when it has never succeeded.');

pseudo_cron_mark_email_attempt($now, 'test');
$assert((int) $settings['pseudo_cron_last_email_attempt'] === $now, 'Email ingest attempt timestamp must be stored.');
$assert((int) $settings['pseudo_cron_last_email'] === 0, 'Scheduling an attempt must not mark email ingest as completed.');
$assert(!pseudo_cron_email_is_due($now + 30), 'Recent failed/unfinished attempts must be throttled briefly.');

pseudo_cron_mark_email_error('boom', 'test');
$assert((int) $settings['pseudo_cron_last_email'] === 0, 'Failed ingest must not update the success timestamp.');
$assert($settings['pseudo_cron_last_email_error'] === 'boom', 'Failed ingest must store the diagnostic error.');
$assert(pseudo_cron_email_is_due($now + 61), 'Email ingest should retry after the attempt throttle expires.');

pseudo_cron_mark_email_success($now + 62, ['checked' => 3, 'processed' => 2, 'skipped' => 1, 'failed' => 0], 'test');
$assert((int) $settings['pseudo_cron_last_email'] === $now + 62, 'Successful ingest must update the success timestamp.');
$assert($settings['pseudo_cron_last_email_error'] === '', 'Successful ingest must clear the previous error.');
$assert(!pseudo_cron_email_is_due($now + 100), 'Successful ingest must respect the five minute interval.');

echo "Pseudo-cron email contract OK\n";
