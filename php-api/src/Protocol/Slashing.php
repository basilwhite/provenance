<?php

declare(strict_types=1);

namespace Provenance\Protocol;

/** Mirrors src/protocol/slashing.ts. */
final class Slashing
{
    /** Overturns must land within this window of the original submission to count toward slashing. */
    public const SLASH_WINDOW_MS = 7 * 24 * 60 * 60 * 1000;

    /**
     * @param array{claim_hash: string, validator_pubkey: string, timestamp: int, evidence_uri: string} $original
     * @param array<int, array<string, mixed>> $existingAudits
     * @param array{audit_verdict: bool, timestamp: int} $pending
     */
    public static function shouldSlashForClaim(array $original, array $existingAudits, array $pending): bool
    {
        foreach ($existingAudits as $audit) {
            if (($audit['stake_slashed'] ?? 0) > 0) {
                return false; // already slashed for this claim — one-time only
            }
        }

        $windowEnd = $original['timestamp'] + self::SLASH_WINDOW_MS;

        $qualifyingOverturns = 0;
        foreach ($existingAudits as $audit) {
            if ($audit['audit_verdict'] === false && $audit['timestamp'] <= $windowEnd) {
                $qualifyingOverturns++;
            }
        }

        $pendingQualifies = $pending['audit_verdict'] === false && $pending['timestamp'] <= $windowEnd;

        return $qualifyingOverturns + ($pendingQualifies ? 1 : 0) >= 2;
    }
}
