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

// --- Pure: filter state normalization from a request ---

$state = project_board_filter_state_from_request([]);
$assert($state === ['assignee' => 'all', 'priority' => 'all', 'due' => 'all', 'search' => ''], 'Empty request must yield an all/empty filter state.');
$assert(project_board_filter_has($state) === false, 'A pristine state must not be "active".');

$state = project_board_filter_state_from_request([
    'assignee' => '42',
    'priority' => 'urgent',
    'due' => 'overdue',
    'search' => '  Deploy API  ',
]);
$assert($state['assignee'] === 42, 'Valid assignee id must normalize to an int.');
$assert($state['priority'] === 'urgent', 'Valid priority key must pass through.');
$assert($state['due'] === 'overdue', 'Valid due option must pass through.');
$assert($state['search'] === 'Deploy API', 'Search must be trimmed.');
$assert(project_board_filter_has($state) === true, 'An active filter state must be detected.');

$state = project_board_filter_state_from_request([
    'assignee' => 'unassigned',
    'priority' => 'bogus',
    'due' => 'bogus',
    'search' => '   ',
]);
$assert($state['assignee'] === 'unassigned', 'The unassigned sentinel must pass through.');
$assert($state['priority'] === 'all', 'Unknown priority must reset to all.');
$assert($state['due'] === 'all', 'Unknown due option must reset to all.');
$assert($state['search'] === '' && project_board_filter_has($state), 'Whitespace-only search must clear but unassigned keeps the state active.');

$state = project_board_filter_state_from_request(['assignee' => 'abc', 'due' => 'today', 'search' => 'q']);
$assert($state['assignee'] === 'all', 'Non-numeric assignee must reset to all.');
$assert($state['due'] === 'today', 'Today due option must pass through.');
$assert(project_board_filter_has($state) === true, 'Search alone must be an active state.');

foreach (['overdue', 'today', 'upcoming', 'none'] as $due_key) {
    $state = project_board_filter_state_from_request(['due' => $due_key]);
    $assert($state['due'] === $due_key, 'Due option must accept: ' . $due_key . '.');
}

// --- Pure: LIKE escaping (wildcards and the escape character itself) ---
$assert(project_board_filter_escape_like('100% done') === '100\% done', 'LIKE escaping must neutralize percent wildcards.');
$assert(project_board_filter_escape_like('a_b') === 'a\_b', 'LIKE escaping must neutralize underscores.');
$assert(project_board_filter_escape_like('c:\\d') === 'c:\\\\d', 'LIKE escaping must neutralize backslashes.');

// --- Read model: filters must reach the board query with parametrized SQL ---
$module = file_get_contents($root . '/includes/modules/projects/project-cards.php');
$assert($module !== false, 'Cards module must be readable.');

foreach ([
    'function project_board_filter_state_from_request',
    'function project_board_filter_has',
    'function project_board_filter_escape_like',
    'function project_board_filter_due_options',
] as $fn) {
    $assert(str_contains($module, $fn), 'Cards module must define ' . $fn . '().');
}

$assert(str_contains($module, 'project_cards_for_board($board_id, array $filters = [])'), 'Board card query must accept a filter state.');
$assert(str_contains($module, "assignee_id IS NULL"), 'Unassigned filter must build an IS NULL clause.');
$assert(str_contains($module, "'assignee_id = ?'"), 'Assignee filter must use a parametrized equality clause.');
$assert(str_contains($module, "'priority = ?'"), 'Priority filter must use a parametrized equality clause.');
$assert(str_contains($module, 'due_date < NOW()'), 'Overdue filter must compare against NOW().');
$assert(str_contains($module, 'CURDATE()'), 'Today filter must compare against the calendar day.');
$assert(str_contains($module, 'due_date IS NULL'), 'No-due-date filter must build an IS NULL clause.');
$assert(str_contains($module, "LIKE ?"), 'Search filter must use a parametrized LIKE clause.');

// --- Route: board page must parse GET into state and pass it to the read model ---
$board_page = file_get_contents($root . '/pages/project.php');
$assert($board_page !== false, 'Board page must be readable.');
$assert(str_contains($board_page, 'project_board_filter_state_from_request'), 'Board page must normalize filter state from the request.');
$assert(str_contains($board_page, 'project_cards_for_board($project_board_id,'), 'Board page must pass filters into the card read model.');
$assert(!str_contains($board_page, 'db_fetch'), 'Board page must not query the database directly.');

// --- Surface: server-rendered GET form with project-* vocabulary ---
$surface = file_get_contents($root . '/includes/components/project-board-surface.php');
$assert($surface !== false, 'Board surface must be readable.');
$assert(str_contains($surface, 'function project_render_board_filters'), 'Board surface must expose the filter bar renderer.');
$assert(str_contains($surface, 'method="get"'), 'Board filters must submit with GET.');
$assert(str_contains($surface, 'name="page"'), 'Board filter form must carry the page token.');
$assert(str_contains($surface, 'name="board_id"'), 'Board filter form must carry the board id.');
$assert(str_contains($surface, 'name="assignee"'), 'Board filter form must expose the assignee control.');
$assert(str_contains($surface, 'name="priority"'), 'Board filter form must expose the priority control.');
$assert(str_contains($surface, 'name="due"'), 'Board filter form must expose the due date control.');
$assert(str_contains($surface, 'name="search"'), 'Board filter form must expose the search input.');
$assert(str_contains($surface, 'project-board-filters'), 'Board filters must use module-owned project-* classes.');
$assert(str_contains($surface, 'project-filter-'), 'Board filter controls must use module-owned project-* classes.');
$assert(!preg_match('/\bkanban-/', $surface), 'Board filters must not reuse ticket kanban classes.');
$assert(!str_contains($surface, 'ticket-priority'), 'Board filters must not reuse ticket priority classes.');
$assert(str_contains($surface, 'project_priority_label('), 'Board filters must render priorities through the module helper.');

// --- UI copy: every literal t() is covered by pt ---
$pt = require $root . '/includes/lang/pt.php';
$pattern = "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/";
preg_match_all($pattern, $surface, $matches);
$missing = [];
foreach ($matches[1] as $raw_key) {
    $key = stripcslashes($raw_key);
    if (str_contains($key, '$')) {
        continue;
    }
    if (!array_key_exists($key, $pt)) {
        $missing[$key] = true;
    }
}
$assert($missing === [], 'Board filter literals missing from pt catalog: ' . implode(', ', array_keys($missing)));

// --- CSS ships layout for the filter bar ---
$css = file_get_contents($root . '/theme.css');
$assert($css !== false, 'theme.css must be readable.');
$assert(str_contains($css, '.project-board-filters'), 'theme.css must style the board filter bar.');
$assert(str_contains($css, 'var(--fd-radius-control)'), 'Board filter CSS must build on fd tokens.');

echo "Project board filters contract OK\n";