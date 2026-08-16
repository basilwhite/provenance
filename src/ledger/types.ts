/**
 * Canonical ledger event fields per the Provenance spec (Section 2, I2.1).
 * This is exactly the field set that is hashed into a Merkle leaf and
 * returned to clients/the offline verifier — internal bookkeeping columns
 * (id, type, ...) live on StoredLedgerEvent instead so they never affect
 * the hash chain or break independent recomputation.
 */
export interface LedgerEvent {
  claim_hash: string;
  evidence_uri: string;
  timestamp: number;
  validator_pubkey: string;
  signature: string;
  audit_ref: string | null;
  audit_verdict: boolean | null;
  stake_locked: number;
  stake_slashed: number;
  batch_root: string | null;
  prev_root: string;
  root: string;
}

export type LedgerEventType = "submission" | "audit" | "batch" | "key_rotation";

/** A LedgerEvent as persisted, with internal metadata the DB needs. */
export interface StoredLedgerEvent extends LedgerEvent {
  id: number;
  type: LedgerEventType;
  /** Only set for type === "key_rotation". */
  old_pubkey: string | null;
  new_pubkey: string | null;
}

/** The exact field order used to build the canonical hash preimage for a leaf. */
export const LEDGER_EVENT_FIELD_ORDER: Array<keyof Omit<LedgerEvent, "prev_root" | "root">> = [
  "claim_hash",
  "evidence_uri",
  "timestamp",
  "validator_pubkey",
  "signature",
  "audit_ref",
  "audit_verdict",
  "stake_locked",
  "stake_slashed",
  "batch_root",
];
