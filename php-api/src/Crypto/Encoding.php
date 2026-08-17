<?php

declare(strict_types=1);

namespace Provenance\Crypto;

use kornrunner\Keccak;

/**
 * PHP strings are already byte strings once decoded from UTF-8 JSON, so
 * there's no separate "utf8ToBytes" step here the way there is in the TS
 * reference (src/crypto/encoding.ts) — a plain string IS the byte buffer.
 */
final class Encoding
{
    public static function bytesToHex(string $bytes): string
    {
        return '0x' . bin2hex($bytes);
    }

    public static function hexToBytes(string $hex): string
    {
        $clean = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        if (strlen($clean) % 2 !== 0) {
            throw new \InvalidArgumentException("Invalid hex string length: {$hex}");
        }
        $bytes = hex2bin($clean);
        if ($bytes === false) {
            throw new \InvalidArgumentException("Invalid hex string: {$hex}");
        }
        return $bytes;
    }

    /** Ethereum-style Keccak-256 (NOT NIST SHA3-256 — see php-api README notes). */
    public static function keccak256Hex(string $input): string
    {
        return '0x' . Keccak::hash($input, 256);
    }

    public static function concatBytes(string ...$parts): string
    {
        return implode('', $parts);
    }
}
