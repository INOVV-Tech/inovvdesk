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