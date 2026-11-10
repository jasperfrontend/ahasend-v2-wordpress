<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

/**
 * An email type that is listed in the settings but not implemented yet.
 */
final class PlannedEmailType implements EmailType
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description = '',
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function isImplemented(): bool
    {
        return false;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function register(string $marker): void
    {
    }
}
