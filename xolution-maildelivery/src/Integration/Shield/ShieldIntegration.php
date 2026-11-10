<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

use Xolution\MailDelivery\Integration\Integration;

/**
 * Shield Security (wp-simple-firewall).
 */
final class ShieldIntegration implements Integration
{
    public function id(): string
    {
        return 'shield';
    }

    public function label(): string
    {
        return __('Shield Security', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        // Defined by Shield's main plugin file, which WordPress loads before init.
        return function_exists('icwp_wpsf_init');
    }

    public function emailTypes(): array
    {
        return [
            new TwoFactorLoginEmail(),
            new EmailSendingVerification(),
        ];
    }
}
