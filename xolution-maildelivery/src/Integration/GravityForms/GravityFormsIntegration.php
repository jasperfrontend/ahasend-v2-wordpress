<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\GravityForms;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\Integration;

/**
 * Gravity Forms. Hooks checked against Gravity Forms 3.2.2.
 */
final class GravityFormsIntegration implements Integration
{
    public function id(): string
    {
        return 'gravityforms';
    }

    public function label(): string
    {
        return __('Gravity Forms', 'xolution-maildelivery');
    }

    public function isAvailable(): bool
    {
        // Defined by Gravity Forms' main plugin file, which WordPress loads before init.
        return class_exists('GFForms', false);
    }

    public function emailTypes(): array
    {
        return [
            new NotificationEmail(
                'notifications',
                __('Form notifications', 'xolution-maildelivery'),
                __('Every notification of every form, except Save and Continue. CC and BCC addresses each get their own copy. The notification\'s From Name is kept, and its From Email becomes the Reply-To when none is set.', 'xolution-maildelivery'),
                false,
            ),
            new NotificationEmail(
                'save-and-continue',
                __('Save and Continue link', 'xolution-maildelivery'),
                __('The notifications for "Form is saved" and "Save and continue email is requested": the link to resume a saved form.', 'xolution-maildelivery'),
                true,
            ),
            new EntryNoteEmail(),
        ];
    }
}
