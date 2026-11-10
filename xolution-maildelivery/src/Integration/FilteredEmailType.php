<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Mail\Claim;

/**
 * An email whose source hands its full wp_mail() arguments (to, subject, message, headers) to a filter before
 * sending, like most WordPress Core emails. Claiming is adding the marker in that filter.
 */
final class FilteredEmailType implements EmailType
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description,
        private readonly string $filter,
        private readonly string $contentType = 'text/plain',
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
        return true;
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    public function register(string $marker): void
    {
        // Last, so whatever other plugins change in this email is kept; we only add the marker.
        add_filter(
            $this->filter,
            static fn (mixed $email): mixed => is_array($email) ? Claim::mark($email, $marker) : $email,
            PHP_INT_MAX,
        );
    }
}
