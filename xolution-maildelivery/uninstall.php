<?php
/**
 * Removes everything the plugin stored: the log table, its settings (including the encrypted API key), its
 * transients and its cron. The API key in AhaSend stays: that takes a setup key, see the Uninstall button on the
 * Setup tab.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Already loaded when the Uninstall button deletes the plugin.
if (!class_exists(\Xolution\MailDelivery\Settings\Cleanup::class, false)) {
    require __DIR__ . '/src/Settings/Cleanup.php';
}

\Xolution\MailDelivery\Settings\Cleanup::removeData();
