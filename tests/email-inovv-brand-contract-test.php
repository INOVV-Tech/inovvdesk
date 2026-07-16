<?php
define('BASE_PATH', dirname(__DIR__));

function get_setting($key, $default = null)
{
    return $key === 'email_language' ? 'pt' : $default;
}

require_once BASE_PATH . '/includes/mailer.php';

function assert_inovv_email($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

assert_inovv_email(
    mailer_brand_name(['smtp_from_name' => 'Inovv Helpdesk', 'app_name' => 'FoxDesk']) === 'Inovv Helpdesk',
    'SMTP sender name must define the public email brand.'
);
assert_inovv_email(
    mailer_brand_name(['smtp_from_name' => '', 'app_name' => 'FoxDesk']) === 'Inovv Helpdesk',
    'Legacy FoxDesk fallback must not leak into outgoing email.'
);
assert_inovv_email(mailer_notification_language('de') === 'pt', 'Portuguese must be the configured outgoing email language.');

$template = get_builtin_email_template('ticket_confirmation', 'pt');
assert_inovv_email(($template['language'] ?? '') === 'pt', 'Portuguese ticket confirmation template is missing.');
assert_inovv_email(str_contains((string) ($template['subject'] ?? ''), 'Ticket recebido'), 'Portuguese subject is missing.');
assert_inovv_email(str_contains((string) ($template['body'] ?? ''), 'Atenciosamente'), 'Portuguese body is missing.');
assert_inovv_email(!str_contains((string) ($template['body'] ?? ''), 'FoxDesk'), 'Portuguese template exposes legacy branding.');

echo "Inovv email branding contract OK\n";
