const { test, expect } = require('@playwright/test');
const { admin, webContainer } = require('./env');
const { dbQuery, dockerExec } = require('./helpers');

test('installer creates config, schema, settings and admin user', async ({ page }) => {
  const config = dockerExec(webContainer, ['sh', '-lc', 'test -f /var/www/html/config.php && grep -E "DB_NAME|SECRET_KEY|APP_URL" /var/www/html/config.php']).trim();
  expect(config).toContain("define('DB_NAME', 'foxdesk')");
  expect(config).toContain("define('APP_URL'");
  expect(config).not.toContain('generate_64_hex_secret_here');

  const installed = dbQuery(`
      SELECT
        (SELECT COUNT(*) FROM users WHERE email = '${admin.email.replaceAll("'", "''")}' AND role = 'admin' AND is_active = 1) AS admin_count,
      (SELECT setting_value FROM settings WHERE setting_key = 'app_name' LIMIT 1) AS app_name,
      (SELECT COUNT(*) FROM statuses) AS statuses_count,
      (SELECT COUNT(*) FROM priorities) AS priorities_count,
      (SELECT COUNT(*) FROM ticket_types) AS ticket_types_count
  `);
  expect(installed).toContain('\n1\tFoxDesk E2E\t');

  const values = installed.trim().split(/\r?\n/).pop().split('\t');
  expect(Number(values[2])).toBeGreaterThan(0);
  expect(Number(values[3])).toBeGreaterThan(0);
  expect(Number(values[4])).toBeGreaterThan(0);

  const installedInstaller = await page.request.get('/install.php', { maxRedirects: 0 });
  expect([302, 303]).toContain(installedInstaller.status());

  const lockedForce = await page.request.get('/install.php?force=1', { maxRedirects: 0 });
  expect([403, 404]).toContain(lockedForce.status());
});
