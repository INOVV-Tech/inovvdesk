<?php

$root = dirname(__DIR__);
$handlers = file_get_contents($root . '/includes/components/ticket-form-handlers.php');
$sidebar = file_get_contents($root . '/includes/components/ticket-detail-sidebar.php');
$crud = file_get_contents($root . '/includes/ticket-crud-functions.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($handlers !== false && $sidebar !== false && $crud !== false, 'Ticket opening-date files must be readable.');
$assert(str_contains($handlers, "isset(\$_POST['update_ticket_created_date'])"), 'Opening-date update handler is missing.');
$assert(str_contains($handlers, "if (!is_admin())"), 'Opening-date updates must reject non-admin users on the server.');
$assert(str_contains($handlers, "DateTime::createFromFormat('!Y-m-d'"), 'Opening-date input must be strictly parsed.');
$assert(str_contains($handlers, "['created_at' => \$new_created_at]"), 'Opening-date handler must update tickets.created_at.');
$assert(str_contains($handlers, "log_ticket_history(\$ticket_id, \$user['id'], 'created_at'"), 'Opening-date changes must be recorded in ticket history.');
$assert(str_contains($sidebar, "<?php if (is_admin()): ?>"), 'Opening-date editor must be restricted to admins in the UI.');
$assert(str_contains($sidebar, 'name="ticket_created_date"'), 'Opening-date editor input is missing.');
$assert(str_contains($sidebar, 'name="update_ticket_created_date"'), 'Opening-date save action is missing.');
$assert(str_contains($crud, "'created_at' => t('Opening date')"), 'Opening-date history label is missing.');

echo "Ticket opening date admin contract OK\n";
