const { test, expect } = require('@playwright/test');
const { dockerExec, php } = require('./helpers');
const { webContainer } = require('./env');

test('upgrade script runs without fatal errors', async () => {
  const output = dockerExec(webContainer, ['php', '/var/www/html/upgrade.php']);
  expect(output).toContain('Upgrade complete');
  expect(output).not.toMatch(/Fatal error|Parse error|Warning/i);
});

test('update package applies with backup evidence and rollback removes added files', async () => {
  const markerPath = '/var/www/html/update-e2e-marker.txt';
  dockerExec(webContainer, ['rm', '-f', markerPath]);

  const packagePath = php(`
    $zipPath = sys_get_temp_dir() . '/foxdesk-e2e-update-' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      fwrite(STDERR, 'cannot open zip');
      exit(2);
    }
    $zip->addFromString('version.json', json_encode([
      'version' => '99.99.99',
      'date' => date('Y-m-d'),
      'min_php' => '8.1',
      'changelog' => ['E2E update package smoke'],
      'delete_files' => []
    ], JSON_PRETTY_PRINT));
    $zip->addFromString('files/update-e2e-marker.txt', 'created by update package');
    $zip->close();
    echo $zipPath;
  `).trim();

  const validation = php(`
    define('BASE_PATH', '/var/www/html');
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/settings-functions.php';
    require_once BASE_PATH . '/includes/update-functions.php';
    $result = validate_update_package(${JSON.stringify(packagePath)});
    echo json_encode(['valid' => $result['valid'], 'version' => $result['version'] ?? null, 'errors' => $result['errors'] ?? []]);
  `).trim();
  expect(JSON.parse(validation)).toMatchObject({ valid: true, version: '99.99.99' });

  const applied = dockerExec(webContainer, [
    'php',
    '-r',
    `
      define('BASE_PATH', '/var/www/html');
      require_once BASE_PATH . '/config.php';
      require_once BASE_PATH . '/includes/database.php';
      require_once BASE_PATH . '/includes/functions.php';
      require_once BASE_PATH . '/includes/settings-functions.php';
      require_once BASE_PATH . '/includes/update-functions.php';
      apply_update(${JSON.stringify(packagePath)});
    `
  ]);
  expect(applied).toContain('Update complete');

  const evidence = php(`
    define('BASE_PATH', '/var/www/html');
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/settings-functions.php';
    require_once BASE_PATH . '/includes/update-functions.php';
    $backups = get_backups();
    $latest = $backups[0] ?? [];
    echo json_encode([
      'marker_exists' => file_exists(BASE_PATH . '/update-e2e-marker.txt'),
      'marker_body' => file_exists(BASE_PATH . '/update-e2e-marker.txt') ? trim(file_get_contents(BASE_PATH . '/update-e2e-marker.txt')) : '',
      'backup_id' => $latest['id'] ?? null,
      'backup_has_files' => !empty($latest['id']) && file_exists(BACKUP_DIR . '/' . $latest['id'] . '/files.zip'),
      'backup_has_info' => !empty($latest['id']) && file_exists(BACKUP_DIR . '/' . $latest['id'] . '/info.json')
    ]);
  `).trim();
  const parsed = JSON.parse(evidence);
  expect(parsed).toMatchObject({
    marker_exists: true,
    marker_body: 'created by update package',
    backup_has_files: true,
    backup_has_info: true
  });
  expect(parsed.backup_id).toBeTruthy();

  const rollback = dockerExec(webContainer, [
    'php',
    '-r',
    `
      define('BASE_PATH', '/var/www/html');
      require_once BASE_PATH . '/config.php';
      require_once BASE_PATH . '/includes/database.php';
      require_once BASE_PATH . '/includes/functions.php';
      require_once BASE_PATH . '/includes/settings-functions.php';
      require_once BASE_PATH . '/includes/update-functions.php';
      rollback_update(${JSON.stringify(parsed.backup_id)}, false);
    `
  ]);
  expect(rollback).toContain('Rollback complete');

  const marker = dockerExec(webContainer, ['sh', '-lc', `test ! -f ${markerPath} && echo removed`]);
  expect(marker.trim()).toBe('removed');
});

test('public report token works and expired token is rejected', async ({ page }) => {
  const token = php(`
    define('BASE_PATH', '/var/www/html');
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/settings-functions.php';
    require_once BASE_PATH . '/includes/ticket-share-functions.php';
    function current_user() { return ['id' => 1]; }
    require_once BASE_PATH . '/includes/report-functions.php';
    $org = db_insert('organizations', ['name' => 'E2E Org', 'is_active' => 1]);
    $report = create_report_template([
      'organization_id' => $org,
      'created_by_user_id' => 1,
      'title' => 'E2E Public Report',
      'date_from' => date('Y-m-d', strtotime('-7 days')),
      'date_to' => date('Y-m-d'),
      'executive_summary' => 'Generated by E2E',
      'is_draft' => 0,
      'expires_at' => date('Y-m-d H:i:s', strtotime('+1 day'))
    ]);
    echo create_report_template_share($report, $org, 1);
  `).trim();

  await page.goto(`/index.php?page=report-public&token=${encodeURIComponent(token)}`);
  await expect(page.locator('body')).toContainText('E2E Public Report');

  php(`
    define('BASE_PATH', '/var/www/html');
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    db_query("UPDATE report_templates SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE title = 'E2E Public Report'");
  `);

  const expired = await page.request.get(`/index.php?page=report-public&token=${encodeURIComponent(token)}`);
  expect(expired.status()).toBe(410);
});

test('backup rollback removes files created after backup and health remains OK', async ({ page }) => {
  const output = php(`
    define('BASE_PATH', '/var/www/html');
    require_once BASE_PATH . '/config.php';
    require_once BASE_PATH . '/includes/database.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/settings-functions.php';
    require_once BASE_PATH . '/includes/update-functions.php';
    $backup = create_backup();
    if (!$backup['success']) {
      fwrite(STDERR, json_encode($backup));
      exit(2);
    }
    file_put_contents(BASE_PATH . '/rollback-marker-e2e.txt', 'created after backup');
    rollback_update($backup['backup_id'], false);
  `);
  expect(output).toContain('Rollback complete');

  const marker = dockerExec(webContainer, ['sh', '-lc', 'test ! -f /var/www/html/rollback-marker-e2e.txt && echo removed']);
  expect(marker.trim()).toBe('removed');

  const health = await page.request.get('/index.php?page=health');
  expect(health.status()).toBe(200);
  expect(await health.json()).toMatchObject({ status: 'ok', db: true });
});
