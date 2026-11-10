<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

use WP_Error;
use Xolution\MailDelivery\Api\Client;
use Xolution\MailDelivery\Api\SendResult;
use Xolution\MailDelivery\Plugin;

final class Sender
{
    public const TEST_MARKER = 'internal/test';

    /**
     * Days AhaSend keeps the content (body, headers). 0 needs a Pro/Max plan plus approval from AhaSend; without
     * it AhaSend silently falls back to the account default (7 days) instead of rejecting 0. 1 day needs no
     * approval, and outlives a WordPress password reset key (valid 1 day) only briefly. Set to 0 once approved.
     */
    private const DATA_RETENTION_DAYS = 1;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Sends a claimed wp_mail() call: one request per To address, each with exactly one recipient.
     * True only when every address was accepted.
     *
     * @param array<string, mixed> $atts wp_mail() arguments
     */
    public function sendWpMail(array $atts, string $marker, string $contentType): bool
    {
        $atts['headers'] = Claim::strip($atts['headers'] ?? []);

        $subject     = (string) ($atts['subject'] ?? '');
        $message     = (string) ($atts['message'] ?? '');
        $recipients  = Address::parseList($atts['to'] ?? []);
        $parsed      = Headers::parse($atts['headers']);
        $attachments = Attachments::fromWpMail($atts['attachments'] ?? []);
        $client      = $this->plugin->client();
        $failures    = [];

        if ($recipients === []) {
            $result = SendResult::failure('No recipient address.');
            $this->plugin->log->record($marker, '', $subject, $result, $this->sandbox());
            do_action('xolution_maildelivery/send_failed', $marker, '', $result);
            $failures[] = $result->error;
        }

        foreach ($recipients as $to) {
            $result = match (true) {
                $client instanceof WP_Error      => SendResult::failure($client->get_error_message()),
                !$to->isValid()                  => SendResult::failure('Invalid recipient address.'),
                $attachments instanceof WP_Error => SendResult::failure($attachments->get_error_message()),
                default                          => $this->sendOne($client, $to, $subject, $message, $contentType, $parsed, $attachments, $marker),
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
        $subject = sprintf('Test email from %s', wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            "This is a test email from Xolution MailDelivery on %s.\n\nIf you can read this, the site can send email through AhaSend.\n",
            home_url(),
        );

        $result = match (true) {
            $client instanceof WP_Error => SendResult::failure($client->get_error_message()),
            !$to->isValid()             => SendResult::failure('Invalid recipient address.'),
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
    ): SendResult {
        $payload = [
            'from'       => $this->plugin->fromAddress()->toApi(),
            'recipients' => [$to->toApi()],
            'subject'    => $subject,
            'text_content' => $contentType === 'text/html' ? HtmlToText::convert($message) : $message,
            // Never rewrite links (magic login links above all), and keep the content at AhaSend as briefly as allowed.
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
