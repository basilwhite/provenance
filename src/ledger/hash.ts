import { keccak256Hex, utf8ToBytes } from "../crypto/encoding.js";
import { hashPair } from "./merkle.js";
import { LEDGER_EVENT_FIELD_ORDER, type LedgerEvent, type StoredLedgerEvent } from "./types.js";

/** Fixed root used as prev_root for the very first event ever appended. */
export const GENESIS_ROOT = keccak256Hex(utf8ToBytes("PROVENANCE_GENESIS_V1"));

/**
 * Canonical, order-fixed hash of an event's spec fields (everything except
 * prev_root/root, which are chain state derived from this hash, not inputs
 * to it). Array-based JSON serialization avoids object-key-order ambiguity,
 * so this is trivially reproducible by the offline verifier.
 */
export function computeLeafHash(event: Omit<LedgerEvent, "prev_root" | "root">): string {
  const fields = LEDGER_EVENT_FIELD_ORDER.map((key) => event[key]);
  return keccak256Hex(utf8ToBytes(JSON.stringify(fields)));
}

/**
 * The ledger-level leaf for an already-stored event, mirroring the choice
 * LedgerStore.appendEvent made at insert time: batch events leaf on their
 * batch_root, everything else leafs on the full canonical field hash.
 */
export function computeLedgerLeaf(event: StoredLedgerEvent): string {
  return event.type === "batch" && event.batch_root ? event.batch_root : computeLeafHash(event);
}

/**
 * Chains a block's Merkle root onto the previous chain root using the same
 * sorted-pair hashPair as the Merkle tree, so a claim's chain-of-custody
 * proof to the latest root can be expressed as one flat sibling-hash list
 * (see ledger/proof.ts) — no separate proof format for "inside a block"
 * vs. "across blocks".
 */
export function computeChainRoot(prevRoot: string, blockMerkleRoot: string): string {
  return hashPair(prevRoot, blockMerkleRoot);
}
