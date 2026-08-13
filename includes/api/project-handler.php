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

/**
 * Membership management is admin-only (product decision 2026-08-12).
 */
function api_project_require_admin_post(): void
{
    if (!project_board_admin_can_manage_members()) {
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
 * Expects: id (optional), name, description (optional), color (optional),
 * template (optional; create mode only — blank | development).
 */
function api_project_board_save()
{
    api_project_require_staff_post();

    if (function_exists('ensure_project_governance_tables')) {
        ensure_project_governance_tables();
    }

    $input = get_json_input();
    $name = api_project_input_string($input, 'name');
    $description = api_project_input_string($input, 'description');
    $color = api_project_input_string($input, 'color');
    $template = api_project_input_string($input, 'template');
    $organization_id = project_board_normalize_id($input['organization_id'] ?? 0);

    if ($organization_id > 0 && function_exists('can_user_use_organization') && !can_user_use_organization($organization_id)) {
        api_error('Forbidden', 403);
    }

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        if ($id > 0) {
            if (!project_board_update($id, $name, $description, $color)) {
                api_error('Project not found', 404);
            }
            api_success(['id' => $id, 'name' => $name]);
        }

        $user = current_user();
        $new_id = project_board_create($name, $description, $color, (int) ($user['id'] ?? 0), $template, $organization_id);
        api_success(['id' => $new_id, 'name' => $name]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Add a staff member to a board (admin only).
 * Expects: board_id, user_id.
 */
function api_project_board_member_add()
{
    api_project_require_admin_post();

    $input = get_json_input();
    $board_id = project_board_normalize_id($input['board_id'] ?? 0);
    $user_id = project_board_normalize_id($input['user_id'] ?? 0);

    try {
        project_board_member_add($board_id, $user_id);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }

    api_success();
}

/**
 * Remove a member from a board (admin only; the last member is protected).
 * Expects: board_id, user_id.
 */
function api_project_board_member_remove()
{
    api_project_require_admin_post();

    $input = get_json_input();
    $board_id = project_board_normalize_id($input['board_id'] ?? 0);
    $user_id = project_board_normalize_id($input['user_id'] ?? 0);

    try {
        if (!project_board_member_remove($board_id, $user_id)) {
            api_error('Member not found', 404);
        }
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }

    api_success();
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

/**
 * Archive or restore a card (own archived-column model, Phase 2 item 4).
 * Expects: id, archived (0|1). Gated on the card's board permission.
 */
function api_project_card_archive()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);
    $archived = !empty($input['archived']);

    $card = project_card_get($id);
    if (!$card) {
        api_error('Card not found', 404);
    }
    if (!project_can_manage_board(project_board_get((int) ($card['board_id'] ?? 0)))) {
        api_error('Forbidden', 403);
    }

    if (!project_card_set_archived($id, $archived)) {
        api_error('Card not found', 404);
    }

    api_success(['id' => $id, 'archived' => $archived ? 1 : 0]);
}

/**
 * Card detail view model (read-only; GET is allowed for staff).
 * Expects: id.
 */
function api_project_card_detail()
{
    if (!project_can_manage()) {
        api_error('Forbidden', 403);
    }

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    $model = project_card_detail_model($id);
    if ($model === null) {
        api_error('Card not found', 404);
    }

    api_success($model);
}

/**
 * Create or update a card comment (internal by construction).
 * Expects: id (optional), card_id (on create), body.
 * The author edits their own comment; the author or an admin may delete.
 */
function api_project_card_comment_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $body = api_project_input_string($input, 'body');

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        $user = current_user();

        if ($id > 0) {
            $comment = project_card_comment_get($id);
            if (!$comment) {
                api_error('Comment not found', 404);
            }
            if (!project_card_comment_can_modify($comment, $user)) {
                api_error('Forbidden', 403);
            }
            if (!project_card_comment_update($id, $body)) {
                api_error('Comment not found', 404);
            }
            api_success(['id' => $id]);
        }

        $card_id = project_board_normalize_id($input['card_id'] ?? 0);
        $new_id = project_card_comment_create($card_id, (int) ($user['id'] ?? 0), $body);
        api_success(['id' => $new_id]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Delete a card comment (author or admin).
 * Expects: id.
 */
function api_project_card_comment_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    $comment = project_card_comment_get($id);
    if (!$comment) {
        api_error('Comment not found', 404);
    }
    if (!project_card_comment_can_modify($comment, current_user(), true)) {
        api_error('Forbidden', 403);
    }

    if (!project_card_comment_delete($id)) {
        api_error('Comment not found', 404);
    }

    api_success();
}

/**
 * Create or update a card checklist.
 * Expects: id (optional), card_id (on create), name.
 */
function api_project_card_checklist_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $name = api_project_input_string($input, 'name');

    try {
        $id = project_board_normalize_id($input['id'] ?? 0);
        if ($id > 0) {
            if (!project_card_checklist_update($id, $name)) {
                api_error('Checklist not found', 404);
            }
            api_success(['id' => $id, 'name' => $name]);
        }

        $card_id = project_board_normalize_id($input['card_id'] ?? 0);
        $new_id = project_card_checklist_create($card_id, $name);
        api_success(['id' => $new_id, 'name' => $name]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Delete a card checklist (cascades items).
 * Expects: id.
 */
function api_project_card_checklist_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_card_checklist_delete($id)) {
        api_error('Checklist not found', 404);
    }

    api_success();
}

/**
 * Add an item to a card checklist.
 * Expects: checklist_id, name.
 */
function api_project_card_checklist_item_save()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $name = api_project_input_string($input, 'name');

    try {
        $checklist_id = project_board_normalize_id($input['checklist_id'] ?? 0);
        $new_id = project_card_checklist_item_create($checklist_id, $name);
        api_success(['id' => $new_id, 'name' => $name]);
    } catch (InvalidArgumentException $e) {
        api_error($e->getMessage());
    }
}

/**
 * Toggle a checklist item.
 * Expects: id, is_checked (0|1).
 */
function api_project_card_checklist_item_toggle()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);
    $is_checked = !empty($input['is_checked']);

    if (!project_card_checklist_item_toggle($id, $is_checked)) {
        api_error('Checklist item not found', 404);
    }

    api_success(['id' => $id, 'is_checked' => $is_checked ? 1 : 0]);
}

/**
 * Delete a checklist item.
 * Expects: id.
 */
function api_project_card_checklist_item_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_card_checklist_item_delete($id)) {
        api_error('Checklist item not found', 404);
    }

    api_success();
}

/**
 * Delete a card attachment (row + file).
 * Expects: id.
 */
function api_project_card_attachment_delete()
{
    api_project_require_staff_post();

    $input = get_json_input();
    $id = project_board_normalize_id($input['id'] ?? 0);

    if (!project_card_attachment_delete($id)) {
        api_error('Attachment not found', 404);
    }

    api_success();
}