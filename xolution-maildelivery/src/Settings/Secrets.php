<?php

declare(strict_types=1);

namespace Xolution\MailDelivery\Settings;

/**
 * Encrypts the API key at rest. The encryption key is derived from the site's salts, which normally live in
 * wp-config.php, so a database dump or backup on its own does not reveal the API key.
 */
final class Secrets
{
    private const PREFIX  = 'v1:';
    private const CONTEXT = 'xolution-maildelivery/api-key/v1';

    private const SALT_CONSTANTS = [
        'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT',
    ];

    public static function encrypt(string $plain): string
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * The plain value, or null when it can't be decrypted (salts changed, or the value is damaged).
     */
    public static function decrypt(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? null : $plain;
    }

    /**
     * False when wp-config.php doesn't define real salts. WordPress then keeps them in the database, next to the
     * encrypted key, and the encryption protects nothing.
     */
    public static function saltsInConfig(): bool
    {
        foreach (self::SALT_CONSTANTS as $name) {
            if (!defined($name)) {
                return false;
            }
            $value = (string) constant($name);
            if ($value === '' || $value === 'put your unique phrase here') {
                return false;
            }
        }

        return true;
    }

    private static function key(): string
    {
        // wp_salt() reads the wp-config.php constants, or the database fallback when they're missing.
        $material = self::CONTEXT . '|' . wp_salt('auth') . '|' . wp_salt('secure_auth');

        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
