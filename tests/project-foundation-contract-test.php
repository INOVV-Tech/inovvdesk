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

// --- Permission model ---
$assert(project_can_view(['role' => 'admin']) === true, 'Admins must be able to view projects.');
$assert(project_can_view(['role' => 'agent']) === true, 'Agents must be able to view projects.');
$assert(project_can_view(['role' => 'user']) === false, 'Clients must not be able to view projects.');
$assert(project_can_view(null) === false, 'Guests must not be able to view projects.');
$assert(project_can_manage(['role' => 'agent']) === true, 'Agents must be able to manage projects as staff.');
$assert(project_can_manage(['role' => 'user']) === false, 'Clients must not manage projects.');

// --- Priorities use the module's own key convention ---
$assert(project_priority_normalize('urgent') === 'urgent', 'Urgent priority must normalize to itself.');
$assert(project_priority_normalize('URGENT') === 'urgent', 'Priority must normalize case.');
$assert(project_priority_normalize('blocked') === 'medium', 'Unknown priority must fall back to medium.');
$assert(in_array('low', project_priority_keys(), true), 'Priority keys must include low.');
$assert(project_priority_label('high') === 'High', 'Priority label must translate through t().');

// --- View models stay pure ---
$lists = [['id' => 1, 'name' => 'Backlog', 'sort_order' => 0], ['id' => 2, 'name' => 'Done', 'sort_order' => 1]];
$cards = [
    ['id' => 10, 'list_id' => 1, 'title' => 'Card A', 'priority' => 'high'],
    ['id' => 11, 'list_id' => 1, 'title' => 'Card B', 'priority' => 'blocked'],
    ['id' => 12, 'list_id' => 99, 'title' => 'Orphan', 'priority' => 'low'],
];
$by_list = project_board_cards_model($lists, $cards);
$assert(count($by_list[1] ?? []) === 2, 'Board cards model must group cards by list.');
$assert(($by_list[1][0]['priority'] ?? '') === 'high', 'Board cards model must keep normalized priority.');
$assert(($by_list[1][1]['priority'] ?? '') === 'medium', 'Board cards model must normalize unknown priority.');
$assert(!isset($by_list[99]), 'Board cards model must drop cards from missing lists.');
$assert(str_contains(project_card_priority_badge_class($cards[0]), 'project-priority-inline--high'), 'Priority badge must use module-owned project-priority classes.');
$assert(!str_contains(project_card_priority_badge_class($cards[0]), 'ticket-priority'), 'Priority badge must never reuse ticket priority classes.');

// --- Routes stay thin and registered ---
$index = file_get_contents($root . '/index.php');
$bootstrap = file_get_contents($root . '/includes/modules/bootstrap.php');
$shell = file_get_contents($root . '/includes/modules/app/app-shell.php');
$header = file_get_contents($root . '/includes/header.php');
$icons = file_get_contents($root . '/includes/icons.php');
$assert($index !== false && $bootstrap !== false && $shell !== false && $header !== false && $icons !== false, 'Project module files must be readable.');

$assert(str_contains($index, "case 'projects'"), 'index.php must route page=projects.');
$assert(str_contains($index, "case 'project'"), 'index.php must route page=project.');
$assert(str_contains($bootstrap, '/projects/project-schema.php'), 'Bootstrap must load project schema.');
$assert(str_contains($bootstrap, '/projects/project-permissions.php'), 'Bootstrap must load project permissions.');
$assert(str_contains($bootstrap, '/projects/project-boards.php'), 'Bootstrap must load project boards module.');
$assert(str_contains($bootstrap, '/projects/project-lists.php'), 'Bootstrap must load project lists module.');
$assert(str_contains($bootstrap, '/projects/project-cards.php'), 'Bootstrap must load project cards module.');
$assert(str_contains($shell, "'key' => 'projects'"), 'App shell must expose projects navigation.');
$assert(str_contains($shell, "'manage_projects'"), 'App shell must expose manage_projects capability.');
$assert(str_contains($shell, "'view_projects'"), 'App shell must expose view_projects capability.');
$assert(str_contains($header, "url('projects')"), 'Sidebar must link to the projects page.');
$assert(str_contains($icons, "'trello' =>"), 'Icon map must ship the trello icon.');

// --- Pages keep business rules out ---
$projects_page = file_get_contents($root . '/pages/projects.php');
$project_page = file_get_contents($root . '/pages/project.php');
$assert($projects_page !== false && $project_page !== false, 'Project pages must be readable.');
$assert(str_contains($projects_page, 'project_requires_staff_redirect()'), 'Projects page must guard staff access.');
$assert(str_contains($project_page, 'project_requires_staff_redirect()'), 'Project page must guard staff access.');
$assert(str_contains($projects_page, 'project_boards_list(true,'), 'Projects page must delegate board listing to the module.');
$assert(str_contains($project_page, 'project_lists_for_board('), 'Project page must delegate list loading to the module.');
$assert(str_contains($project_page, 'project_cards_for_board('), 'Project page must delegate card loading to the module.');
$assert(str_contains($project_page, 'assets/js/project-board.js'), 'Project page must load the board JS module.');
$assert(!str_contains($projects_page, 'db_fetch'), 'Projects page must not query the database directly.');
$assert(!str_contains($project_page, 'db_fetch'), 'Project page must not query the database directly.');

// --- Schema helper surface ---
$schema = file_get_contents($root . '/includes/modules/projects/project-schema.php');
$assert(str_contains($schema, 'function ensure_project_tables'), 'Project schema must expose ensure_project_tables().');
$assert(str_contains($schema, 'SHOW TABLES LIKE'), 'Project schema must use idempotent table checks.');
$assert(str_contains($schema, 'ON DELETE CASCADE'), 'Project schema must cascade board deletion to lists and cards.');

// --- Installer and updater keep parity ---
$schema_sql = file_get_contents($root . '/includes/schema.sql');
$upgrade = file_get_contents($root . '/upgrade.php');
$assert($schema_sql !== false && $upgrade !== false, 'Schema files must be readable.');
$assert(str_contains($schema_sql, 'CREATE TABLE IF NOT EXISTS project_boards'), 'schema.sql must create project_boards.');
$assert(str_contains($schema_sql, 'CREATE TABLE IF NOT EXISTS project_lists'), 'schema.sql must create project_lists.');
$assert(str_contains($schema_sql, 'CREATE TABLE IF NOT EXISTS project_cards'), 'schema.sql must create project_cards.');
$assert(str_contains($upgrade, "SHOW TABLES LIKE 'project_boards'"), 'upgrade.php must migrate project_boards.');
$assert(str_contains($upgrade, "SHOW TABLES LIKE 'project_lists'"), 'upgrade.php must migrate project_lists.');
$assert(str_contains($upgrade, "SHOW TABLES LIKE 'project_cards'"), 'upgrade.php must migrate project_cards.');

// --- Translations cover the new surface ---
$en = require $root . '/includes/lang/en.php';
$pt = require $root . '/includes/lang/pt.php';
foreach (['Projects', 'No projects yet', 'No lists yet', 'Project not found.'] as $key) {
    $assert(is_string($pt[$key] ?? null), 'Portuguese catalog missing project key: ' . $key);
    $assert($pt[$key] !== $key || $key === 'Projects', 'Portuguese project key must be translated: ' . $key);
}

echo "Project foundation contract OK\n";