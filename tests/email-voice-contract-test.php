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
assert_email_voice($specific_subject === 'Ticket atribuído a você TK-42: Fix checkout', 'Ticket event subject must be specific.');

$html = foxdesk_render_ticket_email_html([
    'app_name' => 'Inovv Helpdesk',
    'eyebrow' => 'Nova resposta',
    'title' => 'Nova resposta TK-42: Corrigir checkout',
    'preheader' => 'Sarah respondeu ao TK-42.',
    'body' => "Olá, Lukas.\n\n- Primeira correção\n- Segunda correção\n\nAbra o ticket quando puder.",
    'cta_label' => 'Abrir ticket',
    'cta_url' => 'https://example.test/ticket/42',
    'reason' => 'Você recebeu este e-mail porque este ticket está atribuído a você.',
]);

assert_email_voice(strpos($html, 'display:none') !== false, 'HTML email must include a preheader.');
assert_email_voice(strpos($html, '<ul') !== false && strpos($html, 'Primeira correção') !== false, 'HTML email must render readable bullet lists.');
assert_email_voice(strpos($html, '>Abrir ticket</a>') !== false, 'CTA label must not include stray spaces.');
assert_email_voice(strpos($html, '> Abrir ticket </a>') === false, 'CTA label must not keep legacy padded text.');
assert_email_voice(strpos($html, 'A Inovv Helpdesk mantém as notificações objetivas') !== false, 'HTML email must include the Inovv footer.');
assert_email_voice(strpos($html, 'FoxDesk') === false, 'Customer-facing HTML must not expose the legacy FoxDesk brand.');

$text = foxdesk_render_ticket_email_text([
    'app_name' => 'Inovv Helpdesk',
    'title' => 'Nova resposta TK-42: Corrigir checkout',
    'body' => 'Abra o ticket quando puder.',
    'cta_label' => 'Abrir ticket',
    'cta_url' => 'https://example.test/ticket/42',
]);
assert_email_voice(strpos($text, 'Abrir ticket: https://example.test/ticket/42') !== false, 'Plain text email must include a clear next action.');

echo "Email voice contract OK\n";
