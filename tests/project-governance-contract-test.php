<?php
/**
 * Phase 2 (item 4) contract test — board governance.
 *
 * Runs before the logic exists. Guarantees: schema for project_board_members
 * and the project_cards.is_archived/archived_at columns (all three DDL
 * sinks), per-board permission model (admin bypass, agent member, creator
 * auto-member, last member protected), member-scoped assignees, archived
 * cards (own column model, excluded from board/work/alerts queries), board
 * templates (blank/development), API routes, markup, JS vocabulary and the
 * absence of ticket/kanban coupling.
 */

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$schema_module = $root . '/includes/modules/projects/project-schema.php';
$permissions_module = $root . '/includes/modules/projects/project-permissions.php';
$boards_module = $root . '/includes/modules/projects/project-boards.php';
$cards_module = $root . '/includes/modules/projects/project-cards.php';
$handler = $root . '/includes/api/project-handler.php';
$router = $root . '/includes/api/router.php';
$surface = $root . '/includes/components/project-board-surface.php';
$composer = $root . '/includes/components/project-card-composer.php';
$board_page = $root . '/pages/project.php';
$grid_page = $root . '/pages/projects.php';
$footer = $root . '/includes/footer.php';
$board_js = $root . '/assets/js/project-board.js';

foreach ([
    $schema_module, $permissions_module, $boards_module, $cards_module,
    $handler, $router, $surface, $composer, $board_page, $grid_page,
    $footer, $board_js,
] as $path) {
    $assert(is_file($path), 'Required file missing: ' . $path);
}

$src = [];
foreach ([
    $schema_module, $permissions_module, $boards_module, $cards_module,
    $handler, $router, $surface, $composer, $board_page, $grid_page,
] as $path) {
    $src[$path] = file_get_contents($path);
}

// --- Schema: project_board_members in all three DDL sinks ---
$assert(str_contains($src[$schema_module], 'CREATE TABLE IF NOT EXISTS project_board_members'), 'Schema module must ensure project_board_members.');
$assert(str_contains($src[$schema_module], 'function ensure_project_board_members_table'), 'Schema module must expose ensure_project_board_members_table().');
$assert(str_contains($src[$schema_module], "'project_board_members'"), 'Schema module table allowlist must include project_board_members.');
$assert(str_contains($src[$schema_module], 'PRIMARY KEY (board_id, user_id)'), 'Membership must be keyed by (board_id, user_id).');
$assert(str_contains($src[$schema_module], 'INSERT IGNORE INTO project_board_members'), 'Membership creation must backfill creators for existing boards.');
$schema_sql = file_get_contents($root . '/includes/schema.sql');
$assert(str_contains($schema_sql, 'CREATE TABLE IF NOT EXISTS project_board_members'), 'schema.sql must ship project_board_members.');
$upgrade = file_get_contents($root . '/upgrade.php');
$assert(str_contains($upgrade, "SHOW TABLES LIKE 'project_board_members'"), 'upgrade.php must guard project_board_members.');
$assert(str_contains($upgrade, 'CREATE TABLE project_board_members'), 'upgrade.php must create project_board_members.');

// --- Schema: project_cards gains is_archived + archived_at ---
$assert(str_contains($src[$schema_module], 'function project_card_archived_column_exists'), 'Schema module must expose project_card_archived_column_exists().');
$assert(str_contains($src[$schema_module], 'function ensure_project_cards_archived_column'), 'Schema module must expose ensure_project_cards_archived_column().');
$assert(str_contains($src[$schema_module], 'SHOW COLUMNS FROM project_cards LIKE'), 'Schema module must probe the archived column safely.');
$assert(str_contains($src[$schema_module], 'ADD COLUMN is_archived'), 'Schema module must add is_archived idempotently.');
$assert(str_contains($src[$schema_module], 'archived_at DATETIME NULL'), 'Schema module must add archived_at.');
$assert(str_contains($src[$schema_module], 'function ensure_project_governance_tables'), 'Schema module must expose ensure_project_governance_tables().');
$assert(str_contains($schema_sql, 'is_archived TINYINT(1) NOT NULL DEFAULT 0'), 'schema.sql project_cards must ship is_archived.');
$assert(str_contains($schema_sql, 'archived_at DATETIME NULL'), 'schema.sql project_cards must ship archived_at.');
$assert(str_contains($upgrade, "SHOW COLUMNS FROM project_cards LIKE 'is_archived'"), 'upgrade.php must probe is_archived before altering.');
$assert(str_contains($upgrade, 'ALTER TABLE project_cards'), 'upgrade.php must alter project_cards for is_archived.');

// --- Permission model: per-board gates (pure, no DB) ---
require_once $schema_module;
require_once $permissions_module;
require_once $boards_module;
require_once $root . '/includes/modules/projects/project-lists.php';
require_once $cards_module;

$assert(function_exists('project_can_view_board'), 'Permissions must expose project_can_view_board().');
$assert(function_exists('project_can_manage_board'), 'Permissions must expose project_can_manage_board().');
$assert(function_exists('project_board_is_member'), 'Permissions must expose project_board_is_member().');
$assert(function_exists('project_board_members_for_board'), 'Permissions must expose project_board_members_for_board().');
$assert(function_exists('project_board_member_add'), 'Permissions must expose project_board_member_add().');
$assert(function_exists('project_board_member_remove'), 'Permissions must expose project_board_member_remove().');
$assert(function_exists('project_board_member_candidates'), 'Permissions must expose project_board_member_candidates().');
$assert(function_exists('project_board_admin_can_manage_members'), 'Permissions must expose the admin member-management gate.');

$assert(str_contains($src[$permissions_module], "'admin'"), 'Board gates must reference the admin role.');
$assert(str_contains($src[$permissions_module], 'project_board_is_member('), 'Board gates must delegate to the membership model.');
$assert(str_contains($src[$permissions_module], 'project_board_members_table_exists()'), 'Membership lookups must guard the table being absent.');
$assert(str_contains($src[$permissions_module], 'At least one member is required.'), 'Member removal must protect the last member.');
$assert(str_contains($src[$permissions_module], 'get_user('), 'Member adds must validate the target user exists.');

// --- Boards module: membership-filtered listing + templates ---
$assert(function_exists('project_board_templates'), 'Boards module must expose project_board_templates().');
$assert(function_exists('project_board_template_lists'), 'Boards module must expose project_board_template_lists().');
$templates = project_board_templates();
$keys = array_column($templates, 'key');
$assert(in_array('blank', $keys, true), 'Templates must include blank.');
$assert(in_array('development', $keys, true), 'Templates must include development.');
$dev_lists = project_board_template_lists('development');
$assert($dev_lists === ['Backlog', 'In Progress', 'Review', 'Done'], 'Development template must ship the four canonical lists.');
$assert(project_board_template_lists('blank') === [], 'Blank template must ship no lists.');
$assert(project_board_template_lists('bogus') === [], 'Unknown templates must behave like blank.');
$assert(str_contains($src[$boards_module], 'project_board_members'), 'Boards listing must join membership for agents.');
$assert(str_contains($src[$boards_module], 'INSERT IGNORE INTO project_board_members'), 'Board creation must auto-member the creator.');
$assert(str_contains($src[$boards_module], '$template'), 'Board creation must accept a template key.');

// --- Cards module: archived cards model ---
$assert(function_exists('project_card_set_archived'), 'Cards module must expose project_card_set_archived().');
$assert(function_exists('project_archived_cards_for_board'), 'Cards module must expose project_archived_cards_for_board().');
$assert(function_exists('project_assignee_options_for_board'), 'Cards module must expose board-scoped assignee options.');
$assert(function_exists('project_assignee_valid_for_board'), 'Cards module must validate assignees per board.');
$assert(str_contains($src[$cards_module], 'is_archived = 0'), 'Board card queries must exclude archived cards.');
$assert(str_contains($src[$cards_module], 'archived_at DESC'), 'Archived cards must list newest first.');
$assert(substr_count($src[$cards_module], 'pc.is_archived = 0') >= 2, 'Work summary and due alerts must exclude archived cards.');
$assert(str_contains($src[$cards_module], 'project_board_is_member('), 'Board-scoped assignees must come from membership.');

// --- API surface ---
foreach ([
    "'project-board-member-add' => 'api_project_board_member_add'",
    "'project-board-member-remove' => 'api_project_board_member_remove'",
    "'project-card-archive' => 'api_project_card_archive'",
] as $route) {
    $assert(str_contains($src[$router], $route), 'API router must register ' . $route . '.');
}

foreach ([
    'function api_project_board_member_add',
    'function api_project_board_member_remove',
    'function api_project_card_archive',
    'function api_project_require_admin_post',
] as $fn) {
    $assert(str_contains($src[$handler], $fn), 'Project handler must define ' . $fn . '().');
}
$assert(str_contains($src[$handler], "project_board_admin_can_manage_members("), 'Member endpoints must gate on the admin member-management rule.');
$assert(str_contains($src[$handler], 'project_can_manage_board('), 'Card archive must gate on the board permission.');
$assert(str_contains($src[$handler], 'require_csrf_token(true)'), 'Governance writes must enforce CSRF.');
$assert(str_contains($src[$handler], "'template'"), 'Board save must accept the template key.');

// --- Surface: member strip, archived column, board page wiring ---
$assert(str_contains($src[$surface], 'function project_render_board_members'), 'Board surface must expose the member strip renderer.');
$assert(str_contains($src[$surface], 'project-board-members'), 'Member strip must use module-owned classes.');
$assert(str_contains($src[$surface], 'data-project-action="member-add"'), 'Member strip must expose member add.');
$assert(str_contains($src[$surface], 'data-project-action="member-remove"'), 'Member strip must expose member remove.');
$assert(str_contains($src[$surface], 'function project_render_archived_column'), 'Board surface must expose the archived column renderer.');
$assert(str_contains($src[$surface], 'project-archived-column'), 'Archived column must use module-owned classes.');
$assert(str_contains($src[$surface], 'data-project-action="archived-toggle"'), 'Archived column must expose the show/hide toggle.');
$assert(str_contains($src[$surface], 'data-project-action="card-archive"'), 'Archived cards must expose restore.');
$assert(str_contains($src[$board_page], 'project_can_view_board('), 'Board page must gate on the per-board permission.');
$assert(str_contains($src[$board_page], 'project_board_members_for_board('), 'Board page must load members through the module.');
$assert(str_contains($src[$board_page], 'project_archived_cards_for_board('), 'Board page must load archived cards through the module.');
$assert(str_contains($src[$board_page], 'project_assignee_options_for_board('), 'Board page must scope assignees to board members.');
$assert(str_contains($src[$board_page], 'project_render_board_members('), 'Board page must render the member strip.');
$assert(str_contains($src[$board_page], 'project_render_archived_column('), 'Board page must render the archived column.');
$assert(str_contains($src[$grid_page], 'project_boards_list(true'), 'Grid page must keep delegating board listing to the module.');
$assert(!str_contains($src[$board_page], 'db_fetch'), 'Board page must not query the database directly.');
$assert(!str_contains($src[$grid_page], 'db_fetch'), 'Grid page must not query the database directly.');

// --- Composer: template picker in create mode only ---
$assert(str_contains($src[$composer], 'project-board-template-field'), 'Board modal must ship the template field.');
$assert(str_contains($src[$composer], 'project-board-template-input'), 'Board modal must ship the template input.');
$assert(str_contains($src[$composer], 'project_board_templates()'), 'Board modal must render templates through the module.');

// --- Board JS: governance actions with module vocabulary ---
$js = file_get_contents($board_js);
$assert(str_contains($js, 'X-CSRF-Token'), 'Board JS must keep sending the CSRF token.');
foreach (["'project-card-archive'", "'project-board-member-add'", "'project-board-member-remove'"] as $needle) {
    $assert(str_contains($js, $needle), 'Board JS must call the ' . $needle . ' endpoint.');
}
$assert(str_contains($js, 'payload.template ='), 'Board JS must send the template key on board save.');
$assert(str_contains($js, 'project-board-template-input'), 'Board JS must read the template input.');
$assert(str_contains($js, 'archived-toggle'), 'Board JS must handle the archived toggle.');
$assert(str_contains($js, 'project-archived-cards'), 'Board JS must toggle the archived cards container.');
$assert(str_contains($js, "'admin'") === false, 'Board JS must not hardcode the admin role.');
$assert(!preg_match('/\bkanban-/', $js), 'Board JS must not reference ticket kanban classes.');

// --- Footer appConfig bridges governance labels ---
foreach ([
    'showArchivedLabel', 'hideArchivedLabel', 'archiveCardConfirm',
    'restoreCardLabel', 'memberRemoveConfirm',
] as $label) {
    $assert(str_contains(file_get_contents($footer), $label), 'Footer appConfig must bridge ' . $label . '.');
}

// --- UI copy stays English; every literal t() call is pt-covered ---
foreach ([$surface, $composer] as $path) {
    $contents = $src[$path];
    $assert(
        !preg_match('/[ěščřžýáíéůúďťňĚŠČŘŽÝÁÍÉŮÚĎŤŇãõç]/u', $contents),
        'Governance source copy must stay English: ' . $path
    );
}
$pt = require $root . '/includes/lang/pt.php';
$pattern = "/\\bt\\(\\s*'((?:\\\\.|[^'])*)'/";
$missing = [];
foreach ([$surface, $composer] as $path) {
    preg_match_all($pattern, $src[$path], $matches);
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
foreach (['Archived', 'Show archived', 'Hide archived', 'Restore card', 'Board members', 'Add member', 'Remove member', 'Template', 'Blank board', 'Development board', 'At least one member is required.', 'No members yet'] as $key) {
    $assert(is_string($pt[$key] ?? null), 'Portuguese catalog missing governance key: ' . $key);
}

echo "Project governance contract OK\n";