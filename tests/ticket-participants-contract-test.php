<?php

$root = dirname(__DIR__);
require_once $root . '/includes/ticket-participant-functions.php';

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

$display = ticket_participant_display_users([
    ['id' => 4, 'first_name' => 'Responsible'],
    ['id' => 7, 'first_name' => 'Collaborator'],
], 4);
$assert(array_column($display, 'id') === [7], 'The assignee must not be duplicated in Participants.');

$participants = $read('includes/ticket-participant-functions.php');
$schema = $read('includes/schema.sql');
$upgrade = $read('upgrade.php');
$facade = $read('includes/ticket-functions.php');
$context = $read('includes/modules/tickets/ticket-detail-context.php');
$sidebar = $read('includes/components/ticket-detail-sidebar.php');
$composer = $read('includes/components/ticket-detail-composer.php');
$handlers = $read('includes/components/ticket-form-handlers.php');
$notifications = $read('includes/notification-functions.php');
$mailer = $read('includes/mailer.php');
$portuguese = $read('includes/lang/pt.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS ticket_participants'), 'Fresh installs must create ticket_participants.');
$assert(str_contains($upgrade, "SHOW TABLES LIKE 'ticket_participants'"), 'Existing installs must create ticket_participants during upgrade.');
$assert(str_contains($facade, "ticket-participant-functions.php"), 'Ticket participants must be loaded by the ticket facade.');
$assert(str_contains($participants, 'ensure_ticket_participants_table'), 'Runtime participant storage migration is missing.');
$assert(str_contains($participants, 'FROM ticket_time_entries tte'), 'Staff with time entries must be recognized as participants.');
$assert(str_contains($participants, 'SUM(duration_minutes) AS total_minutes'), 'Participant time totals must come from actual time entries.');

$assert(str_contains($context, "'participant_users'"), 'Ticket detail context must expose participants.');
$assert(str_contains($context, "'participant_time_minutes'"), 'Ticket detail context must expose participant hours.');
$assert(str_contains($sidebar, 'data-ticket-participants'), 'Ticket detail must render the Participants field.');
$assert(str_contains($sidebar, 'name="add_participant"'), 'Ticket detail must allow adding participants.');
$assert(str_contains($sidebar, 'participant_time_minutes'), 'Participant chips must show their individual time.');

$assert(str_contains($composer, 'name="manual_time_user_id"'), 'Manual time must allow selecting who receives the hours.');
$assert(str_contains($handlers, "'user_id' => \$manual_time_user_id"), 'Manual time must be stored against the selected participant.');
$assert(str_contains($handlers, 'allowed_time_user_ids'), 'Manual time attribution must be restricted to the responsible agent and participants.');
$assert(str_contains($handlers, 'add_ticket_participant'), 'Ticket detail must persist added participants.');
$assert(str_contains($handlers, 'remove_ticket_participant'), 'Ticket detail must remove explicit participants.');

$assert(str_contains($notifications, 'get_ticket_participant_user_ids($ticket_id)'), 'In-app updates must include ticket participants.');
$assert(str_contains($mailer, "['type' => 'participant']"), 'Email updates must include ticket participants.');
$assert(str_contains($portuguese, "'Participants' => 'Participantes'"), 'Portuguese Participants translation is missing.');
$assert(str_contains($portuguese, "'Credit hours to' => 'Creditar horas para'"), 'Portuguese time-credit translation is missing.');

echo "Ticket participants contract OK\n";
