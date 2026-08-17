<?php

declare(strict_types=1);

namespace Provenance\Ledger;

/**
 * Independently recomputes the entire hash chain from genesis given only
 * the raw events, checking against each event's stored prev_root/root.
 * Mirrors src/ledger/replay.ts.
 */
final class Replay
{
    /** @param array<int, array<string, mixed>> $events */
    public static function replayChain(array $events): array
    {
        $prevRoot = Hash::genesisRoot();

        foreach ($events as $event) {
            if ($event['prev_root'] !== $prevRoot) {
                return ['valid' => false, 'mismatchAtEventId' => $event['id'], 'recomputedLatestRoot' => $prevRoot];
            }

            $leaf = Hash::computeLedgerLeaf($event);
            $blockRoot = Merkle::buildMerkleTree([$leaf])['root'];
            $recomputedRoot = Hash::computeChainRoot($prevRoot, $blockRoot);

            if ($recomputedRoot !== $event['root']) {
                return ['valid' => false, 'mismatchAtEventId' => $event['id'], 'recomputedLatestRoot' => $recomputedRoot];
            }

            $prevRoot = $recomputedRoot;
        }

        return ['valid' => true, 'recomputedLatestRoot' => $prevRoot];
    }
}
