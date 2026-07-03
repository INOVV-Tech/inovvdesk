<?php

$root = dirname(__DIR__);
$page = file_get_contents($root . '/pages/tickets.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($page !== false, 'Tickets page must be readable.');
$assert(
    str_contains($page, '$show_time = ticket_time_table_exists();'),
    'Ticket list must calculate worked-time totals for every visible ticket, not only for admins.'
);
$assert(
    str_contains($page, '$can_manage_ticket_time = is_admin() || is_agent();'),
    'Ticket list must keep time-management controls separate from public time totals.'
);
$assert(
    str_contains($page, 'if ($can_manage_ticket_time)'),
    'Running timer detail queries must remain limited to staff users.'
);
$assert(
    str_contains($page, '<?php elseif ($show_time): ?>'),
    'Customer ticket table must render a read-only worked-time column when time tracking exists.'
);
$assert(
    str_contains($page, '$colspan = is_admin() ? 8 : (is_agent() ? 6 : ($show_time ? 6 : 5));'),
    'Customer ticket table colspan must account for the read-only worked-time column.'
);
$assert(
    substr_count($page, 'class="inline-log-time__btn js-inline-log-time"') === 2,
    'Inline log-time buttons must stay only in the existing admin/agent table branches.'
);

echo "Ticket list time visibility contract OK\n";
