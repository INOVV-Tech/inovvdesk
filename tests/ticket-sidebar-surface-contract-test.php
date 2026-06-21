<?php

$root = dirname(__DIR__);
$component = file_get_contents($root . '/includes/components/ticket-detail-surface.php');
$page = file_get_contents($root . '/includes/components/ticket-detail-sidebar.php');
$theme = file_get_contents($root . '/theme.css');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($component !== false && $page !== false && $theme !== false, 'Ticket sidebar surface files must be readable.');

foreach ([
    'function ticket_detail_priority_key',
    'function ticket_detail_priority_pill_class',
    'function ticket_detail_render_priority_pill',
] as $needle) {
    $assert(str_contains($component, $needle), 'Ticket detail component missing sidebar helper: ' . $needle);
}

foreach ([
    'data-ticket-sidebar-surface',
    'ticket-client-pill',
    'ticket-side-list',
    'ticket-side-row',
    'ticket-side-label',
    'ticket-side-value',
    'ticket-side-select',
    'ticket-side-edit-button',
    'ticket-date-value',
    'ticket-side-action-button',
    'ticket-attachment-link',
    'ticket-share-input',
] as $needle) {
    $assert(str_contains($page, $needle), 'Ticket detail sidebar missing surface contract: ' . $needle);
}

foreach ([
    '.ticket-sidebar > .card',
    '.ticket-side-card',
    '.ticket-side-list',
    '.ticket-side-row',
    '.ticket-side-label',
    '.ticket-side-value',
    '.ticket-client-pill',
    '.ticket-side-select',
    '.ticket-side-action-button',
    '.ticket-priority-pill',
    '.ticket-date-value',
    '.ticket-attachment-link',
    '.ticket-share-input',
] as $needle) {
    $assert(str_contains($theme, $needle), 'theme.css missing sidebar selector: ' . $needle);
}

$assert(!str_contains($page, 'style='), 'Ticket sidebar surface must not use inline style attributes.');
$assert(!str_contains($page, 'onmouseover='), 'Ticket sidebar surface must not use inline mouseover styling.');
$assert(!str_contains($page, 'onmouseout='), 'Ticket sidebar surface must not use inline mouseout styling.');
$assert(!str_contains($page, 'text-xs py-0.5 px-1 rounded border-0 cursor-pointer'), 'Quick property selects must use ticket-side-select.');
$assert(!str_contains($theme, '.ticket-sidebar > .card:first-child'), 'Ticket sidebar cards must not use merged-card first-child styling.');
$assert(!str_contains($theme, '.ticket-sidebar > .card + .card'), 'Ticket sidebar cards must keep normal card spacing.');

echo "Ticket sidebar surface contract OK\n";
