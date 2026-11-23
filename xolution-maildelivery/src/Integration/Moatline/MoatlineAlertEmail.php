<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Moatline;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Claim;

/**
 * A set of Moatline alerts, recognised by the Moatline methods that send them.
 *
 * Every Moatline email, from the free plugin and the Pro add-on alike, goes through one helper:
 * nnms_send_alert($subject, $body), a plain function that sends a bare plain-text wp_mail() with no filter of its
 * own (checked against Moatline Malware Scanner 1.4.4 and Moatline Pro 1.5.2). What tells the alerts apart is the
 * method that called nnms_send_alert(): NNMS_Scanner::finalise() for threats found by a scan, NNMS_Watch::on_install()
 * for a new plugin, and so on. So the email is claimed when, on the call stack at wp_mail(), nnms_send_alert() is the
 * direct caller of wp_mail() and one of this type's methods is the direct caller of nnms_send_alert().
 *
 * If Moatline renames the helper or a method, nothing matches any more and the email goes through the normal mailer
 * again.
 */
final class MoatlineAlertEmail implements EmailType
{
    private const HELPER = 'nnms_send_alert';

    /** Deep enough for wp_mail filter → WP_Hook → apply_filters → wp_mail → nnms_send_alert → the sending method. */
    private const TRACE_DEPTH = 12;

    /**
     * @param list<array{0: string, 1: string}> $senders [class, method] pairs that call nnms_send_alert() for this
     *                                                   type. Moatline's classes aren't namespaced.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description,
        private readonly array $senders,
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
        // nnms_send_alert() sends plain text with no headers.
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
        // An email another type already claimed stays with that type.
        if (!is_array($atts) || Claim::find($atts['headers'] ?? []) !== null) {
            return $atts;
        }

        $trace ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_DEPTH);
        $sender = self::senderOf($trace);
        if ($sender === null) {
            return $atts;
        }

        foreach ($this->senders as [$class, $method]) {
            if ($sender === [$class, $method]) {
                return Claim::mark($atts, $marker);
            }
        }

        return $atts;
    }

    /**
     * The [class, method] that called nnms_send_alert(), when nnms_send_alert() called wp_mail(). In a backtrace,
     * the frame after a call is the call of the function that made it.
     *
     * @param list<array<string, mixed>> $trace
     * @return array{0: string, 1: string}|null
     */
    private static function senderOf(array $trace): ?array
    {
        $trace = array_values($trace);
        foreach ($trace as $i => $frame) {
            if (($frame['function'] ?? null) !== 'wp_mail' || isset($frame['class'])) {
                continue;
            }

            $helper = $trace[$i + 1] ?? null;
            $sender = $trace[$i + 2] ?? null;
            if (
                $helper === null
                || ($helper['function'] ?? null) !== self::HELPER
                || isset($helper['class'])
                || !is_string($sender['class'] ?? null)
                || !is_string($sender['function'] ?? null)
            ) {
                return null;
            }

            return [$sender['class'], $sender['function']];
        }

        return null;
    }
}
