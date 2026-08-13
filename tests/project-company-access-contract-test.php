<?php
/**
 * Contract test — company-linked projects.
 *
 * Guarantees: project_boards.organization_id in all three DDL sinks
 * (schema.sql, upgrade.php, project-schema.php idempotent guard), board
 * creation persisting the company link, the per-user
 * can_view_all_company_projects permission (admin payload, agent opt-in
 * payload, default false, helper gate), the OR visibility rule in the board
 * listing and per-board gate, the company picker in the board modal, the
 * organization_id payload on board save (JS + API with organization
 * validation), admin user forms shipping the agent-only toggle, and
 * translations.
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
$handler = $root . '/includes/api/project-handler.php';
$composer = $root . '/includes/components/project-card-composer.php';
$grid_page = $root . '/pages/projects.php';
$board_js = $root . '/assets/js/project-board.js';
$team_users = $root . '/includes/modules/team/team-users.php';
$user_functions = $root . '/includes/user-functions.php';
$admin_users_page = $root . '/pages/admin/users.php';
$schema_sql = $root . '/includes/schema.sql';
$upgrade = $root . '/upgrade.php';

foreach ([
    $schema_module, $permissions_module, $boards_module, $handler, $composer,
    $grid_page, $board_js, $team_users, $user_functions, $admin_users_page,
    $schema_sql, $upgrade,
] as $path) {
    $assert(is_file($path), 'Required file missing: ' . $path);
}

$src = [];
foreach ([
    $schema_module, $permissions_module, $boards_module, $handler, $composer,
    $grid_page, $board_js, $team_users, $user_functions, $admin_users_page,
    $schema_sql, $upgrade,
] as $path) {
    $src[$path] = file_get_contents($path);
}

// --- Schema: organization_id on project_boards in all three DDL sinks ---
$assert(str_contains($src[$schema_sql], 'organization_id INT NULL'), 'schema.sql must ship project_boards.organization_id.');
$assert(str_contains($src[$schema_sql], 'idx_project_boards_organization'), 'schema.sql must index project_boards.organization_id.');
$assert(str_contains($src[$upgrade], "SHOW COLUMNS FROM project_boards LIKE 'organization_id'"), 'upgrade.php must probe organization_id before altering.');
$assert(str_contains($src[$upgrade], 'ADD COLUMN organization_id INT NULL'), 'upgrade.php must add project_boards.organization_id idempotently.');
$assert(str_contains($src[$upgrade], 'fk_project_boards_organization'), 'upgrade.php must add the organization foreign key.');
$assert(str_contains($src[$schema_module], 'function project_board_organization_column_exists'), 'Schema module must expose project_board_organization_column_exists().');
$assert(str_contains($src[$schema_module], 'function ensure_project_boards_organization_column'), 'Schema module must expose ensure_project_boards_organization_column().');
$assert(str_contains($src[$schema_module], "SHOW COLUMNS FROM project_boards LIKE 'organization_id'"), 'Schema module must probe the organization column safely.');
$assert(str_contains($src[$schema_module], 'ensure_project_boards_organization_column()'), 'Governance ensure must wire the organization column.');
$assert(str_contains($src[$schema_module], 'CREATE TABLE IF NOT EXISTS project_boards'), 'Schema module must keep creating project_boards.');
$assert(str_contains($src[$schema_module], 'organization_id INT NULL'), 'Schema module CREATE TABLE must ship organization_id.');

// --- Boards module: creation persists the link; listing widens for the permission ---
require_once $schema_module;
require_once $permissions_module;
require_once $boards_module;

$assert(function_exists('project_board_create'), 'Boards module must expose project_board_create().');
$assert(str_contains($src[$boards_module], 'int $organization_id = 0'), 'Board creation must accept an organization id.');
$assert(str_contains($src[$boards_module], "\$values['organization_id']"), 'Board creation must persist the organization id.');
$assert(str_contains($src[$boards_module], 'project_board_organization_column_exists()'), 'Board creation must guard the organization column.');
$assert(str_contains($src[$boards_module], 'can_view_all_company_projects('), 'Board listing must consult the company-wide permission.');
$assert(str_contains($src[$boards_module], 'organization_id IN ('), 'Board listing must widen to company boards.');
$assert(str_contains($src[$boards_module], 'get_user_organization_ids('), 'Board listing must scope company boards to the user organizations.');

// --- Permissions module: per-board gate widens for the permission ---
$assert(function_exists('project_can_view_board'), 'Permissions must expose project_can_view_board().');
$assert(str_contains($src[$permissions_module], 'can_view_all_company_projects('), 'Board gate must consult the company-wide permission.');
$assert(str_contains($src[$permissions_module], 'organization_id'), 'Board gate must read the board organization.');
$assert(str_contains($src[$permissions_module], 'get_user_organization_ids('), 'Board gate must scope to the user organizations.');

// --- Membership backing: company-wide access is membership-backed ---
$assert(function_exists('project_board_sync_company_memberships'), 'Permissions must expose project_board_sync_company_memberships().');
$assert(str_contains($src[$permissions_module], 'INSERT IGNORE INTO project_board_members'), 'Membership sync must be idempotent.');
$assert(str_contains($src[$permissions_module], 'SELECT id, ? FROM project_boards'), 'Membership sync must join company boards.');
$assert(str_contains($src[$permissions_module], 'project_board_organization_column_exists()'), 'Membership sync must guard the organization column.');
$assert(substr_count($src[$admin_users_page], 'project_board_sync_company_memberships(') >= 4, 'Admin users page must sync memberships on all four user/AI handlers.');
$assert(str_contains($src[$admin_users_page], 'get_user_organization_ids('), 'Admin users page must sync against all user organizations.');
$assert(str_contains($src[$boards_module], 'JSON_CONTAINS(permissions'), 'Board creation must join agents holding the flag for the company.');
$assert(str_contains($src[$boards_module], '\"can_view_all_company_projects\":true'), 'Board creation must probe the flag in the permissions JSON.');

// --- Permission payload: admin always on, agent opt-in, default off ---
require_once $user_functions;
require_once $team_users;
$assert(function_exists('team_users_permission_payload'), 'Team module must expose team_users_permission_payload().');
$admin_payload = team_users_permission_payload('admin', null, [], []);
$assert(($admin_payload['can_view_all_company_projects'] ?? null) === true, 'Admin payload must grant company-wide project access.');
$agent_off = team_users_permission_payload('agent', null, [], []);
$assert(($agent_off['can_view_all_company_projects'] ?? null) === false, 'Agent payload must default company-wide access to false.');
$agent_on = team_users_permission_payload('agent', null, [], ['can_view_all_company_projects' => '1']);
$assert(($agent_on['can_view_all_company_projects'] ?? null) === true, 'Agent payload must enable company-wide access when checked.');
$user_payload = team_users_permission_payload('user', null, [], ['can_view_all_company_projects' => '1']);
$assert(($user_payload['can_view_all_company_projects'] ?? null) === false, 'Client payload must never grant company-wide project access.');

// --- user-functions: default off + helper gate ---
$assert(function_exists('can_view_all_company_projects'), 'User functions must expose can_view_all_company_projects().');
$assert(str_contains($src[$user_functions], "'can_view_all_company_projects' => false"), 'Permission defaults must ship the flag off.');
$assert(can_view_all_company_projects(['role' => 'admin']) === true, 'Admins must hold company-wide project access.');
$assert(can_view_all_company_projects(['role' => 'user']) === false, 'Clients must never hold company-wide project access.');
$assert(can_view_all_company_projects([]) === false, 'Guests must never hold company-wide project access.');

// --- API: board save accepts and validates the organization ---
$assert(str_contains($src[$handler], "'organization_id'"), 'Board save must read the organization id.');
$assert(str_contains($src[$handler], 'can_user_use_organization('), 'Board save must validate the organization for the actor.');
$assert(str_contains($src[$handler], 'project_board_create($name, $description, $color'), 'Board save must forward the organization id to creation.');

// --- Modal: company picker present in the board modal ---
$assert(str_contains($src[$composer], 'project-board-organization-field'), 'Board modal must ship the organization field.');
$assert(str_contains($src[$composer], 'project-board-organization-input'), 'Board modal must ship the organization input.');
$assert(str_contains($src[$composer], 'array $organizations = []'), 'Modal renderer must accept organizations.');
$assert(str_contains($src[$composer], "'No company'"), 'Board modal must offer a no-company option.');

// --- Grid page: loads organizations for the modal ---
$assert(str_contains($src[$grid_page], 'get_organizations(true)'), 'Grid page must load organizations for the board modal.');
$assert(str_contains($src[$grid_page], 'project_render_modal_templates([], $project_agents, $project_organizations)'), 'Grid page must pass organizations to the modal.');

// --- JS: sends organization_id on create, toggles the field ---
$assert(str_contains($src[$board_js], 'payload.organization_id ='), 'Board JS must send the organization id on save.');
$assert(str_contains($src[$board_js], 'project-board-organization-input'), 'Board JS must read the organization input.');
$assert(str_contains($src[$board_js], 'project-board-organization-field'), 'Board JS must toggle the organization field.');

// --- Admin users page: agent-only toggle on add/edit and AI agent forms ---
$assert(substr_count($src[$admin_users_page], 'name="can_view_all_company_projects"') >= 4, 'Admin users page must ship the toggle on all four user/AI forms.');
$assert(str_contains($src[$admin_users_page], 'add_can_view_all_company_projects_wrap'), 'Add form must wrap the toggle for role switching.');
$assert(str_contains($src[$admin_users_page], 'edit_can_view_all_company_projects'), 'Edit form must expose the toggle with an id.');
$assert(str_contains($src[$admin_users_page], 'permissions.can_view_all_company_projects === true'), 'Edit modal must restore the toggle from permissions.');
$assert(str_contains($src[$admin_users_page], 'ai_edit_can_view_all_company_projects'), 'AI agent edit form must expose the toggle.');

// --- Translations: every language ships the labels ---
foreach (['en', 'es', 'de', 'it', 'cs', 'pt'] as $language) {
    $catalog = require $root . '/includes/lang/' . $language . '.php';
    $assert(is_string($catalog['Can view all company projects'] ?? null), $language . ' catalog missing Can view all company projects.');
    $assert(is_string($catalog['No company'] ?? null), $language . ' catalog missing No company.');
    $assert(is_string($catalog['Links the project to a company so agents with company-wide project access can see it.'] ?? null), $language . ' catalog missing the company link helper text.');
}
$pt = require $root . '/includes/lang/pt.php';
$assert($pt['Can view all company projects'] !== 'Can view all company projects', 'Portuguese label must be translated.');

echo "Project company access contract OK\n";