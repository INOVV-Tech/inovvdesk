<?php

$root = dirname(__DIR__);
require_once $root . '/includes/modules/notifications/notification-policy.php';
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

// --- Pure: project card notification policy (no ticket coupling) ---
$assert(project_card_event_normalize('project.card.assigned') === 'project.card.assigned', 'Normalized card events must pass through.');
$assert(project_card_event_normalize('PROJECT.CARD.OVERDUE') === 'project.card.overdue', 'Card event normalization must lowercase input.');
$assert(project_card_event_normalize('') === '', 'Empty card events must stay empty.');

$assert(should_send_project_card_email('project.card.assigned'), 'Assignment must trigger a card email.');
$assert(should_send_project_card_email('project.card.assigned', [], [], ['assignment_is_self' => true]) === false, 'Self-assignment must be suppressed.');
$assigned_card = ['assignee_id' => 7];
$assert(should_send_project_card_email('project.card.assigned', $assigned_card, ['id' => 7]) === false, 'Assigning a card to yourself must be suppressed.');
$assert(should_send_project_card_email('project.card.due_soon', $assigned_card), 'Due-soon must trigger a card email.');
$assert(should_send_project_card_email('project.card.overdue', $assigned_card), 'Overdue must trigger a card email.');
$assert(should_send_project_card_email('project.card.due_soon', $assigned_card, [], ['suppress_due' => true]) === false, 'Context may suppress due alerts.');
$assert(should_send_project_card_email('project.card.created') === false, 'Card creation must not email by default.');
$assert(should_send_project_card_email('project.card.updated') === false, 'Card updates must not email by default.');
$assert(should_send_project_card_email('ticket.created') === false, 'Ticket events must not be card emails.');
$assert(should_send_project_card_email('') === false, 'Unknown card events must not email.');
$assert(project_card_email_suppression_reason('project.card.updated') === 'card_update_not_actionable', 'Card updates must explain suppression.');

// --- Pure: work summary input parsing is table-existence guarded (no DB here) ---
$assert(function_exists('project_card_work_summary'), 'Cards module must define the Work summary read model.');
$assert(function_exists('project_card_due_alert_candidates'), 'Cards module must define due alert candidates.');

// --- Global search: staff-only project sections wired end to end ---
$search = file_get_contents($root . '/includes/modules/search/global-search.php');
$assert($search !== false, 'Global search module must be readable.');
$assert(str_contains($search, "'projects' => ['label' => 'Projects'"), 'Global search must expose a projects section.');
$assert(str_contains($search, "'cards' => ['label' => 'Cards'"), 'Global search must expose a cards section.');
$assert(str_contains($search, 'function global_search_projects'), 'Global search must define the projects search model.');
$assert(str_contains($search, 'function global_search_cards'), 'Global search must define the cards search model.');
$assert(str_contains($search, "global_search_projects(\$query, \$user, \$limit_per_section)"), 'Global search must wire projects into the result builder.');
$assert(str_contains($search, "global_search_cards(\$query, \$user, \$limit_per_section)"), 'Global search must wire cards into the result builder.');
$assert(str_contains($search, "project_boards"), 'Projects search must query the project module table.');
$assert(str_contains($search, "project_cards"), 'Cards search must query the project module table.');
$assert(str_contains($search, "'is_archived' => 0'") || str_contains($search, 'is_archived = 0'), 'Projects search must exclude archived boards.');
$assert(str_contains($search, "url('project', ['board_id'"), 'Project search items must link to the board page.');

// Staff-only: client users must not see project sections (model + app shell)
$assert(str_contains($search, "\$user['role']"), 'Project search models must guard on the user role.');
$shell = file_get_contents($root . '/includes/modules/app/app-shell.php');
$assert($shell !== false, 'App shell must be readable.');
$assert(str_contains($shell, "unset(\$sections['clients'], \$sections['contacts'], \$sections['reports'], \$sections['projects'], \$sections['cards'])"), 'App shell must strip project sections for client users.');

// Palette iterates the new sections
$palette = file_get_contents($root . '/assets/js/shortcuts.js');
$assert($palette !== false, 'Command palette JS must be readable.');
$assert(str_contains($palette, "'projects', 'cards'") || (str_contains($palette, "'projects'") && str_contains($palette, "'cards'")), 'Command palette must iterate project search sections.');

// --- Work page: "My cards" section (staff only, module-owned classes) ---
$work = file_get_contents($root . '/pages/work.php');
$assert($work !== false, 'Work page must be readable.');
$assert(str_contains($work, 'project_card_work_summary'), 'Work page must use the project card summary model.');
$assert(str_contains($work, 'data-work-my-cards'), 'Work page must expose the My cards hook.');
$assert(str_contains($work, '$is_staff'), 'Work My cards must be staff-scoped.');
$assert(!str_contains($work, 'db_fetch'), 'Work page must not query the database directly.');

// --- App feed: projects key for native clients ---
$feed = file_get_contents($root . '/includes/modules/app/app-feed.php');
$assert($feed !== false, 'App feed must be readable.');
$assert(str_contains($feed, 'function app_feed_project_cards'), 'App feed must define the project cards formatter.');
$assert(str_contains($feed, 'project_card_work_summary('), 'App feed must reuse the Work summary read model.');
$assert(str_contains($feed, "'projects' => app_feed_project_cards"), 'App feed payload must expose a projects key.');

// --- Due alert candidates: parametrized, assignee-scoped, board-scoped ---
$cards_module = file_get_contents($root . '/includes/modules/projects/project-cards.php');
$assert($cards_module !== false, 'Cards module must be readable.');
$assert(str_contains($cards_module, 'function project_card_work_summary'), 'Cards module must define the Work summary.');
$assert(str_contains($cards_module, "'assignee_id = ?'"), 'Work summary must scope by assignee.');
$assert(str_contains($cards_module, 'is_archived = 0'), 'Work summary must exclude archived boards.');
$assert(str_contains($cards_module, 'function project_card_due_alert_candidates'), 'Cards module must define due alert candidates.');
$assert(str_contains($cards_module, "due_date >= NOW()"), 'Due-soon candidates must start at now.');
$assert(str_contains($cards_module, "due_date < NOW()"), 'Overdue candidates must be before now.');
$assert(substr_count($cards_module, "'assignee_id'") >= 2, 'Work summary and alert candidates must both scope by assignee.');

// --- Notification policy module owns the card branches ---
$policy = file_get_contents($root . '/includes/modules/notifications/notification-policy.php');
$assert($policy !== false, 'Notification policy must be readable.');
$assert(str_contains($policy, 'function project_card_event_normalize'), 'Policy must normalize card events.');
$assert(str_contains($policy, 'function should_send_project_card_email'), 'Policy must decide card emails.');
$assert(str_contains($policy, 'function project_card_email_suppression_reason'), 'Policy must explain card suppressions.');
$assert(str_contains($policy, 'project.card.assigned'), 'Policy must handle assignment events.');
$assert(str_contains($policy, 'project.card.due_soon'), 'Policy must handle due-soon events.');
$assert(str_contains($policy, 'project.card.overdue'), 'Policy must handle overdue events.');
$assert(str_contains($policy, "in_array((string) (\$user['role']") || str_contains($policy, "'admin', 'agent'"), 'Policy must speak staff vocabulary without ticket coupling.');

// --- UI copy: work page literals are pt-covered ---
$pt = require $root . '/includes/lang/pt.php';
$pattern = "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/";
preg_match_all($pattern, $work, $matches);
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
$assert($missing === [], 'Work My cards literals missing from pt catalog: ' . implode(', ', array_keys($missing)));

// --- Theme ships layout for the My cards section ---
$css = file_get_contents($root . '/theme.css');
$assert($css !== false, 'theme.css must be readable.');
$assert(str_contains($css, '.work-my-cards-'), 'theme.css must style the My cards section.');

echo "Project integrations contract OK\n";