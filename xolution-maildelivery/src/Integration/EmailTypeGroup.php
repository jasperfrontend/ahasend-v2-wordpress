<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

/**
 * A named set of email types within one integration, shown under its own sub-heading in the settings.
 */
final class EmailTypeGroup
{
    /**
     * @param list<EmailType> $types
     */
    public function __construct(
        public readonly string $label,
        public readonly array $types,
        public readonly string $description = '',
    ) {
    }
}
