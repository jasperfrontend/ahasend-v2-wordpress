<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\WpCore;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\FilteredEmailType;
use Xolution\MailDelivery\Integration\Integration;

/**
 * WordPress Core's account emails. Hooks checked against WordPress 7.1.4.
 */
final class WpCoreIntegration implements Integration
{
    public function id(): string
    {
        return 'wp-core';
    }

    public function label(): string
    {
        return __('WordPress Core', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function emailTypes(): array
    {
        return [
            new FilteredEmailType(
                'password-reset',
                __('Password reset', 'xolution-maildelivery'),
                __('The reset link sent from the "Lost your password?" form.', 'xolution-maildelivery'),
                'retrieve_password_notification_email',
            ),
            new FilteredEmailType(
                'new-user',
                __('New user: login details (to the user)', 'xolution-maildelivery'),
                __('Sent to a new user, with the link to set their password.', 'xolution-maildelivery'),
                'wp_new_user_notification_email',
            ),
            new FilteredEmailType(
                'new-user-admin',
                __('New user registration (to the site admin)', 'xolution-maildelivery'),
                __('Tells the site admin email address that a user registered.', 'xolution-maildelivery'),
                'wp_new_user_notification_email_admin',
            ),
            new FilteredEmailType(
                'password-changed',
                __('Password changed (to the user)', 'xolution-maildelivery'),
                __('Confirms to a user that their password was changed.', 'xolution-maildelivery'),
                'password_change_email',
            ),
            new FilteredEmailType(
                'password-changed-admin',
                __('Password reset by a user (to the site admin)', 'xolution-maildelivery'),
                __('Tells the site admin email address that a user reset their password.', 'xolution-maildelivery'),
                'wp_password_change_notification_email',
            ),
            new FilteredEmailType(
                'email-changed',
                __('Email address changed (to the old address)', 'xolution-maildelivery'),
                __('Tells a user, at their old address, that their email address was changed.', 'xolution-maildelivery'),
                'email_change_email',
            ),
            new EmailChangeConfirmation(
                'email-change-confirm',
                __('Email change confirmation (to the new address)', 'xolution-maildelivery'),
                __('The link a user must click before a new email address on their profile takes effect.', 'xolution-maildelivery'),
                'new_user_email_content',
            ),
            new EmailChangeConfirmation(
                'admin-email-change',
                __('Admin email change confirmation (to the new address)', 'xolution-maildelivery'),
                __('The link that must be clicked before a new site admin email address takes effect.', 'xolution-maildelivery'),
                'new_admin_email_content',
            ),
        ];
    }
}
