<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\WpCore;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Claim;

/**
 * The "Password Reset" email from wp-login.php?action=lostpassword (retrieve_password()).
 */
final class PasswordReset implements EmailType
{
    public function id(): string
    {
        return 'password-reset';
    }

    public function label(): string
    {
        return __('Password reset', 'xolution-maildelivery');
    }

    public function description(): string
    {
        return __('The reset link sent from the "Lost your password?" form.', 'xolution-maildelivery');
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        // Core writes this email as plain text. Sent as-is.
        return 'text/plain';
    }

    public function register(string $marker): void
    {
        // Last, so whatever other plugins change in this email is kept; we only add the marker.
        add_filter(
            'retrieve_password_notification_email',
            static fn (array $email): array => Claim::mark($email, $marker),
            PHP_INT_MAX,
        );
    }
}
