<?php
/**
 * Pseudo-cron system (WordPress-style)
 *
 * Triggered on page loads. When tasks are due, fires a non-blocking
 * HTTP request to the cron endpoint so the user's page load is not delayed.
 *
 * Tasks:
 *  - email ingestion   (every 5 minutes)
 *  - recurring tasks    (every 60 minutes)
 *  - maintenance/cleanup (every 24 hours)
 */

// Task definitions: setting key => interval in seconds
define('PSEUDO_CRON_TASKS', [
    'pseudo_cron_last_email'       => 300,   // 5 minutes
    'pseudo_cron_last_recurring'   => 3600,  // 1 hour
    'pseudo_cron_last_reports'     => 21600, // 6 hours
    'pseudo_cron_last_maintenance' => 86400, // 24 hours
]);

/**
 * Check if any pseudo-cron task is due and trigger if so.
 * Called on every page load from header.php.
 */
function pseudo_cron_check()
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    // Enabled by default for shared hosting installs; explicit "0" disables it.
    if ((string) get_setting('pseudo_cron_enabled', '1') === '0') {
        return;
    }

    $now = time();

    // Quick check: is any task overdue?
    $due = false;
    $email_due = false;
    foreach (PSEUDO_CRON_TASKS as $setting_key => $interval) {
        $last_run = (int) get_setting($setting_key, '0');
        if ($now - $last_run >= $interval) {
            $due = true;
            if ($setting_key === 'pseudo_cron_last_email') {
                $email_due = true;
            }
            break;
        }
    }

    if (!$due) {
        return;
    }

    // IMAP ingest must still work on hosts that block loopback HTTP requests.
    if ($email_due) {
        pseudo_cron_schedule_inline_email_ingest($now);
    }

    // Fire non-blocking request to cron endpoint for the rest of the tasks.
    $last_attempt = (int) get_setting('pseudo_cron_last_trigger_attempt', '0');
    if ($now - $last_attempt >= 60) {
        save_setting('pseudo_cron_last_trigger_attempt', (string) $now);
        pseudo_cron_trigger();
    }
}

/**
 * Fire a non-blocking HTTP request to the cron endpoint.
 * The request is "fire and forget" — we don't wait for a response.
 */
function pseudo_cron_trigger()
{
    $secret = get_setting('pseudo_cron_secret');
    if (!$secret) {
        // Generate secret on first use
        $secret = bin2hex(random_bytes(20));
        save_setting('pseudo_cron_secret', $secret);
    }

    // Build URL to cron endpoint
    $base_url = function_exists('foxdesk_get_request_base_url')
        ? foxdesk_get_request_base_url($_SERVER['SCRIPT_NAME'] ?? '/index.php')
        : null;

    if ($base_url === null || $base_url === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_path = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base_url = $protocol . '://' . $host . rtrim($script_path, '/');
    }

    $url = rtrim($base_url, '/') . '/index.php?page=cron&token=' . urlencode($secret);

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return false;
    }

    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $ssl_prefix = ($parts['scheme'] === 'https') ? 'ssl://' : '';

    $fp = @fsockopen($ssl_prefix . $parts['host'], $port, $errno, $errstr, 2);
    if (!$fp) {
        // fsockopen failed — try file_get_contents as fallback (blocking but works on more hosts)
        $ctx = stream_context_create(['http' => [
            'timeout' => 1,
            'method'  => 'GET',
            'header'  => "Connection: close\r\n",
        ]]);
        return @file_get_contents($url, false, $ctx) !== false;
    }

    $path_query = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');
    $request  = "GET {$path_query} HTTP/1.1\r\n";
    $request .= "Host: {$parts['host']}\r\n";
    $request .= "Connection: close\r\n";
    $request .= "User-Agent: FoxDesk-PseudoCron/1.0\r\n";
    $request .= "\r\n";

    fwrite($fp, $request);
    fclose($fp); // Don't wait for response
    return true;
}

/**
 * Schedule a direct email ingest fallback after the current page is sent.
 */
function pseudo_cron_schedule_inline_email_ingest(int $now): void
{
    static $scheduled = false;
    if ($scheduled) {
        return;
    }

    $lock_time = (int) get_setting('pseudo_cron_email_inline_lock', '0');
    if ($lock_time > 0 && ($now - $lock_time) < 300) {
        return;
    }

    $scheduled = true;
    save_setting('pseudo_cron_email_inline_lock', (string) $now);
    save_setting('pseudo_cron_last_email', (string) $now);

    register_shutdown_function('pseudo_cron_run_inline_email_ingest', $now);
}

/**
 * Run IMAP ingest inline as a fallback for shared hosting without cron/loopback.
 */
function pseudo_cron_run_inline_email_ingest(int $started_at): void
{
    ignore_user_abort(true);
    @set_time_limit(120);

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    try {
        require_once BASE_PATH . '/includes/email-ingest-functions.php';
        $cfg = email_ingest_config();
        $enabled = !empty($cfg['enabled'])
            && trim((string) ($cfg['host'] ?? '')) !== ''
            && trim((string) ($cfg['username'] ?? '')) !== ''
            && trim((string) ($cfg['password'] ?? '')) !== '';

        if (!$enabled) {
            pseudo_cron_log('info', 'Inline email ingest skipped', ['reason' => 'disabled_or_missing_config']);
            return;
        }

        $result = email_ingest_run();
        pseudo_cron_log('info', 'Inline email ingest completed', [
            'checked' => (int) ($result['checked'] ?? 0),
            'processed' => (int) ($result['processed'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'duration_seconds' => max(0, time() - $started_at),
        ]);
    } catch (Throwable $e) {
        pseudo_cron_log('error', 'Inline email ingest failed', ['error' => $e->getMessage()]);
        error_log('[pseudo-cron] inline email error: ' . $e->getMessage());
    } finally {
        save_setting('pseudo_cron_email_inline_lock', '0');
    }
}

function pseudo_cron_log(string $level, string $message, array $context = []): void
{
    try {
        $has_table = (bool) db_fetch_one("SHOW TABLES LIKE 'debug_log'");
        if (!$has_table) {
            return;
        }

        db_insert('debug_log', [
            'channel' => 'pseudo_cron',
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'user_id' => null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'web',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Logging must never break a page load.
    }
}
