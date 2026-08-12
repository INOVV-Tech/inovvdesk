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

function project_boards_list(bool $include_archived = false): array
{
    if (!project_boards_table_exists()) {
        return [];
    }

    $sql = "SELECT * FROM project_boards WHERE 1=1";
    $params = [];
    if (!$include_archived) {
        $sql .= " AND is_archived = 0";
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

function project_board_create(string $name, ?string $description, $color, int $created_by): int
{
    $name = project_board_validate_name($name);
    $created_by = project_board_normalize_id($created_by);
    if ($created_by <= 0) {
        throw new InvalidArgumentException(project_validation_message('Board owner is required.'));
    }

    return (int) db_insert('project_boards', [
        'name' => $name,
        'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
        'color' => project_board_normalize_color($color),
        'is_archived' => 0,
        'created_by' => $created_by,
    ]);
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