<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\WpCore;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Address;
use Xolution\MailDelivery\Mail\Claim;

/**
 * The confirmation emails WordPress sends to a *new* address before an email change takes effect: for a user
 * (send_confirmation_on_profile_email()) and for the site admin email (update_option_new_admin_email()).
 *
 * Core only filters the text, then fills in the link and calls wp_mail() without headers (checked against WP 7.1.4).
 * The filter does hand over the new address and the hash that ends up in the confirmation link, so the next
 * wp_mail() to exactly that address containing exactly that hash is claimed. One-shot.
 */
final class EmailChangeConfirmation implements EmailType
{
    /** @var array{to: string, hash: string}|null */
    private ?array $pending = null;

    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description,
        private readonly string $contentFilter,
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
        return 'text/plain';
    }

    public function register(string $marker): void
    {
        add_filter($this->contentFilter, [$this, 'expect'], PHP_INT_MAX, 2);
        add_filter('wp_mail', fn (mixed $atts): mixed => $this->claim($atts, $marker), PHP_INT_MAX - 1);
    }

    /**
     * @param mixed $data ['hash' => ..., 'newemail' => ...]
     */
    public function expect(mixed $content, mixed $data = null): mixed
    {
        $this->pending = null;
        if (is_array($data) && is_string($data['hash'] ?? null) && $data['hash'] !== '' && is_string($data['newemail'] ?? null)) {
            $this->pending = ['to' => strtolower(trim($data['newemail'])), 'hash' => $data['hash']];
        }

        return $content;
    }

    public function claim(mixed $atts, string $marker): mixed
    {
        if (!is_array($atts) || $this->pending === null) {
            return $atts;
        }
        $to = Address::parseList($atts['to'] ?? []);
        if (
            count($to) !== 1
            || strtolower($to[0]->email) !== $this->pending['to']
            || !str_contains((string) ($atts['message'] ?? ''), $this->pending['hash'])
        ) {
            return $atts;
        }

        $this->pending = null;

        return Claim::mark($atts, $marker);
    }
}
