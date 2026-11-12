<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\GravityForms;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Claim;
use Xolution\MailDelivery\Mail\Headers;

/**
 * An entry note emailed from the entry screen ("Also email this note to"). Gravity Forms sends it with a bare
 * wp_mail() straight from GFEntryDetail::lead_detail_page(), plain text, From the admin who wrote the note
 * (checked against Gravity Forms 3.2.2). It is claimed when exactly that method called wp_mail(), and the admin's
 * address becomes the Reply-To, so a reply reaches them rather than this site's sending address.
 */
final class EntryNoteEmail implements EmailType
{
    private const CALLER_CLASS  = 'GFEntryDetail';
    private const CALLER_METHOD = 'lead_detail_page';

    /** Deep enough for wp_mail filter → WP_Hook → apply_filters → wp_mail → the caller. */
    private const TRACE_DEPTH = 12;

    public function id(): string
    {
        return 'entry-note';
    }

    public function label(): string
    {
        return __('Entry note emailed', 'xolution-maildelivery');
    }

    public function description(): string
    {
        return __('A note added to an entry and emailed from the entry screen. Replies go to the admin who wrote it.', 'xolution-maildelivery');
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
        // Just before the dispatcher's own late wp_mail filter, which keeps only enabled markers.
        add_filter('wp_mail', fn (mixed $atts): mixed => $this->claim($atts, $marker), PHP_INT_MAX - 1);
    }

    /**
     * @param list<array<string, mixed>>|null $trace Only for tests; defaults to the current call stack.
     */
    public function claim(mixed $atts, string $marker, ?array $trace = null): mixed
    {
        if (!is_array($atts) || Claim::find($atts['headers'] ?? []) !== null) {
            return $atts;
        }

        $trace = array_values($trace ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH));
        foreach ($trace as $i => $frame) {
            if (!isset($frame['class']) && ($frame['function'] ?? null) === 'wp_mail') {
                $caller = $trace[$i + 1] ?? [];
                if (($caller['class'] ?? null) !== self::CALLER_CLASS || ($caller['function'] ?? null) !== self::CALLER_METHOD) {
                    return $atts;
                }
                $atts['headers'] = GravityFormsMessage::withReplyToFromSender(Headers::toLines($atts['headers'] ?? []));

                return Claim::mark($atts, $marker);
            }
        }

        return $atts;
    }
}
