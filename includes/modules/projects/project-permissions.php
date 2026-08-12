<?php
/**
 * Project module permissions.
 *
 * Projects are a staff surface: clients (role 'user') never see or use
 * them. Since Phase 2 (item 4), access is per board: admins have full
 * access and management rights everywhere; agents only see and edit boards
 * where they are members (the board creator is automatically a member).
 * Permission checks stay here so pages, API handlers and future native
 * clients share one model.
 */

function project_is_staff_user(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    return !empty($user) && in_array((string) ($user['role'] ?? ''), ['admin', 'agent'], true);
}

function project_can_view(?array $user = null): bool
{
    return project_is_staff_user($user);
}

function project_can_manage(?array $user = null): bool
{
    return project_is_staff_user($user);
}

/**
 * Per-board visibility: admins bypass membership; agents must be members.
 */
function project_can_view_board(?array $board, ?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!project_is_staff_user($user)) {
        return false;
    }
    if (in_array((string) ($user['role'] ?? ''), ['admin'], true)) {
        return true;
    }

    $board_id = (int) ($board['id'] ?? 0);
    return $board_id > 0 && project_board_is_member($board_id, (int) ($user['id'] ?? 0));
}

/**
 * Per-board management: same gate as visibility for staff (agents manage
 * boards they are members of; admins manage everything).
 */
function project_can_manage_board(?array $board, ?array $user = null): bool
{
    return project_can_view_board($board, $user);
}

/**
 * Only admins manage board membership (product decision 2026-08-12).
 */
function project_board_admin_can_manage_members(?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    return project_is_staff_user($user) && in_array((string) ($user['role'] ?? ''), ['admin'], true);
}

/**
 * Membership read model.
 */
function project_board_is_member(int $board_id, ?int $user_id): bool
{
    $board_id = project_board_normalize_id($board_id);
    $user_id = max(0, (int) $user_id);
    if ($board_id <= 0 || $user_id <= 0 || !project_board_members_table_exists()) {
        return false;
    }

    return (bool) db_fetch_one(
        "SELECT 1 FROM project_board_members WHERE board_id = ? AND user_id = ?",
        [$board_id, $user_id]
    );
}

/**
 * Staff rows for a board's members, ordered for the member strip.
 */
function project_board_members_for_board($board_id): array
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_members_table_exists()) {
        return [];
    }

    return db_fetch_all(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.role
         FROM project_board_members m
         JOIN users u ON u.id = m.user_id
         WHERE m.board_id = ?
         ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC",
        [$board_id]
    );
}

/**
 * Staff users not yet members of the board (admin picker candidates).
 */
function project_board_member_candidates($board_id, ?int $limit = 50): array
{
    $board_id = project_board_normalize_id($board_id);
    if ($board_id <= 0 || !project_board_members_table_exists() || !function_exists('get_all_users')) {
        return [];
    }

    $members = [];
    foreach (project_board_members_for_board($board_id) as $member) {
        $members[(int) ($member['id'] ?? 0)] = true;
    }

    $candidates = [];
    foreach (get_all_users() as $candidate) {
        if (!in_array((string) ($candidate['role'] ?? ''), ['admin', 'agent'], true)) {
            continue;
        }
        if (isset($members[(int) ($candidate['id'] ?? 0)])) {
            continue;
        }
        $candidates[] = [
            'id' => (int) ($candidate['id'] ?? 0),
            'name' => trim((string) (($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))) ?: (string) ($candidate['email'] ?? ''),
        ];
        if ($limit > 0 && count($candidates) >= $limit) {
            break;
        }
    }
    return $candidates;
}

/**
 * Add a staff member to a board (idempotent). Validates the target user.
 */
function project_board_member_add(int $board_id, int $user_id): bool
{
    $board_id = project_board_normalize_id($board_id);
    $user_id = project_board_normalize_id($user_id);
    if ($board_id <= 0 || !project_board_get($board_id)) {
        throw new InvalidArgumentException(project_validation_message('Project not found.'));
    }
    if ($user_id <= 0) {
        throw new InvalidArgumentException(project_validation_message('Invalid member.'));
    }
    if (!project_board_members_table_exists()) {
        return false;
    }

    $user = function_exists('get_user') ? get_user($user_id) : null;
    if (!$user || !in_array((string) ($user['role'] ?? ''), ['admin', 'agent'], true)) {
        throw new InvalidArgumentException(project_validation_message('Invalid member.'));
    }

    db_query(
        "INSERT IGNORE INTO project_board_members (board_id, user_id) VALUES (?, ?)",
        [$board_id, $user_id]
    );
    return true;
}

/**
 * Remove a member. The last member of a board can never be removed (the
 * board would become invisible to its agents).
 */
function project_board_member_remove(int $board_id, int $user_id): bool
{
    $board_id = project_board_normalize_id($board_id);
    $user_id = project_board_normalize_id($user_id);
    if ($board_id <= 0 || $user_id <= 0 || !project_board_members_table_exists()) {
        return false;
    }
    if (!project_board_is_member($board_id, $user_id)) {
        return false;
    }

    $count_row = db_fetch_one(
        "SELECT COUNT(*) AS total FROM project_board_members WHERE board_id = ?",
        [$board_id]
    );
    if ((int) ($count_row['total'] ?? 0) <= 1) {
        throw new InvalidArgumentException(project_validation_message('At least one member is required.'));
    }

    db_delete('project_board_members', 'board_id = ? AND user_id = ?', [$board_id, $user_id]);
    return true;
}

function project_requires_staff_redirect(): void
{
    if (project_can_view()) {
        return;
    }

    $home = function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'work';
    header('Location: index.php?page=' . $home);
    exit;
}

function project_active_tab(): string
{
    return 'projects';
}