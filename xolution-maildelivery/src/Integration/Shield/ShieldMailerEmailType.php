<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\Shield;

defined('ABSPATH') || exit;

use Closure;
use Xolution\MailDelivery\Integration\EmailType;
use Xolution\MailDelivery\Mail\Claim;

/**
 * A Shield email recognised by the Shield method that sends it.
 *
 * Shield sends its alerts, reports and notices through one mailer, EmailCon::sendVO(), which calls a bare
 * wp_mail() with no filter or action of its own (checked against Shield 22.3.4). What tells them apart is the
 * method that called sendVO(): InstantAlertsCon::sendAlert() for every instant alert, with the alert's handler as
 * its first argument; ReportGenerator::sendNotificationEmail() for both reports, with the report; and so on. So
 * the email is claimed when, on the call stack at wp_mail(), exactly that method (and optionally its arguments)
 * called sendVO(). Only the direct caller counts, so an email sent while another one is being built can't be
 * mistaken for it.
 *
 * If Shield renames either method, nothing matches any more and the email goes through the normal mailer again.
 */
final class ShieldMailerEmailType implements EmailType
{
    private const MAILER_CLASS  = 'FernleafSystems\\Wordpress\\Plugin\\Shield\\Controller\\Email\\EmailCon';
    private const MAILER_METHOD = 'sendVO';

    /** Deep enough for wp_mail filter → WP_Hook → apply_filters → wp_mail → sendVO → the sending method. */
    private const TRACE_DEPTH = 16;

    /**
     * @param string                            $class     Fully qualified Shield class that declares $method.
     * @param string                            $method    The method that calls EmailCon::sendVO() for this email.
     * @param (Closure(list<mixed>): bool)|null $matchArgs Checks the method's arguments; null matches any call.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description,
        private readonly string $class,
        private readonly string $method,
        private readonly ?Closure $matchArgs = null,
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
        // Shield builds all its emails as HTML; a plain-text version is added when it's sent.
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

        // Arguments are needed for $matchArgs; objects are not.
        $trace ??= debug_backtrace(0, self::TRACE_DEPTH);
        $caller = self::callerOfMailer($trace);
        if (
            $caller === null
            || ($caller['class'] ?? null) !== $this->class
            || ($caller['function'] ?? null) !== $this->method
        ) {
            return $atts;
        }

        $args = array_values((array) ($caller['args'] ?? []));
        if ($this->matchArgs !== null && !($this->matchArgs)($args)) {
            return $atts;
        }

        return Claim::mark($atts, $marker);
    }

    /**
     * The frame of the method that called EmailCon::sendVO(), innermost first: in a backtrace, the frame after a
     * call is the call of the function that made it.
     *
     * @param list<array<string, mixed>> $trace
     * @return array<string, mixed>|null
     */
    private static function callerOfMailer(array $trace): ?array
    {
        $trace = array_values($trace);
        foreach ($trace as $i => $frame) {
            if (($frame['class'] ?? null) === self::MAILER_CLASS && ($frame['function'] ?? null) === self::MAILER_METHOD) {
                return $trace[$i + 1] ?? null;
            }
        }

        return null;
    }
}
