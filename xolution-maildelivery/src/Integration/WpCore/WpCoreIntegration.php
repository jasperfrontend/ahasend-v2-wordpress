<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\WpCore;

use Xolution\MailDelivery\Integration\Integration;
use Xolution\MailDelivery\Integration\PlannedEmailType;

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
            new PasswordReset(),
            new PlannedEmailType('new-user', __('New user: set your password (to the user)', 'xolution-maildelivery')),
            new PlannedEmailType('new-user-admin', __('New user (to the site admin)', 'xolution-maildelivery')),
            new PlannedEmailType('password-changed', __('Password changed', 'xolution-maildelivery')),
            new PlannedEmailType('email-changed', __('Email address changed', 'xolution-maildelivery')),
            new PlannedEmailType('admin-email-change', __('Admin email change confirmation', 'xolution-maildelivery')),
        ];
    }
}
