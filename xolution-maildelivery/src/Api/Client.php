<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Api;

defined('ABSPATH') || exit;

use WP_Error;

final class Client
{
    private const BASE_URL = 'https://api.ahasend.com';

    /**
     * A user is waiting on wp-login.php: keep a request and its one retry short. Only a first attempt that failed
     * fast is retried, and the retry gets a shorter timeout, so one email never holds a PHP worker for much more
     * than TIMEOUT seconds, even while AhaSend is slow (a logged-out visitor can trigger password reset emails).
     */
    private const TIMEOUT           = 10;
    private const RETRY_TIMEOUT     = 5;
    private const RETRY_ONLY_WITHIN = 3;
    private const MAX_RETRY_WAIT    = 3;
    private const SUCCESS_STATUSES  = ['queued', 'scheduled'];

    public function __construct(private readonly string $apiKey, private readonly string $accountId)
    {
    }

    /**
     * GET /v2/ping: checks the key, its IP allow list and the network. Says nothing about send scopes.
     */
    public function ping(): SendResult
    {
        $response = wp_remote_get(self::BASE_URL . '/v2/ping', [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(),
        ]);
        if ($response instanceof WP_Error) {
            return SendResult::failure($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return $code === 200
            ? SendResult::success(null, $code)
            : SendResult::failure(self::errorMessage($response), $code);
    }

    /**
     * Sends one message to one recipient. Retries once, with the same idempotency key, on a network error,
     * 429 or 5xx, so a retry can never deliver the email twice. No retry when the first attempt took longer than
     * RETRY_ONLY_WITHIN seconds (a timeout above all): the user has waited long enough.
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload, string $idempotencyKey): SendResult
    {
        $url  = self::BASE_URL . '/v2/accounts/' . rawurlencode($this->accountId) . '/messages';
        $args = [
            'timeout'     => self::TIMEOUT,
            'headers'     => $this->headers() + [
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ],
            'body'        => (string) wp_json_encode($payload),
            'data_format' => 'body',
        ];

        for ($attempt = 1; ; $attempt++) {
            $args['timeout'] = $attempt === 1 ? self::TIMEOUT : self::RETRY_TIMEOUT;
            $started         = microtime(true);
            $response        = wp_remote_post($url, $args);
            $elapsed         = microtime(true) - $started;
            $result          = $this->sendResult($response);
            $wait            = $this->retryWait($response);

            if ($result->ok || $wait === null || $attempt >= 2 || $elapsed > self::RETRY_ONLY_WITHIN) {
                return $result;
            }
            sleep($wait);
        }
    }

    private function sendResult(array|WP_Error $response): SendResult
    {
        if ($response instanceof WP_Error) {
            return SendResult::failure($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 202) {
            return SendResult::failure(self::errorMessage($response), $code);
        }

        // 202 can still hold a per-recipient error: check the item, not just the status code.
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $item = is_array($body) && isset($body['data'][0]) && is_array($body['data'][0]) ? $body['data'][0] : null;
        if ($item === null) {
            return SendResult::failure(__('AhaSend answered 202 without a message in "data".', 'xolution-maildelivery'), $code);
        }
        if (in_array($item['status'] ?? null, self::SUCCESS_STATUSES, true)) {
            return SendResult::success(isset($item['id']) ? (string) $item['id'] : null, $code);
        }

        return SendResult::failure((string) ($item['error'] ?? __('AhaSend did not accept the message.', 'xolution-maildelivery')), $code);
    }

    /**
     * Seconds to wait before a retry, or null when the response must not be retried.
     */
    private function retryWait(array|WP_Error $response): ?int
    {
        if ($response instanceof WP_Error) {
            return 1;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 429 && $code < 500) {
            return null;
        }
        $after = (int) wp_remote_retrieve_header($response, 'retry-after');

        return max(1, min(self::MAX_RETRY_WAIT, $after));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    private static function errorMessage(array $response): string
    {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $msg  = is_array($body) && isset($body['message']) ? (string) $body['message'] : '';

        return $msg !== '' ? sprintf('HTTP %d: %s', $code, $msg) : sprintf('HTTP %d', $code);
    }
}
