<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

defined('ABSPATH') || exit;

final class Address
{
    public function __construct(public readonly string $email, public readonly string $name = '')
    {
    }

    /**
     * Parses wp_mail()'s $to: an array, or a comma-separated string, of "email" or "Name <email>".
     * Splits on commas the way core does. Addresses are returned as written; validate with isValid().
     *
     * @param string|list<string> $list
     * @return list<self>
     */
    public static function parseList(string|array $list): array
    {
        $parts     = is_array($list) ? $list : explode(',', $list);
        $addresses = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*)<(.+)>\s*$/', $part, $m) === 1) {
                // A display name never needs control characters; drop them so none reach a header.
                $name        = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $m[1]) ?? '';
                $addresses[] = new self(trim($m[2]), trim(trim($name), '"\''));
            } else {
                $addresses[] = new self($part);
            }
        }

        return $addresses;
    }

    public function isValid(): bool
    {
        return is_email($this->email) !== false;
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function toApi(): array
    {
        return $this->name === '' ? ['email' => $this->email] : ['email' => $this->email, 'name' => $this->name];
    }
}
