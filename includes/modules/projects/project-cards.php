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
    $card['priority_label'] = project_priority_label($card['priority']);
    $card['due_display'] = ((string) ($card['due_date'] ?? '') !== '')
        ? (function_exists('format_date') ? format_date($card['due_date']) : (string) $card['due_date'])
        : '';
    $assignee_name = '';
    if ((int) ($card['assignee_id'] ?? 0) > 0 && function_exists('get_user')) {
        $assignee = get_user((int) $card['assignee_id']);
        if ($assignee) {
            $assignee_name = function_exists('user_avatar_display_name')
                ? user_avatar_display_name($assignee)
                : trim((string) (($assignee['first_name'] ?? '') . ' ' . ($assignee['last_name'] ?? '')));
            if ($assignee_name === '') {
                $assignee_name = trim((string) ($assignee['email'] ?? ''));
            }
        }
    }
    $card['assignee_name'] = $assignee_name;
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

/**
 * Project card detail support (Phase 2): comments, checklists, attachments.
 * All storage lives in module-owned project_card_* tables; cards never
 * touch the ticket attachments/comments tables.
 */

function project_card_preview_description(?string $description): string
{
    $description = trim((string) $description);
    if ($description === '') {
        return '';
    }
    $flattened = preg_replace(
        '/<\/?(?:p|div|li|h[1-6]|ul|ol|blockquote|pre|br)[^>]*>/i',
        ' ',
        $description
    ) ?? $description;
    $plain = trim(strip_tags($flattened));
    return preg_replace('/\s+/u', ' ', $plain) ?: '';
}

function project_comment_validate_body($body): string
{
    $body = trim((string) $body);
    if ($body === '') {
        throw new InvalidArgumentException(project_validation_message('Comment body is required.'));
    }
    return $body;
}

function project_card_comment_create(int $card_id, int $author_id, string $body): int
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_get($card_id)) {
        throw new InvalidArgumentException(project_validation_message('Card not found.'));
    }
    $author_id = project_board_normalize_id($author_id);
    if ($author_id <= 0) {
        throw new InvalidArgumentException(project_validation_message('Comment author is required.'));
    }
    $body = project_comment_validate_body($body);

    return (int) db_insert('project_card_comments', [
        'card_id' => $card_id,
        'author_id' => $author_id,
        'body' => $body,
    ]);
}

function project_card_comment_update(int $comment_id, string $body): bool
{
    $comment_id = project_board_normalize_id($comment_id);
    if ($comment_id <= 0 || !project_card_comment_get($comment_id)) {
        return false;
    }

    db_update('project_card_comments', ['body' => project_comment_validate_body($body)], 'id = ?', [$comment_id]);
    return true;
}

function project_card_comment_delete(int $comment_id): bool
{
    $comment_id = project_board_normalize_id($comment_id);
    if ($comment_id <= 0 || !project_card_comment_get($comment_id)) {
        return false;
    }

    db_delete('project_card_comments', 'id = ?', [$comment_id]);
    return true;
}

/**
 * Comments are internal by construction (the module is staff-only).
 * The author edits their own comment; author or admin may delete.
 */
function project_card_comment_can_modify(array $comment, ?array $user, bool $for_delete = false): bool
{
    if (!$user || empty($user['id'])) {
        return false;
    }
    if ((int) ($comment['author_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return true;
    }
    if ($for_delete && in_array((string) ($user['role'] ?? ''), ['admin'], true)) {
        return true;
    }
    return false;
}

function project_card_comment_get(int $comment_id): ?array
{
    $comment_id = project_board_normalize_id($comment_id);
    if ($comment_id <= 0 || !project_card_comments_table_exists()) {
        return null;
    }

    $comment = db_fetch_one("SELECT * FROM project_card_comments WHERE id = ?", [$comment_id]);
    return $comment ?: null;
}

function project_card_comments_for_card(int $card_id): array
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_comments_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT c.*, u.first_name, u.last_name, u.email AS author_email
         FROM project_card_comments c
         LEFT JOIN users u ON u.id = c.author_id
         WHERE c.card_id = ?
         ORDER BY c.created_at ASC, c.id ASC",
        [$card_id]
    );
}

function project_card_checklist_validate_name($name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        throw new InvalidArgumentException(project_validation_message('Checklist name is required.'));
    }
    if (function_exists('mb_strlen') ? mb_strlen($name) > 255 : strlen($name) > 255) {
        throw new InvalidArgumentException(project_validation_message('Checklist name is too long.'));
    }
    return $name;
}

function project_card_checklist_create(int $card_id, string $name): int
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_get($card_id)) {
        throw new InvalidArgumentException(project_validation_message('Card not found.'));
    }
    $name = project_card_checklist_validate_name($name);

    return (int) db_insert('project_card_checklists', [
        'card_id' => $card_id,
        'name' => $name,
        'sort_order' => project_card_checklist_next_sort_order($card_id),
    ]);
}

function project_card_checklist_update(int $checklist_id, string $name): bool
{
    $checklist_id = project_board_normalize_id($checklist_id);
    if ($checklist_id <= 0 || !project_card_checklist_get($checklist_id)) {
        return false;
    }

    db_update('project_card_checklists', ['name' => project_card_checklist_validate_name($name)], 'id = ?', [$checklist_id]);
    return true;
}

function project_card_checklist_delete(int $checklist_id): bool
{
    $checklist_id = project_board_normalize_id($checklist_id);
    if ($checklist_id <= 0 || !project_card_checklist_get($checklist_id)) {
        return false;
    }

    db_delete('project_card_checklists', 'id = ?', [$checklist_id]);
    return true;
}

function project_card_checklist_get(int $checklist_id): ?array
{
    $checklist_id = project_board_normalize_id($checklist_id);
    if ($checklist_id <= 0 || !project_card_checklists_table_exists()) {
        return null;
    }

    $checklist = db_fetch_one("SELECT * FROM project_card_checklists WHERE id = ?", [$checklist_id]);
    return $checklist ?: null;
}

function project_card_checklist_next_sort_order(int $card_id): int
{
    $card_id = project_board_normalize_id($card_id);
    $row = db_fetch_one(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM project_card_checklists WHERE card_id = ?",
        [$card_id]
    );
    return (int) ($row['next_order'] ?? 0);
}

/**
 * Progress of a checklist item set: ['done' => n, 'total' => m].
 */
function project_card_checklist_progress(array $items): array
{
    $total = count($items);
    $done = 0;
    foreach ($items as $item) {
        if (!empty($item['is_checked'])) {
            $done++;
        }
    }
    return ['done' => $done, 'total' => $total];
}

/**
 * Nest card checklist items into their checklists (pure view model).
 */
function project_checklists_model(array $checklists, array $items): array
{
    $by_checklist = [];
    foreach ($checklists as $checklist) {
        $by_checklist[(int) ($checklist['id'] ?? 0)] = $checklist;
    }

    foreach ($items as $item) {
        $checklist_key = (int) ($item['checklist_id'] ?? 0);
        if (!isset($by_checklist[$checklist_key])) {
            continue;
        }
        $by_checklist[$checklist_key]['items'][] = $item;
    }

    foreach ($by_checklist as $key => $checklist) {
        $by_checklist[$key]['items'] = $by_checklist[$key]['items'] ?? [];
        $by_checklist[$key]['progress'] = project_card_checklist_progress($by_checklist[$key]['items']);
    }

    return $by_checklist;
}

function project_card_checklists_for_card(int $card_id): array
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_checklists_table_exists()) {
        return [];
    }

    $checklists = db_fetch_all(
        "SELECT * FROM project_card_checklists WHERE card_id = ? ORDER BY sort_order ASC, id ASC",
        [$card_id]
    );
    if (!$checklists) {
        return [];
    }

    $items = db_fetch_all(
        "SELECT * FROM project_card_checklist_items
         WHERE checklist_id IN (SELECT id FROM project_card_checklists WHERE card_id = ?)
         ORDER BY sort_order ASC, id ASC",
        [$card_id]
    );

    $model = project_checklists_model($checklists, $items ?: []);
    return array_values($model);
}

function project_card_checklist_item_create(int $checklist_id, string $name): int
{
    $checklist_id = project_board_normalize_id($checklist_id);
    if ($checklist_id <= 0 || !project_card_checklist_get($checklist_id)) {
        throw new InvalidArgumentException(project_validation_message('Checklist not found.'));
    }
    $name = project_card_checklist_validate_name($name);

    return (int) db_insert('project_card_checklist_items', [
        'checklist_id' => $checklist_id,
        'name' => $name,
        'sort_order' => project_card_checklist_item_next_sort_order($checklist_id),
    ]);
}

function project_card_checklist_item_toggle(int $item_id, bool $is_checked): bool
{
    $item_id = project_board_normalize_id($item_id);
    if ($item_id <= 0 || !project_card_checklist_item_get($item_id)) {
        return false;
    }

    db_update('project_card_checklist_items', ['is_checked' => $is_checked ? 1 : 0], 'id = ?', [$item_id]);
    return true;
}

function project_card_checklist_item_delete(int $item_id): bool
{
    $item_id = project_board_normalize_id($item_id);
    if ($item_id <= 0 || !project_card_checklist_item_get($item_id)) {
        return false;
    }

    db_delete('project_card_checklist_items', 'id = ?', [$item_id]);
    return true;
}

function project_card_checklist_item_get(int $item_id): ?array
{
    $item_id = project_board_normalize_id($item_id);
    if ($item_id <= 0 || !project_card_checklist_items_table_exists()) {
        return null;
    }

    $item = db_fetch_one("SELECT * FROM project_card_checklist_items WHERE id = ?", [$item_id]);
    return $item ?: null;
}

function project_card_checklist_item_next_sort_order(int $checklist_id): int
{
    $checklist_id = project_board_normalize_id($checklist_id);
    $row = db_fetch_one(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM project_card_checklist_items WHERE checklist_id = ?",
        [$checklist_id]
    );
    return (int) ($row['next_order'] ?? 0);
}

function project_card_attachments_for_card(int $card_id): array
{
    $card_id = project_board_normalize_id($card_id);
    if ($card_id <= 0 || !project_card_attachments_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT a.*, u.first_name, u.last_name, u.email AS uploader_email
         FROM project_card_attachments a
         LEFT JOIN users u ON u.id = a.uploaded_by
         WHERE a.card_id = ?
         ORDER BY a.created_at ASC, a.id ASC",
        [$card_id]
    );
}

function project_card_attachment_delete(int $attachment_id): bool
{
    $attachment_id = project_board_normalize_id($attachment_id);
    if ($attachment_id <= 0 || !project_card_attachments_table_exists()) {
        return false;
    }

    $attachment = db_fetch_one("SELECT * FROM project_card_attachments WHERE id = ?", [$attachment_id]);
    if (!$attachment) {
        return false;
    }

    if (function_exists('delete_attachment_file')) {
        delete_attachment_file((string) ($attachment['filename'] ?? ''));
    }

    db_delete('project_card_attachments', 'id = ?', [$attachment_id]);
    return true;
}

/**
 * Full card detail view model: card + comments + checklists + attachments.
 */
function project_card_detail_model(int $card_id): ?array
{
    $card = project_card_get($card_id);
    if (!$card) {
        return null;
    }

    $card['priority'] = project_priority_normalize((string) ($card['priority'] ?? ''));

    $attachments = project_card_attachments_for_card($card_id);
    if (function_exists('attachment_download_url')) {
        foreach ($attachments as $key => $attachment) {
            $attachments[$key]['download_url'] = attachment_download_url($attachment);
        }
    }

    return [
        'card' => $card,
        'comments' => project_card_comments_for_card($card_id),
        'checklists' => project_card_checklists_for_card($card_id),
        'attachments' => $attachments,
    ];
}