<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Integration\GravityForms;

defined('ABSPATH') || exit;

use Xolution\MailDelivery\Integration\PerEmailOptions;
use Xolution\MailDelivery\Mail\Claim;
use Xolution\MailDelivery\Mail\Headers;

/**
 * Gravity Forms notifications. Every notification, whatever triggers it (submission, payment, resend, the REST
 * API, background processing), goes through GFCommon::send_email(), which hands the finished email to the filter
 * 'gform_pre_send_email' just before wp_mail() (checked against Gravity Forms 3.2.2). That is the claim.
 *
 * Two instances: the Save and Continue notifications (events 'form_saved' and 'form_save_email_requested', plus
 * the legacy GFCommon::send_resume_link(), which has no notification) and all other notifications.
 *
 * Per email: the format GF chose (HTML, text, or multipart sent as HTML), the notification's From Name, and every
 * To, CC and BCC address getting its own copy. Its From Email becomes the Reply-To when there is none.
 */
final class NotificationEmail implements PerEmailOptions
{
    private const SAVE_AND_CONTINUE_EVENTS = ['form_saved', 'form_save_email_requested'];

    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $description,
        private readonly bool $saveAndContinue,
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
        // GF's default; the real one comes per email from contentTypeFor().
        return 'text/html';
    }

    public function register(string $marker): void
    {
        // Last, so whatever other plugins change in the email is kept.
        add_filter(
            'gform_pre_send_email',
            fn (mixed $email, mixed $format = null, mixed $notification = null): mixed => $this->claim($email, $notification, $marker),
            PHP_INT_MAX,
            3,
        );
    }

    public function claim(mixed $email, mixed $notification, string $marker): mixed
    {
        if (!is_array($email) || !empty($email['abort_email']) || Claim::find($email['headers'] ?? []) !== null) {
            return $email;
        }
        if (self::isSaveAndContinue($notification) !== $this->saveAndContinue) {
            return $email;
        }

        // A multipart body we can't take apart is left to the normal mailer, rather than sent as garbage.
        $flat = GravityFormsMessage::flattenMultipart($email);
        if ($flat === null) {
            return $email;
        }

        $flat['headers'] = GravityFormsMessage::withReplyToFromSender(Headers::toLines($flat['headers'] ?? []));

        return Claim::mark($flat, $marker);
    }

    public function contentTypeFor(array $atts): string
    {
        return GravityFormsMessage::contentType(Headers::toLines($atts['headers'] ?? []));
    }

    public function fromNameFor(array $atts): string
    {
        return GravityFormsMessage::from(Headers::toLines($atts['headers'] ?? []))?->name ?? '';
    }

    public function recipientsFor(array $atts): array
    {
        return GravityFormsMessage::allRecipients($atts['to'] ?? [], Headers::toLines($atts['headers'] ?? []));
    }

    private static function isSaveAndContinue(mixed $notification): bool
    {
        if (!is_array($notification)) {
            return true; // Only GFCommon::send_resume_link() sends without a notification.
        }

        return in_array((string) ($notification['event'] ?? ''), self::SAVE_AND_CONTINUE_EVENTS, true);
    }
}
