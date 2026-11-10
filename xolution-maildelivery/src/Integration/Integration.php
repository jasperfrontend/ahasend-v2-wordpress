<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

/**
 * A source of email (WordPress Core, Shield, WooCommerce, ...) that this plugin can take over.
 */
interface Integration
{
    /** Stable ID, used in markers and settings: 'wp-core'. */
    public function id(): string;

    public function label(): string;

    /** False when the source isn't present on this site (e.g. the plugin isn't active). */
    public function isAvailable(): bool;

    /** @return list<EmailType> */
    public function emailTypes(): array;
}
