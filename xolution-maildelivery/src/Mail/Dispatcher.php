<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Mail;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Plugin;

/**
 * The single place where claimed email leaves WordPress. Everything without a valid claim is left to core.
 */
final class Dispatcher
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        // Early, so a claimed email is ours even when another mailer plugin also uses pre_wp_mail.
        add_filter('pre_wp_mail', [$this, 'handle'], -100, 2);
        // Last, after every other wp_mail filter: remove markers we won't act on, so they don't leak into the
        // normal mailer's headers.
        add_filter('wp_mail', [$this, 'stripUnclaimable'], PHP_INT_MAX);
    }

    public function stripUnclaimable(mixed $atts): mixed
    {
        if (!is_array($atts)) {
            return $atts;
        }
        $marker = Claim::find($atts['headers'] ?? []);
        if ($marker !== null && $this->plugin->registry->enabledType($marker) === null) {
            $atts['headers'] = Claim::strip($atts['headers'] ?? []);
        }

        return $atts;
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function handle(mixed $return, array $atts): mixed
    {
        if ($return !== null) {
            return $return; // Already short-circuited by someone else.
        }
        $marker = Claim::find($atts['headers'] ?? []);
        $type   = $marker === null ? null : $this->plugin->registry->enabledType($marker);
        if ($type === null) {
            return null;
        }

        // No fallback to the normal mailer: a claimed email is sent by AhaSend or it fails (ARCHITECTURE decision 2).
        return (new Sender($this->plugin))->sendWpMail($atts, $marker, $type->contentType());
    }
}
