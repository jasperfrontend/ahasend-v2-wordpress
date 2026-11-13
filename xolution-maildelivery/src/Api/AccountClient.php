<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Api;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * The few account calls the setup and the uninstall make with a setup key: an AhaSend API v2 key with the scopes
 * api-keys:write and domains:read (setup), api-keys:delete and, to look keys up by label, api-keys:read
 * (uninstall). The setup key only lives in this object, for one request; it is never stored.
 */
final class AccountClient
{
    private const BASE_URL     = 'https://api.ahasend.com';
    private const TIMEOUT      = 15;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly string $setupKey, private readonly string $accountId)
    {
    }

    /**
     * GET /v2/accounts/{account}/domains/{domain}: whether the domain is in this account, with its DNS verified.
     */
    public function checkDomain(string $domain): true|WP_Error
    {
        $response = wp_remote_get($this->url('/domains/' . rawurlencode($domain)), [
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(),
        ]);
        if ($response instanceof WP_Error) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return new WP_Error('xmd_domain_missing', sprintf(
                /* translators: %s: domain name */
                __('The domain %s is not in this AhaSend account.', 'xolution-maildelivery'),
                $domain,
            ));
        }
        if ($code !== 200) {
            return new WP_Error('xmd_domain_check', self::errorMessage($response));
        }
        $body   = json_decode((string) wp_remote_retrieve_body($response), true);
        $record = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : $body; // with or without a data wrapper
        if (!is_array($record) || ($record['dns_valid'] ?? null) !== true) {
            return new WP_Error('xmd_domain_dns', sprintf(
                /* translators: %s: domain name */
                __('The domain %s is in this AhaSend account, but its DNS records are not verified yet.', 'xolution-maildelivery'),
                $domain,
            ));
        }

        return true;
    }

    /**
     * POST /v2/accounts/{account}/api-keys: a key that may only send from $domain, only from $ips.
     *
     * @param list<string> $ips
     * @return array{secret: string, id: string}|WP_Error The new key's secret, which AhaSend returns only this once,
     *                                                     and its ID ('' if missing), which the uninstall needs.
     */
    public function createSendKey(string $label, string $domain, array $ips, string $idempotencyKey): array|WP_Error
    {
        $response = wp_remote_post($this->url('/api-keys'), [
            'timeout'     => self::TIMEOUT,
            'headers'     => $this->headers() + [
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ],
            'body'        => (string) wp_json_encode([
                'label'         => $label,
                // The braces are part of the scope: messages:send:{example.com}.
                'scopes'        => ['messages:send:{' . $domain . '}'],
                'ip_allow_list' => array_values($ips),
            ]),
            'data_format' => 'body',
        ]);
        if ($response instanceof WP_Error) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 201 && $code !== 200) {
            return new WP_Error('xmd_key_create', self::errorMessage($response));
        }
        $body   = json_decode((string) wp_remote_retrieve_body($response), true);
        $secret = is_array($body) && is_string($body['secret_key'] ?? null) ? $body['secret_key'] : '';
        if (!str_starts_with($secret, 'aha-sk-')) {
            return new WP_Error('xmd_key_create', __('AhaSend created the key but did not return its secret.', 'xolution-maildelivery'));
        }

        $id = is_string($body['id'] ?? null) ? strtolower($body['id']) : '';

        return ['secret' => $secret, 'id' => preg_match(self::UUID_PATTERN, $id) === 1 ? $id : ''];
    }

    /**
     * DELETE /v2/accounts/{account}/api-keys/{id}. Needs api-keys:delete. A key that is already gone counts as done.
     *
     * @return bool|WP_Error True when deleted, false when AhaSend doesn't know the key (404).
     */
    public function deleteKey(string $keyId): bool|WP_Error
    {
        if (preg_match(self::UUID_PATTERN, $keyId) !== 1) {
            return new WP_Error('xmd_key_delete', __('This is not an AhaSend key ID.', 'xolution-maildelivery'));
        }
        $response = wp_remote_request($this->url('/api-keys/' . rawurlencode($keyId)), [
            'method'  => 'DELETE',
            'timeout' => self::TIMEOUT,
            'headers' => $this->headers(),
        ]);
        if ($response instanceof WP_Error) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            return false;
        }
        if ($code !== 200 && $code !== 204) {
            return new WP_Error('xmd_key_delete', self::errorMessage($response));
        }

        return true;
    }

    /**
     * GET /v2/accounts/{account}/api-keys, every page: the IDs of the keys called exactly $label. Needs
     * api-keys:read.
     *
     * @return list<string>|WP_Error
     */
    public function keyIdsWithLabel(string $label): array|WP_Error
    {
        $ids   = [];
        $after = '';
        // 100 per page; 50 pages is far more keys than the account will ever hold, and stops a looping cursor.
        for ($page = 0; $page < 50; $page++) {
            $query    = ['limit' => 100] + ($after === '' ? [] : ['after' => $after]);
            $response = wp_remote_get($this->url('/api-keys') . '?' . http_build_query($query), [
                'timeout' => self::TIMEOUT,
                'headers' => $this->headers(),
            ]);
            if ($response instanceof WP_Error) {
                return $response;
            }
            if ((int) wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('xmd_key_list', self::errorMessage($response));
            }
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($body) || !is_array($body['data'] ?? null)) {
                return new WP_Error('xmd_key_list', __('AhaSend returned an unexpected list of keys.', 'xolution-maildelivery'));
            }
            foreach ($body['data'] as $key) {
                $id = is_array($key) && is_string($key['id'] ?? null) ? strtolower($key['id']) : '';
                if (($key['label'] ?? null) === $label && preg_match(self::UUID_PATTERN, $id) === 1) {
                    $ids[] = $id;
                }
            }
            $next = $body['pagination']['next_cursor'] ?? '';
            if (empty($body['pagination']['has_more']) || !is_string($next) || $next === '' || $next === $after) {
                break;
            }
            $after = $next;
        }

        return array_values(array_unique($ids));
    }

    private function url(string $path): string
    {
        return self::BASE_URL . '/v2/accounts/' . rawurlencode($this->accountId) . $path;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->setupKey,
            'Accept'        => 'application/json',
        ];
    }

    private static function errorMessage(array $response): string
    {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $msg  = is_array($body) && isset($body['message']) && is_string($body['message']) ? $body['message'] : '';

        return $msg !== '' ? sprintf('HTTP %d: %s', $code, $msg) : sprintf('HTTP %d', $code);
    }
}
