<?php

declare(strict_types=1);

namespace Provenance\Protocol;

use Provenance\Ledger\Store as LedgerStore;
use Provenance\Scoring\ScoreStore;
use Provenance\Scoring\Wilson;

/**
 * F3.3: mirrors src/protocol/finalize.ts. A claim only contributes to its
 * submitter's score once it has received >= 2 independent audits; once a
 * claim clears that bar, ALL of its audits count. Aggregates across the
 * validator's full key-rotation lineage (F1.2).
 */
final class Finalize
{
    private const MIN_AUDITS_TO_FINALIZE_CLAIM = 2;

    public static function finalizeClaimIfReady(LedgerStore $ledgerStore, ScoreStore $scoreStore, string $claimHash): array
    {
        $original = $ledgerStore->findOriginalClaim($claimHash);
        if ($original === null) {
            return ['updated' => false];
        }

        $auditsForThisClaim = $ledgerStore->getAuditsForClaim($claimHash);
        if (count($auditsForThisClaim) < self::MIN_AUDITS_TO_FINALIZE_CLAIM) {
            return ['updated' => false];
        }

        $lineage = $ledgerStore->resolveIdentityLineage($original['validator_pubkey']);
        $currentIdentity = $lineage[count($lineage) - 1];

        $confirmations = 0;
        $overturns = 0;
        foreach ($lineage as $pubkey) {
            foreach ($ledgerStore->getAllClaimsForValidator($pubkey) as $claim) {
                $audits = $ledgerStore->getAuditsForClaim($claim['claim_hash']);
                if (count($audits) < self::MIN_AUDITS_TO_FINALIZE_CLAIM) {
                    continue;
                }
                foreach ($audits as $audit) {
                    if ($audit['audit_verdict'] === true) {
                        $confirmations++;
                    } elseif ($audit['audit_verdict'] === false) {
                        $overturns++;
                    }
                }
            }
        }

        $n = $confirmations + $overturns;
        $score = Wilson::computeScore($n, $confirmations, $overturns);
        $scoreStore->upsert($currentIdentity, $n, $confirmations, $overturns, $score);

        return ['updated' => true, 'score' => $score, 'n' => $n, 'confirmations' => $confirmations, 'overturns' => $overturns];
    }
}
