<?php
/**
 * Removes everything the plugin stored: the log table, its settings (including the encrypted API key) and its cron.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}xmd_log");
delete_option('xmd_settings');
delete_option('xmd_log_db_version');
delete_option('xmd_alert_state');
wp_clear_scheduled_hook('xmd_purge_log');
wp_clear_scheduled_hook('xmd_health_check');
