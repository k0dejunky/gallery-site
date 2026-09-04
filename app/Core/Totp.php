<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal RFC 6238 TOTP (time-based one-time password) implementation with no
 * external dependency. Used for admin two-factor authentication. Secrets are
 * stored base32-encoded and codes are verified against the current 30-second
 * window plus one step on either side to tolerate clock drift.
 */
class Totp
{
    /** Base32 alphabet used for secrets (RFC 4648, no padding). */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Default period (seconds) between codes. */
    public const PERIOD = 30;

    /** Digits in the generated/verified code. */
    public const DIGITS = 6;

    /**
     * Generate a new random base32 secret for a user. 20 bytes => 160 bits,
     * the RFC 4226 recommended secret length.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);
        $bits   = '';
        $len    = strlen($random);

        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($random[$i])), 8, '0', STR_PAD_LEFT);
        }

        $secret = '';
        $bitsLen = strlen($bits);

        for ($i = 0; $i + 5 <= $bitsLen; $i += 5) {
            $secret .= self::BASE32_ALPHABET[bindec(substr($bits, $i, 5))];
        }

        return $secret;
    }

    /**
     * Build the otpauth:// URI for provisioning into an authenticator app.
     */
    public static function provisioningUri(string $secret, string $issuer, string $account): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Verify a user-supplied code against the secret. Accepts the current
     * 30-second window plus/minus one step to tolerate minor clock drift.
     */
    public static function verify(string $secret, string $code): bool
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $secret));

        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $decoded = self::base32Decode($secret);

        if ($decoded === '') {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);
        $expected = (int) $code;

        for ($offset = -1; $offset <= 1; $offset++) {
            if (self::hotp($decoded, $counter + $offset) === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * HMAC-based one-time password (RFC 4226) for the given counter.
     */
    private static function hotp(string $binarySecret, int $counter): int
    {
        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash   = hash_hmac('sha1', $binary, $binarySecret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binaryCode = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return $binaryCode;
    }

    /**
     * Decode a base32 string (RFC 4648) into raw bytes.
     */
    private static function base32Decode(string $input): string
    {
        $map = array_flip(str_split(self::BASE32_ALPHABET));
        $bits = '';
        $len  = strlen($input);

        for ($i = 0; $i < $len; $i++) {
            if (!isset($map[$input[$i]])) {
                continue;
            }
            $bits .= str_pad(decbin($map[$input[$i]]), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        $bitsLen = strlen($bits);

        for ($i = 0; $i + 8 <= $bitsLen; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }

        return $out;
    }
}
