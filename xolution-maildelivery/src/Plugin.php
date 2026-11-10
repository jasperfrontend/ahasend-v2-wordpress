<?php

declare(strict_types=1);

namespace Xolution\MailDelivery;

use WP_Error;
use Xolution\MailDelivery\Admin\Cloak;
use Xolution\MailDelivery\Admin\Notices;
use Xolution\MailDelivery\Admin\SettingsPage;
use Xolution\MailDelivery\Api\Client;
use Xolution\MailDelivery\Integration\Registry;
use Xolution\MailDelivery\Integration\WpCore\WpCoreIntegration;
use Xolution\MailDelivery\Log\LogTable;
use Xolution\MailDelivery\Mail\Dispatcher;
use Xolution\MailDelivery\Mail\FromAddress;
use Xolution\MailDelivery\Settings\Managers;
use Xolution\MailDelivery\Settings\Options;
use Xolution\MailDelivery\Settings\Secrets;
use Xolution\MailDelivery\Update\GitHubUpdater;

final class Plugin
{
    public const SLUG       = 'xolution-maildelivery';
    public const PURGE_HOOK = 'xmd_purge_log';

    private static ?self $instance = null;

    public readonly Options $options;
    public readonly Managers $managers;
    public readonly Registry $registry;
    public readonly LogTable $log;

    private function __construct()
    {
        $this->options  = new Options();
        $this->managers = new Managers($this->options);
        $this->registry = new Registry($this->options, [
            new WpCoreIntegration(),
        ]);
        $this->log = new LogTable();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        $this->log->maybeUpgrade();

        (new Dispatcher($this))->register();
        $this->registry->registerEnabledTypes();
        // Not admin-only: update checks and auto-updates run from cron.
        (new GitHubUpdater())->register();

        add_action(self::PURGE_HOOK, [$this->log, 'purge']);
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }

        if (is_admin()) {
            (new Cloak($this->managers))->register();
            (new SettingsPage($this))->register();
            (new Notices($this))->register();
        }
    }

    public static function activate(): void
    {
        $plugin = self::instance();
        $plugin->log->install();
        $userId = get_current_user_id();
        if ($userId > 0) {
            $plugin->managers->ensure($userId);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::PURGE_HOOK);
    }

    public static function basename(): string
    {
        return plugin_basename(FILE);
    }

    /**
     * True when everything needed to send is saved. Says nothing about whether the key still decrypts.
     */
    public function isConfigured(): bool
    {
        return $this->options->get('account_id') !== ''
            && $this->options->get('api_key') !== ''
            && $this->options->get('sending_domain') !== '';
    }

    /**
     * An API client, or the reason none can be made.
     */
    public function client(): Client|WP_Error
    {
        if (!$this->isConfigured()) {
            return new WP_Error(
                'xmd_not_configured',
                __('Xolution MailDelivery is not configured: the AhaSend account ID, API key or sending domain is missing.', 'xolution-maildelivery'),
            );
        }

        $key = Secrets::decrypt($this->options->get('api_key'));
        if ($key === null) {
            return new WP_Error(
                'xmd_key_unreadable',
                __('The stored AhaSend API key can no longer be decrypted, probably because the salts in wp-config.php changed. Re-enter the API key.', 'xolution-maildelivery'),
            );
        }

        return new Client($key, $this->options->get('account_id'));
    }

    public function fromAddress(): FromAddress
    {
        return FromAddress::forSite($this->options->get('sending_domain'));
    }
}
