<?php
/**
 * Project boards: query and write model.
 *
 * Phase 0 ships the read model used by the board grid route. Phase 1 (MVP)
 * adds create/rename/archive/delete through the same module, keeping routes
 * thin per the monolith exit inventory.
 */

function project_board_normalize_id($board_id): int
{
    return max(0, (int) $board_id);
}

/**
 * Board templates (Phase 2 item 4): blank (no lists) and development
 * (Backlog → In Progress → Review → Done, in that order). Only applied on
 * board creation.
 */
function project_board_templates(): array
{
    return [
        ['key' => 'blank', 'name' => 'Blank board', 'lists' => []],
        ['key' => 'development', 'name' => 'Development board', 'lists' => ['Backlog', 'In Progress', 'Review', 'Done']],
    ];
}

/**
 * Lists of a template key; unknown or blank templates ship no lists.
 */
function project_board_template_lists(?string $template): array
{
    foreach (project_board_templates() as $candidate) {
        if (($candidate['key'] ?? '') === trim((string) $template)) {
            return array_values(array_filter(array_map(
                static fn ($name) => trim((string) $name),
                $candidate['lists'] ?? []
            )));
        }
    }
    return [];
}

function project_boards_list(bool $include_archived = false, ?array $user = null): array
{
    if (!project_boards_table_exists()) {
        return [];
    }

    $sql = "SELECT * FROM project_boards WHERE 1=1";
    $params = [];
    if (!$include_archived) {
        $sql .= " AND is_archived = 0";
    }

    // Per-board membership (Phase 2 item 4): agents only see boards they
    // belong to; admins see everything. Without a user context (CLI, no
    // session) no membership filter is applied.
    // Company-linked boards (Phase 3): agents with can_view_all_company_projects
    // also see every board tied to one of their companies.
    if ($user === null && function_exists('current_user')) {
        $user = current_user() ?: null;
    }
    if ($user && in_array((string) ($user['role'] ?? ''), ['agent'], true)) {
        $user_id = (int) ($user['id'] ?? 0);
        if ($user_id > 0 && project_board_members_table_exists()) {
            $sql .= " AND (id IN (SELECT board_id FROM project_board_members WHERE user_id = ?)";
            $params[] = $user_id;

            if (function_exists('project_board_organization_column_exists')
                && project_board_organization_column_exists()
                && function_exists('can_view_all_company_projects')
                && can_view_all_company_projects($user)) {
                $organization_ids = function_exists('get_user_organization_ids') ? get_user_organization_ids($user_id) : [];
                if (!empty($organization_ids)) {
                    $placeholders = implode(',', array_fill(0, count($organization_ids), '?'));
                    $sql .= " OR (organization_id IS NOT NULL AND organization_id IN ({$placeholders}))";
                    foreach ($organization_ids as $organization_id) {
                        $params[] = (int) $organization_id;
                    }
                }
            }

            $sql .= ")";
        } elseif ($user_id <= 0) {
            $sql .= " AND 1 = 0";
        }
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    return db_fetch_all($sql, $params);
}

function project_board_get($board_id): ?array
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_boards_table_exists()) {
        return null;
    }

    $board = db_fetch_one("SELECT * FROM project_boards WHERE id = ?", [$board_id]);
    return $board ?: null;
}

function project_board_get_visible($board_id): ?array
{
    $board = project_board_get($board_id);
    if (!$board || !empty($board['is_archived'])) {
        return null;
    }

    return $board;
}

function project_board_count(): int
{
    if (!project_boards_table_exists()) {
        return 0;
    }

    $row = db_fetch_one("SELECT COUNT(*) AS total FROM project_boards WHERE is_archived = 0");
    return (int) ($row['total'] ?? 0);
}

function project_board_url(array $board): string
{
    return url('project', ['board_id' => (int) ($board['id'] ?? 0)]);
}

function project_board_label(array $board): string
{
    $name = trim((string) ($board['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return function_exists('t') ? t('Project') : 'Project';
}

function project_board_validate_name($name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        throw new InvalidArgumentException(project_validation_message('Board name is required.'));
    }
    if (function_exists('mb_strlen') ? mb_strlen($name) > 255 : strlen($name) > 255) {
        throw new InvalidArgumentException(project_validation_message('Board name is too long.'));
    }
    return $name;
}

function project_board_normalize_color($color): string
{
    $color = trim((string) $color);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0a84ff';
}

function project_board_create(string $name, ?string $description, $color, int $created_by, ?string $template = null, int $organization_id = 0): int
{
    $name = project_board_validate_name($name);
    $created_by = project_board_normalize_id($created_by);
    if ($created_by <= 0) {
        throw new InvalidArgumentException(project_validation_message('Board owner is required.'));
    }

    $values = [
        'name' => $name,
        'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
        'color' => project_board_normalize_color($color),
        'is_archived' => 0,
        'created_by' => $created_by,
    ];
    $organization_id = project_board_normalize_id($organization_id);
    if ($organization_id > 0 && function_exists('project_board_organization_column_exists') && project_board_organization_column_exists()) {
        $values['organization_id'] = $organization_id;
    }

    $board_id = (int) db_insert('project_boards', $values);

    // The creator becomes a member automatically (per-board permission model).
    if (project_board_members_table_exists()) {
        db_query(
            "INSERT IGNORE INTO project_board_members (board_id, user_id) VALUES (?, ?)",
            [$board_id, $created_by]
        );

        // Company-wide access is membership-backed: every active agent with
        // can_view_all_company_projects for this company joins automatically.
        if ($organization_id > 0 && project_board_organization_column_exists()) {
            db_query(
                "INSERT IGNORE INTO project_board_members (board_id, user_id)
                 SELECT ?, id FROM users
                 WHERE is_active = 1
                   AND role = 'agent'
                   AND permissions LIKE '%\"can_view_all_company_projects\":true%'
                   AND (organization_id = ? OR permissions LIKE '%\"organization_ids\"%' AND JSON_CONTAINS(permissions, ?, '$.organization_ids'))",
                [$board_id, $organization_id, (string) $organization_id]
            );
        }
    }

    // Board templates pre-seed the lists in order (blank ships none).
    foreach (project_board_template_lists($template) as $position => $list_name) {
        db_insert('project_lists', [
            'board_id' => $board_id,
            'name' => $list_name,
            'sort_order' => $position,
        ]);
    }

    return $board_id;
}

function project_board_update(int $board_id, string $name, ?string $description, $color): bool
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0) {
        return false;
    }

    db_update('project_boards', [
        'name' => project_board_validate_name($name),
        'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
        'color' => project_board_normalize_color($color),
    ], 'id = ?', [$board_id]);

    return true;
}

function project_board_set_archived(int $board_id, bool $archived): bool
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        return false;
    }

    db_update('project_boards', ['is_archived' => $archived ? 1 : 0], 'id = ?', [$board_id]);
    return true;
}

function project_board_delete(int $board_id): bool
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        return false;
    }

    db_delete('project_boards', 'id = ?', [$board_id]);
    return true;
}