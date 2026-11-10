<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Api;

defined('ABSPATH') || exit;

final class SendResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly ?string $messageId,
        public readonly string $error,
    ) {
    }

    public static function success(?string $messageId, ?int $httpStatus): self
    {
        return new self(true, $httpStatus, $messageId, '');
    }

    public static function failure(string $error, ?int $httpStatus = null): self
    {
        return new self(false, $httpStatus, null, $error);
    }
}
