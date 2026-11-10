<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

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
        $accountId = strtolower(trim($accountId));
        if ($accountId !== '' && preg_match(self::UUID_PATTERN, $accountId) !== 1) {
            throw new InvalidArgumentException(__('The account ID is not a valid UUID. It was not saved.', 'xolution-maildelivery'));
        }
        $this->plugin->options->update(['account_id' => $accountId]);
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
        $domain = strtolower(trim($domain));
        if ($domain !== '' && preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException(__('The sending domain is not a valid domain name. It was not saved.', 'xolution-maildelivery'));
        }
        $this->plugin->options->update(['sending_domain' => $domain]);
    }

    public function setSandbox(bool $sandbox): void
    {
        $this->plugin->options->update(['sandbox' => $sandbox]);
    }

    /** An empty value removes it. */
    public function setSlackWebhook(string $url): void
    {
        $url = trim($url);
        if ($url !== '' && (!str_starts_with($url, SlackAlerts::WEBHOOK_PREFIX) || wp_http_validate_url($url) === false)) {
            throw new InvalidArgumentException(sprintf(
                __('The Slack webhook must start with %s. It was not saved.', 'xolution-maildelivery'),
                SlackAlerts::WEBHOOK_PREFIX,
            ));
        }
        $this->plugin->options->update(['slack_webhook' => $url]);
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
