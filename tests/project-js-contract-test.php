<?php

$root = dirname(__DIR__);
$js = @file_get_contents($root . '/assets/js/project-board.js');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert($js !== false, 'project-board.js must exist.');
$assert(trim($js) !== '', 'project-board.js must not be an empty stub.');

// --- API conventions mirror the ticket kanban ---
$assert(str_contains($js, 'X-CSRF-Token'), 'Board JS must send the CSRF token.');
$assert(str_contains($js, 'window.csrfToken'), 'Board JS must read window.csrfToken.');
$assert(str_contains($js, 'window.appConfig'), 'Board JS must consume appConfig.');
$assert(str_contains($js, 'projectApi('), 'Board JS must use the shared API helper.');
$assert(str_contains($js, 'JSON.stringify(payload'), 'Board JS must send JSON bodies.');

// --- Drag & drop vocabulary ---
foreach ([
    'dragstart', 'dragover', 'drop', 'dragend',
    'kanban-drop-placeholder', 'kanban-drag-ghost', 'just-dropped',
] as $needle) {
    $assert(str_contains($js, $needle), 'Board JS must implement ' . $needle . '.');
}
$assert(str_contains($js, 'effectAllowed'), 'Board JS must set the drag effect.');

// --- Optimistic move with revert on failure ---
$assert(str_contains($js, 'project-card-move'), 'Board JS must call project-card-move.');
$assert(str_contains($js, 'order: order'), 'Card move must persist the target list order.');
$assert(str_contains($js, 'savedSource'), 'Card move must keep a revert source.');

// --- All write actions present ---
foreach ([
    'project-board-save', 'project-board-archive', 'project-board-delete',
    'project-list-save', 'project-list-delete', 'project-list-reorder',
    'project-card-save', 'project-card-move', 'project-card-delete',
] as $action) {
    $assert(str_contains($js, "'" . $action . "'"), 'Board JS must call the ' . $action . ' endpoint.');
}

// --- Mobile fallback ---
$assert(str_contains($js, '.project-mobile-move'), 'Board JS must handle the mobile move select.');
$assert(str_contains($js, 'populateMoveSelects'), 'Board JS must populate mobile move selects.');
$assert(str_contains($js, "addEventListener('change'"), 'Board JS must listen to mobile select changes.');

// --- Companion files referenced consistently ---
$board_page = file_get_contents($root . '/pages/project.php');
$grid_page = file_get_contents($root . '/pages/projects.php');
$assert(str_contains($board_page, 'assets/js/project-board.js'), 'Board page must load project-board.js.');
$assert(str_contains($grid_page, 'assets/js/project-board.js'), 'Grid page must load project-board.js.');

// --- JS labels bridged through appConfig ---
$footer = file_get_contents($root . '/includes/footer.php');
foreach ([
    'newProjectLabel', 'renameProjectLabel', 'newCardLabel', 'editCardLabel',
    'listNameRequiredLabel', 'deleteProjectConfirm', 'deleteListConfirm', 'deleteCardConfirm',
] as $label) {
    $assert(str_contains($footer, $label), 'Footer appConfig must bridge ' . $label . '.');
}

echo "Project JS contract OK\n";