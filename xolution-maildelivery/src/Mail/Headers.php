<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

defined('ABSPATH') || exit;

/**
 * Turns wp_mail()'s $headers (a string or an array of lines) into what the AhaSend API takes.
 */
final class Headers
{
    /**
     * Headers this plugin sets itself, or that AhaSend sets or rejects. Never copied into the request's headers.
     */
    private const RESERVED = [
        'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender', 'return-path',
        'content-type', 'content-transfer-encoding', 'mime-version', 'message-id', 'date',
    ];

    /**
     * @return list<string>
     */
    public static function toLines(string|array $headers): array
    {
        if (is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        $lines = [];
        foreach ($headers as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Splits headers into a Reply-To address and the custom headers AhaSend may pass on.
     * From, Cc, Bcc and Content-Type are dropped: the plugin decides those (FINDINGS decision 2, ARCHITECTURE 4 and 7).
     *
     * @param list<string> $lines
     * @return array{reply_to: ?array{email: string, name?: string}, headers: array<string, string>}
     */
    public static function parse(array $lines): array
    {
        $replyTo = null;
        $custom  = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name  = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));
            $lower = strtolower($name);

            // A CR or other control character left in a value could start a new header further down the line.
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                continue;
            }
            if ($lower === 'reply-to') {
                $first   = Address::parseList($value)[0] ?? null;
                $replyTo = $first !== null && $first->isValid() ? $first->toApi() : null;
                continue;
            }
            if (in_array($lower, self::RESERVED, true) || strcasecmp($name, Claim::HEADER) === 0) {
                continue;
            }
            // AhaSend: visible ASCII, no spaces, no colons.
            if ($value === '' || preg_match('/^[!-9;-~]+$/', $name) !== 1) {
                continue;
            }
            $custom[$name] = $value;
        }

        return ['reply_to' => $replyTo, 'headers' => $custom];
    }
}
