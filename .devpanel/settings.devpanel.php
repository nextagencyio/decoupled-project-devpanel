<?php

/**
 * @file
 * DevPanel platform settings override.
 *
 * Loaded from settings.php when DP_APP_ID environment variable is set
 * (i.e. when running on DevPanel or DDEV with DevPanel env vars).
 * Reads database credentials from environment variables injected by
 * DevPanel's container orchestrator.
 */

$databases['default']['default']['database'] = getenv('DB_NAME');
$databases['default']['default']['username'] = getenv('DB_USER');
$databases['default']['default']['password'] = getenv('DB_PASSWORD');
$databases['default']['default']['host'] = getenv('DB_HOST');
$databases['default']['default']['port'] = getenv('DB_PORT');
$databases['default']['default']['driver'] = getenv('DB_DRIVER');
$databases['default']['default']['isolation_level'] = 'READ COMMITTED';

// Hash salt from generated file.
$salt_file = __DIR__ . '/salt.txt';
if (file_exists($salt_file)) {
  $settings['hash_salt'] = trim(file_get_contents($salt_file));
}

// Config sync directory.
$settings['config_sync_directory'] = '../config/sync';

// Private files.
$settings['file_private_path'] = '../private';

// Trusted host patterns from DevPanel hostname.
$dp_hostname = getenv('DP_HOSTNAME');
if ($dp_hostname) {
  $settings['trusted_host_patterns'][] = '^' . preg_quote($dp_hostname) . '$';
} else {
  $settings['trusted_host_patterns'][] = '.*';
}
