import { concatBytes, hexToBytes, utf8ToBytes } from "./encoding.js";

/**
 * Canonical message construction for each signed action. Kept centralized
 * so the server and the offline verifier CLI can never drift apart on what
 * bytes actually get signed.
 */

/** Used by /submit and /audit: sign over (claim_hash + timestamp). */
export function claimTimestampMessage(claimHash: string, timestamp: number): Uint8Array {
  return concatBytes(hexToBytes(claimHash), utf8ToBytes(String(timestamp)));
}

/** Used by POST /keys/rotate: the OLD key signs the NEW key. */
export function rotationMessage(oldPubkeyHex: string, newPubkeyHex: string): Uint8Array {
  return concatBytes(hexToBytes(oldPubkeyHex), hexToBytes(newPubkeyHex));
}

/** Used by POST /submit/batch: sign over (batch_root + timestamp). */
export function batchMessage(batchRoot: string, timestamp: number): Uint8Array {
  return concatBytes(hexToBytes(batchRoot), utf8ToBytes(String(timestamp)));
}
