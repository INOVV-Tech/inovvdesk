<?php
/**
 * API Handler: Project boards, lists and cards.
 *
 * Staff-only surface (agents and admins). Every write requires POST + CSRF,
 * with optimistic DOM updates and revert on error handled in project-board.js.
 */

function api_project_require_staff_post(): void
{
    if (!project_can_manage()) {
        api_error('Forbidden', 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
    require_csrf_token(true);
}

function api_project_input_string(array $input, string $key): string
{
    return trim((string) ($input[$key] ?? ''));
}

/**
 * Create or update a board.
 * Expects: id (optional), name, description (optional), color (optional).
 */
function api_project_board_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $name = api_project_input_string($input, 'name');
    $description = api_project_input_string($input, 'description');
    $color = api_project_input_string($input, 'color');

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        if ($id > 0) {
            if (!project_board_update($id, $name, $description, $color)) {
                api_error('Project not found', 404);
            }
            api_success(['id' => $id, 'name' => $name]);
        }

        $user = current_user();
        $new_id = project_board_create($name, $description, $color, (int) ($user['id'] ?? 0));
        api_success(['id' => $new_id, 'name' => $name]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Archive / unarchive a board.
 * Expects: id, archived (0|1).
 */
function api_project_board_archive()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);
    $archived = !empty($input['archived']);

    if (!project_board_set_archived($id, $archived)) {
        api_error('Project not found', 404);
    }

    api_success();
}

/**
 * Delete a board (cascades lists and cards).
 * Expects: id.
 */
function api_project_board_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_board_delete($id)) {
        api_error('Project not found', 404);
    }

    api_success();
}

/**
 * Create or update a list.
 * Expects: id (optional), board_id (on create), name.
 */
function api_project_list_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $name = api_project_input_string($input, 'name');

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        if ($id > 0) {
            if (!project_list_update($id, $name)) {
                api_error('List not found', 404);
            }
            api_success(['id' => $id, 'name' => $name]);
        }

        $board_id = project_board_normalize_id($input['board_id'] ?? 0);
        $new_id = project_list_create($board_id, $name);
        api_success(['id' => $new_id, 'name' => $name]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Delete a list (cascades cards).
 * Expects: id.
 */
function api_project_list_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_list_delete($id)) {
        api_error('List not found', 404);
    }

    api_success();
}

/**
 * Reorder lists of a board.
 * Expects: board_id, order (array of list ids).
 */
function api_project_list_reorder()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $board_id = project_board_normalize_id($input['board_id'] ?? 0);
    $order = is_array($input['order'] ?? null) ? $input['order'] : [];

    if (!project_lists_reorder($board_id, $order)) {
        api_error('Invalid data');
    }

    api_success();
}

/**
 * Create or update a card.
 * Expects: id (optional), board_id/list_id (on create), title,
 * description (optional), assignee_id (optional), due_date (optional),
 * priority (optional).
 */
function api_project_card_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $title = api_project_input_string($input, 'title');
    $description = api_project_input_string($input, 'description');
    $priority = api_project_input_string($input, 'priority');
    $due_date = api_project_input_string($input, 'due_date');
    $assignee_id = project_board_normalize_id($input['assignee_id'] ?? 0) ?: null;

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        if ($id > 0) {
            if (!project_card_update($id, $title, $description, $assignee_id, $due_date, $priority)) {
                api_error('Card not found', 404);
            }
            api_success(['id' => $id, 'title' => $title]);
        }

        $board_id = project_board_normalize_id($input['board_id'] ?? 0);
        $list_id = project_board_normalize_id($input['list_id'] ?? 0);
        $user = current_user();
        $new_id = project_card_create(
            $board_id,
            $list_id,
            $title,
            $description,
            $assignee_id,
            $due_date,
            $priority,
            (int) ($user['id'] ?? 0)
        );
        api_success(['id' => $new_id, 'title' => $title]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Move a card between lists (or reorder inside a list).
 * Expects: card_id, to_list_id, order (array of card ids in the target list
 * after the move).
 */
function api_project_card_move()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $card_id = project_board_normalize_id($input['card_id'] ?? 0);
    $to_list_id = project_board_normalize_id($input['to_list_id'] ?? 0);
    $order = is_array($input['order'] ?? null) ? $input['order'] : [];

    if (!project_card_move($card_id, $to_list_id, $order)) {
        api_error('Invalid move');
    }

    api_success();
}

/**
 * Delete a card.
 * Expects: id.
 */
function api_project_card_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_card_delete($id)) {
        api_error('Card not found', 404);
    }

    api_success();
}