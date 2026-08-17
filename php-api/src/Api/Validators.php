<?php

declare(strict_types=1);

namespace Provenance\Api;

final class Validators
{
    private const PUBKEY_HEX_RE = '/^0x[0-9a-fA-F]{64}$/';
    private const SIGNATURE_HEX_RE = '/^0x[0-9a-fA-F]{128}$/';
    private const HASH_HEX_RE = '/^0x[0-9a-fA-F]{64}$/';

    public static function requireString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new ApiException(400, 'invalid_request', "{$field} is required and must be a non-empty string");
        }
        return $value;
    }

    /** Accepts JSON numbers decoded as PHP int; rejects floats/strings to mirror the TS Number check. */
    public static function requireNumber(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new ApiException(400, 'invalid_request', "{$field} is required and must be a positive integer");
        }
        return $value;
    }

    public static function requireBoolean(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new ApiException(400, 'invalid_request', "{$field} is required and must be a boolean");
        }
        return $value;
    }

    public static function requirePubkeyHex(mixed $value, string $field): string
    {
        $str = self::requireString($value, $field);
        if (!preg_match(self::PUBKEY_HEX_RE, $str)) {
            throw new ApiException(400, 'invalid_request', "{$field} must be a 0x-prefixed 32-byte hex string");
        }
        return $str;
    }

    public static function requireSignatureHex(mixed $value, string $field): string
    {
        $str = self::requireString($value, $field);
        if (!preg_match(self::SIGNATURE_HEX_RE, $str)) {
            throw new ApiException(400, 'invalid_request', "{$field} must be a 0x-prefixed 64-byte hex string");
        }
        return $str;
    }

    public static function requireClaimHashHex(mixed $value, string $field): string
    {
        $str = self::requireString($value, $field);
        if (!preg_match(self::HASH_HEX_RE, $str)) {
            throw new ApiException(400, 'invalid_request', "{$field} must be a 0x-prefixed 32-byte hex string");
        }
        return $str;
    }
}
