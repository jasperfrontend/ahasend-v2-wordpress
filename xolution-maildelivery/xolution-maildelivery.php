<?php
/**
 * Plugin Name:       Xolution MailDelivery
 * Description:       Sends selected WordPress emails through the AhaSend API instead of the server's own mailer.
 * Version:           0.0.6
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Author:            Team Xolution
 * Author URI:        https://xolution.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       xolution-maildelivery
 * Update URI:        https://github.com/jasperfrontend/ahasend-v2-wordpress
 */

declare(strict_types=1);

namespace Xolution\MailDelivery;

defined('ABSPATH') || exit;

const VERSION = '0.0.6';
const FILE    = __FILE__;

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

// On init, not plugins_loaded: labels are translated, and WordPress warns about translations loaded before init.
// No email this plugin claims is sent earlier than that.
add_action('init', static function (): void {
    Plugin::instance()->boot();
}, 1);
