<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Mail\Address;

/**
 * An email type whose emails differ from one another, like Gravity Forms notifications: HTML or plain text per
 * notification, their own From Name, and CC/BCC addresses that must not miss the email.
 *
 * Everything is read from the claimed email's own wp_mail() arguments, so there is no state between the claim and
 * the send. The sender address itself always stays this site's (ARCHITECTURE decision 7).
 */
interface PerEmailOptions extends EmailType
{
    /**
     * @param array<string, mixed> $atts The claimed wp_mail() arguments.
     * @return string 'text/plain' or 'text/html'
     */
    public function contentTypeFor(array $atts): string;

    /**
     * @param array<string, mixed> $atts The claimed wp_mail() arguments.
     * @return string The From display name, or '' for the site title.
     */
    public function fromNameFor(array $atts): string;

    /**
     * Everyone who gets their own copy (FINDINGS decisions 2 and 4: one email to one person, one request each).
     *
     * @param array<string, mixed> $atts The claimed wp_mail() arguments.
     * @return list<Address>
     */
    public function recipientsFor(array $atts): array;
}
