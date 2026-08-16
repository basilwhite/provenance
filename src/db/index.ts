import Database from "better-sqlite3";
import { mkdirSync } from "node:fs";
import { dirname } from "node:path";

const SCHEMA = `
CREATE TABLE IF NOT EXISTS ledger_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  claim_hash TEXT NOT NULL,
  evidence_uri TEXT NOT NULL,
  timestamp INTEGER NOT NULL,
  validator_pubkey TEXT NOT NULL,
  signature TEXT NOT NULL,
  audit_ref TEXT,
  audit_verdict INTEGER,
  stake_locked INTEGER NOT NULL DEFAULT 0,
  stake_slashed INTEGER NOT NULL DEFAULT 0,
  batch_root TEXT,
  prev_root TEXT NOT NULL,
  root TEXT NOT NULL,
  old_pubkey TEXT,
  new_pubkey TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ledger_events_pubkey ON ledger_events(validator_pubkey);
CREATE INDEX IF NOT EXISTS idx_ledger_events_claim_hash ON ledger_events(claim_hash);
CREATE INDEX IF NOT EXISTS idx_ledger_events_audit_ref ON ledger_events(audit_ref);

CREATE TABLE IF NOT EXISTS claim_texts (
  claim_hash TEXT PRIMARY KEY,
  claim_text TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS batch_leaves (
  batch_event_id INTEGER NOT NULL REFERENCES ledger_events(id),
  leaf_index INTEGER NOT NULL,
  claim_hash TEXT NOT NULL,
  evidence_uri TEXT NOT NULL,
  timestamp INTEGER NOT NULL,
  validator_pubkey TEXT NOT NULL,
  signature TEXT NOT NULL,
  PRIMARY KEY (batch_event_id, leaf_index)
);
CREATE INDEX IF NOT EXISTS idx_batch_leaves_claim_hash ON batch_leaves(claim_hash);

CREATE TABLE IF NOT EXISTS validator_scores (
  validator_pubkey TEXT PRIMARY KEY,
  n INTEGER NOT NULL DEFAULT 0,
  confirmations INTEGER NOT NULL DEFAULT 0,
  overturns INTEGER NOT NULL DEFAULT 0,
  score REAL NOT NULL DEFAULT 0.5,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS stakes (
  validator_pubkey TEXT PRIMARY KEY,
  amount_locked INTEGER NOT NULL DEFAULT 0,
  amount_slashed INTEGER NOT NULL DEFAULT 0
);
`;

export type Db = Database.Database;

export function createDb(path = ":memory:"): Db {
  if (path !== ":memory:") {
    mkdirSync(dirname(path), { recursive: true });
  }
  const db = new Database(path);
  db.pragma("journal_mode = WAL");
  db.pragma("foreign_keys = ON");
  db.exec(SCHEMA);
  return db;
}

let defaultDb: Db | null = null;

/** Lazily-created singleton used by the HTTP server (not by tests). */
export function getDefaultDb(): Db {
  if (!defaultDb) {
    const path = process.env["PROVENANCE_DB_PATH"] ?? "data/provenance.db";
    defaultDb = createDb(path);
  }
  return defaultDb;
}
