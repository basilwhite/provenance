import { computeChainRoot, computeLedgerLeaf, GENESIS_ROOT } from "./hash.js";
import { buildMerkleTree } from "./merkle.js";
import type { StoredLedgerEvent } from "./types.js";

export interface ReplayResult {
  valid: boolean;
  mismatchAtEventId?: number;
  recomputedLatestRoot: string;
}

/**
 * Independently recomputes the entire hash chain from GENESIS_ROOT given
 * only the raw events (in id order), and checks it against each event's
 * stored prev_root/root. Any tampering with historical data — a changed
 * field, a reordered or dropped event — is caught here. Shared by the
 * I2.2 tamper-detection test and the offline verifier CLI (F6.2).
 */
export function replayChain(events: StoredLedgerEvent[]): ReplayResult {
  let prevRoot = GENESIS_ROOT;

  for (const event of events) {
    if (event.prev_root !== prevRoot) {
      return { valid: false, mismatchAtEventId: event.id, recomputedLatestRoot: prevRoot };
    }

    const leaf = computeLedgerLeaf(event);
    const blockRoot = buildMerkleTree([leaf]).root;
    const recomputedRoot = computeChainRoot(prevRoot, blockRoot);

    if (recomputedRoot !== event.root) {
      return { valid: false, mismatchAtEventId: event.id, recomputedLatestRoot: recomputedRoot };
    }

    prevRoot = recomputedRoot;
  }

  return { valid: true, recomputedLatestRoot: prevRoot };
}
