<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

/**
 * Marks an email as taken over. The marker is a header that travels with the email's own wp_mail() arguments,
 * so there is no shared state between integrations.
 */
final class Claim
{
    public const HEADER = 'X-MailDelivery-Takeover';

    /**
     * @param array<string, mixed> $email Anything with a 'headers' key in wp_mail() format.
     * @return array<string, mixed>
     */
    public static function mark(array $email, string $marker): array
    {
        $headers   = Headers::toLines($email['headers'] ?? []);
        $headers   = array_values(array_filter($headers, static fn (string $line): bool => !self::isMarkerLine($line)));
        $headers[] = self::HEADER . ': ' . $marker;

        $email['headers'] = $headers;

        return $email;
    }

    /**
     * The marker in these headers, if any.
     */
    public static function find(string|array $headers): ?string
    {
        foreach (Headers::toLines($headers) as $line) {
            if (self::isMarkerLine($line)) {
                return trim(substr($line, strpos($line, ':') + 1));
            }
        }

        return null;
    }

    /**
     * The headers without any marker.
     *
     * @return list<string>
     */
    public static function strip(string|array $headers): array
    {
        return array_values(array_filter(
            Headers::toLines($headers),
            static fn (string $line): bool => !self::isMarkerLine($line),
        ));
    }

    private static function isMarkerLine(string $line): bool
    {
        $colon = strpos($line, ':');

        return $colon !== false && strcasecmp(trim(substr($line, 0, $colon)), self::HEADER) === 0;
    }
}
