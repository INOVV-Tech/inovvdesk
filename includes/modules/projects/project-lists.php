<?php
/**
 * Project board lists (columns): query model.
 *
 * Phase 0 ships the read model for the board detail route. Phase 1 (MVP)
 * adds create/rename/delete/reorder through this module.
 */

function project_lists_for_board($board_id): array
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_lists_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT * FROM project_lists WHERE board_id = ? ORDER BY sort_order ASC, id ASC",
        [$board_id]
    );
}

function project_list_get($list_id): ?array
{
    $list_id = project_board_normalize_id($list_id);
    if ($list_id <= 0 || !project_lists_table_exists()) {
        return null;
    }

    $list = db_fetch_one("SELECT * FROM project_lists WHERE id = ?", [$list_id]);
    return $list ?: null;
}

function project_list_label(array $list): string
{
    $name = trim((string) ($list['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return function_exists('t') ? t('List') : 'List';
}

function project_list_validate_name($name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        throw new InvalidArgumentException(project_validation_message('List name is required.'));
    }
    if (function_exists('mb_strlen') ? mb_strlen($name) > 255 : strlen($name) > 255) {
        throw new InvalidArgumentException(project_validation_message('List name is too long.'));
    }
    return $name;
}

function project_list_create(int $board_id, string $name, ?int $sort_order = null): int
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        throw new InvalidArgumentException(project_validation_message('Project not found.'));
    }

    $name = project_list_validate_name($name);
    $sort_order = $sort_order ?? project_list_next_sort_order($board_id);

    return (int) db_insert('project_lists', [
        'board_id' => $board_id,
        'name' => $name,
        'sort_order' => max(0, (int) $sort_order),
    ]);
}

function project_list_next_sort_order(int $board_id): int
{
    $board_id = project_board_normalize_id($board_id);
    $row = db_fetch_one(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM project_lists WHERE board_id = ?",
        [$board_id]
    );
    return (int) ($row['next_order'] ?? 0);
}

function project_list_update(int $list_id, string $name): bool
{
    $list_id = project_board_normalize_id($list_id);
    if ($list_id <= 0 || !project_list_get($list_id)) {
        return false;
    }

    db_update('project_lists', ['name' => project_list_validate_name($name)], 'id = ?', [$list_id]);
    return true;
}

function project_list_delete(int $list_id): bool
{
    $list_id = project_board_normalize_id($list_id);
    if ($list_id <= 0 || !project_list_get($list_id)) {
        return false;
    }

    db_delete('project_lists', 'id = ?', [$list_id]);
    return true;
}

function project_list_belongs_to_board(int $list_id, int $board_id): bool
{
    $list = project_list_get($list_id);
    return $list !== null && (int) ($list['board_id'] ?? 0) === $board_id;
}

/**
 * Renumber lists in the given order. All ids must belong to the board.
 */
function project_lists_reorder(int $board_id, array $order): bool
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        return false;
    }

    $position = 1;
    foreach ($order as $list_id) {
        $list_id = project_board_normalize_id($list_id);
        if ($list_id <= 0 || !project_list_belongs_to_board($list_id, $board_id)) {
            continue;
        }
        db_update('project_lists', ['sort_order' => $position], 'id = ?', [$list_id]);
        $position++;
    }

    return $position > 1;
}