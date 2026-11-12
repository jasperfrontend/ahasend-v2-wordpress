<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use Xolution\MailDelivery\Plugin;

/**
 * The .xmd config file: a site's MailDelivery setup as JSON, to set up the next site the same way.
 *
 * It never holds an API key. It does hold the Slack webhook (decided 2026-11-12: one file sets up everything;
 * a leaked webhook can only post to the channel).
 *
 *   {
 *     "format": "xolution-maildelivery", "version": 1,
 *     "account_id": "…", "sending_domain": "…", "server_ips": ["…"],
 *     "slack_webhook": "…", "sandbox": false, "enabled_types": ["wp-core/password-reset", …]
 *   }
 */
final class ConfigFile
{
    public const FORMAT    = 'xolution-maildelivery';
    public const VERSION   = 1;
    public const EXTENSION = 'xmd';

    /** Larger than any real config; refused before decoding. */
    public const MAX_BYTES = 65536;

    /**
     * @return array{format: string, version: int, account_id: string, sending_domain: string, server_ips: list<string>, slack_webhook: string, sandbox: bool, enabled_types: list<string>}
     */
    public static function export(Plugin $plugin): array
    {
        $o = $plugin->options;

        return [
            'format'         => self::FORMAT,
            'version'        => self::VERSION,
            'account_id'     => (string) $o->get('account_id'),
            'sending_domain' => (string) $o->get('sending_domain'),
            'server_ips'     => array_values(array_map('strval', (array) $o->get('server_ips'))),
            'slack_webhook'  => (string) $o->get('slack_webhook'),
            'sandbox'        => (bool) $o->get('sandbox'),
            'enabled_types'  => $plugin->registry->enabledMarkers(),
        ];
    }

    public static function encode(array $config): string
    {
        return (string) wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Reads a config file. Missing fields are empty; unknown fields are ignored, so a newer file still loads.
     *
     * @return array{account_id: string, sending_domain: string, server_ips: list<string>, slack_webhook: string, sandbox: bool, enabled_types: list<string>}
     * @throws InvalidArgumentException With a message fit for the user.
     */
    public static function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(__('This file is too large to be a MailDelivery config file.', 'xolution-maildelivery'));
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException(__('This is not a MailDelivery config file.', 'xolution-maildelivery'));
        }
        if (!is_int($data['version'] ?? null) || $data['version'] > self::VERSION) {
            throw new InvalidArgumentException(__('This config file was made by a newer version of MailDelivery. Update the plugin first.', 'xolution-maildelivery'));
        }

        return [
            'account_id'     => Configurator::validAccountId(self::string($data, 'account_id')),
            'sending_domain' => Configurator::validSendingDomain(self::string($data, 'sending_domain')),
            'server_ips'     => Configurator::validServerIps(self::strings($data, 'server_ips')),
            'slack_webhook'  => Configurator::validSlackWebhook(self::string($data, 'slack_webhook')),
            'sandbox'        => ($data['sandbox'] ?? false) === true,
            'enabled_types'  => array_values(array_unique(self::strings($data, 'enabled_types'))),
        ];
    }

    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
