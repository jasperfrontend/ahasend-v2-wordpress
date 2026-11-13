<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Duplicator;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Claim;

/**
 * Duplicator Pro's scheduled-backup email: what Settings → Schedules → "Send Build Email" (Never / On Failure /
 * Always) switches on, sent to its "Email Address", or the site admin email when that is empty.
 *
 * Checked against Duplicator Pro 5.1.1. Two methods of SchedulePackageHandler send it, each with a bare wp_mail()
 * and no filter of their own:
 *   - sendBuildEmail(): a scheduled backup finished ("Backup of <site> (<schedule>) Succeeded" or "Failed");
 *   - sendScheduleFailureEmail(): a scheduled backup couldn't even start ("... Failed").
 * So the email is claimed when one of them is the direct caller of wp_mail() on the call stack. If Duplicator
 * renames them, nothing matches any more and the email goes through the normal mailer again.
 *
 * When it's sent stays Duplicator's choice: this only changes the route.
 */
final class ScheduledBackupEmail implements EmailType
{
    private const HANDLER = 'Duplicator\\Addons\\ScheduleAddon\\Package\\SchedulePackageHandler';
    private const SENDERS = ['sendBuildEmail', 'sendScheduleFailureEmail'];

    /** Deep enough for our wp_mail filter → WP_Hook → apply_filters → wp_mail → the sending method. */
    private const TRACE_DEPTH = 12;

    public function id(): string
    {
        return 'scheduled-backup';
    }

    public function label(): string
    {
        return __('Scheduled backup result', 'xolution-maildelivery');
    }

    public function description(): string
    {
        return __('The "Send Build Email" of Duplicator Pro\'s schedules: a scheduled backup succeeded, failed, or couldn\'t start. When it\'s sent, and to whom, is set in Duplicator → Settings → Schedules.', 'xolution-maildelivery');
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        // Duplicator renders both as HTML templates; a plain-text version is added when it's sent.
        return 'text/html';
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
        // An email another type already claimed stays with that type.
        if (!is_array($atts) || Claim::find($atts['headers'] ?? []) !== null) {
            return $atts;
        }

        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH);
        $caller = self::callerOfWpMail($trace);
        if (
            $caller === null
            || ($caller['class'] ?? null) !== self::HANDLER
            || !in_array($caller['function'] ?? null, self::SENDERS, true)
        ) {
            return $atts;
        }

        return Claim::mark($atts, $marker);
    }

    /**
     * The frame of the function that called wp_mail(): in a backtrace, the frame after a call is the call of the
     * function that made it.
     *
     * @param list<array<string, mixed>> $trace
     * @return array<string, mixed>|null
     */
    private static function callerOfWpMail(array $trace): ?array
    {
        $trace = array_values($trace);
        foreach ($trace as $i => $frame) {
            if (($frame['function'] ?? null) === 'wp_mail' && !isset($frame['class'])) {
                return $trace[$i + 1] ?? null;
            }
        }

        return null;
    }
}
