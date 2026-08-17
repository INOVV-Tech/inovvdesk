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
foreach (['name="icon"', 'type="radio"', 'Optional identifier used internally.', 'Optional. Enter field keys separated by commas'] as $needle) {
    if (!str_contains($admin, $needle)) throw new RuntimeException("Initiative settings helper missing: {$needle}");
}

echo "initiative-module-contract-test: ok\n";
