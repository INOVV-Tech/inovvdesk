<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/tickets/ticket-status-groups.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }
    return $contents;
};

$assert(
    ticket_status_group_from_status(['name' => 'Aguardando fornecedor', 'is_closed' => 0]) === 'waiting',
    'Existing Portuguese waiting statuses must keep working through name fallback.'
);
$assert(
    ticket_status_group_from_status(['name' => 'Retorno externo', 'status_group' => 'waiting', 'is_closed' => 0]) === 'waiting',
    'Explicit waiting group must classify any custom status as waiting.'
);
$assert(
    ticket_status_group_from_status(['name' => 'Finalizado', 'status_group' => 'waiting', 'is_closed' => 1]) === 'done',
    'Closed status must take precedence over waiting.'
);
$assert(ticket_status_group_from_form(['is_waiting' => '1']) === 'waiting', 'Waiting checkbox must persist the waiting group.');
$assert(ticket_status_group_from_form(['is_closed' => '1', 'is_waiting' => '1']) === 'done', 'Closed checkbox must win if both values are posted.');
$assert(ticket_status_group_from_form([]) === 'active', 'Unmarked custom status must persist as active.');

$group_helper = $read('includes/modules/tickets/ticket-status-groups.php');
$settings_statuses = $read('pages/admin/statuses-content.php');
$legacy_statuses = $read('pages/admin/statuses.php');
$schema = $read('includes/schema.sql');
$installer = $read('install.php');
$upgrade = $read('upgrade.php');
$ticket_views = $read('includes/modules/tickets/ticket-list-views.php');
$work_queues = $read('includes/modules/work/work-queues.php');
$portuguese = $read('includes/lang/pt.php');

$assert(str_contains($group_helper, 'function ensure_ticket_status_group_column'), 'Runtime status-group migration helper is missing.');
$assert(str_contains($schema, 'status_group VARCHAR(20)'), 'Fresh installs must create the status_group column.');
$assert(str_contains($installer, 'is_closed, status_group'), 'Installer must seed explicit status groups.');
$assert(str_contains($upgrade, "SHOW COLUMNS FROM statuses LIKE 'status_group'"), 'Upgrade must add status_group to existing installations.');

foreach ([$settings_statuses, $legacy_statuses] as $status_page) {
    $assert(str_contains($status_page, 'ensure_ticket_status_group_column'), 'Status management must ensure status-group storage exists.');
    $assert(str_contains($status_page, 'name="is_waiting"'), 'Status management must expose the waiting checkbox.');
    $assert(str_contains($status_page, 'ticket_status_group_from_form($_POST)'), 'Status management must persist the selected group.');
    $assert(str_contains($status_page, "t('Mark as waiting status')"), 'Status management must label the waiting control.');
    $assert(str_contains($status_page, "t('Waiting')"), 'Waiting statuses must display a badge.');
}

$assert(str_contains($ticket_views, "'filters' => ['status_group' => 'waiting']"), 'Waiting ticket view must filter by the persisted group.');
$assert(str_contains($work_queues, "work_status_ids_for_group"), 'Ticket filtering must resolve status IDs from workflow groups.');
$assert(str_contains($portuguese, "'Mark as waiting status' => 'Marcar como Aguardando'"), 'Portuguese waiting control translation is missing.');
$assert(str_contains($portuguese, "'Waiting' => 'Aguardando'"), 'Portuguese waiting badge translation is missing.');

echo "Status waiting group contract OK\n";
