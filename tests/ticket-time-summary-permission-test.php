<?php

$root = dirname(__DIR__);

$GLOBALS['test_users'] = [
    7 => [
        'id' => 7,
        'role' => 'user',
        'organization_id' => null,
        'permissions' => json_encode([
            'ticket_scope' => 'own',
            'can_view_time' => false,
        ]),
    ],
    8 => [
        'id' => 8,
        'role' => 'agent',
        'organization_id' => null,
        'permissions' => json_encode([
            'ticket_scope' => 'assigned',
            'can_view_time' => false,
        ]),
    ],
    9 => [
        'id' => 9,
        'role' => 'user',
        'organization_id' => null,
        'permissions' => json_encode([
            'ticket_scope' => 'own',
            'can_view_time' => false,
        ]),
    ],
];

function current_user($force_refresh = false)
{
    return $GLOBALS['test_current_user'] ?? null;
}

function get_user($id)
{
    return $GLOBALS['test_users'][(int) $id] ?? null;
}

function user_has_ticket_access($ticket_id, $user_id)
{
    return false;
}

require_once $root . '/includes/user-functions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$ticket = ['id' => 42, 'user_id' => 7, 'assignee_id' => 8];

$customer = $GLOBALS['test_users'][7];
$other_customer = $GLOBALS['test_users'][9];
$agent_without_time_permission = $GLOBALS['test_users'][8];

$assert(!can_view_time($customer), 'Customer must not receive detailed time-entry permission by default.');
$assert(
    can_view_ticket_time_summary($ticket, $customer),
    'Customer must be allowed to see the aggregate worked-time total on their own ticket.'
);
$assert(
    !can_view_ticket_time_summary($ticket, $other_customer),
    'Customer must not see worked-time totals for tickets they cannot access.'
);
$assert(
    !can_view_ticket_time_summary($ticket, $agent_without_time_permission),
    'Agent with time permission disabled must not regain time visibility via the public summary helper.'
);

echo "Ticket time summary permission OK\n";
