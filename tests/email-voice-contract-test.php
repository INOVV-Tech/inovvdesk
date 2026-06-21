<?php
define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/modules/email/email-renderer.php';

function assert_email_voice($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$normalized = foxdesk_email_normalize_subject(" Reply\non\t ticket  ");
assert_email_voice($normalized === 'Reply on ticket', 'Subject normalization must remove control whitespace.');

$specific_subject = foxdesk_ticket_email_subject('ticket.assigned', [
    'id' => 42,
    'title' => 'Fix checkout',
], [
    'ticket_code' => 'TK-42',
]);
assert_email_voice($specific_subject === 'Assigned to you TK-42: Fix checkout', 'Ticket event subject must be specific.');

$html = foxdesk_render_ticket_email_html([
    'app_name' => 'FoxDesk',
    'eyebrow' => 'Reply added',
    'title' => 'Reply added TK-42: Fix checkout',
    'preheader' => 'Sarah replied to TK-42.',
    'body' => "Hello Lukas,\n\n- First fix\n- Second fix\n\nOpen the ticket when you have a minute.",
    'cta_label' => 'Open ticket',
    'cta_url' => 'https://example.test/ticket/42',
    'reason' => 'You are receiving this because you are assigned to this ticket.',
]);

assert_email_voice(strpos($html, 'display:none') !== false, 'HTML email must include a preheader.');
assert_email_voice(strpos($html, '<ul') !== false && strpos($html, 'First fix') !== false, 'HTML email must render readable bullet lists.');
assert_email_voice(strpos($html, '>Open ticket</a>') !== false, 'CTA label must not include stray spaces.');
assert_email_voice(strpos($html, '> Open ticket </a>') === false, 'CTA label must not keep legacy padded text.');
assert_email_voice(strpos($html, 'FoxDesk keeps ticket emails short') !== false, 'HTML email must include a concise footer.');

$text = foxdesk_render_ticket_email_text([
    'app_name' => 'FoxDesk',
    'title' => 'Reply added TK-42: Fix checkout',
    'body' => 'Open the ticket when you have a minute.',
    'cta_label' => 'Open ticket',
    'cta_url' => 'https://example.test/ticket/42',
]);
assert_email_voice(strpos($text, 'Open ticket: https://example.test/ticket/42') !== false, 'Plain text email must include a clear next action.');

echo "Email voice contract OK\n";
