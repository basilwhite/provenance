<?php

declare(strict_types=1);

namespace Provenance\Domain;

use Provenance\Crypto\Encoding;

final class ClaimHash
{
    /**
     * claim_hash = keccak256(claim_text + evidence_uri + timestamp).
     *
     * IMPORTANT: the field delimiter is an actual NUL byte ("\x00"), not a
     * space. src/domain/claimHash.ts's FIELD_DELIMITER constant is a real
     * NUL byte despite its own comment claiming otherwise — confirmed by
     * inspecting the file's raw bytes and cross-checking against the live
     * claim_hash the TS server actually returns (see
     * php-api/verify-keccak.php). This class matches the TS reference's
     * ACTUAL behavior, per the porting brief ("124 tests are what
     * 'correct' means here"), not its comment.
     */
    public static function compute(string $claimText, string $evidenceUri, int $timestamp): string
    {
        $preimage = $claimText . "\x00" . $evidenceUri . "\x00" . (string)$timestamp;
        return Encoding::keccak256Hex($preimage);
    }
}
