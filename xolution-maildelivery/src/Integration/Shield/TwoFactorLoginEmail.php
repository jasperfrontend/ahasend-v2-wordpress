<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

use WP_User;
use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Address;
use Xolution\MailDelivery\Mail\Claim;

/**
 * Shield's Email 2FA email ("Two-Factor Login Verification"): the login code and, with "Enable Auto-Login Links"
 * on, the one-click login link.
 *
 * Shield sends it with a bare wp_mail() call that has no filter of its own, so it is recognised by what Shield
 * announces just before sending it (checked against Shield 22.3.4, Provider/Email::sendEmailTwoFactorVerify()):
 *   1. action 'shield/2fa/email/pre_send_email/nonce_verified' ($user) → the recipient;
 *   2. filter 'shield/2fa_email_otp' ($code)                          → the code, which Shield always puts in the body;
 *   3. wp_mail() → claimed when it goes to exactly that user and contains exactly that code. One-shot.
 *
 * If Shield sends through its own SureSend service instead, wp_mail() never runs and nothing is claimed.
 */
final class TwoFactorLoginEmail implements EmailType
{
    private ?string $pendingEmail = null;
    private ?string $pendingCode  = null;

    public function id(): string
    {
        return 'two-factor-login';
    }

    public function label(): string
    {
        return __('Email 2FA login (code and auto-login link)', 'xolution-maildelivery');
    }

    public function description(): string
    {
        return __('The "Two-Factor Login Verification" email Shield sends at every login when Email Authentication is on.', 'xolution-maildelivery');
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        // Shield builds this email as HTML; a plain-text version is added when it's sent.
        return 'text/html';
    }

    public function register(string $marker): void
    {
        add_action('shield/2fa/email/pre_send_email/nonce_verified', [$this, 'expectRecipient'], PHP_INT_MAX, 1);
        // Last, so the code we match is the one that ends up in the email.
        add_filter('shield/2fa_email_otp', [$this, 'expectCode'], PHP_INT_MAX);
        // Just before the dispatcher's own late wp_mail filter, which keeps only enabled markers.
        add_filter('wp_mail', fn (mixed $atts): mixed => $this->claim($atts, $marker), PHP_INT_MAX - 1);
    }

    public function expectRecipient(mixed $user): void
    {
        $this->pendingEmail = $user instanceof WP_User ? strtolower((string) $user->user_email) : null;
        $this->pendingCode  = null;
    }

    public function expectCode(mixed $code): mixed
    {
        if (is_string($code) && $code !== '') {
            $this->pendingCode = $code;
        }

        return $code;
    }

    public function claim(mixed $atts, string $marker): mixed
    {
        if (!is_array($atts) || $this->pendingEmail === null || $this->pendingCode === null) {
            return $atts;
        }
        $to = Address::parseList($atts['to'] ?? []);
        if (
            count($to) !== 1
            || strtolower($to[0]->email) !== $this->pendingEmail
            || !str_contains((string) ($atts['message'] ?? ''), $this->pendingCode)
        ) {
            return $atts;
        }

        $this->pendingEmail = null;
        $this->pendingCode  = null;

        return Claim::mark($atts, $marker);
    }
}
