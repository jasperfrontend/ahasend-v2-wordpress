<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

/**
 * A plain-text version of an HTML email, sent alongside it. Links are kept as visible URLs, so a login link still
 * works in a mail client that shows only text.
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html; // includes Outlook's <!--[if mso]> blocks
        $html = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        // HTML whitespace means one space; line breaks come only from the markup below.
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;

        // Links become placeholders, so decoding entities later can't touch the URLs.
        $links = [];
        $html  = preg_replace_callback(
            '#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $m) use (&$links): string {
                $url   = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $links[] = ($label === '' || $label === $url) ? $url : $label . ' (' . $url . ')';

                return "\x1A" . (count($links) - 1) . "\x1A";
            },
            $html,
        ) ?? $html;

        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
        $html = preg_replace('#</?(p|div|h[1-6]|tr|table|ul|ol|blockquote|section|header|footer)\b[^>]*>#i', "\n\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('/\x1A(\d+)\x1A/', static fn (array $m): string => $links[(int) $m[1]] ?? '', $text) ?? $text;

        $lines = array_map(
            static fn (string $line): string => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line),
            explode("\n", $text),
        );
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '';

        return trim($text) . "\n";
    }
}
