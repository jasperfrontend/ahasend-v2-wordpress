<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Cli;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use WP_CLI;
use WP_Error;
use Xolution\MailDelivery\Integration\EmailTypeGroup;
use Xolution\MailDelivery\Integration\GroupsEmailTypes;
use Xolution\MailDelivery\Integration\Registry;
use Xolution\MailDelivery\Mail\Sender;
use Xolution\MailDelivery\Plugin;
use Xolution\MailDelivery\Settings\Configurator;
use Xolution\MailDelivery\Settings\Secrets;

use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * Sets up and checks Xolution MailDelivery: AhaSend credentials, email types, managers and alerts.
 *
 * ## EXAMPLES
 *
 *     # Set up one site; asks for the API key without showing it.
 *     $ wp maildelivery setup --account-id=<uuid> --sending-domain=mail-delivery.example.com --manager=admin
 *
 *     # Set up every site on a server with the same key, typed once.
 *     $ read -rs KEY
 *     $ for site in /var/www/*; do
 *     >   printf '%s' "$KEY" | wp --path="$site/public" maildelivery setup --key-from-stdin \
 *     >     --account-id=<uuid> --sending-domain=mail-delivery.example.com --manager=admin
 *     > done
 *
 *     # See how a site is set up and whether AhaSend accepts its key.
 *     $ wp maildelivery status
 */
final class Command
{
    /**
     * Saves the AhaSend credentials and, optionally, email types, managers and the Slack webhook.
     *
     * The API key is never taken as an argument, so it stays out of shell history and process lists: it is asked
     * for without echo, or read from STDIN with --key-from-stdin. Leave it out with --keep-key to change other
     * settings only.
     *
     * ## OPTIONS
     *
     * [--account-id=<uuid>]
     * : AhaSend account ID.
     *
     * [--sending-domain=<domain>]
     * : Domain verified in AhaSend; the From address becomes <site-host>@<domain>.
     *
     * [--key-from-stdin]
     * : Read the API key from STDIN instead of asking for it.
     *
     * [--keep-key]
     * : Don't ask for an API key; keep the saved one.
     *
     * [--enable=<markers>]
     * : Comma-separated email types to switch on (replaces the current set). See `wp maildelivery types`.
     *
     * [--manager=<users>]
     * : Comma-separated user logins, emails or IDs that become the plugin's managers (replaces the current set).
     *
     * [--slack-webhook=<url>]
     * : Slack incoming webhook for alerts. Use an empty value to remove it.
     *
     * [--[no-]sandbox]
     * : Switch AhaSend sandbox mode on (--sandbox) or off (--no-sandbox). Left unchanged when not given.
     *
     * ## EXAMPLES
     *
     *     $ wp maildelivery setup --account-id=<uuid> --sending-domain=mail-delivery.example.com \
     *         --enable=wp-core/password-reset,shield/two-factor-login --manager=admin
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function setup(array $args, array $assoc): void
    {
        $plugin = Plugin::instance();
        $config = new Configurator($plugin);

        if (isset($assoc['account-id'])) {
            $this->apply(static fn () => $config->setAccountId((string) $assoc['account-id']));
        }
        if (isset($assoc['sending-domain'])) {
            $this->apply(static fn () => $config->setSendingDomain((string) $assoc['sending-domain']));
        }

        if (!get_flag_value($assoc, 'keep-key', false)) {
            $key = get_flag_value($assoc, 'key-from-stdin', false)
                ? self::firstLine((string) stream_get_contents(STDIN))
                : trim((string) \cli\prompt('AhaSend API key (input hidden)', false, ': ', true));
            $this->apply(static fn () => $config->setApiKey($key));
            WP_CLI::log('API key saved (encrypted), ending in …' . substr($key, -4) . '.');
        }

        if (array_key_exists('sandbox', $assoc)) {
            $config->setSandbox((bool) get_flag_value($assoc, 'sandbox', false));
        }
        if (array_key_exists('slack-webhook', $assoc)) {
            $this->apply(static fn () => $config->setSlackWebhook((string) $assoc['slack-webhook']));
        }
        if (isset($assoc['manager'])) {
            $this->apply(static fn () => $config->setManagers(self::userIds((string) $assoc['manager'])));
        }

        $client = $plugin->client();
        if ($client instanceof WP_Error) {
            WP_CLI::warning($client->get_error_message());
        } else {
            $ping = $client->ping();
            $ping->ok
                ? WP_CLI::log('Connection OK: AhaSend accepts the key from this server.')
                : WP_CLI::warning('Connection failed: ' . $ping->error);
        }

        if (isset($assoc['enable'])) {
            $this->apply(static fn () => $config->setEnabledTypes(self::csv((string) $assoc['enable'])));
        }

        if ($plugin->managers->ids() === []) {
            WP_CLI::warning('No managers are set, so every administrator sees the plugin. Set them with --manager.');
        }
        WP_CLI::success('Saved. Run `wp maildelivery status` to check.');
    }

    /**
     * Shows the settings and checks whether AhaSend accepts the key from this server.
     *
     * ## OPTIONS
     *
     * [--[no-]ping]
     * : Contact AhaSend to check the key (default), or not (--no-ping).
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $plugin = Plugin::instance();
        $o      = $plugin->options;
        $hint   = (string) $o->get('api_key_hint');
        $client = $plugin->client();

        $connection = 'not checked';
        if ($client instanceof WP_Error) {
            $connection = 'error: ' . $client->get_error_message();
        } elseif (get_flag_value($assoc, 'ping', true)) {
            $ping       = $client->ping();
            $connection = $ping->ok ? 'OK' : 'failed: ' . $ping->error;
        }

        $managers = array_map(static function (int $id): string {
            $user = get_userdata($id);

            return $user ? $user->user_login : (string) $id;
        }, $plugin->managers->ids());

        $rows = [
            ['setting' => 'version', 'value' => \Xolution\MailDelivery\VERSION],
            ['setting' => 'account ID', 'value' => (string) $o->get('account_id') ?: '(not set)'],
            ['setting' => 'API key', 'value' => $hint === '' ? '(not set)' : 'aha-sk-…' . $hint],
            ['setting' => 'sending domain', 'value' => (string) $o->get('sending_domain') ?: '(not set)'],
            ['setting' => 'From', 'value' => $o->get('sending_domain') === '' ? '-' : $plugin->fromAddress()->email],
            ['setting' => 'sandbox', 'value' => $o->get('sandbox') ? 'on' : 'off'],
            ['setting' => 'email types on', 'value' => implode(', ', $plugin->registry->enabledMarkers()) ?: '(none)'],
            ['setting' => 'managers', 'value' => $managers === [] ? '(none: every administrator)' : implode(', ', $managers)],
            ['setting' => 'Slack alerts', 'value' => $plugin->alerts->webhookUrl() === null ? 'off' : 'on'],
            ['setting' => 'salts in wp-config.php', 'value' => Secrets::saltsInConfig() ? 'yes' : 'NO: the key encryption protects nothing'],
            ['setting' => 'connection', 'value' => $connection],
        ];
        format_items('table', $rows, ['setting', 'value']);
    }

    /**
     * Lists all email types with their marker and whether they're on.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function types(array $args, array $assoc): void
    {
        $plugin  = Plugin::instance();
        $enabled = $plugin->registry->enabledMarkers();
        $rows    = [];
        foreach ($plugin->registry->integrations() as $integration) {
            // In a grouped integration a label is short ("Admin login"); the group name says what it is.
            $groups = $integration instanceof GroupsEmailTypes
                ? $integration->emailTypeGroups()
                : [new EmailTypeGroup('', $integration->emailTypes())];
            foreach ($groups as $group) {
                foreach ($group->types as $type) {
                    $marker = Registry::marker($integration, $type);
                    $rows[] = [
                        'marker'    => $marker,
                        'email'     => ($group->label === '' ? '' : $group->label . ': ') . $type->label(),
                        'available' => !$type->isImplemented() ? 'not yet' : ($integration->isAvailable() ? 'yes' : 'source not active'),
                        'on'        => in_array($marker, $enabled, true) ? 'on' : 'off',
                    ];
                }
            }
        }
        format_items((string) ($assoc['format'] ?? 'table'), $rows, ['marker', 'email', 'available', 'on']);
    }

    /**
     * Switches email types on, keeping the ones already on.
     *
     * ## OPTIONS
     *
     * <marker>...
     * : One or more markers, e.g. shield/two-factor-login.
     *
     * @param list<string> $args
     */
    public function enable(array $args): void
    {
        $plugin = Plugin::instance();
        $this->apply(static fn () => (new Configurator($plugin))->setEnabledTypes([...$plugin->registry->enabledMarkers(), ...$args]));
        WP_CLI::success('On: ' . implode(', ', $plugin->registry->enabledMarkers()));
    }

    /**
     * Switches email types off.
     *
     * ## OPTIONS
     *
     * [<marker>...]
     * : One or more markers.
     *
     * [--all]
     * : Switch every email type off.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function disable(array $args, array $assoc): void
    {
        $plugin = Plugin::instance();
        $keep   = get_flag_value($assoc, 'all', false) ? [] : array_values(array_diff($plugin->registry->enabledMarkers(), $args));
        $this->apply(static fn () => (new Configurator($plugin))->setEnabledTypes($keep));
        WP_CLI::success('On: ' . (implode(', ', $plugin->registry->enabledMarkers()) ?: '(none)'));
    }

    /**
     * Sends a test email through AhaSend, outside the email-type switches.
     *
     * ## OPTIONS
     *
     * <email>
     * : Recipient.
     *
     * @param list<string> $args
     */
    public function test(array $args): void
    {
        $result = (new Sender(Plugin::instance()))->sendTest($args[0]);
        $result->ok
            ? WP_CLI::success('Accepted by AhaSend, message ' . $result->messageId . '.')
            : WP_CLI::error('Failed: ' . $result->error);
    }

    /**
     * Posts a test message to the Slack webhook.
     *
     * @subcommand alert-test
     */
    public function alertTest(): void
    {
        $result = Plugin::instance()->alerts->sendTest();
        $result === true ? WP_CLI::success('Posted to Slack.') : WP_CLI::error((string) $result);
    }

    /**
     * Runs the health check now, as cron does twice a day (posts to Slack when it fails).
     */
    public function health(): void
    {
        $plugin = Plugin::instance();
        if ($plugin->registry->enabledMarkers() === []) {
            WP_CLI::log('No email types are on, so there is nothing to check.');

            return;
        }
        $plugin->alerts->healthCheck();
        $state = get_option(\Xolution\MailDelivery\Alerts\SlackAlerts::STATE_OPTION);
        is_array($state) && isset($state['since'])
            ? WP_CLI::error('Failing since ' . wp_date('Y-m-d H:i', (int) $state['since']) . '. See `wp maildelivery status`.')
            : WP_CLI::success('Healthy.');
    }

    /**
     * Lists the managers: the users who see the plugin.
     *
     * @subcommand managers
     */
    public function managers(): void
    {
        $plugin = Plugin::instance();
        $ids    = $plugin->managers->ids();
        if ($ids === []) {
            WP_CLI::log('No managers are set, so every administrator sees the plugin.');

            return;
        }
        $rows = array_map(static function (int $id): array {
            $user = get_userdata($id);

            return ['ID' => $id, 'login' => $user ? $user->user_login : '?', 'email' => $user ? $user->user_email : '?'];
        }, $ids);
        format_items('table', $rows, ['ID', 'login', 'email']);
    }

    /**
     * Adds managers.
     *
     * ## OPTIONS
     *
     * <user>...
     * : User logins, emails or IDs. They must be administrators.
     *
     * @subcommand manager-add
     *
     * @param list<string> $args
     */
    public function managerAdd(array $args): void
    {
        $plugin = Plugin::instance();
        $this->apply(static fn () => (new Configurator($plugin))->setManagers([...$plugin->managers->ids(), ...self::userIds(implode(',', $args))]));
        $this->managers();
    }

    /**
     * Removes managers. At least one must stay.
     *
     * ## OPTIONS
     *
     * <user>...
     * : User logins, emails or IDs.
     *
     * @subcommand manager-remove
     *
     * @param list<string> $args
     */
    public function managerRemove(array $args): void
    {
        $plugin = Plugin::instance();
        $keep   = array_values(array_diff($plugin->managers->ids(), self::userIds(implode(',', $args))));
        $this->apply(static fn () => (new Configurator($plugin))->setManagers($keep));
        $this->managers();
    }

    /**
     * Runs a Configurator call and turns a refusal into a WP-CLI error (which stops the command).
     */
    private function apply(callable $step): void
    {
        try {
            $step();
        } catch (InvalidArgumentException $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * @return list<int>
     */
    private static function userIds(string $list): array
    {
        $ids = [];
        foreach (self::csv($list) as $ref) {
            $user = is_numeric($ref)
                ? get_user_by('id', (int) $ref)
                : (str_contains($ref, '@') ? get_user_by('email', $ref) : get_user_by('login', $ref));
            if (!$user) {
                WP_CLI::error(sprintf('No user "%s".', $ref));
            }
            $ids[] = (int) $user->ID;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function csv(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $v): bool => $v !== ''));
    }

    private static function firstLine(string $input): string
    {
        return trim(strtok($input, "\r\n") ?: '');
    }
}
