<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\Managers;

/**
 * Hides the plugin from everyone who isn't a manager. Not a security boundary (an administrator can always run
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
            unset($transient->response[Plugin::basename()]);
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
            unset($plugins[Plugin::basename()]);
        }

        return $plugins;
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
