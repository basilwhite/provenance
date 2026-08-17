<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\Validators;
use Provenance\Ledger\Store as LedgerStore;
use Provenance\Scoring\ScoreStore;

/** Mirrors src/api/routes/score.ts (F4.2, plus the /events addition for F6.2's CLI). */
final class ScoreRoute
{
    public static function handleScore(\PDO $db, string $pubkeyParam): array
    {
        $pubkey = Validators::requirePubkeyHex($pubkeyParam, 'pubkey');
        $ledgerStore = new LedgerStore($db);
        $scoreStore = new ScoreStore($db);

        $lineage = $ledgerStore->resolveIdentityLineage($pubkey);
        $currentIdentity = $lineage[count($lineage) - 1];
        $record = $scoreStore->getMostRecentAcrossKeys($lineage);

        return ['status' => 200, 'body' => [...$record, 'validator_pubkey' => $currentIdentity]];
    }

    public static function handleEvents(\PDO $db, string $pubkeyParam): array
    {
        $pubkey = Validators::requirePubkeyHex($pubkeyParam, 'pubkey');
        $ledgerStore = new LedgerStore($db);
        $scoreStore = new ScoreStore($db);

        $lineage = $ledgerStore->resolveIdentityLineage($pubkey);
        $currentIdentity = $lineage[count($lineage) - 1];
        $events = $ledgerStore->getEventsForIdentity($pubkey);
        $record = $scoreStore->getMostRecentAcrossKeys($lineage);

        return [
            'status' => 200,
            'body' => ['validator_pubkey' => $currentIdentity, 'events' => $events, 'reported_score' => $record['score']],
        ];
    }
}
