<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/projects/project-schema.php';
require_once $root . '/includes/modules/projects/project-permissions.php';
require_once $root . '/includes/modules/projects/project-boards.php';
require_once $root . '/includes/modules/projects/project-lists.php';
require_once $root . '/includes/modules/projects/project-cards.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

function project_assert_throws(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

// --- Pure validation ---
$assert(project_card_validate_title('  Deploy API  ') === 'Deploy API', 'Card title must be trimmed.');
project_assert_throws(static fn () => project_card_validate_title(''), 'Card title must be required.');
project_assert_throws(static fn () => project_card_validate_title(str_repeat('b', 256)), 'Card title must reject overlong names.');

$assert(project_card_normalize_due_date('') === null, 'Empty due date must normalize to null.');
$assert(project_card_normalize_due_date('2026-08-20') === '2026-08-20 00:00:00', 'Date-only due dates must normalize to midnight.');
$assert(project_card_normalize_due_date('2026-08-20T14:30') === '2026-08-20 14:30:00', 'Datetime-local due dates must normalize.');
$assert(project_card_normalize_due_date('2026-08-20 18:45:00') === '2026-08-20 18:45:00', 'Datetime due dates must pass through.');
project_assert_throws(static fn () => project_card_normalize_due_date('not-a-date'), 'Invalid due dates must throw.');

// --- Card view model grouping (pure) ---
$lists = [['id' => 1, 'name' => 'Backlog'], ['id' => 2, 'name' => 'Done']];
$cards = [
    ['id' => 10, 'list_id' => 1, 'title' => 'A', 'priority' => 'high', 'due_date' => '2026-08-20 10:00:00'],
    ['id' => 11, 'list_id' => 1, 'title' => 'B', 'priority' => 'weird', 'due_date' => ''],
    ['id' => 12, 'list_id' => 5, 'title' => 'Orphan', 'priority' => 'low'],
];
$by_list = project_board_cards_model($lists, $cards);
$assert(count($by_list[1]) === 2, 'Cards must group by list id.');
$assert(($by_list[1][1]['priority'] ?? '') === 'medium', 'Unknown priority must normalize to medium in view model.');
$assert(isset($by_list[2]) && count($by_list[2]) === 0, 'Every list must get an empty bucket.');
$assert(!isset($by_list[5]), 'Cards from missing lists must be dropped.');

// --- Write surface exists (API-facing contract) ---
$module = file_get_contents($root . '/includes/modules/projects/project-cards.php');
$lists_module = file_get_contents($root . '/includes/modules/projects/project-lists.php');
$handler = file_get_contents($root . '/includes/api/project-handler.php');
$router = file_get_contents($root . '/includes/api/router.php');
$assert($module !== false && $lists_module !== false && $handler !== false && $router !== false, 'Project files must be readable.');

foreach ([
    'function project_card_create',
    'function project_card_update',
    'function project_card_move',
    'function project_card_delete',
    'function project_assignee_options',
    'function project_card_belongs_to_board',
] as $fn) {
    $assert(str_contains($module, $fn), 'Cards module must define ' . $fn . '().');
}

foreach ([
    'function project_list_create',
    'function project_list_update',
    'function project_list_delete',
    'function project_lists_reorder',
    'function project_list_belongs_to_board',
] as $fn) {
    $assert(str_contains($lists_module, $fn), 'Lists module must define ' . $fn . '().');
}

foreach ([
    "'project-list-save' => 'api_project_list_save'",
    "'project-list-delete' => 'api_project_list_delete'",
    "'project-list-reorder' => 'api_project_list_reorder'",
    "'project-card-save' => 'api_project_card_save'",
    "'project-card-move' => 'api_project_card_move'",
    "'project-card-delete' => 'api_project_card_delete'",
] as $route) {
    $assert(str_contains($router, $route), 'API router must register ' . $route . '.');
}

foreach ([
    'function api_project_list_save',
    'function api_project_list_reorder',
    'function api_project_card_save',
    'function api_project_card_move',
    'function api_project_card_delete',
] as $fn) {
    $assert(str_contains($handler, $fn), 'Project handler must define ' . $fn . '().');
}

// --- Move semantics: target list must belong to the same board ---
$assert(str_contains($module, 'project_list_belongs_to_board($to_list_id, $board_id)'), 'Card move must validate the target list belongs to the board.');
$assert(str_contains($module, "'sort_order' => \$position"), 'Card move must renumber the target list.');

// --- Board detail renders through components ---
$board_page = file_get_contents($root . '/pages/project.php');
$surface = file_get_contents($root . '/includes/components/project-board-surface.php');
$composer = file_get_contents($root . '/includes/components/project-card-composer.php');
$assert($board_page !== false && $surface !== false && $composer !== false, 'Board page files must be readable.');
$assert(str_contains($board_page, 'project_render_board('), 'Board page must render through the shared component.');
$assert(str_contains($board_page, 'project_render_modal_templates('), 'Board page must render modal templates.');
$assert(str_contains($surface, 'function project_render_board'), 'Board surface must expose the board renderer.');
$assert(str_contains($surface, 'function project_render_project_column'), 'Board surface must expose the column renderer.');
$assert(str_contains($surface, 'function project_render_project_card'), 'Board surface must expose the card renderer.');
$assert(str_contains($surface, 'data-project-action="card-open-create"'), 'Columns must expose add-card.');
$assert(str_contains($surface, 'data-project-action="list-create"'), 'Board must expose list creation.');
$assert(str_contains($surface, 'data-project-action="list-delete"'), 'Columns must expose delete list.');
$assert(str_contains($surface, 'project-mobile-move'), 'Cards must ship the mobile move fallback.');
$assert(str_contains($composer, 'project-card-assignee-input'), 'Card modal must include the assignee field.');
$assert(str_contains($composer, 'project-card-priority-input'), 'Card modal must include the priority field.');
$assert(str_contains($composer, 'project-card-due-input'), 'Card modal must include the due date field.');
$assert(str_contains($composer, 'project-card-description-input'), 'Card modal must include the description field.');
$assert(str_contains($composer, 'data-project-action="card-delete"'), 'Card modal must expose delete.');
$assert(!str_contains($board_page, 'db_fetch'), 'Board page must not query the database directly.');

// --- Assignee options restrict to staff users ---
$assignee_fn = $module;
$assert(str_contains($assignee_fn, "'admin', 'agent'"), 'Assignee options must only include staff roles.');

echo "Project cards contract OK\n";