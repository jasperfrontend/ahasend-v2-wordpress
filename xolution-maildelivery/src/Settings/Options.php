<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

defined('ABSPATH') || exit;

/**
 * All settings live in one option, never autoloaded.
 */
final class Options
{
    public const OPTION = 'xmd_settings';

    private const DEFAULTS = [
        'account_id'     => '',
        'api_key'        => '', // encrypted, see Secrets
        'api_key_hint'   => '', // last four characters, for display only
        'sending_domain' => '',
        'sandbox'        => false,
        'enabled_types'  => [], // markers, e.g. 'wp-core/password-reset'
        'managers'       => [], // user IDs
        'slack_webhook'  => '', // plain on purpose, see SlackAlerts
    ];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function get(string $name): mixed
    {
        return $this->all()[$name] ?? self::DEFAULTS[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        $data = array_merge($this->all(), array_intersect_key($changes, self::DEFAULTS));
        update_option(self::OPTION, $data, false);
        $this->data = $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if ($this->data === null) {
            $stored     = get_option(self::OPTION, []);
            $this->data = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        }

        return $this->data;
    }
}
