#!/usr/bin/env php
<?php
/**
 * Sync the admin account password from ADMIN_EMAIL / ADMIN_PASSWORD in .env.
 *
 * Runs once at container startup (see /start.sh) to create the admin or apply
 * a changed ADMIN_PASSWORD. Can also be run manually after editing .env:
 *
 *   docker compose exec web php /var/www/html/bin/sync_admin_password.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../inc/Config.php';
require_once __DIR__ . '/../inc/DB.php';
require_once __DIR__ . '/../inc/Auth.php';

Config::load(__DIR__ . '/../.env');

$email = Config::get('ADMIN_EMAIL');
$password = Config::get('ADMIN_PASSWORD');

if (!$email || !$password) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env\n");
    exit(1);
}

try {
    Auth::syncAdminPassword($email, $password);
    echo "[" . date('Y-m-d H:i:s') . "] Admin password synced for {$email}\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
