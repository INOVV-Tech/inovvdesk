<?php

$root = dirname(__DIR__);

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

$sidebar = $read('includes/components/ticket-detail-sidebar.php');
$handlers = $read('includes/components/ticket-form-handlers.php');
$crud = $read('includes/ticket-crud-functions.php');
$bulk = $read('includes/modules/tickets/ticket-bulk-actions.php');
$api = $read('includes/api/ticket-handler.php');
$ticket_list = $read('pages/tickets.php');
$portuguese = $read('includes/lang/pt.php');

$assert(str_contains($sidebar, 'name="delete_ticket_permanently"'), 'Archived ticket detail must render the permanent delete button.');
$assert(str_contains($sidebar, '<?php if (is_admin()): ?>'), 'Permanent delete button must be rendered for administrators only.');
$assert(str_contains($sidebar, "t('Delete permanently')"), 'Permanent delete button must have a clear label.');
$assert(str_contains($sidebar, 'comments, attachments, and recorded hours'), 'Permanent delete confirmation must name all removed data.');

$assert(str_contains($handlers, "isset(\$_POST['delete_ticket_permanently'])"), 'Ticket detail handler must receive permanent deletion.');
$assert(str_contains($handlers, "!is_admin() || empty(\$ticket['is_archived'])"), 'Server must restrict permanent deletion to administrators and archived tickets.');
$assert(str_contains($handlers, 'delete_ticket_permanently($ticket_id)'), 'Ticket detail handler must call the complete deletion helper.');
$assert(str_contains($handlers, "'ticket_deleted_permanently'"), 'Permanent ticket deletion must be security-audited.');

$assert(str_contains($crud, 'function delete_ticket_permanently'), 'Complete ticket deletion helper is missing.');
$assert(str_contains($crud, "db_delete('ticket_time_entries', 'ticket_id = ?', [\$id])"), 'Ticket deletion must remove recorded hours.');
$assert(str_contains($crud, 'get_ticket_attachments($id)'), 'Complete deletion must collect attachment files.');
$assert(str_contains($crud, '@unlink($path)'), 'Complete deletion must remove attachment files from disk.');
$assert(str_contains($bulk, 'delete_ticket_permanently($ticket_id)'), 'Archived bulk deletion must use the complete deletion helper.');
$assert(str_contains($bulk, "if (!is_admin())"), 'Archived bulk deletion must reject non-administrators on the server.');
$assert(str_contains($ticket_list, '$bulk_delete_mode = $bulk_actions_enabled && $is_archive && is_admin();'), 'Archived bulk delete controls must be limited to administrators.');
$assert(str_contains($api, 'delete_ticket_permanently($ticket_id)'), 'Ticket cancellation must use the complete deletion helper.');

foreach ([
    'Excluir permanentemente',
    'Todos os comentários, anexos e horas registradas serão removidos.',
    'Ticket excluído permanentemente.',
] as $translation) {
    $assert(str_contains($portuguese, $translation), 'Portuguese permanent deletion copy is missing: ' . $translation);
}

echo "Ticket permanent deletion contract OK\n";
