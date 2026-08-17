<?php

declare(strict_types=1);

namespace Provenance\Ledger;

/**
 * Append-only ledger store backed by MySQL/PDO. Mirrors src/ledger/store.ts
 * exactly: every appendEvent() call forms a new single-event "block" (leaf
 * hash passed through the same Merkle tree builder used for batch
 * commitments), chained onto the previous root. Never issues UPDATE/DELETE
 * against ledger_events (also enforced at the DB level — see Db\Connection).
 */
final class Store
{
    public function __construct(private readonly \PDO $db)
    {
    }

    public function getLatestRoot(): string
    {
        $row = $this->db->query('SELECT root FROM ledger_events ORDER BY id DESC LIMIT 1')->fetch();
        return $row['root'] ?? Hash::genesisRoot();
    }

    /**
     * @param array{
     *   type: string, claim_hash: string, evidence_uri: string, timestamp: int,
     *   validator_pubkey: string, signature: string, audit_ref?: ?string,
     *   audit_verdict?: ?bool, stake_locked?: int, stake_slashed?: int,
     *   batch_root?: ?string, old_pubkey?: ?string, new_pubkey?: ?string
     * } $input
     * @return array<string, mixed> the stored event
     */
    public function appendEvent(array $input): array
    {
        $prevRoot = $this->getLatestRoot();

        $auditRef = $input['audit_ref'] ?? null;
        $auditVerdict = $input['audit_verdict'] ?? null;
        $stakeLocked = $input['stake_locked'] ?? 0;
        $stakeSlashed = $input['stake_slashed'] ?? 0;
        $batchRoot = $input['batch_root'] ?? null;

        $eventForHash = [
            'claim_hash' => $input['claim_hash'],
            'evidence_uri' => $input['evidence_uri'],
            'timestamp' => $input['timestamp'],
            'validator_pubkey' => $input['validator_pubkey'],
            'signature' => $input['signature'],
            'audit_ref' => $auditRef,
            'audit_verdict' => $auditVerdict,
            'stake_locked' => $stakeLocked,
            'stake_slashed' => $stakeSlashed,
            'batch_root' => $batchRoot,
            'type' => $input['type'],
        ];

        $leaf = Hash::computeLedgerLeaf($eventForHash);
        $blockRoot = Merkle::buildMerkleTree([$leaf])['root'];
        $root = Hash::computeChainRoot($prevRoot, $blockRoot);

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO ledger_events
                (type, claim_hash, evidence_uri, timestamp, validator_pubkey, signature,
                 audit_ref, audit_verdict, stake_locked, stake_slashed, batch_root,
                 prev_root, root, old_pubkey, new_pubkey, created_at)
            VALUES (:type, :claim_hash, :evidence_uri, :timestamp, :validator_pubkey, :signature,
                    :audit_ref, :audit_verdict, :stake_locked, :stake_slashed, :batch_root,
                    :prev_root, :root, :old_pubkey, :new_pubkey, :created_at)
        SQL);

        $stmt->execute([
            'type' => $input['type'],
            'claim_hash' => $input['claim_hash'],
            'evidence_uri' => $input['evidence_uri'],
            'timestamp' => $input['timestamp'],
            'validator_pubkey' => $input['validator_pubkey'],
            'signature' => $input['signature'],
            'audit_ref' => $auditRef,
            'audit_verdict' => $auditVerdict === null ? null : ($auditVerdict ? 1 : 0),
            'stake_locked' => $stakeLocked,
            'stake_slashed' => $stakeSlashed,
            'batch_root' => $batchRoot,
            'prev_root' => $prevRoot,
            'root' => $root,
            'old_pubkey' => $input['old_pubkey'] ?? null,
            'new_pubkey' => $input['new_pubkey'] ?? null,
            'created_at' => (int)(microtime(true) * 1000),
        ]);

        $id = (int)$this->db->lastInsertId();
        $stored = $this->getById($id);
        if ($stored === null) {
            throw new \RuntimeException('Failed to read back just-inserted ledger event');
        }
        return $stored;
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ledger_events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToEvent($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function getEventsByPubKey(string $pubkey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ledger_events WHERE validator_pubkey = ? ORDER BY id ASC');
        $stmt->execute([$pubkey]);
        return array_map($this->rowToEvent(...), $stmt->fetchAll());
    }

    /** Returns the original event for claim_hash plus any audit events referencing it. */
    public function getEventByClaimHash(string $claimHash): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ledger_events WHERE claim_hash = ? OR audit_ref = ? ORDER BY id ASC');
        $stmt->execute([$claimHash, $claimHash]);
        return array_map($this->rowToEvent(...), $stmt->fetchAll());
    }

    public function getSubmissionEvent(string $claimHash): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ledger_events WHERE claim_hash = ? AND type = 'submission' LIMIT 1");
        $stmt->execute([$claimHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToEvent($row);
    }

    /**
     * Resolves the original claim a claim_hash refers to, whether it came in
     * as a standalone /submit or as a leaf inside a /submit/batch.
     * @return array{claim_hash: string, validator_pubkey: string, timestamp: int, evidence_uri: string}|null
     */
    public function findOriginalClaim(string $claimHash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM ledger_events WHERE claim_hash = ? AND type = 'submission' LIMIT 1"
        );
        $stmt->execute([$claimHash]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        $stmt = $this->db->prepare(
            'SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM batch_leaves WHERE claim_hash = ? LIMIT 1'
        );
        $stmt->execute([$claimHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Same as findOriginalClaim, but takes an InnoDB row lock (SELECT ...
     * FOR UPDATE) so concurrent audit requests on the same claim serialize
     * here — closing the double-slash race that PHP's per-request-process
     * model opens up (see Db\Connection's uniq_audit_ref_validator comment
     * for the same concern applied to duplicate-audit detection). Must be
     * called inside a transaction.
     */
    public function lockOriginalClaim(string $claimHash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM ledger_events WHERE claim_hash = ? AND type = 'submission' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$claimHash]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        $stmt = $this->db->prepare(
            'SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM batch_leaves WHERE claim_hash = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$claimHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int, array{claim_hash: string, validator_pubkey: string, timestamp: int, evidence_uri: string}> */
    public function getAllClaimsForValidator(string $pubkey): array
    {
        $stmt = $this->db->prepare(
            "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM ledger_events WHERE validator_pubkey = ? AND type = 'submission'"
        );
        $stmt->execute([$pubkey]);
        $submissions = $stmt->fetchAll();

        $stmt = $this->db->prepare(
            'SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM batch_leaves WHERE validator_pubkey = ?'
        );
        $stmt->execute([$pubkey]);
        $leaves = $stmt->fetchAll();

        return [...$submissions, ...$leaves];
    }

    public function getAuditsForClaim(string $claimHash): array
    {
        $stmt = $this->db->prepare("SELECT * FROM ledger_events WHERE type = 'audit' AND audit_ref = ? ORDER BY id ASC");
        $stmt->execute([$claimHash]);
        return array_map($this->rowToEvent(...), $stmt->fetchAll());
    }

    public function getAllEvents(): array
    {
        $stmt = $this->db->query('SELECT * FROM ledger_events ORDER BY id ASC');
        return array_map($this->rowToEvent(...), $stmt->fetchAll());
    }

    public function findRotationByOldPubkey(string $oldPubkey): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ledger_events WHERE type = 'key_rotation' AND old_pubkey = ? LIMIT 1");
        $stmt->execute([$oldPubkey]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToEvent($row);
    }

    public function findRotationByNewPubkey(string $newPubkey): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ledger_events WHERE type = 'key_rotation' AND new_pubkey = ? LIMIT 1");
        $stmt->execute([$newPubkey]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->rowToEvent($row);
    }

    /**
     * F1.2: resolves every pubkey in one validator's continuous identity —
     * walking backward through rotations to the earliest key and forward to
     * the current one. Returns oldest-first; the last entry is current.
     * @return string[]
     */
    public function resolveIdentityLineage(string $pubkey): array
    {
        $earliest = $pubkey;
        $seenBackward = [$earliest => true];
        while (true) {
            $rotation = $this->findRotationByNewPubkey($earliest);
            if ($rotation === null || $rotation['old_pubkey'] === null || isset($seenBackward[$rotation['old_pubkey']])) {
                break;
            }
            $earliest = $rotation['old_pubkey'];
            $seenBackward[$earliest] = true;
        }

        $chain = [$earliest];
        $seenForward = [$earliest => true];
        $current = $earliest;
        while (true) {
            $rotation = $this->findRotationByOldPubkey($current);
            if ($rotation === null || $rotation['new_pubkey'] === null || isset($seenForward[$rotation['new_pubkey']])) {
                break;
            }
            $current = $rotation['new_pubkey'];
            $chain[] = $current;
            $seenForward[$current] = true;
        }

        return $chain;
    }

    /**
     * Full, unfiltered history for a validator's identity as a SUBMITTER
     * (F6.1): every claim/batch/rotation event they authored across their
     * rotation lineage, plus every audit event anyone made against one of
     * their claims. Deliberately excludes audits THEY performed on other
     * validators' claims (see src/ledger/store.ts getEventsForIdentity for
     * the full rationale).
     */
    public function getEventsForIdentity(string $pubkey): array
    {
        $lineage = $this->resolveIdentityLineage($pubkey);
        $placeholders = implode(',', array_fill(0, count($lineage), '?'));

        $sql = "SELECT * FROM ledger_events
                WHERE (validator_pubkey IN ($placeholders) AND type != 'audit')
                   OR audit_ref IN (
                     SELECT claim_hash FROM ledger_events WHERE type = 'submission' AND validator_pubkey IN ($placeholders)
                     UNION
                     SELECT claim_hash FROM batch_leaves WHERE validator_pubkey IN ($placeholders)
                   )
                ORDER BY id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([...$lineage, ...$lineage, ...$lineage]);
        return array_map($this->rowToEvent(...), $stmt->fetchAll());
    }

    private function rowToEvent(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['timestamp'] = (int)$row['timestamp'];
        $row['stake_locked'] = (int)$row['stake_locked'];
        $row['stake_slashed'] = (int)$row['stake_slashed'];
        $row['audit_verdict'] = $row['audit_verdict'] === null ? null : (bool)$row['audit_verdict'];
        unset($row['created_at']);
        return $row;
    }
}
