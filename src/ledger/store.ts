import type { Db } from "../db/index.js";
import { buildMerkleTree } from "./merkle.js";
import { computeChainRoot, computeLeafHash, GENESIS_ROOT } from "./hash.js";
import type { LedgerEventType, StoredLedgerEvent } from "./types.js";

export interface NewLedgerEventInput {
  type: LedgerEventType;
  claim_hash: string;
  evidence_uri: string;
  timestamp: number;
  validator_pubkey: string;
  signature: string;
  audit_ref?: string | null;
  audit_verdict?: boolean | null;
  stake_locked?: number;
  stake_slashed?: number;
  batch_root?: string | null;
  old_pubkey?: string | null;
  new_pubkey?: string | null;
}

export interface OriginalClaimInfo {
  claim_hash: string;
  validator_pubkey: string;
  timestamp: number;
  evidence_uri: string;
}

interface LedgerEventRow {
  id: number;
  type: LedgerEventType;
  claim_hash: string;
  evidence_uri: string;
  timestamp: number;
  validator_pubkey: string;
  signature: string;
  audit_ref: string | null;
  audit_verdict: number | null;
  stake_locked: number;
  stake_slashed: number;
  batch_root: string | null;
  prev_root: string;
  root: string;
  old_pubkey: string | null;
  new_pubkey: string | null;
  created_at: number;
}

function rowToEvent(row: LedgerEventRow): StoredLedgerEvent {
  return {
    id: row.id,
    type: row.type,
    claim_hash: row.claim_hash,
    evidence_uri: row.evidence_uri,
    timestamp: row.timestamp,
    validator_pubkey: row.validator_pubkey,
    signature: row.signature,
    audit_ref: row.audit_ref,
    audit_verdict: row.audit_verdict === null ? null : Boolean(row.audit_verdict),
    stake_locked: row.stake_locked,
    stake_slashed: row.stake_slashed,
    batch_root: row.batch_root,
    prev_root: row.prev_root,
    root: row.root,
    old_pubkey: row.old_pubkey,
    new_pubkey: row.new_pubkey,
  };
}

/**
 * Append-only ledger store backed by SQLite. Each appendEvent call forms a
 * new single-event "block": the event's leaf hash is passed through the
 * same Merkle tree builder used for batch commitments (F5.3), then chained
 * onto the previous root via computeChainRoot so any historical tamper is
 * detectable by replaying the chain from GENESIS_ROOT.
 */
export class LedgerStore {
  constructor(private readonly db: Db) {}

  getLatestRoot(): string {
    const row = this.db
      .prepare("SELECT root FROM ledger_events ORDER BY id DESC LIMIT 1")
      .get() as { root: string } | undefined;
    return row?.root ?? GENESIS_ROOT;
  }

  appendEvent(input: NewLedgerEventInput): StoredLedgerEvent {
    const prevRoot = this.getLatestRoot();

    const auditRef = input.audit_ref ?? null;
    const auditVerdict = input.audit_verdict ?? null;
    const stakeLocked = input.stake_locked ?? 0;
    const stakeSlashed = input.stake_slashed ?? 0;
    const batchRoot = input.batch_root ?? null;

    // Batch events use batch_root directly as their ledger-level leaf (it's
    // already a hash committing to every claim in the batch, authenticated
    // separately by batch_signature), so a claim's proof can chain straight
    // from its per-claim Merkle proof into the outer block chain without a
    // second, differently-shaped hashing step in between. Every other event
    // type hashes its full canonical field set instead.
    const leaf =
      input.type === "batch" && batchRoot
        ? batchRoot
        : computeLeafHash({
            claim_hash: input.claim_hash,
            evidence_uri: input.evidence_uri,
            timestamp: input.timestamp,
            validator_pubkey: input.validator_pubkey,
            signature: input.signature,
            audit_ref: auditRef,
            audit_verdict: auditVerdict,
            stake_locked: stakeLocked,
            stake_slashed: stakeSlashed,
            batch_root: batchRoot,
          });
    const blockRoot = buildMerkleTree([leaf]).root;
    const root = computeChainRoot(prevRoot, blockRoot);

    const stmt = this.db.prepare(`
      INSERT INTO ledger_events
        (type, claim_hash, evidence_uri, timestamp, validator_pubkey, signature,
         audit_ref, audit_verdict, stake_locked, stake_slashed, batch_root,
         prev_root, root, old_pubkey, new_pubkey, created_at)
      VALUES (@type, @claim_hash, @evidence_uri, @timestamp, @validator_pubkey, @signature,
              @audit_ref, @audit_verdict, @stake_locked, @stake_slashed, @batch_root,
              @prev_root, @root, @old_pubkey, @new_pubkey, @created_at)
    `);

    const result = stmt.run({
      type: input.type,
      claim_hash: input.claim_hash,
      evidence_uri: input.evidence_uri,
      timestamp: input.timestamp,
      validator_pubkey: input.validator_pubkey,
      signature: input.signature,
      audit_ref: auditRef,
      audit_verdict: auditVerdict === null ? null : auditVerdict ? 1 : 0,
      stake_locked: stakeLocked,
      stake_slashed: stakeSlashed,
      batch_root: batchRoot,
      prev_root: prevRoot,
      root,
      old_pubkey: input.old_pubkey ?? null,
      new_pubkey: input.new_pubkey ?? null,
      created_at: Date.now(),
    });

    const stored = this.getById(Number(result.lastInsertRowid));
    if (!stored) {
      throw new Error("Failed to read back just-inserted ledger event");
    }
    return stored;
  }

  getById(id: number): StoredLedgerEvent | null {
    const row = this.db.prepare("SELECT * FROM ledger_events WHERE id = ?").get(id) as
      | LedgerEventRow
      | undefined;
    return row ? rowToEvent(row) : null;
  }

  getEventsByPubKey(pubkey: string): StoredLedgerEvent[] {
    const rows = this.db
      .prepare("SELECT * FROM ledger_events WHERE validator_pubkey = ? ORDER BY id ASC")
      .all(pubkey) as LedgerEventRow[];
    return rows.map(rowToEvent);
  }

  /** Returns the original event for claim_hash plus any audit events referencing it. */
  getEventByClaimHash(claimHash: string): StoredLedgerEvent[] {
    const rows = this.db
      .prepare("SELECT * FROM ledger_events WHERE claim_hash = ? OR audit_ref = ? ORDER BY id ASC")
      .all(claimHash, claimHash) as LedgerEventRow[];
    return rows.map(rowToEvent);
  }

  /**
   * Resolves the original claim a claim_hash refers to, whether it came in
   * as a standalone /submit or as a leaf inside a /submit/batch. Used for
   * self-audit rejection and score attribution.
   */
  findOriginalClaim(claimHash: string): OriginalClaimInfo | null {
    const submission = this.db
      .prepare(
        "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM ledger_events WHERE claim_hash = ? AND type = 'submission' LIMIT 1",
      )
      .get(claimHash) as OriginalClaimInfo | undefined;
    if (submission) return submission;

    const leaf = this.db
      .prepare(
        "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM batch_leaves WHERE claim_hash = ? LIMIT 1",
      )
      .get(claimHash) as OriginalClaimInfo | undefined;
    return leaf ?? null;
  }

  /** All claim_hashes ever submitted by a validator, via /submit or as a batch leaf. */
  getAllClaimsForValidator(pubkey: string): OriginalClaimInfo[] {
    const submissions = this.db
      .prepare(
        "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM ledger_events WHERE validator_pubkey = ? AND type = 'submission'",
      )
      .all(pubkey) as OriginalClaimInfo[];
    const leaves = this.db
      .prepare(
        "SELECT claim_hash, validator_pubkey, timestamp, evidence_uri FROM batch_leaves WHERE validator_pubkey = ?",
      )
      .all(pubkey) as OriginalClaimInfo[];
    return [...submissions, ...leaves];
  }

  getSubmissionEvent(claimHash: string): StoredLedgerEvent | null {
    const row = this.db
      .prepare("SELECT * FROM ledger_events WHERE claim_hash = ? AND type = 'submission' LIMIT 1")
      .get(claimHash) as LedgerEventRow | undefined;
    return row ? rowToEvent(row) : null;
  }

  getAuditsForClaim(claimHash: string): StoredLedgerEvent[] {
    const rows = this.db
      .prepare("SELECT * FROM ledger_events WHERE type = 'audit' AND audit_ref = ? ORDER BY id ASC")
      .all(claimHash) as LedgerEventRow[];
    return rows.map(rowToEvent);
  }

  getAllEvents(): StoredLedgerEvent[] {
    const rows = this.db.prepare("SELECT * FROM ledger_events ORDER BY id ASC").all() as LedgerEventRow[];
    return rows.map(rowToEvent);
  }

  findRotationByOldPubkey(oldPubkey: string): StoredLedgerEvent | null {
    const row = this.db
      .prepare("SELECT * FROM ledger_events WHERE type = 'key_rotation' AND old_pubkey = ? LIMIT 1")
      .get(oldPubkey) as LedgerEventRow | undefined;
    return row ? rowToEvent(row) : null;
  }

  findRotationByNewPubkey(newPubkey: string): StoredLedgerEvent | null {
    const row = this.db
      .prepare("SELECT * FROM ledger_events WHERE type = 'key_rotation' AND new_pubkey = ? LIMIT 1")
      .get(newPubkey) as LedgerEventRow | undefined;
    return row ? rowToEvent(row) : null;
  }

  /**
   * F1.2: resolves every pubkey in one validator's continuous identity —
   * walking backward through rotations to the earliest key and forward to
   * the current one — so score/history lookups treat a rotated validator
   * as a single lifelong track record rather than starting over at 0.5.
   * Returns oldest-first; the last entry is the currently active key.
   */
  resolveIdentityLineage(pubkey: string): string[] {
    let earliest = pubkey;
    const seenBackward = new Set([earliest]);
    for (;;) {
      const rotation = this.findRotationByNewPubkey(earliest);
      if (!rotation || !rotation.old_pubkey || seenBackward.has(rotation.old_pubkey)) break;
      earliest = rotation.old_pubkey;
      seenBackward.add(earliest);
    }

    const chain: string[] = [earliest];
    const seenForward = new Set(chain);
    let current = earliest;
    for (;;) {
      const rotation = this.findRotationByOldPubkey(current);
      if (!rotation || !rotation.new_pubkey || seenForward.has(rotation.new_pubkey)) break;
      current = rotation.new_pubkey;
      chain.push(current);
      seenForward.add(current);
    }
    return chain;
  }

  /**
   * Full, unfiltered history for a validator's identity as a SUBMITTER
   * (F6.1): every claim/batch/rotation event they authored across their
   * rotation lineage, PLUS every audit event anyone made against one of
   * their claims (direct or batched) — a validator can't hide an
   * overturned verdict by it not being "theirs". Deliberately excludes
   * audits *they themselves* performed on other validators' claims: this
   * spec's scoring only rates submitter behavior, so an auditor's own
   * activity isn't part of their track record here. That also keeps every
   * 'audit' row in this list unambiguously groupable by audit_ref for the
   * offline verifier's score recomputation (see cli/verify.ts).
   */
  getEventsForIdentity(pubkey: string): StoredLedgerEvent[] {
    const lineage = this.resolveIdentityLineage(pubkey);
    const placeholders = lineage.map(() => "?").join(",");
    const rows = this.db
      .prepare(
        `SELECT * FROM ledger_events
         WHERE (validator_pubkey IN (${placeholders}) AND type != 'audit')
            OR audit_ref IN (
              SELECT claim_hash FROM ledger_events WHERE type = 'submission' AND validator_pubkey IN (${placeholders})
              UNION
              SELECT claim_hash FROM batch_leaves WHERE validator_pubkey IN (${placeholders})
            )
         ORDER BY id ASC`,
      )
      .all(...lineage, ...lineage, ...lineage) as LedgerEventRow[];
    return rows.map(rowToEvent);
  }
}
