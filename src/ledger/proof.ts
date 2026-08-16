import type { Db } from "../db/index.js";
import { computeLedgerLeaf, GENESIS_ROOT } from "./hash.js";
import { buildMerkleTree, getMerkleProof } from "./merkle.js";
import type { LedgerStore } from "./store.js";

export interface ChainProof {
  path: string[];
  root: string;
}

/**
 * Builds the sibling-hash path from an already-appended event's own leaf
 * (see computeLedgerLeaf) all the way to the current latest chain root, by
 * replaying every event that came after it. Proof length is O(events
 * since origin) — this simulation uses one event per block for simplicity,
 * trading succinctness for a much simpler append API (see README).
 */
export function buildChainProof(ledgerStore: LedgerStore, originEventId: number): ChainProof {
  const all = ledgerStore.getAllEvents();
  const originIndex = all.findIndex((e) => e.id === originEventId);
  if (originIndex === -1) {
    throw new Error(`event ${originEventId} not found`);
  }

  const origin = all[originIndex] as (typeof all)[number];
  const path: string[] = [origin.prev_root];

  for (let k = originIndex + 1; k < all.length; k++) {
    const ev = all[k] as (typeof all)[number];
    path.push(computeLedgerLeaf(ev));
  }

  const root = all.length > 0 ? (all[all.length - 1] as (typeof all)[number]).root : GENESIS_ROOT;
  return { path, root };
}

export interface BatchLeafProof {
  batchEventId: number;
  batchRoot: string;
  path: string[];
}

/** Merkle proof of an individual claim's inclusion in its batch's batch_root, if it is a batch leaf. */
export function findBatchLeafProof(db: Db, claimHash: string): BatchLeafProof | null {
  const leafRow = db
    .prepare("SELECT batch_event_id FROM batch_leaves WHERE claim_hash = ? LIMIT 1")
    .get(claimHash) as { batch_event_id: number } | undefined;
  if (!leafRow) return null;

  const rows = db
    .prepare("SELECT leaf_index, claim_hash FROM batch_leaves WHERE batch_event_id = ? ORDER BY leaf_index ASC")
    .all(leafRow.batch_event_id) as Array<{ leaf_index: number; claim_hash: string }>;

  const leaves = rows.map((r) => r.claim_hash);
  const index = rows.findIndex((r) => r.claim_hash === claimHash);
  const tree = buildMerkleTree(leaves);
  const path = getMerkleProof(tree.layers, index);

  return { batchEventId: leafRow.batch_event_id, batchRoot: tree.root, path };
}
