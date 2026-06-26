#!/bin/sh
set -e

mkdir -p /var/www/html/uploads /var/www/html/storage/tickets /var/www/html/backups
chown -R www-data:www-data /var/www/html/uploads /var/www/html/storage /var/www/html/backups || true

cat > /var/www/html/config.php <<PHP
<?php

define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'foxdesk');
define('DB_USER', getenv('DB_USER') ?: 'foxdesk');
define('DB_PASS', getenv('DB_PASS') ?: 'foxdesk_password');

define('SECRET_KEY', getenv('SECRET_KEY') ?: 'change_this_secret_key');
define('APP_NAME', getenv('APP_NAME') ?: 'FoxDesk');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');

define('APP_DEBUG', getenv('APP_DEBUG') === 'true');
define('TRUST_PROXY', true);

define('IMAP_ENABLED', getenv('IMAP_ENABLED') === 'true');
define('IMAP_HOST', getenv('IMAP_HOST') ?: '');
define('IMAP_PORT', getenv('IMAP_PORT') ?: 993);
define('IMAP_ENCRYPTION', getenv('IMAP_ENCRYPTION') ?: 'ssl');
define('IMAP_VALIDATE_CERT', getenv('IMAP_VALIDATE_CERT') === 'true');
define('IMAP_USERNAME', getenv('IMAP_USERNAME') ?: '');
define('IMAP_PASSWORD', getenv('IMAP_PASSWORD') ?: '');
define('IMAP_FOLDER', getenv('IMAP_FOLDER') ?: 'INBOX');
define('IMAP_PROCESSED_FOLDER', getenv('IMAP_PROCESSED_FOLDER') ?: 'Processed');
define('IMAP_FAILED_FOLDER', getenv('IMAP_FAILED_FOLDER') ?: 'Failed');
define('IMAP_MAX_EMAILS_PER_RUN', 50);
define('IMAP_MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);
define('IMAP_DENY_EXTENSIONS', 'php,phtml,php3,php4,php5,phar,exe,bat,cmd,js,vbs,ps1,sh');
define('IMAP_STORAGE_BASE', 'storage/tickets');
define('IMAP_MARK_SEEN_ON_SKIP', true);
define('IMAP_ALLOW_UNKNOWN_SENDERS', false);

define('UPLOAD_DIR', 'uploads/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
PHP

if [ "$DISABLE_INSTALLER" = "true" ] && [ -f /var/www/html/install.php ]; then
  rm -f /var/www/html/install.php
fi

exec apache2-foreground