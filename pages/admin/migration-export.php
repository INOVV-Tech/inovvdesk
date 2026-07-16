<?php
if (!is_admin()) {
    flash(t('Access denied.'), 'error');
    redirect(function_exists('foxdesk_authenticated_home_page') ? foxdesk_authenticated_home_page() : 'dashboard');
}

$page_title = t('Cloud migration');
$page = 'admin';
$tenant_name = get_setting('app_name', 'FoxDesk');
$cloud_url = trim((string) get_setting('migration_cloud_url', 'https://app.foxdesk.net'));
$stored_token = trim((string) get_setting('migration_cloud_token', ''));
$cutover_target = trim((string) get_setting('migration_cloud_target_url', ''));
$migration_response = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'download_export') {
    try {
        migration_download_export_package();
    } catch (Throwable $e) {
        if (function_exists('debug_log')) {
            debug_log('Migration export failed', ['error' => $e->getMessage()], 'error', 'migration');
        }
        flash(t('Migration export failed: {error}', ['error' => $e->getMessage()]), 'error');
        redirect('admin', ['section' => 'migration-export']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'connect_cloud') {
    require_csrf_token();
    $cloud_url = trim((string) ($_POST['cloud_url'] ?? $cloud_url));
    $token = trim((string) ($_POST['migration_token'] ?? $stored_token));
    try {
        $migration_response = migration_cloud_connect($cloud_url, $token);
        $stored_token = $token;
        flash(t('FoxDesk Cloud connection verified.'), 'success');
    } catch (Throwable $e) {
        flash(t('Cloud connection failed: {error}', ['error' => $e->getMessage()]), 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'analyze_cloud') {
    require_csrf_token();
    $cloud_url = trim((string) ($_POST['cloud_url'] ?? $cloud_url));
    $token = trim((string) ($_POST['migration_token'] ?? $stored_token));
    try {
        $migration_response = migration_cloud_plan($cloud_url, $token);
        $stored_token = $token;
        flash(t('Migration plan refreshed.'), 'success');
    } catch (Throwable $e) {
        flash(t('Migration analysis failed: {error}', ['error' => $e->getMessage()]), 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['migration_action'] ?? ''), ['start_cloud_sync', 'run_cloud_sync_batch'], true)) {
    require_csrf_token();
    $cloud_url = trim((string) ($_POST['cloud_url'] ?? $cloud_url));
    $token = trim((string) ($_POST['migration_token'] ?? $stored_token));
    $restart = ($_POST['migration_action'] ?? '') === 'start_cloud_sync';
    try {
        $migration_response = migration_cloud_web_sync_run($cloud_url, $token, [
            'restart' => $restart,
            'row_limit' => (int) ($_POST['row_limit'] ?? 250),
            'attachment_limit' => (int) ($_POST['attachment_limit'] ?? 2),
        ]);
        $stored_token = $token;
        if (!empty($migration_response['done'])) {
            flash(t('Cloud sync finished. Verify the SaaS workspace before final cutover.'), 'success');
        } else {
            flash(t('Cloud sync batch completed. Continue until the stage is complete.'), 'success');
        }
    } catch (Throwable $e) {
        flash(t('Cloud sync batch failed: {error}', ['error' => $e->getMessage()]), 'error');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'clear_cloud_sync_state') {
    require_csrf_token();
    migration_cloud_clear_sync_state();
    flash(t('Saved cloud sync progress was cleared.'), 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['migration_action'] ?? '') === 'mark_cutover') {
    require_csrf_token();
    $target = trim((string) ($_POST['cutover_target_url'] ?? ''));
    if ($target === '') {
        flash(t('Cloud workspace URL is required before cutover.'), 'error');
    } else {
        migration_cloud_mark_cutover($target, ['source' => 'admin_page']);
        flash(t('Cutover completed. This self-hosted FoxDesk is no longer the active instance.'), 'success');
        header('Location: ' . $target);
        exit;
    }
}

$readiness = migration_export_readiness();
$counts = [];
$count_errors = [];
foreach (migration_export_tables() as $table) {
    if (!table_exists($table)) {
        continue;
    }

    try {
        $counts[$table] = migration_count_rows($table);
    } catch (Throwable $e) {
        $counts[$table] = 0;
        $count_errors[$table] = $e->getMessage();
    }
}

$attachment_count = (int) ($counts['attachments'] ?? 0);
$storage_bytes = 0;
if (table_exists('attachments') && column_exists('attachments', 'file_size')) {
    try {
        $storage_bytes = (int) (db_fetch_one("SELECT COALESCE(SUM(file_size), 0) AS bytes FROM attachments")['bytes'] ?? 0);
    } catch (Throwable $e) {
        $storage_bytes = 0;
        $count_errors['attachments_storage'] = $e->getMessage();
    }
}

$inventory = migration_inventory();
$last_plan = json_decode((string) get_setting('migration_cloud_last_plan_json', ''), true);
$last_plan = is_array($last_plan) ? $last_plan : null;
$display_plan = is_array($migration_response) && isset($migration_response['plan']) ? $migration_response : $last_plan;
$sync_state = migration_cloud_sync_state();
$sync_summary = is_array($sync_state['summary'] ?? null) ? $sync_state['summary'] : [];
$sync_attachment_summary = is_array($sync_summary['attachments'] ?? null) ? $sync_summary['attachments'] : [];
$sync_stage = (string) ($sync_state['stage'] ?? 'idle');
$sync_current_table = (string) ($sync_state['current_table'] ?? '');

require_once BASE_PATH . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
            <div>
                <p class="text-sm font-semibold text-blue-600 mb-2"><?php echo e(t('FoxDesk Cloud sync')); ?></p>
                <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo e(t('Sync to SaaS, then cut over once')); ?></h1>
                <p class="text-gray-600 max-w-3xl">
                    <?php echo e(t('Use this bridge for a controlled one-way migration. This self-hosted FoxDesk stays active during sync.')); ?>
                    <?php echo e(t('After final cutover, it redirects users to FoxDesk Cloud and disables local IMAP/email notification processing so only one instance remains active.')); ?>
                </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold bg-gray-100 text-gray-700">
                <?php echo e(t('Mode')); ?>: <?php echo e(migration_cloud_mode()); ?>
            </span>
        </div>

        <form method="post" class="grid lg:grid-cols-[1fr_1fr_auto_auto] gap-3 mt-6 items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="migration_action" value="analyze_cloud">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('FoxDesk Cloud URL')); ?></label>
                <input class="form-input" name="cloud_url" value="<?php echo e($cloud_url); ?>" placeholder="https://app.foxdesk.net">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('Migration token')); ?></label>
                <input class="form-input" name="migration_token" value="<?php echo e($stored_token); ?>" placeholder="fdmig_..." autocomplete="off">
            </div>
            <button class="btn btn-secondary" type="submit" name="migration_action" value="connect_cloud"><?php echo e(t('Connect')); ?></button>
            <button class="btn btn-primary" type="submit" name="migration_action" value="analyze_cloud"><?php echo e(t('Analyze sync')); ?></button>
        </form>

        <?php if ($display_plan && !empty($display_plan['plan'])): ?>
            <?php $plan = $display_plan['plan']; ?>
            <div class="grid md:grid-cols-4 gap-3 mt-6">
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Rows')); ?></div>
                    <div class="text-2xl font-bold text-gray-900"><?php echo (int) ($plan['total_rows'] ?? 0); ?></div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Attachments')); ?></div>
                    <div class="text-2xl font-bold text-gray-900"><?php echo (int) ($plan['attachments']['rows'] ?? 0); ?></div>
                    <div class="text-sm text-gray-500"><?php echo e(format_file_size((int) ($plan['attachments']['bytes'] ?? 0))); ?></div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Direction')); ?></div>
                    <div class="text-base font-bold text-gray-900"><?php echo e(t('Self-hosted → SaaS')); ?></div>
                </div>
                <div class="border border-gray-200 rounded-lg p-4">
                    <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Cutover')); ?></div>
                    <div class="text-base font-bold text-gray-900"><?php echo e(t('Single active instance')); ?></div>
                </div>
            </div>
            <div class="mt-4 bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-900">
                <?php echo e(t('Core data and attachments are streamed over the migration API and stored through the SaaS storage driver.')); ?>
                <?php echo e(t('ZIP export remains available below as a fallback when API sync is not available.')); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">
            <div>
                <p class="text-sm font-semibold text-blue-600 mb-2"><?php echo e(t('Browser-safe sync runner')); ?></p>
                <h2 class="text-xl font-bold text-gray-900 mb-2"><?php echo e(t('Run migration without server SSH')); ?></h2>
                <p class="text-gray-600 max-w-3xl">
                    <?php echo e(t('Each click runs a short migration batch and saves progress. Use it on shared hosting where PHP CLI, cron, or long-running shell commands are not available.')); ?>
                </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?php echo $sync_stage === 'complete' ? 'bg-green-100 text-green-700' : ($sync_stage === 'idle' ? 'bg-gray-100 text-gray-700' : 'bg-blue-100 text-blue-700'); ?>">
                <?php echo e(t('Stage')); ?>: <?php echo e($sync_stage); ?>
            </span>
        </div>

        <div class="grid md:grid-cols-4 gap-3 mt-5">
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Current table')); ?></div>
                <div class="text-base font-bold text-gray-900 mt-1"><?php echo e($sync_current_table !== '' ? $sync_current_table : '-'); ?></div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Table offset')); ?></div>
                <div class="text-base font-bold text-gray-900 mt-1"><?php echo (int) ($sync_state['offset'] ?? 0); ?></div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Attachment offset')); ?></div>
                <div class="text-base font-bold text-gray-900 mt-1"><?php echo (int) ($sync_state['attachment_offset'] ?? 0); ?></div>
            </div>
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="text-xs uppercase font-semibold text-gray-500"><?php echo e(t('Rows sent')); ?></div>
                <div class="text-base font-bold text-gray-900 mt-1"><?php echo (int) ($sync_summary['total_sent'] ?? 0); ?></div>
            </div>
        </div>

        <div class="grid md:grid-cols-5 gap-3 mt-3 text-sm">
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="text-gray-500"><?php echo e(t('Attachments sent')); ?></div>
                <strong class="text-gray-900"><?php echo (int) ($sync_attachment_summary['sent'] ?? 0); ?></strong>
            </div>
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="text-gray-500"><?php echo e(t('Uploaded')); ?></div>
                <strong class="text-gray-900"><?php echo (int) ($sync_attachment_summary['uploaded'] ?? 0); ?></strong>
            </div>
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="text-gray-500"><?php echo e(t('Mapped')); ?></div>
                <strong class="text-gray-900"><?php echo (int) ($sync_attachment_summary['mapped'] ?? 0); ?></strong>
            </div>
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="text-gray-500"><?php echo e(t('Skipped')); ?></div>
                <strong class="text-gray-900"><?php echo (int) ($sync_attachment_summary['skipped'] ?? 0); ?></strong>
            </div>
            <div class="rounded-lg border border-gray-100 p-3">
                <div class="text-gray-500"><?php echo e(t('Failed')); ?></div>
                <strong class="<?php echo ((int) ($sync_attachment_summary['failed'] ?? 0)) > 0 ? 'text-red-700' : 'text-gray-900'; ?>"><?php echo (int) ($sync_attachment_summary['failed'] ?? 0); ?></strong>
            </div>
        </div>

        <div class="grid lg:grid-cols-[1fr_auto_auto_auto] gap-3 mt-5 items-end">
            <form method="post" class="contents">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="migration_action" value="run_cloud_sync_batch">
                <div class="grid md:grid-cols-4 gap-3">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('FoxDesk Cloud URL')); ?></label>
                        <input class="form-input" name="cloud_url" value="<?php echo e($cloud_url); ?>" placeholder="https://app.foxdesk.net">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('Rows per batch')); ?></label>
                        <input class="form-input" type="number" min="1" max="1000" name="row_limit" value="<?php echo (int) ($sync_state['limits']['rows'] ?? 250); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('Files per batch')); ?></label>
                        <input class="form-input" type="number" min="1" max="25" name="attachment_limit" value="<?php echo (int) ($sync_state['limits']['attachments'] ?? 2); ?>">
                    </div>
                    <div class="md:col-span-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><?php echo e(t('Migration token')); ?></label>
                        <input class="form-input" name="migration_token" value="<?php echo e($stored_token); ?>" placeholder="fdmig_..." autocomplete="off">
                    </div>
                </div>
                <button class="btn btn-primary whitespace-nowrap" type="submit" <?php echo $sync_stage === 'complete' ? 'disabled' : ''; ?>>
                    <?php echo e(t('Run next batch')); ?>
                </button>
                <button class="btn btn-secondary whitespace-nowrap" type="submit" name="migration_action" value="start_cloud_sync" onclick="return confirm(<?php echo e(json_encode(t('Restart saved sync progress and start again?'), JSON_UNESCAPED_UNICODE)); ?>)">
                    <?php echo e(t('Restart sync')); ?>
                </button>
            </form>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="migration_action" value="clear_cloud_sync_state">
                <button class="btn btn-secondary whitespace-nowrap" type="submit" onclick="return confirm(<?php echo e(json_encode(t('Clear saved sync progress? This does not delete SaaS data.'), JSON_UNESCAPED_UNICODE)); ?>)">
                    <?php echo e(t('Clear progress')); ?>
                </button>
            </form>
        </div>

        <?php if (!empty($sync_summary['tables']) && is_array($sync_summary['tables'])): ?>
            <div class="mt-5 border border-gray-100 rounded-lg overflow-hidden">
                <div class="grid grid-cols-6 bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                    <div class="px-3 py-2 col-span-2"><?php echo e(t('Table')); ?></div>
                    <div class="px-3 py-2"><?php echo e(t('Sent')); ?></div>
                    <div class="px-3 py-2"><?php echo e(t('Created')); ?></div>
                    <div class="px-3 py-2"><?php echo e(t('Mapped')); ?></div>
                    <div class="px-3 py-2"><?php echo e(t('Skipped')); ?></div>
                </div>
                <?php foreach ($sync_summary['tables'] as $table => $table_summary): ?>
                    <?php $table_summary = is_array($table_summary) ? $table_summary : []; ?>
                    <div class="grid grid-cols-6 border-t border-gray-100 text-sm">
                        <div class="px-3 py-2 col-span-2 font-medium text-gray-800"><?php echo e((string) $table); ?></div>
                        <div class="px-3 py-2 text-gray-600"><?php echo (int) ($table_summary['sent'] ?? 0); ?></div>
                        <div class="px-3 py-2 text-gray-600"><?php echo (int) ($table_summary['created'] ?? 0); ?></div>
                        <div class="px-3 py-2 text-gray-600"><?php echo (int) ($table_summary['mapped'] ?? 0); ?></div>
                        <div class="px-3 py-2 text-gray-600"><?php echo (int) ($table_summary['skipped'] ?? 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
            $sync_errors = array_merge(
                is_array($sync_summary['errors'] ?? null) ? $sync_summary['errors'] : [],
                is_array($sync_attachment_summary['errors'] ?? null) ? $sync_attachment_summary['errors'] : []
            );
        ?>
        <?php if ($sync_errors): ?>
            <div class="mt-5 bg-red-50 border border-red-100 rounded-lg p-4 text-sm text-red-900">
                <div class="font-semibold mb-2"><?php echo e(t('Sync warnings')); ?></div>
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach (array_slice($sync_errors, 0, 10) as $sync_error): ?>
                        <li><?php echo e((string) ($sync_error['message'] ?? $sync_error['error'] ?? t('Unknown sync error'))); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
        <div class="font-semibold mb-2"><?php echo e(t('Final cutover is destructive operationally.')); ?></div>
        <p class="mb-3">
            <?php echo e(t('Use this only after the final sync is complete and the SaaS workspace has been verified. It disables local IMAP/email processing and redirects users to the cloud workspace.')); ?>
        </p>
        <form method="post" class="grid md:grid-cols-[1fr_auto] gap-3 items-end">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="migration_action" value="mark_cutover">
            <div>
                <label class="block text-sm font-semibold mb-1"><?php echo e(t('Cloud workspace URL')); ?></label>
                <input class="form-input" name="cutover_target_url" value="<?php echo e($cutover_target); ?>" placeholder="https://app.foxdesk.net/">
            </div>
            <button class="btn btn-secondary" type="submit" onclick="return confirm(<?php echo e(json_encode(t('Final cutover will stop this self-hosted FoxDesk from acting as the active instance. Continue?'), JSON_UNESCAPED_UNICODE)); ?>)">
                <?php echo e(t('Final cutover')); ?>
            </button>
        </form>
    </div>

    <?php if (!$readiness['ready']): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-5 text-sm text-red-900">
            <div class="font-semibold mb-2"><?php echo e(t('Migration export is not ready on this server.')); ?></div>
            <ul class="list-disc pl-5 space-y-1">
                <?php if (!$readiness['zip_available']): ?>
                    <li><?php echo e(t('PHP extension')); ?> <code>ZipArchive</code> <?php echo e(t('is missing. Enable/install PHP zip support first.')); ?></li>
                <?php endif; ?>
                <?php if (empty($readiness['backup_dir']['writable'])): ?>
                    <li><?php echo e(t('Backup directory is not writable:')); ?> <code><?php echo e($readiness['backup_dir']['path']); ?></code></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($count_errors): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-5 text-sm text-amber-900">
            <div class="font-semibold mb-2"><?php echo e(t('Some export counts could not be read.')); ?></div>
            <div><?php echo e(t('The export can still run, but check the server log if the package is incomplete.')); ?></div>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-blue-600 mb-2"><?php echo e(t('Fallback package')); ?></p>
                <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo e(t('Export this self-hosted FoxDesk')); ?></h1>
                <p class="text-gray-600 max-w-3xl">
                    <?php echo e(t('This creates a migration ZIP that the FoxDesk SaaS platform admin can import into a new hosted workspace if API sync is not available.')); ?>
                    <?php echo e(t('It includes {app} users, clients, tickets, comments, time entries, reports, notification metadata, settings, and attachment files.', ['{app}' => $tenant_name])); ?>
                </p>
            </div>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="migration_action" value="download_export">
                <button type="submit" class="btn btn-primary whitespace-nowrap" <?php echo $readiness['ready'] ? '' : 'disabled'; ?>>
                    <?php echo e(t('Download migration package')); ?>
                </button>
            </form>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500"><?php echo e(t('Attachments')); ?></div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo $attachment_count; ?></div>
            <div class="text-sm text-gray-500 mt-1"><?php echo e(format_file_size($storage_bytes)); ?> <?php echo e(t('total')); ?></div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500"><?php echo e(t('Users')); ?></div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo (int) ($counts['users'] ?? 0); ?></div>
            <div class="text-sm text-gray-500 mt-1"><?php echo e(t('Password hashes are preserved for import.')); ?></div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="text-sm text-gray-500"><?php echo e(t('Tickets')); ?></div>
            <div class="text-2xl font-bold text-gray-900 mt-1"><?php echo (int) ($counts['tickets'] ?? 0); ?></div>
            <div class="text-sm text-gray-500 mt-1"><?php echo e(t('IDs are remapped during SaaS import.')); ?></div>
        </div>
    </div>

    <div class="bg-white border border-gray-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4"><?php echo e(t('Package contents')); ?></h2>
        <div class="grid md:grid-cols-3 gap-3">
            <?php foreach ($counts as $table => $count): ?>
                <div class="flex items-center justify-between border border-gray-100 rounded-md px-3 py-2 text-sm">
                    <span class="font-medium text-gray-700"><?php echo e($table); ?></span>
                    <span class="text-gray-500"><?php echo (int) $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-lg p-5 text-sm text-blue-900">
        <strong><?php echo e(t('Fallback path:')); ?></strong>
        <?php echo e(t('If API sync is unavailable, log in to the FoxDesk SaaS platform admin, import this ZIP, verify the hosted workspace, then return here for final cutover.')); ?>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
