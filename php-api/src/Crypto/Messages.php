<?php

declare(strict_types=1);

namespace Provenance\Crypto;

/** Byte-for-byte mirror of src/crypto/messages.ts — see php-api/verify-ed25519.php for cross-verification. */
final class Messages
{
    public static function claimTimestampMessage(string $claimHashHex, int $timestamp): string
    {
        return Encoding::hexToBytes($claimHashHex) . (string)$timestamp;
    }

    public static function rotationMessage(string $oldPubkeyHex, string $newPubkeyHex): string
    {
        return Encoding::hexToBytes($oldPubkeyHex) . Encoding::hexToBytes($newPubkeyHex);
    }

    public static function batchMessage(string $batchRootHex, int $timestamp): string
    {
        return Encoding::hexToBytes($batchRootHex) . (string)$timestamp;
    }
}
