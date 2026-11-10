<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Alerts;

defined('ABSPATH') || exit;

use WP_Error;
use Xolution\MailDelivery\Api\SendResult;
use Xolution\MailDelivery\Plugin;

/**
 * Posts to a Slack incoming webhook when claimed email can't be sent, when the twice-daily health check fails, and
 * once when things work again. There is no fallback mailer, so this is how a broken site gets noticed.
 *
 * The webhook URL is stored unencrypted on purpose: when the salts change, the API key can no longer be decrypted
 * and every claimed email fails. An encrypted webhook would become unreadable at that same moment, and the alert
 * would never arrive. A leaked webhook can only post messages to the channel.
 *
 * Messages carry the site, the email type, the error and a masked recipient. Never content.
 */
final class SlackAlerts
{
    public const HEALTH_HOOK    = 'xmd_health_check';
    public const STATE_OPTION   = 'xmd_alert_state';
    public const WEBHOOK_PREFIX = 'https://hooks.slack.com/';

    /** The same error from the same site is posted at most once per hour. */
    private const THROTTLE = HOUR_IN_SECONDS;
    private const TIMEOUT  = 3;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('xolution_maildelivery/send_failed', [$this, 'onSendFailed'], 10, 3);
        add_action('xolution_maildelivery/send_succeeded', [$this, 'onWorking'], 10, 0);
        add_action(self::HEALTH_HOOK, [$this, 'healthCheck']);
        if (!wp_next_scheduled(self::HEALTH_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::HEALTH_HOOK);
        }
    }

    public function onSendFailed(string $marker, string $recipient, SendResult $result): void
    {
        $this->markFailing();
        $this->post(
            sprintf(
                ":rotating_light: *MailDelivery: email not sent* on %s\n*Email type:* `%s` to %s\n*Error:* %s\n%s",
                $this->siteLink(),
                self::escape($marker),
                self::escape(self::maskEmail($recipient)),
                self::escape($result->error),
                $this->logLink(),
            ),
            'send|' . $marker . '|' . $result->error,
        );
    }

    /**
     * Twice a day, only while email types are switched on: can the key be decrypted, and does AhaSend accept it
     * from this server? Catches a rotated salt, a revoked key or a changed server IP before a user does.
     */
    public function healthCheck(): void
    {
        if ($this->plugin->registry->enabledMarkers() === []) {
            return;
        }
        $client = $this->plugin->client();
        if ($client instanceof WP_Error) {
            $error = $client->get_error_message();
        } else {
            $ping  = $client->ping();
            $error = $ping->ok ? null : $ping->error;
        }

        if ($error === null) {
            $this->onWorking();

            return;
        }

        $this->markFailing();
        $this->post(
            sprintf(
                ":warning: *MailDelivery: health check failed* on %s\nEmails this site takes over will fail until this is fixed.\n*Error:* %s\n%s",
                $this->siteLink(),
                self::escape($error),
                $this->logLink(),
            ),
            'health|' . $error,
        );
    }

    /**
     * Something was sent or checked successfully. Posts "recovered" once, only after a failure.
     */
    public function onWorking(): void
    {
        $state = get_option(self::STATE_OPTION);
        if (!is_array($state) || !isset($state['since'])) {
            return;
        }
        delete_option(self::STATE_OPTION);
        $this->post(
            sprintf(
                ":white_check_mark: *MailDelivery: working again* on %s (failing since %s).",
                $this->siteLink(),
                wp_date('Y-m-d H:i', (int) $state['since']),
            ),
            null,
        );
    }

    /**
     * True when Slack accepted the test message, otherwise the reason.
     */
    public function sendTest(): true|string
    {
        if ($this->webhookUrl() === null) {
            return __('No Slack webhook is saved.', 'xolution-maildelivery');
        }
        $result = $this->post(
            sprintf(':large_green_circle: *MailDelivery: test alert* from %s. Alerts from this site arrive here.', $this->siteLink()),
            null,
        );

        return $result === true ? true : (string) $result;
    }

    public function webhookUrl(): ?string
    {
        $url = (string) $this->plugin->options->get('slack_webhook');

        return str_starts_with($url, self::WEBHOOK_PREFIX) ? $url : null;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($email === '' || $at === false || $at === 0) {
            return $email === '' ? '(no recipient)' : '***';
        }

        return $email[0] . '***' . substr($email, $at);
    }

    /**
     * Slack mrkdwn: only &, < and > need escaping.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private function markFailing(): void
    {
        $state = get_option(self::STATE_OPTION);
        if (!is_array($state) || !isset($state['since'])) {
            update_option(self::STATE_OPTION, ['since' => time()], false);
        }
    }

    /**
     * @return true|string|null true when posted, the error when Slack refused, null when skipped.
     */
    private function post(string $text, ?string $throttleKey): true|string|null
    {
        $url = $this->webhookUrl();
        if ($url === null) {
            return null;
        }
        if ($throttleKey !== null) {
            $key = 'xmd_alert_' . md5($throttleKey);
            if (get_transient($key) !== false) {
                return null;
            }
            set_transient($key, 1, self::THROTTLE);
        }

        $response = wp_remote_post($url, [
            'timeout'     => self::TIMEOUT,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => (string) wp_json_encode(['text' => $text]),
            'data_format' => 'body',
        ]);
        if ($response instanceof WP_Error) {
            return $response->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return $code === 200 ? true : trim(sprintf('HTTP %d %s', $code, (string) wp_remote_retrieve_body($response)));
    }

    private function siteLink(): string
    {
        $url  = home_url();
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        return '<' . self::escape($url) . '|' . self::escape($host) . '>';
    }

    private function logLink(): string
    {
        return '<' . self::escape(admin_url('options-general.php?page=' . Plugin::SLUG . '&tab=log')) . '|Open the MailDelivery log>';
    }
}
