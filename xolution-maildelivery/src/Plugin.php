<?php

declare(strict_types=1);

namespace Xolution\MailDelivery;

defined('ABSPATH') || exit;

use WP_Error;
use Xolution\MailDelivery\Alerts\SlackAlerts;
use Xolution\MailDelivery\Admin\Cloak;
use Xolution\MailDelivery\Admin\Notices;
use Xolution\MailDelivery\Admin\SettingsPage;
use Xolution\MailDelivery\Admin\SetupWizard;
use Xolution\MailDelivery\Api\Client;
use Xolution\MailDelivery\Integration\GravityForms\GravityFormsIntegration;
use Xolution\MailDelivery\Integration\Integration;
use Xolution\MailDelivery\Integration\Registry;
use Xolution\MailDelivery\Integration\Shield\LoginNotice;
use Xolution\MailDelivery\Integration\Shield\ShieldIntegration;
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
    public readonly SlackAlerts $alerts;

    private function __construct()
    {
        $this->options  = new Options();
        $this->managers = new Managers($this->options);
        [$integrations, $addonIds] = self::integrations();
        $this->registry            = new Registry($this->options, $integrations, $addonIds);
        $this->log    = new LogTable();
        $this->alerts = new SlackAlerts($this);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * The built-in integrations, then those added by add-ons. An add-on can't replace or remove a built-in one,
     * and of two integrations with the same ID only the first counts.
     *
     * @return array{0: list<Integration>, 1: list<string>} The integrations, and the IDs of those from add-ons.
     */
    private static function integrations(): array
    {
        $builtIn = [
            new WpCoreIntegration(),
            new ShieldIntegration(),
            new GravityFormsIntegration(),
        ];

        /**
         * Lets an add-on plugin add integrations. Runs once, on init at priority 1, when the plugin object is
         * built; hook it when the add-on's main file loads. See ADDON_API and ARCHITECTURE.md, "Add-ons".
         *
         * @since 0.0.18
         * @param list<Integration> $integrations
         */
        $all = apply_filters('xolution_maildelivery_integrations', $builtIn);

        $seen         = [];
        $integrations = [];
        $addonIds     = [];
        foreach (array_merge($builtIn, is_array($all) ? $all : []) as $index => $integration) {
            if ($integration instanceof Integration && !isset($seen[$integration->id()])) {
                $seen[$integration->id()] = true;
                $integrations[]           = $integration;
                if ($index >= count($builtIn)) {
                    $addonIds[] = $integration->id();
                }
            }
        }

        return [$integrations, $addonIds];
    }

    public function boot(): void
    {
        $this->log->maybeUpgrade();

        (new Dispatcher($this))->register();
        $this->registry->registerEnabledTypes();
        // Not admin-only: update checks, auto-updates and the health check run from cron.
        (new GitHubUpdater())->register();
        $this->alerts->register();
        // On wp-login.php, so not admin-only. Checks Shield's settings itself and stays silent without Shield.
        (new LoginNotice())->register();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('maildelivery', Cli\Command::class);
        }

        add_action(self::PURGE_HOOK, [$this->log, 'purge']);
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }

        if (is_admin()) {
            (new Cloak($this->managers))->register();
            (new SettingsPage($this))->register();
            (new SetupWizard($this))->register();
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
        wp_clear_scheduled_hook(SlackAlerts::HEALTH_HOOK);
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
