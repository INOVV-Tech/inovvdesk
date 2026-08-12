<?php
/**
 * Project cards: query and view model.
 *
 * Phase 0 ships the read model for a board's cards (grouped by list, with
 * priority helpers reusing the ticket priority key convention). Phase 1
 * (MVP) adds create/edit/move/reorder/delete through this module.
 */

function project_priority_keys(): array
{
    return ['low', 'medium', 'high', 'urgent'];
}

function project_priority_normalize(?string $priority): string
{
    $priority = strtolower(trim((string) $priority));
    return in_array($priority, project_priority_keys(), true) ? $priority : 'medium';
}

function project_priority_label(string $priority): string
{
    $label = ucfirst(project_priority_normalize($priority));
    return function_exists('t') ? t($label) : $label;
}

function project_cards_for_board($board_id): array
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_cards_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT * FROM project_cards WHERE board_id = ? ORDER BY sort_order ASC, created_at DESC, id DESC",
        [$board_id]
    );
}

function project_cards_for_list($list_id): array
{
    $list_id = project_board_normalize_id($list_id);
    if ($list_id <= 0 || !project_cards_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT * FROM project_cards WHERE list_id = ? ORDER BY sort_order ASC, created_at DESC, id DESC",
        [$list_id]
    );
}

function project_card_get($card_id): ?array
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_cards_table_exists()) {
        return null;
    }

    $card = db_fetch_one("SELECT * FROM project_cards WHERE id = ?", [$card_id]);
    return $card ?: null;
}

/**
 * Group cards by list id for board rendering.
 */
function project_board_cards_model(array $lists, array $cards): array
{
    $by_list = [];
    foreach ($lists as $list) {
        $by_list[(int) ($list['id'] ?? 0)] = [];
    }

    foreach ($cards as $card) {
        $list_key = (int) ($card['list_id'] ?? 0);
        if (!isset($by_list[$list_key])) {
            continue;
        }
        $card['priority'] = project_priority_normalize((string) ($card['priority'] ?? ''));
        $by_list[$list_key][] = $card;
    }

    return $by_list;
}

function project_card_priority_badge_class(array $card, string $base = 'badge-inline ticket-priority-inline'): string
{
    return $base . ' ticket-priority-inline--' . project_priority_normalize((string) ($card['priority'] ?? ''));
}