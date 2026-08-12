<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/projects/project-schema.php';
require_once $root . '/includes/modules/projects/project-permissions.php';
require_once $root . '/includes/modules/projects/project-boards.php';

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

// --- Validation (pure, no DB) ---
$assert(project_board_validate_name('  Backlog  ') === 'Backlog', 'Board name must be trimmed.');
project_assert_throws(static fn () => project_board_validate_name('   '), 'Board name must be required.');
project_assert_throws(static fn () => project_board_validate_name(str_repeat('a', 256)), 'Board name must reject overlong names.');
$assert(project_board_normalize_color('#0a84ff') === '#0a84ff', 'Valid hex color must pass through.');
$assert(project_board_normalize_color('red') === '#0a84ff', 'Invalid color must fall back to the default.');
$assert(project_board_normalize_color('') === '#0a84ff', 'Empty color must fall back to the default.');
project_assert_throws(static fn () => project_board_create('X', null, '#0a84ff', 0), 'Board create must reject missing owner.');

// --- Write surface exists in module (API-facing contract) ---
$module = file_get_contents($root . '/includes/modules/projects/project-boards.php');
$handler = file_get_contents($root . '/includes/api/project-handler.php');
$router = file_get_contents($root . '/includes/api/router.php');
$assert($module !== false && $handler !== false && $router !== false, 'Project files must be readable.');

foreach ([
    'function project_board_create',
    'function project_board_update',
    'function project_board_set_archived',
    'function project_board_delete',
    'function project_boards_list',
    'function project_board_get_visible',
] as $fn) {
    $assert(str_contains($module, $fn), 'Project boards module must define ' . $fn . '().');
}

foreach ([
    "'project-board-save' => 'api_project_board_save'",
    "'project-board-archive' => 'api_project_board_archive'",
    "'project-board-delete' => 'api_project_board_delete'",
] as $route) {
    $assert(str_contains($router, $route), 'API router must register ' . $route . '.');
}

foreach ([
    'function api_project_board_save',
    'function api_project_board_archive',
    'function api_project_board_delete',
    'function api_project_require_staff_post',
] as $fn) {
    $assert(str_contains($handler, $fn), 'Project handler must define ' . $fn . '().');
}

// --- API handler enforces staff + CSRF + JSON reads ---
$assert(str_contains($handler, 'project_can_manage()'), 'Project handler must enforce staff permission.');
$assert(str_contains($handler, 'require_csrf_token(true)'), 'Project handler must enforce CSRF.');
$assert(str_contains($handler, 'get_json_input()'), 'Project handler must read JSON bodies.');
$assert(str_contains($handler, 'InvalidArgumentException'), 'Project handler must surface validation errors.');
$handler_require = file_get_contents($root . '/includes/api/router.php');
$assert(str_contains($handler_require, 'require_once __DIR__ . \'/project-handler.php\''), 'Router must include project handler.');

// --- Grid rendering delegates to shared components ---
$project_page = file_get_contents($root . '/pages/projects.php');
$surface = file_get_contents($root . '/includes/components/project-board-surface.php');
$assert($project_page !== false && $surface !== false, 'Project grid files must be readable.');
$assert(str_contains($project_page, 'project_boards_list(true)'), 'Projects page must list boards through the module.');
$assert(str_contains($project_page, 'project_render_board_grid('), 'Projects page must render the grid through the shared component.');
$assert(str_contains($surface, 'function project_render_board_grid'), 'Board surface must expose the grid renderer.');
$assert(str_contains($surface, 'function project_render_board_grid_card'), 'Board surface must expose the grid card renderer.');
$assert(str_contains($surface, 'data-project-action="board-open-create"'), 'Grid must expose the create board action.');
$assert(str_contains($surface, 'data-project-action="board-archive"'), 'Grid cards must expose archive/restore.');
$assert(str_contains($surface, 'data-project-action="board-delete"'), 'Grid cards must expose delete.');
$assert(str_contains($project_page, 'project_render_modal_templates('), 'Projects page must render modal templates.');
$composer = file_get_contents($root . '/includes/components/project-card-composer.php');
$assert(str_contains($composer, 'function project_render_modal_templates'), 'Composer component must define the modal templates renderer.');
$assert(!str_contains($project_page, 'db_fetch'), 'Projects page must not query the database directly.');

echo "Project boards contract OK\n";