<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$files = [
    'pages/projects.php',
    'pages/project.php',
    'includes/components/project-board-surface.php',
    'includes/components/project-card-composer.php',
    'includes/modules/projects/project-boards.php',
    'includes/modules/projects/project-lists.php',
    'includes/modules/projects/project-cards.php',
    'includes/modules/projects/project-permissions.php',
    'includes/api/project-handler.php',
];

// --- UI contract: no hardcoded radius values in pages/components ---
foreach ([
    'pages/projects.php',
    'pages/project.php',
    'includes/components/project-board-surface.php',
    'includes/components/project-card-composer.php',
] as $path) {
    $contents = file_get_contents($root . '/' . $path);
    $assert($contents !== false, 'Project UI file must be readable: ' . $path);
    $assert(
        !preg_match('/rounded-(?:sm|md|lg|xl|2xl|3xl|[0-9]+)|border-radius:\s*[0-9]+px/', $contents),
        'Project UI must not hardcode radius values: ' . $path
    );
}

// --- UI contract: primitives used for controls ---
$surface = file_get_contents($root . '/includes/components/project-board-surface.php');
$composer = file_get_contents($root . '/includes/components/project-card-composer.php');
$assert(str_contains($surface, 'fd-button'), 'Board surface must use fd-button primitives.');
$assert(str_contains($surface, 'form-input'), 'Board surface must use form-input for composable inputs.');
$assert(str_contains($composer, 'form-input'), 'Card composer must use form-input.');
$assert(str_contains($composer, 'form-select'), 'Card composer must use form-select.');
$assert(str_contains($composer, 'modal-overlay'), 'Card composer must use the modal-overlay pattern.');
$assert(str_contains($composer, 'modal-panel'), 'Card composer must use the modal-panel pattern.');

// --- Kanban vocabulary reused for cards ---
$assert(str_contains($surface, 'kanban-card'), 'Board surface must reuse kanban card classes.');
$assert(str_contains($surface, 'kanban-column'), 'Board surface must reuse kanban column classes.');
$assert(str_contains($surface, 'kanban-cards'), 'Board surface must reuse kanban cards container classes.');
$assert(str_contains($surface, 'kanban-count'), 'Board surface must keep column counts.');
$assert(str_contains($surface, 'project_card_priority_badge_class('), 'Card priorities must go through the shared badge helper.');
$cards_module = file_get_contents($root . '/includes/modules/projects/project-cards.php');
$assert(str_contains($cards_module, "'badge-inline ticket-priority-inline'"), 'Priority badges must reuse ticket priority classes.');

// --- Every literal t() call is covered by pt (mirrors main language test) ---
$pt = require $root . '/includes/lang/pt.php';
$pattern = "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/";
$missing = [];
foreach ($files as $path) {
    $contents = file_get_contents($root . '/' . $path);
    preg_match_all($pattern, $contents, $matches);
    foreach ($matches[1] as $raw_key) {
        $key = stripcslashes($raw_key);
        if (str_contains($key, '$')) {
            continue;
        }
        if (!array_key_exists($key, $pt)) {
            $missing[$key] = $path;
        }
    }
}
$assert($missing === [], 'Project literal translations missing from pt catalog: ' . implode(', ', array_keys($missing)));

// --- Source copy stays English outside lang files ---
foreach ($files as $path) {
    $contents = file_get_contents($root . '/' . $path);
    $assert(
        !preg_match('/[ěščřžýáíéůúďťňĚŠČŘŽÝÁÍÉŮÚĎŤŇãõç]/u', $contents),
        'Project source copy must stay English: ' . $path
    );
}

// --- Modal markup must be a11y friendly ---
$assert(str_contains($composer, 'role="dialog"'), 'Project modals must declare role=dialog.');
$assert(str_contains($composer, 'aria-modal="true"'), 'Project modals must declare aria-modal.');
$assert(str_contains($composer, 'aria-labelledby='), 'Project modals must be labelled.');

// --- CSS ships only layout classes, no redefinition ---
$css = file_get_contents($root . '/theme.css');
$assert($css !== false, 'theme.css must be readable.');
$assert(str_contains($css, '.projects-grid'), 'theme.css must style the projects grid.');
$assert(str_contains($css, '.project-board-card-actions'), 'theme.css must style board card actions.');
$assert(str_contains($css, '.project-list-composer'), 'theme.css must style the list composer.');
$assert(str_contains($css, 'var(--fd-radius-control)'), 'Project CSS must build on fd tokens.');
$layout_marker = strpos($css, 'Projects module — MVP layout');
$assert($layout_marker !== false, 'Theme must contain the Projects MVP layout section.');
$project_css = substr($css, $layout_marker);
$assert(!str_contains($project_css, '.kanban-dot {'), 'Project CSS must not redefine kanban primitives.');

echo "Project UI contract OK\n";