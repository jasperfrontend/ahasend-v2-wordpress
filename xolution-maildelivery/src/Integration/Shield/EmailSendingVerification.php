<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Address;
use Xolution\MailDelivery\Mail\Claim;

/**
 * Shield's "Email Sending Verification" email, sent once before Email Authentication can be switched on. It tests
 * the route the 2FA emails will take, so it has to take that route too.
 *
 * Shield announces nothing before sending it (checked against Shield 22.3.4,
 * EmailDeliveryVerificationMailer::send()), but its verify link is a Shield action URL with
 * ex=email_send_verify, which no other email contains.
 */
final class EmailSendingVerification implements EmailType
{
    /** The verify link's execute parameter, after ?, & or the ; of an escaped &amp; / &#038;. */
    private const EXECUTE_PARAM = '/[?&;]ex=email_send_verify(?![a-z0-9_.:\-])/i';

    public function id(): string
    {
        return 'email-verification';
    }

    public function label(): string
    {
        return __('Email sending verification', 'xolution-maildelivery');
    }

    public function description(): string
    {
        return __('The one-time email Shield sends before Email Authentication can be switched on. Switch this on too, so Shield tests the same route its 2FA emails will take.', 'xolution-maildelivery');
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        return 'text/html';
    }

    public function register(string $marker): void
    {
        add_filter('wp_mail', fn (mixed $atts): mixed => $this->claim($atts, $marker), PHP_INT_MAX - 1);
    }

    public function claim(mixed $atts, string $marker): mixed
    {
        if (!is_array($atts) || count(Address::parseList($atts['to'] ?? [])) !== 1) {
            return $atts;
        }
        $message = (string) ($atts['message'] ?? '');
        if (!str_contains($message, 'shield_action') || preg_match(self::EXECUTE_PARAM, $message) !== 1) {
            return $atts;
        }

        return Claim::mark($atts, $marker);
    }
}
