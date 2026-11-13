<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

/**
 * Removes everything the plugin stored on the site. Used by uninstall.php (deleting the plugin from the Plugins
 * screen) and by the Uninstall button on the Setup tab.
 *
 * Plain names, no other classes: uninstall.php loads this file on its own, without the plugin's autoloader.
 */
final class Cleanup
{
    public static function removeData(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}xmd_log"); // LogTable

        delete_option('xmd_settings');       // Options, including the encrypted API key
        delete_option('xmd_log_db_version'); // LogTable
        delete_option('xmd_alert_state');    // SlackAlerts

        // Short-lived ones (xmd_flash_*, xmd_setup_report_*, xmd_alert_*). With an object cache they aren't in the
        // options table, and simply expire within minutes.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_xmd_') . '%',
            $wpdb->esc_like('_transient_timeout_xmd_') . '%',
        ));

        wp_clear_scheduled_hook('xmd_purge_log');    // Plugin::PURGE_HOOK
        wp_clear_scheduled_hook('xmd_health_check'); // SlackAlerts::HEALTH_HOOK
    }
}
