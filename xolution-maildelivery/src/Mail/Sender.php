<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

defined('ABSPATH') || exit;

use WP_Error;
use Xolution\MailDelivery\Api\Client;
use Xolution\MailDelivery\Api\SendResult;
use Xolution\MailDelivery\Plugin;

final class Sender
{
    public const TEST_MARKER = 'internal/test';

    /**
     * Days AhaSend keeps the content (body, headers), so support can read the email a user actually received.
     * Same as the metadata and the plugin's own log. Decided 2026-11-11, replacing the original "keep nothing"
     * (ARCHITECTURE.md, Sending). Note: 0 would need a Pro/Max plan plus AhaSend's approval; without it AhaSend
     * silently uses the account default instead.
     */
    private const DATA_RETENTION_DAYS = 7;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Sends a claimed wp_mail() call: one request per To address, each with exactly one recipient.
     * True only when every address was accepted.
     *
     * @param array<string, mixed> $atts     wp_mail() arguments
     * @param string               $fromName   Display name for this email; '' for the site title.
     * @param list<Address>|null   $recipients Who gets a copy; null for the To addresses.
     */
    public function sendWpMail(
        array $atts,
        string $marker,
        string $contentType,
        string $fromName = '',
        ?array $recipients = null,
    ): bool {
        $atts['headers'] = Claim::strip($atts['headers'] ?? []);

        $subject     = (string) ($atts['subject'] ?? '');
        $message     = (string) ($atts['message'] ?? '');
        $recipients ??= Address::parseList($atts['to'] ?? []);
        $parsed      = Headers::parse($atts['headers']);
        $attachments = Attachments::fromWpMail($atts['attachments'] ?? []);
        $client      = $this->plugin->client();
        $failures    = [];

        if ($recipients === []) {
            $result = SendResult::failure(__('No recipient address.', 'xolution-maildelivery'));
            $this->plugin->log->record($marker, '', $subject, $result, $this->sandbox());
            do_action('xolution_maildelivery/send_failed', $marker, '', $result);
            $failures[] = $result->error;
        }

        foreach ($recipients as $to) {
            $result = match (true) {
                $client instanceof WP_Error      => SendResult::failure($client->get_error_message()),
                !$to->isValid()                  => SendResult::failure(__('Invalid recipient address.', 'xolution-maildelivery')),
                $attachments instanceof WP_Error => SendResult::failure($attachments->get_error_message()),
                default                          => $this->sendOne($client, $to, $subject, $message, $contentType, $parsed, $attachments, $marker, $fromName),
            };
            $this->plugin->log->record($marker, $to->email, $subject, $result, $this->sandbox());
            if ($result->ok) {
                do_action('xolution_maildelivery/send_succeeded', $marker);
            } else {
                do_action('xolution_maildelivery/send_failed', $marker, $to->email, $result);
                $failures[] = sprintf('%s: %s', $to->email, $result->error);
            }
        }

        if ($failures !== []) {
            do_action('wp_mail_failed', new WP_Error(
                'wp_mail_failed',
                'Xolution MailDelivery (' . $marker . '): ' . implode('; ', $failures),
                $atts,
            ));

            return false;
        }

        do_action('wp_mail_succeeded', $atts);

        return true;
    }

    /**
     * A test email to one address, outside wp_mail() and outside the email-type switches.
     */
    public function sendTest(string $email): SendResult
    {
        $client  = $this->plugin->client();
        $to      = new Address($email);
        $subject = sprintf(
            /* translators: %s: site title */
            __('Test email from %s', 'xolution-maildelivery'),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        );
        $message = sprintf(
            /* translators: %s: site URL */
            __('This is a test email from Xolution MailDelivery on %s.', 'xolution-maildelivery') . "\n\n"
            . __('If you can read this, the site can send email through AhaSend.', 'xolution-maildelivery') . "\n",
            home_url(),
        );

        $result = match (true) {
            $client instanceof WP_Error => SendResult::failure($client->get_error_message()),
            !$to->isValid()             => SendResult::failure(__('Invalid recipient address.', 'xolution-maildelivery')),
            default                     => $this->sendOne($client, $to, $subject, $message, 'text/plain', ['reply_to' => null, 'headers' => []], [], self::TEST_MARKER),
        };
        $this->plugin->log->record(self::TEST_MARKER, $email, $subject, $result, $this->sandbox());
        // A working test email means the setup works again; a failing one the manager sees right away.
        if ($result->ok) {
            do_action('xolution_maildelivery/send_succeeded', self::TEST_MARKER);
        }

        return $result;
    }

    /**
     * @param array{reply_to: ?array<string, string>, headers: array<string, string>} $parsed
     * @param list<array<string, mixed>> $attachments
     */
    private function sendOne(
        Client $client,
        Address $to,
        string $subject,
        string $message,
        string $contentType,
        array $parsed,
        array $attachments,
        string $marker,
        string $fromName = '',
    ): SendResult {
        $from    = $this->plugin->fromAddress();
        $payload = [
            'from'       => ($fromName === '' ? $from : $from->withName($fromName))->toApi(),
            'recipients' => [$to->toApi()],
            'subject'    => $subject,
            'text_content' => $contentType === 'text/html' ? HtmlToText::convert($message) : $message,
            // Never rewrite links (magic login links above all). Content kept 7 days, for support.
            'tracking'   => ['open' => false, 'click' => false],
            'retention'  => ['metadata' => 7, 'data' => self::DATA_RETENTION_DAYS],
            'tags'       => [$marker],
        ];
        if ($contentType === 'text/html') {
            $payload['html_content'] = $message;
        }
        if ($parsed['reply_to'] !== null) {
            $payload['reply_to'] = $parsed['reply_to'];
        }
        if ($parsed['headers'] !== []) {
            $payload['headers'] = $parsed['headers'];
        }
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        if ($this->sandbox()) {
            $payload['sandbox']        = true;
            $payload['sandbox_result'] = 'deliver';
        }

        // A fresh key per address per call: AhaSend replays even error responses for a reused key.
        return $client->send($payload, wp_generate_uuid4());
    }

    private function sandbox(): bool
    {
        return (bool) $this->plugin->options->get('sandbox');
    }
}
