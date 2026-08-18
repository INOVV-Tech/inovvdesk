<?php
$root = dirname(__DIR__);
$required = [
    'includes/modules/initiatives/initiative-schema.php',
    'includes/modules/initiatives/initiative-permissions.php',
    'includes/modules/initiatives/initiative-financial.php',
    'includes/modules/initiatives/initiative-workflow.php',
    'includes/modules/initiatives/initiative-service.php',
    'includes/modules/initiatives/initiative-governance.php',
    'includes/modules/initiatives/initiative-portfolio.php',
    'includes/modules/initiatives/initiative-settings.php',
    'pages/initiatives.php', 'pages/initiative.php', 'pages/initiative-form.php',
    'pages/admin/initiatives.php', 'includes/api/initiative-handler.php',
];
foreach ($required as $file) if (!is_file($root . '/' . $file)) throw new RuntimeException("Missing {$file}");

$router = file_get_contents($root . '/includes/api/router.php');
foreach (['initiatives','initiative-get','initiative-create','initiative-update','initiative-transition','initiative-approve','initiative-evaluate','initiative-measure','initiative-triage','initiative-homologate','initiative-rollout','initiative-portfolio'] as $action) {
    if (!str_contains($router, "'{$action}'")) throw new RuntimeException("Missing API route {$action}");
}
$schema = file_get_contents($root . '/includes/modules/initiatives/initiative-schema.php');
foreach (['initiatives','initiative_status_transitions','initiative_financial_snapshots','initiative_ticket_links','initiative_evaluations','initiative_events'] as $table) {
    if (!str_contains($schema, "'{$table}'")) throw new RuntimeException("Missing table {$table}");
}
$detail = file_get_contents($root . '/pages/initiative.php');
foreach (['fd-card','fd-button','fd-input','fd-select','fd-table','fd-badge'] as $primitive) if (!str_contains($detail,$primitive)) throw new RuntimeException("Missing UI primitive {$primitive}");

$admin = file_get_contents($root . '/pages/admin/initiatives.php');
$approvals = file_get_contents($root . '/pages/admin/initiative-approvals.php');
if (str_contains($admin, "includes/components/admin-nav.php") || str_contains($approvals, "includes/components/admin-nav.php")) {
    throw new RuntimeException('Initiative settings must not render the legacy admin navigation panel.');
}
foreach (['name="icon"', 'type="radio"'] as $needle) {
    if (!str_contains($admin, $needle)) throw new RuntimeException("Initiative settings helper missing: {$needle}");
}
foreach (['data-icon-picker', 'required_fields[]', 'edit_type', 'delete_category', 'initiative-org-sort', 'reorder_org_units', 'delete_status', 'delete_transition', 'measurement_checkpoints[]', 'checkpoint-chips', 'Committee versus organizational structure', 'Agent permissions'] as $needle) {
    if (!str_contains($admin, $needle)) throw new RuntimeException("Initiative settings CRUD/UX contract missing: {$needle}");
}
foreach (['accent-indigo-600', 'editModal', "t((string) \$status['name'])"] as $needle) {
    if (!str_contains($admin, $needle)) throw new RuntimeException("Initiative settings selection/modal/translation contract missing: {$needle}");
}
foreach (['flex flex-col overflow-hidden shadow-2xl', "body.className = 'p-5 overflow-y-auto'", 'focus({preventScroll: true})'] as $needle) {
    if (!str_contains($admin, $needle)) throw new RuntimeException("Initiative settings modal layout contract missing: {$needle}");
}
foreach (['edit_rule', 'initiative_approval_action', 'value="delete"', 'data-server-edit-modal', 'Edit approval rule'] as $needle) {
    if (!str_contains($approvals, $needle)) throw new RuntimeException("Initiative approval rule CRUD/modal contract missing: {$needle}");
}
$settingsModule = file_get_contents($root . '/includes/modules/initiatives/initiative-settings.php');
foreach (['initiative_admin_delete_type', 'initiative_admin_delete_category', 'initiative_admin_delete_org_unit', 'initiative_admin_reorder_org_units', 'initiative_admin_delete_status', 'initiative_admin_delete_committee', 'initiative_admin_delete_criterion'] as $function) {
    if (!str_contains($settingsModule, "function {$function}")) throw new RuntimeException("Initiative settings operation missing: {$function}");
}
$permissionsModule = file_get_contents($root . '/includes/modules/initiatives/initiative-permissions.php');
if (!str_contains($permissionsModule, "!is_admin()")) throw new RuntimeException('Only administrators may manage granular initiative permissions.');
$portfolio = file_get_contents($root . '/pages/initiatives.php');
if (!str_contains($portfolio, "initiative_action']??'')==='delete'") || !str_contains($portfolio, "url('initiative-form'")) throw new RuntimeException('Portfolio must expose easy edit and remove actions.');
$service = file_get_contents($root . '/includes/modules/initiatives/initiative-service.php');
if (!str_contains($service, 'u.id=t.assignee_id') || str_contains($service, 'u.id=t.assigned_to')) {
    throw new RuntimeException('Initiative ticket details must use the canonical tickets.assignee_id column.');
}

echo "initiative-module-contract-test: ok\n";
