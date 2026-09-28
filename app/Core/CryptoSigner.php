<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal secp256k1 signing helper for the crypto-native Auto Poster channels
 * (Nostr, Hive, DeSo). Uses the php-secp256k1 extension when present; without
 * it the channels report that posting is unavailable rather than producing
 * invalid signatures.
 */
final class CryptoSigner
{
    /** @var \GMP|null lazily-created secp256k1 context */
    private static $context = null;

    public static function available(): bool
    {
        return extension_loaded('secp256k1') && function_exists('secp256k1_ec_pubkey_create');
    }

    private static function ctx()
    {
        if (self::$context === null && self::available()) {
            self::$context = secp256k1_context_create(SECP256K1_CONTEXT_SIGN | SECP256K1_CONTEXT_VERIFY);
        }

        return self::$context;
    }

    /** Derive the 33-byte compressed public key from a 32-byte secret key. */
    public static function pubkeyFromSeckey(string $seckey): string
    {
        $ctx = self::ctx();
        if ($ctx === null) {
            throw new \RuntimeException('secp256k1 extension not available.');
        }

        $pub = null;
        if (!secp256k1_ec_pubkey_create($ctx, $pub, $seckey)) {
            throw new \RuntimeException('Invalid secp256k1 secret key.');
        }

        $serialized = '';
        if (!secp256k1_ec_pubkey_serialize($ctx, $serialized, $pub, true)) {
            throw new \RuntimeException('Could not serialize public key.');
        }

        return $serialized;
    }

    /** Nostr NIP-01 schnorr signature (64 bytes) over a 32-byte message. */
    public static function signSchnorr(string $seckey, string $msg32): string
    {
        $ctx = self::ctx();
        if ($ctx === null) {
            throw new \RuntimeException('secp256k1 extension not available.');
        }

        $sig = null;
        if (!secp256k1_schnorrsig_sign32($ctx, $sig, $msg32, $seckey)) {
            throw new \RuntimeException('Schnorr signing failed.');
        }

        $sig64 = '';
        if (!secp256k1_schnorrsig_serialize($ctx, $sig64, $sig)) {
            throw new \RuntimeException('Schnorr signature serialization failed.');
        }

        return $sig64;
    }

    /**
     * Recoverable ECDSA signature over a 32-byte message. Returns
     * [compact64, recid].
     */
    public static function signRecoverable(string $seckey, string $msg32): array
    {
        $ctx = self::ctx();
        if ($ctx === null) {
            throw new \RuntimeException('secp256k1 extension not available.');
        }

        $sig = null;
        if (!secp256k1_ecdsa_sign_recoverable($ctx, $sig, $msg32, $seckey)) {
            throw new \RuntimeException('ECDSA signing failed.');
        }

        $sig64 = '';
        $recid = 0;
        if (!secp256k1_ecdsa_recoverable_signature_serialize_compact($ctx, $sig, $sig64, $recid)) {
            throw new \RuntimeException('ECDSA signature serialization failed.');
        }

        return [$sig64, $recid];
    }

    /**
     * Bech32 (BIP-173) decode used for nostr nsec1/npub1 keys. Returns the raw
     * data bytes (5-bit converted back), or null when invalid.
     */
    public static function bech32Decode(string $input): ?string
    {
        $input = trim($input);
        if (strlen($input) < 8 || strtolower($input) !== $input && strtoupper($input) !== $input) {
            return null;
        }
        $input = strtolower($input);

        $pos = strrpos($input, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($input)) {
            return null;
        }

        $hrp = substr($input, 0, $pos);
        $dataPart = substr($input, $pos + 1);

        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $values = [];
        foreach (str_split($dataPart) as $char) {
            $idx = strpos($charset, $char);
            if ($idx === false) {
                return null;
            }
            $values[] = $idx;
        }

        $bits = [];
        foreach ($values as $value) {
            for ($i = 4; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        }

        $out = '';
        for ($i = 0; $i + 8 <= count($bits); $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | $bits[$i + $b];
            }
            $out .= chr($byte);
        }

        return $hrp !== '' && $out !== '' ? $out : null;
    }

    /**
     * Convert a 32-byte secret key given in hex, bech32 (nsec1...) or base64
     * into raw 32 bytes.
     */
    public static function normalizeSeckey(string $key): string
    {
        $key = trim($key);

        if (strpos($key, 'nsec1') === 0) {
            $decoded = self::bech32Decode($key);
            if ($decoded !== null) {
                return substr($decoded, 0, 32);
            }
            throw new \RuntimeException('Invalid nsec bech32 key.');
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $key)) {
            return hex2bin(strtolower($key));
        }

        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        throw new \RuntimeException('Unrecognized secret key format (expected hex, nsec1 or base64).');
    }
}