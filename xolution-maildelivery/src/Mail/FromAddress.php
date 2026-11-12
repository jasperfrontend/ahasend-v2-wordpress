<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

defined('ABSPATH') || exit;

/**
 * The sender for this site: <site-host>@<sending-domain>, e.g. client-site.nl@mail-delivery.example.com, so the
 * AhaSend dashboard shows which site sent what.
 */
final class FromAddress
{
    private const MAX_LOCAL_PART = 64;

    /** Long enough for any real sender name; keeps a runaway merge tag out of the From header. */
    private const MAX_NAME_LENGTH = 100;

    private function __construct(public readonly string $email, public readonly string $name)
    {
    }

    public static function forSite(string $sendingDomain): self
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return new self(
            self::localPart($host) . '@' . strtolower($sendingDomain),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        );
    }

    /**
     * The same address with another display name, e.g. a Gravity Forms notification's From Name. Falls back to
     * the site title when nothing printable is left.
     */
    public function withName(string $name): self
    {
        // A display name never needs control characters; drop them so none reach a header.
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', wp_specialchars_decode($name, ENT_QUOTES)) ?? '');
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        return $name === '' ? $this : new self($this->email, $name);
    }

    public static function localPart(string $host): string
    {
        $host = strtolower(trim($host, '. '));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host  = $ascii !== false ? $ascii : $host;
        }
        // Keep what a dot-atom local part allows from a host name, and no leading, trailing or double dots.
        $host = preg_replace('/[^a-z0-9.\-]/', '-', $host) ?? '';
        $host = trim(preg_replace('/\.{2,}/', '.', $host) ?? '', '.');
        if ($host === '') {
            $host = 'wordpress';
        }

        if (strlen($host) > self::MAX_LOCAL_PART) {
            // Cut short, but keep it unique and traceable with a hash of the full host.
            $hash = substr(hash('sha256', $host), 0, 8);
            $host = rtrim(substr($host, 0, self::MAX_LOCAL_PART - 9), '.') . '-' . $hash;
        }

        return $host;
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function toApi(): array
    {
        return $this->name === '' ? ['email' => $this->email] : ['email' => $this->email, 'name' => $this->name];
    }
}
