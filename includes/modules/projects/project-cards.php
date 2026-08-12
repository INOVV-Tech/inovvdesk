<?php
/**
 * Project cards: query and view model.
 *
 * Phase 0 ships the read model for a board's cards (grouped by list, with
 * priority helpers using the module's own key convention). Phase 1
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

function project_card_priority_badge_class(array $card, string $base = 'badge-inline project-priority-inline'): string
{
    return $base . ' project-priority-inline--' . project_priority_normalize((string) ($card['priority'] ?? ''));
}

function project_card_validate_title($title): string
{
    $title = trim((string) $title);
    if ($title === '') {
        throw new InvalidArgumentException(project_validation_message('Card title is required.'));
    }
    if (function_exists('mb_strlen') ? mb_strlen($title) > 255 : strlen($title) > 255) {
        throw new InvalidArgumentException(project_validation_message('Card title is too long.'));
    }
    return $title;
}

function project_card_normalize_due_date($due_date): ?string
{
    $due_date = trim((string) $due_date);
    if ($due_date === '') {
        return null;
    }

    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
    foreach ($formats as $format) {
        $parsed = DateTime::createFromFormat($format, $due_date);
        if ($parsed !== false) {
            if ($format === 'Y-m-d') {
                $parsed->setTime(0, 0, 0);
            }
            return $parsed->format('Y-m-d H:i:s');
        }
    }

    throw new InvalidArgumentException(project_validation_message('Invalid due date.'));
}

function project_card_validate_assignee($assignee_id): ?int
{
    $assignee_id = project_board_normalize_id($assignee_id);
    if ($assignee_id <= 0) {
        return null;
    }

    $assignee = function_exists('get_user') ? get_user($assignee_id) : null;
    if (!$assignee || !in_array((string) ($assignee['role'] ?? ''), ['admin', 'agent'], true)) {
        throw new InvalidArgumentException(project_validation_message('Invalid assignee.'));
    }
    return $assignee_id;
}

/**
 * Staff users available as card assignees.
 */
function project_assignee_options(): array
{
    if (!function_exists('get_all_users')) {
        return [];
    }

    $options = [];
    foreach (get_all_users() as $candidate) {
        if (!in_array((string) ($candidate['role'] ?? ''), ['admin', 'agent'], true)) {
            continue;
        }
        $options[] = [
            'id' => (int) ($candidate['id'] ?? 0),
            'name' => trim((string) (($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))) ?: (string) ($candidate['email'] ?? ''),
        ];
    }
    return $options;
}

function project_card_create(int $board_id, int $list_id, string $title, ?string $description, ?int $assignee_id, $due_date, $priority, int $created_by, ?int $sort_order = null): int
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        throw new InvalidArgumentException(project_validation_message('Project not found.'));
    }
    if (!project_list_belongs_to_board($list_id, $board_id)) {
        throw new InvalidArgumentException(project_validation_message('Invalid list.'));
    }

    $title = project_card_validate_title($title);
    $assignee_id = project_card_validate_assignee($assignee_id);
    $due_date = project_card_normalize_due_date($due_date);
    $priority = project_priority_normalize($priority);
    $created_by = project_board_normalize_id($created_by);
    if ($created_by <= 0) {
        throw new InvalidArgumentException(project_validation_message('Card owner is required.'));
    }

    $sort_order = $sort_order ?? project_card_next_sort_order($list_id);

    return (int) db_insert('project_cards', [
        'board_id' => $board_id,
        'list_id' => $list_id,
        'title' => $title,
        'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
        'assignee_id' => $assignee_id,
        'due_date' => $due_date,
        'priority' => $priority,
        'sort_order' => max(0, (int) $sort_order),
        'created_by' => $created_by,
    ]);
}

function project_card_next_sort_order(int $list_id): int
{
    $list_id = project_board_normalize_id($list_id);
    $row = db_fetch_one(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM project_cards WHERE list_id = ?",
        [$list_id]
    );
    return (int) ($row['next_order'] ?? 0);
}

function project_card_update(int $card_id, string $title, ?string $description, ?int $assignee_id, $due_date, $priority): bool
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_get($card_id)) {
        return false;
    }

    db_update('project_cards', [
        'title' => project_card_validate_title($title),
        'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
        'assignee_id' => project_card_validate_assignee($assignee_id),
        'due_date' => project_card_normalize_due_date($due_date),
        'priority' => project_priority_normalize($priority),
    ], 'id = ?', [$card_id]);

    return true;
}

/**
 * Move a card to another list (or reorder within the same list).
 *
 * $order must be the full ordered card id list of the target list after the
 * move; the target list is renumbered from 1. Other lists keep their order
 * (gaps are fine because sorting falls back to created_at).
 */
function project_card_move(int $card_id, int $to_list_id, array $order): bool
{
    $card = project_card_get($card_id);
    if (!$card) {
        return false;
    }

    $board_id = (int) ($card['board_id'] ?? 0);
    if (!project_list_belongs_to_board($to_list_id, $board_id)) {
        return false;
    }

    db_update('project_cards', ['list_id' => $to_list_id], 'id = ?', [$card_id]);

    $position = 1;
    foreach ($order as $ordered_card_id) {
        $ordered_card_id = project_board_normalize_id($ordered_card_id);
        if ($ordered_card_id <= 0) {
            continue;
        }
        // Only renumber cards that belong to the target list of the same board.
        $ordered_card = project_card_get($ordered_card_id);
        if (!$ordered_card || (int) ($ordered_card['board_id'] ?? 0) !== $board_id) {
            continue;
        }
        db_update('project_cards', ['list_id' => $to_list_id, 'sort_order' => $position], 'id = ?', [$ordered_card_id]);
        $position++;
    }

    return true;
}

function project_card_delete(int $card_id): bool
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_get($card_id)) {
        return false;
    }

    db_delete('project_cards', 'id = ?', [$card_id]);
    return true;
}

function project_card_belongs_to_board(int $card_id, int $board_id): bool
{
    $card = project_card_get($card_id);
    return $card !== null && (int) ($card['board_id'] ?? 0) === $board_id;
}