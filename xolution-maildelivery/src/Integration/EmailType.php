<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

/**
 * One kind of email from an integration, switched on or off on its own.
 */
interface EmailType
{
    /** Stable ID within the integration: 'password-reset'. */
    public function id(): string;

    public function label(): string;

    public function description(): string;

    /** Listed but not switchable while false. */
    public function isImplemented(): bool;

    /** 'text/plain' or 'text/html'. Decided here, not by the wp_mail_content_type filter. */
    public function contentType(): string;

    /**
     * Hooks into the source so its emails get claimed with Claim::mark(). Only called when the type is enabled.
     *
     * @param string $marker '<integration>/<type>', to pass to Claim::mark().
     */
    public function register(string $marker): void;
}
