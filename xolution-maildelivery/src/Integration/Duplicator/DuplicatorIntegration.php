<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Duplicator;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\Integration;

/**
 * Duplicator Pro (duplicator-pro). Checked against Duplicator Pro 5.1.1.
 */
final class DuplicatorIntegration implements Integration
{
    /** The class that sends the scheduled-backup emails; only Duplicator Pro has schedules. */
    private const SCHEDULE_HANDLER = 'Duplicator\\Addons\\ScheduleAddon\\Package\\SchedulePackageHandler';

    public function id(): string
    {
        return 'duplicator';
    }

    public function label(): string
    {
        return __('Duplicator Pro', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        // DUPLICATOR_VERSION is defined when Duplicator's main file loads, which WordPress does before init.
        return defined('DUPLICATOR_VERSION') && class_exists(self::SCHEDULE_HANDLER);
    }

    public function emailTypes(): array
    {
        return [new ScheduledBackupEmail()];
    }
}
