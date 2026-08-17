<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\ApiException;
use Provenance\Api\Validators;
use Provenance\Ledger\Proof;
use Provenance\Ledger\Store as LedgerStore;
use Provenance\Scoring\ScoreStore;

/** Mirrors src/api/routes/verify.ts (F6.1). */
final class VerifyRoute
{
    public static function handle(\PDO $db, string $claimHashParam): array
    {
        $claimHash = Validators::requireClaimHashHex($claimHashParam, 'claim_hash');

        $ledgerStore = new LedgerStore($db);
        $scoreStore = new ScoreStore($db);

        $original = $ledgerStore->findOriginalClaim($claimHash);
        if ($original === null) {
            throw new ApiException(404, 'claim_not_found', "no claim found for claim_hash {$claimHash}");
        }

        $lineage = $ledgerStore->resolveIdentityLineage($original['validator_pubkey']);
        $currentIdentity = $lineage[count($lineage) - 1];
        $events = $ledgerStore->getEventsForIdentity($original['validator_pubkey']);
        $scoreRecord = $scoreStore->getMostRecentAcrossKeys($lineage);

        $batchLeaf = Proof::findBatchLeafProof($db, $claimHash);
        if ($batchLeaf !== null) {
            $innerPath = $batchLeaf['path'];
            $originEventId = $batchLeaf['batchEventId'];
        } else {
            $submission = $ledgerStore->getSubmissionEvent($claimHash);
            if ($submission === null) {
                throw new ApiException(404, 'claim_not_found', "no submission event found for claim_hash {$claimHash}");
            }
            $innerPath = [];
            $originEventId = $submission['id'];
        }

        $chainProof = Proof::buildChainProof($ledgerStore, $originEventId);

        return [
            'status' => 200,
            'body' => [
                'validator_pubkey' => $currentIdentity,
                'events' => $events,
                'current_score' => $scoreRecord['score'],
                'merkle_proof' => [
                    'claim_hash' => $claimHash,
                    'path' => [...$innerPath, ...$chainProof['path']],
                    'root' => $chainProof['root'],
                ],
            ],
        ];
    }
}
