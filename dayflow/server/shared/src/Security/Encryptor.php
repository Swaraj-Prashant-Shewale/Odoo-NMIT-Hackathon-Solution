<?php

declare(strict_types=1);

namespace Dayflow\Kernel\Security;

use Dayflow\Kernel\Support\Env;

/**
 * Authenticated encryption for the few fields that must be stored reversibly.
 *
 * Bank account numbers and tax identifiers have to be read back to run payroll,
 * so they cannot simply be hashed. AES-256-GCM gives confidentiality and
 * tamper detection together: a modified ciphertext fails to decrypt rather than
 * returning altered data.
 */
final class Encryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc.v1.';

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /** Returns null when the value cannot be decrypted or was tampered with. */
    public static function decrypt(string $payload): ?string
    {
        if (!self::isEncrypted($payload)) {
            return null;
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * A deterministic, non-reversible fingerprint.
     *
     * Encrypted columns cannot be searched, so a keyed hash is stored alongside
     * them when a lookup or uniqueness check is needed.
     */
    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), self::key() . '|blind-index');
    }

    /** Shows only the last four characters, for display on screen. */
    public static function maskTail(string $value, int $visible = 4): string
    {
        $length = strlen($value);
        if ($length <= $visible) {
            return str_repeat('*', max($length, 1));
        }

        return str_repeat('*', $length - $visible) . substr($value, -$visible);
    }

    private static function key(): string
    {
        $key = Env::require('ENCRYPTION_KEY');

        // The configured value is stretched to exactly 32 bytes so operators do
        // not have to supply a precisely sized binary key.
        return hash('sha256', $key, true);
    }
}
