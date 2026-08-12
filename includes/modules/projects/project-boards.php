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