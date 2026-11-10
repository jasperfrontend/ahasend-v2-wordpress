<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Admin;

defined('ABSPATH') || exit;

use WP_Error;
use Xolution\MailDelivery\Plugin;

/**
 * Warns managers, on every admin page, when switched-on email types can't be sent.
 */
final class Notices
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!$this->plugin->managers->isManager() || $this->plugin->registry->enabledMarkers() === []) {
            return;
        }
        $client = $this->plugin->client();
        if (!$client instanceof WP_Error) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Xolution MailDelivery:', 'xolution-maildelivery'),
            esc_html($client->get_error_message() . ' ' . __('Emails it takes over are failing until this is fixed.', 'xolution-maildelivery')),
            esc_url(admin_url('options-general.php?page=' . Plugin::SLUG)),
            esc_html__('Open settings', 'xolution-maildelivery'),
        );
    }
}
