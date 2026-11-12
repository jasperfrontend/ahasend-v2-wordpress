<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\Managers;

/**
 * Hides the plugin, and add-ons that ask for it, from everyone who isn't a manager. Not a security boundary (an administrator can always run
 * code); it keeps the plugin out of sight and out of reach of normal clicking.
 */
final class Cloak
{
    public function __construct(private readonly Managers $managers)
    {
    }

    public function register(): void
    {
        add_filter('all_plugins', [$this, 'hideFromPluginList']);
        add_filter('plugin_action_links_' . Plugin::basename(), [$this, 'actionLinks']);
        add_filter('site_transient_update_plugins', [$this, 'hideFromUpdatesPage']);
    }

    /**
     * Keeps our update off Dashboard → Updates for non-managers. Only on that screen: everywhere else (cron,
     * auto-updates, MainWP) must keep seeing it.
     */
    public function hideFromUpdatesPage(mixed $transient): mixed
    {
        global $pagenow;

        if ($pagenow !== 'update-core.php' || !is_object($transient) || $this->managers->isManager()) {
            return $transient;
        }
        if (isset($transient->response) && is_array($transient->response)) {
            foreach (self::basenames() as $basename) {
                unset($transient->response[$basename]);
            }
        }

        return $transient;
    }

    /**
     * @param array<string, array<string, mixed>> $plugins
     * @return array<string, array<string, mixed>>
     */
    public function hideFromPluginList(array $plugins): array
    {
        if (!$this->managers->isManager()) {
            foreach (self::basenames() as $basename) {
                unset($plugins[$basename]);
            }
        }

        return $plugins;
    }

    /**
     * This plugin and the add-ons that asked to be hidden with it.
     *
     * @return list<string> Plugin basenames: 'folder/file.php'.
     */
    private static function basenames(): array
    {
        /**
         * Lets an add-on plugin be hidden from non-managers, like this plugin itself.
         *
         * @since 0.0.18
         * @param list<string> $basenames Plugin basenames, e.g. from plugin_basename(__FILE__).
         */
        $basenames = apply_filters('xolution_maildelivery_cloaked_plugins', [Plugin::basename()]);

        return array_values(array_unique(array_merge(
            [Plugin::basename()],
            array_filter(is_array($basenames) ? $basenames : [], 'is_string'),
        )));
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        $url = admin_url('options-general.php?page=' . Plugin::SLUG);

        return ['settings' => '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'xolution-maildelivery') . '</a>'] + $links;
    }
}
