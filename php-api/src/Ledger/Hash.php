<?php

declare(strict_types=1);

namespace Provenance\Ledger;

use Provenance\Crypto\Encoding;

/**
 * Mirrors src/ledger/hash.ts. computeLeafHash's JSON encoding was verified
 * byte-for-byte against JS's JSON.stringify (see php-api/verify-json-encoding.php)
 * — JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE are required for parity,
 * since PHP escapes "/" and non-ASCII by default and JS does neither.
 */
final class Hash
{
    private static ?string $genesisRoot = null;

    public static function genesisRoot(): string
    {
        if (self::$genesisRoot === null) {
            self::$genesisRoot = Encoding::keccak256Hex('PROVENANCE_GENESIS_V1');
        }
        return self::$genesisRoot;
    }

    /** Exact field order from src/ledger/types.ts LEDGER_EVENT_FIELD_ORDER. */
    public const FIELD_ORDER = [
        'claim_hash',
        'evidence_uri',
        'timestamp',
        'validator_pubkey',
        'signature',
        'audit_ref',
        'audit_verdict',
        'stake_locked',
        'stake_slashed',
        'batch_root',
    ];

    /** @param array<string, mixed> $event fields keyed exactly as in FIELD_ORDER (prev_root/root excluded) */
    public static function computeLeafHash(array $event): string
    {
        $fields = array_map(static fn(string $key) => $event[$key], self::FIELD_ORDER);
        $json = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return Encoding::keccak256Hex($json);
    }

    /** batch events leaf on batch_root directly; everything else leafs on the full field hash. */
    public static function computeLedgerLeaf(array $event): string
    {
        if ($event['type'] === 'batch' && !empty($event['batch_root'])) {
            return $event['batch_root'];
        }
        return self::computeLeafHash($event);
    }

    public static function computeChainRoot(string $prevRoot, string $blockMerkleRoot): string
    {
        return Merkle::hashPair($prevRoot, $blockMerkleRoot);
    }
}
