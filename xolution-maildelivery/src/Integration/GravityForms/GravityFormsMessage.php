<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\GravityForms;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Mail\Address;
use Xolution\MailDelivery\Mail\Headers;

/**
 * Reads the emails Gravity Forms builds in GFCommon::send_email() (checked against Gravity Forms 3.2.2): a From
 * header with the notification's From Name and From Email, optional Reply-To, Cc and Bcc headers, a Content-type
 * of text/html, text/plain or multipart/alternative, and for multipart a hand-built MIME body.
 */
final class GravityFormsMessage
{
    /**
     * The From header's address and display name. GF writes it as: From: "Name" <email>.
     *
     * @param list<string> $lines
     */
    public static function from(array $lines): ?Address
    {
        $value = self::header($lines, 'from');
        if ($value === null) {
            return null;
        }
        // Not Address::parseList(): that splits on commas, and a From Name may contain one ("Smith, John").
        if (preg_match('/^(.*)<([^<>]+)>\s*$/', $value, $m) === 1) {
            $name = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $m[1]) ?? '';

            return new Address(trim($m[2]), trim(trim($name), '"\''));
        }

        return new Address(trim($value));
    }

    /**
     * Adds Reply-To: <From Email> when there is no Reply-To yet. The email is sent from this site's own address,
     * so without it a reply would go there instead of to whoever GF meant to send it.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function withReplyToFromSender(array $lines): array
    {
        if (self::header($lines, 'reply-to') !== null) {
            return $lines;
        }
        $from = self::from($lines);
        if ($from === null || !$from->isValid()) {
            return $lines;
        }
        $lines[] = 'Reply-To: ' . $from->email;

        return $lines;
    }

    /**
     * To, Cc and Bcc together, each address once.
     *
     * @param string|list<string> $to
     * @param list<string>        $lines
     * @return list<Address>
     */
    public static function allRecipients(string|array $to, array $lines): array
    {
        $all = Address::parseList($to);
        foreach (['cc', 'bcc'] as $name) {
            foreach (self::headers($lines, $name) as $value) {
                array_push($all, ...Address::parseList($value));
            }
        }

        $seen   = [];
        $unique = [];
        foreach ($all as $address) {
            $key = strtolower($address->email);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $address;
            }
        }

        return $unique;
    }

    /**
     * @param list<string> $lines
     * @return string 'text/plain' or 'text/html'; GF's own default is HTML.
     */
    public static function contentType(array $lines): string
    {
        return stripos((string) self::header($lines, 'content-type'), 'text/plain') === 0 ? 'text/plain' : 'text/html';
    }

    /**
     * Turns a multipart notification into its HTML part, with a matching Content-type. AhaSend gets the HTML and
     * a text version made from it, so nothing is lost. Null when the body isn't the multipart GF builds.
     *
     * @param array<string, mixed> $email gform_pre_send_email's email array.
     * @return array<string, mixed>|null
     */
    public static function flattenMultipart(array $email): ?array
    {
        $lines       = Headers::toLines($email['headers'] ?? []);
        $contentType = (string) self::header($lines, 'content-type');
        if (stripos($contentType, 'multipart/') !== 0) {
            return $email;
        }

        $boundary = preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m) === 1 ? $m[1] : '';
        // GF adds the boundary through the wp_mail_content_type filter, not always in the header itself.
        if ($boundary === '' && class_exists('GFCommon', false) && isset(\GFCommon::$email_boundary)) {
            $boundary = (string) \GFCommon::$email_boundary;
        }
        $html = $boundary === '' ? null : self::htmlPart((string) ($email['message'] ?? ''), $boundary);
        if ($html === null) {
            return null;
        }

        $lines = array_values(array_filter($lines, static fn (string $line): bool => !self::is($line, 'content-type')));
        $lines[] = 'Content-type: text/html; charset=UTF-8';

        $email['message'] = $html;
        $email['headers'] = $lines;

        return $email;
    }

    private static function htmlPart(string $message, string $boundary): ?string
    {
        $message = str_replace("\r\n", "\n", $message);
        foreach (explode('--' . $boundary, $message) as $part) {
            $part = ltrim($part, "\n");
            $end  = strpos($part, "\n\n");
            if ($end === false) {
                continue;
            }
            if (stripos(substr($part, 0, $end), 'text/html') !== false) {
                return rtrim(substr($part, $end + 2), "\n");
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private static function header(array $lines, string $name): ?string
    {
        return self::headers($lines, $name)[0] ?? null;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function headers(array $lines, string $name): array
    {
        $values = [];
        foreach ($lines as $line) {
            if (self::is($line, $name)) {
                $values[] = trim(substr($line, strpos($line, ':') + 1));
            }
        }

        return $values;
    }

    private static function is(string $line, string $name): bool
    {
        $colon = strpos($line, ':');

        return $colon !== false && strcasecmp(trim(substr($line, 0, $colon)), $name) === 0;
    }
}
