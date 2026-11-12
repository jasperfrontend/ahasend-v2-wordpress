<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use WP_Error;
use Xolution\MailDelivery\Alerts\SlackAlerts;
use Xolution\MailDelivery\Plugin;

/**
 * Validates and saves settings. Shared by the settings page and WP-CLI, so both enforce the same rules.
 * Every setter throws InvalidArgumentException with a message fit for the user, and saves nothing then.
 */
final class Configurator
{
    private const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const DOMAIN_PATTERN = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** An empty value clears it. */
    public function setAccountId(string $accountId): void
    {
        $this->plugin->options->update(['account_id' => self::validAccountId($accountId)]);
    }

    /**
     * The account ID normalised, or an exception when it isn't one. Empty stays empty.
     */
    public static function validAccountId(string $accountId): string
    {
        $accountId = strtolower(trim($accountId));
        if ($accountId !== '' && preg_match(self::UUID_PATTERN, $accountId) !== 1) {
            throw new InvalidArgumentException(__('The account ID is not a valid UUID. It was not saved.', 'xolution-maildelivery'));
        }

        return $accountId;
    }

    public function setApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if (!str_starts_with($apiKey, 'aha-sk-') || strlen($apiKey) <= 20) {
            throw new InvalidArgumentException(__('The API key must be an AhaSend API v2 key, starting with "aha-sk-". Keys from API v1 do not work. It was not saved.', 'xolution-maildelivery'));
        }
        $this->plugin->options->update([
            'api_key'      => Secrets::encrypt($apiKey),
            'api_key_hint' => substr($apiKey, -4),
        ]);
    }

    /** An empty value clears it. */
    public function setSendingDomain(string $domain): void
    {
        $this->plugin->options->update(['sending_domain' => self::validSendingDomain($domain)]);
    }

    /**
     * The domain normalised, or an exception when it isn't one. Empty stays empty.
     */
    public static function validSendingDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain !== '' && preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException(__('The sending domain is not a valid domain name. It was not saved.', 'xolution-maildelivery'));
        }

        return $domain;
    }

    public function setSandbox(bool $sandbox): void
    {
        $this->plugin->options->update(['sandbox' => $sandbox]);
    }

    /** An empty value removes it. */
    public function setSlackWebhook(string $url): void
    {
        $this->plugin->options->update(['slack_webhook' => self::validSlackWebhook($url)]);
    }

    /**
     * The webhook URL trimmed, or an exception when it isn't a Slack webhook. Empty stays empty.
     */
    public static function validSlackWebhook(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && (!str_starts_with($url, SlackAlerts::WEBHOOK_PREFIX) || wp_http_validate_url($url) === false)) {
            throw new InvalidArgumentException(sprintf(
                /* translators: %s: the required URL prefix, https://hooks.slack.com/ */
                __('The Slack webhook must start with %s. It was not saved.', 'xolution-maildelivery'),
                SlackAlerts::WEBHOOK_PREFIX,
            ));
        }

        return $url;
    }

    /**
     * @param list<string> $ips
     */
    public function setServerIps(array $ips): void
    {
        $this->plugin->options->update(['server_ips' => self::validServerIps($ips)]);
    }

    /**
     * IPv4 or IPv6 addresses, or CIDR blocks of them, each once. AhaSend refuses an allow list that allows every
     * address, and more than 100 entries.
     *
     * @param list<string> $ips
     * @return list<string>
     */
    public static function validServerIps(array $ips): array
    {
        $valid   = [];
        $refused = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            [$address, $prefix] = array_pad(explode('/', $ip, 2), 2, null);
            $isV4    = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6    = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $max     = $isV4 ? 32 : 128;
            $prefixOk = $prefix === null || (ctype_digit($prefix) && (int) $prefix >= 1 && (int) $prefix <= $max);
            if (($isV4 || $isV6) && $prefixOk) {
                $valid[strtolower($ip)] = strtolower($ip);
            } else {
                $refused[] = $ip;
            }
        }
        if ($refused !== []) {
            throw new InvalidArgumentException(sprintf(
                /* translators: %s: comma-separated values */
                __('Not an IP address or CIDR block: %s.', 'xolution-maildelivery'),
                implode(', ', $refused),
            ));
        }
        if (count($valid) > 100) {
            throw new InvalidArgumentException(__('AhaSend allows at most 100 server IPs per key.', 'xolution-maildelivery'));
        }

        return array_values($valid);
    }

    /**
     * Replaces the switched-on email types. Unknown or not yet implemented markers are refused, and nothing can be
     * switched on until the plugin can send.
     *
     * @param list<string> $markers
     */
    public function setEnabledTypes(array $markers): void
    {
        $markers    = array_values(array_unique(array_map('trim', $markers)));
        $switchable = $this->plugin->registry->filterSwitchable($markers);
        $refused    = array_diff($markers, $switchable);
        if ($refused !== []) {
            throw new InvalidArgumentException(sprintf(
                /* translators: %s: comma-separated email type markers */
                __('Unknown or not yet available email types: %s. Nothing was changed.', 'xolution-maildelivery'),
                implode(', ', $refused),
            ));
        }
        if ($switchable !== [] && $this->plugin->client() instanceof WP_Error) {
            throw new InvalidArgumentException(__('Email types can only be switched on once the account ID, a working API key and the sending domain are saved.', 'xolution-maildelivery'));
        }
        $this->plugin->options->update(['enabled_types' => $switchable]);
    }

    /**
     * @param list<int> $userIds
     */
    public function setManagers(array $userIds): void
    {
        $valid = array_values(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0 && user_can($id, 'manage_options'),
        ));
        if ($valid === []) {
            throw new InvalidArgumentException(__('At least one manager is needed, and managers must be administrators. The managers were not changed.', 'xolution-maildelivery'));
        }
        $this->plugin->managers->replace($valid);
    }
}
