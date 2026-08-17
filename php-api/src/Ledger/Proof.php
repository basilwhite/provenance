<?php

declare(strict_types=1);

namespace Provenance\Ledger;

/** Mirrors src/ledger/proof.ts. */
final class Proof
{
    /** @return array{path: string[], root: string} */
    public static function buildChainProof(Store $ledgerStore, int $originEventId): array
    {
        $all = $ledgerStore->getAllEvents();
        $originIndex = null;
        foreach ($all as $i => $event) {
            if ($event['id'] === $originEventId) {
                $originIndex = $i;
                break;
            }
        }
        if ($originIndex === null) {
            throw new \RuntimeException("event {$originEventId} not found");
        }

        $origin = $all[$originIndex];
        $path = [$origin['prev_root']];

        for ($k = $originIndex + 1; $k < count($all); $k++) {
            $path[] = Hash::computeLedgerLeaf($all[$k]);
        }

        $root = count($all) > 0 ? $all[count($all) - 1]['root'] : Hash::genesisRoot();
        return ['path' => $path, 'root' => $root];
    }

    /** @return array{batchEventId: int, batchRoot: string, path: string[]}|null */
    public static function findBatchLeafProof(\PDO $db, string $claimHash): ?array
    {
        $stmt = $db->prepare('SELECT batch_event_id FROM batch_leaves WHERE claim_hash = ? LIMIT 1');
        $stmt->execute([$claimHash]);
        $leafRow = $stmt->fetch();
        if ($leafRow === false) {
            return null;
        }

        $stmt = $db->prepare('SELECT leaf_index, claim_hash FROM batch_leaves WHERE batch_event_id = ? ORDER BY leaf_index ASC');
        $stmt->execute([$leafRow['batch_event_id']]);
        $rows = $stmt->fetchAll();

        $leaves = array_column($rows, 'claim_hash');
        $index = null;
        foreach ($rows as $i => $row) {
            if ($row['claim_hash'] === $claimHash) {
                $index = $i;
                break;
            }
        }

        $tree = Merkle::buildMerkleTree($leaves);
        $path = Merkle::getMerkleProof($tree['layers'], $index);

        return ['batchEventId' => (int)$leafRow['batch_event_id'], 'batchRoot' => $tree['root'], 'path' => $path];
    }
}
