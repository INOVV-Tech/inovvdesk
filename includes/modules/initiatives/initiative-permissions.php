<?php

function initiative_capabilities(): array
{
    return [
        'initiatives.view', 'initiatives.view_all', 'initiatives.create',
        'initiatives.edit_own', 'initiatives.edit_all', 'initiatives.delete',
        'initiatives.submit', 'initiatives.triage', 'initiatives.approve',
        'initiatives.manage_execution', 'initiatives.finance_validate',
        'initiatives.committee_evaluate', 'initiatives.homologate',
        'initiatives.manage_settings', 'initiatives.manage_workflow',
        'initiatives.manage_committee', 'initiatives.view_financial',
        'initiatives.export', 'initiatives.manage_portfolio',
    ];
}

function initiative_default_capabilities_for_role(string $role): array
{
    if ($role === 'admin') {
        return array_fill_keys(initiative_capabilities(), true);
    }
    if ($role === 'agent') {
        return array_fill_keys([
            'initiatives.view', 'initiatives.create', 'initiatives.edit_own',
            'initiatives.submit', 'initiatives.manage_execution',
        ], true);
    }
    return [];
}

function initiative_user_capabilities(?array $user = null): array
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user) return [];
    $capabilities = initiative_default_capabilities_for_role((string) ($user['role'] ?? ''));
    if (($user['role'] ?? '') === 'admin') return $capabilities;

    $permissions = function_exists('get_user_permissions') ? (get_user_permissions((int) $user['id']) ?? []) : [];
    $configured = $permissions['initiatives'] ?? [];
    if (is_array($configured)) {
        foreach ($configured as $capability => $allowed) {
            if (in_array($capability, initiative_capabilities(), true)) {
                $capabilities[$capability] = (bool) $allowed;
            }
        }
    }
    return $capabilities;
}

function initiative_can(string $capability, ?array $initiative = null, ?array $user = null): bool
{
    $user = $user ?? (function_exists('current_user') ? current_user() : null);
    if (!$user || !in_array($capability, initiative_capabilities(), true)) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    $allowed = !empty(initiative_user_capabilities($user)[$capability]);
    if (!$allowed) return false;
    if ($initiative && $capability === 'initiatives.view' && empty(initiative_user_capabilities($user)['initiatives.view_all'])) {
        $id = (int) ($user['id'] ?? 0);
        return in_array($id, [(int) ($initiative['author_id'] ?? 0), (int) ($initiative['owner_id'] ?? 0), (int) ($initiative['sponsor_id'] ?? 0)], true)
            || initiative_user_has_action((int) ($initiative['id'] ?? 0), $id);
    }
    if ($initiative && $capability === 'initiatives.edit_own') {
        $id = (int) ($user['id'] ?? 0);
        return in_array($id, [(int) ($initiative['author_id'] ?? 0), (int) ($initiative['owner_id'] ?? 0)], true);
    }
    return true;
}

function initiative_can_edit(array $initiative, ?array $user = null): bool
{
    return initiative_can('initiatives.edit_all', $initiative, $user)
        || initiative_can('initiatives.edit_own', $initiative, $user);
}

function initiative_user_has_action(int $initiative_id, int $user_id): bool
{
    if ($initiative_id <= 0 || $user_id <= 0) return false;
    $approval = db_fetch_one("SELECT 1 FROM initiative_approvals WHERE initiative_id = ? AND approver_id = ? AND decision = 'pending'", [$initiative_id, $user_id]);
    if ($approval) return true;
    return (bool) db_fetch_one(
        "SELECT 1 FROM initiatives i JOIN initiative_statuses s ON s.id=i.status_id
         JOIN initiative_committees c ON c.is_active=1
         JOIN initiative_committee_members m ON m.committee_id=c.id AND m.user_id=?
         WHERE i.id=? AND s.status_group='committee'
         AND NOT EXISTS (SELECT 1 FROM initiative_evaluations e WHERE e.initiative_id=i.id AND e.committee_id=c.id AND e.evaluator_id=m.user_id AND e.submitted_at IS NOT NULL) LIMIT 1",
        [$user_id, $initiative_id]
    );
}

function initiative_save_user_capabilities(int $user_id, array $selected): void
{
    if (!initiative_can('initiatives.manage_settings')) throw new RuntimeException('Forbidden');
    $row = db_fetch_one('SELECT permissions FROM users WHERE id = ?', [$user_id]);
    if (!$row) throw new InvalidArgumentException('User not found.');
    $permissions = json_decode((string) ($row['permissions'] ?? ''), true);
    if (!is_array($permissions)) $permissions = [];
    $permissions['initiatives'] = [];
    foreach (initiative_capabilities() as $capability) {
        $permissions['initiatives'][$capability] = in_array($capability, $selected, true);
    }
    db_update('users', ['permissions' => json_encode($permissions)], 'id = ?', [$user_id]);
}
