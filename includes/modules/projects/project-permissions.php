<?php
/**
 * Project module permissions.
 *
 * Projects are a staff surface: clients (role 'user') never see or use
 * them. Admins have full access; agents have full access to all boards
 * until per-board membership lands post-MVP. Permission checks stay here
 * so pages, API handlers and future native clients share one model.
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