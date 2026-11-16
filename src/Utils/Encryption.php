<?php

namespace ClinicFlow\Utils;

use RuntimeException;

class Encryption {
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'enc_gcm:';

    /**
     * The key every install shipped with before app_key existed. It is published in the
     * source repo, so data protected only by it is NOT secret. Kept solely so that data
     * written under it can still be read and then re-encrypted (see EncryptionMigrationService).
     */
    public const LEGACY_DEFAULT_KEY = 'clinicflow_default_secret_encryption_key_2025!';

    /** The operator-configured secret (config 'app_key' or APP_KEY env), or null if none is set. */
    public static function configuredKeyMaterial(): ?string {
        if (function_exists('getAppConfig')) {
            $cfg = getAppConfig();
            if (!empty($cfg['app_key'])) {
                return (string) $cfg['app_key'];
            }
        }
        $env = getenv('APP_KEY');
        return ($env !== false && $env !== '') ? (string) $env : null;
    }

    /** True when no real key is configured (or it was set to the published default). */
    public static function isUsingDefaultKey(): bool {
        $m = self::configuredKeyMaterial();
        return $m === null || $m === self::LEGACY_DEFAULT_KEY;
    }

    /** May data written under the old default key still be read? On by default; set 'allow_legacy_key' => false once migrated. */
    public static function legacyFallbackAllowed(): bool {
        if (function_exists('getAppConfig')) {
            $cfg = getAppConfig();
            if (array_key_exists('allow_legacy_key', $cfg)) {
                return (bool) $cfg['allow_legacy_key'];
            }
        }
        return true;
    }

    public static function isEncrypted(?string $value): bool {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }

    public static function getKey(?string $customKey = null): string {
        if ($customKey && strlen($customKey) >= 16) {
            return hash('sha256', $customKey, true);
        }
        return hash('sha256', self::configuredKeyMaterial() ?? self::LEGACY_DEFAULT_KEY, true);
    }

    /**
     * Strict decrypt with an explicit secret: returns the plaintext, or null if the value is
     * not an encrypted value or this secret does not open it. (decrypt() cannot tell you:
     * on failure it hands back the ciphertext, which would be shown to users as garbage.)
     */
    public static function decryptStrict(?string $ciphertext, string $keyMaterial): ?string {
        if (!self::isEncrypted($ciphertext)) {
            return null;
        }
        $parts = explode(':', substr((string) $ciphertext, strlen(self::PREFIX)));
        if (count($parts) !== 3) {
            return null;
        }
        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $data = base64_decode($parts[2], true);
        if ($iv === false || $tag === false || $data === false) {
            return null;
        }
        $plain = openssl_decrypt($data, self::CIPHER, hash('sha256', $keyMaterial, true), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /** Encrypt with an explicit secret (used by the re-encryption tool and tests). */
    public static function encryptWith(string $plaintext, string $keyMaterial): string {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, hash('sha256', $keyMaterial, true), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ct);
    }

    public static function encrypt(?string $plaintext, ?string $customKey = null): ?string {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }

        if (str_starts_with($plaintext, self::PREFIX)) {
            return $plaintext; // Already encrypted
        }

        $key = self::getKey($customKey);
        $iv = random_bytes(12); // 96-bit IV for AES-GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Encryption failed.");
        }

        return self::PREFIX . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
    }

    public static function decrypt(?string $ciphertext, ?string $customKey = null): ?string {
        if ($ciphertext === null || $ciphertext === '') {
            return $ciphertext;
        }

        if (!str_starts_with($ciphertext, self::PREFIX)) {
            return $ciphertext; // Plaintext fallback
        }

        $payload = substr($ciphertext, strlen(self::PREFIX));
        $parts = explode(':', $payload);

        if (count($parts) !== 3) {
            return $ciphertext;
        }

        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $data = base64_decode($parts[2], true);

        if ($iv === false || $tag === false || $data === false) {
            return $ciphertext;
        }

        $key = self::getKey($customKey);

        $plaintext = openssl_decrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext !== false) {
            return $plaintext;
        }

        // Written before a real app_key was set? Read it with the old default key, so that
        // adding a key never makes existing notes unreadable. Writes always use the new key.
        if ($customKey === null && !self::isUsingDefaultKey() && self::legacyFallbackAllowed()) {
            $legacy = self::decryptStrict($ciphertext, self::LEGACY_DEFAULT_KEY);
            if ($legacy !== null) {
                return $legacy;
            }
        }

        return $ciphertext;
    }
}
