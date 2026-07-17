<?php

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

foreach ([
    'pages/admin/migration-export.php',
    'includes/migration-functions.php',
    'bin/sync-to-cloud.php',
    'SELF_HOSTED_TO_SAAS_MIGRATION.md',
] as $removed_path) {
    $assert(!file_exists($root . '/' . $removed_path), 'Removed cloud migration file is present: ' . $removed_path);
}

$version = json_decode((string) file_get_contents($root . '/version.json'), true);
$delete_files = is_array($version) ? (array) ($version['delete_files'] ?? []) : [];
foreach ([
    'pages/admin/migration-export.php',
    'includes/migration-functions.php',
    'bin/sync-to-cloud.php',
    'SELF_HOSTED_TO_SAAS_MIGRATION.md',
] as $obsolete_path) {
    $assert(in_array($obsolete_path, $delete_files, true), 'Updater must delete obsolete cloud migration file: ' . $obsolete_path);
}

foreach ([
    'index.php',
    'includes/header.php',
    'includes/functions.php',
    'includes/email-ingest-functions.php',
    'bin/ingest-emails.php',
    'bin/run-maintenance.php',
] as $runtime_path) {
    $contents = file_get_contents($root . '/' . $runtime_path);
    $assert($contents !== false, 'Unable to read runtime file: ' . $runtime_path);
    $assert(!str_contains($contents, 'migration-export'), 'Cloud migration route remains in ' . $runtime_path);
    $assert(!str_contains($contents, 'migration_cloud_'), 'Cloud migration behavior remains in ' . $runtime_path);
    $assert(!str_contains($contents, 'migration-functions.php'), 'Cloud migration include remains in ' . $runtime_path);
}

echo "Cloud migration removal contract OK\n";
