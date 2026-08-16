import { concatBytes, hexToBytes, keccak256Hex, utf8ToBytes } from "../crypto/encoding.js";

export interface MerkleTree {
  root: string;
  /** layers[0] = leaves, layers[layers.length - 1] = [root] */
  layers: string[][];
}

/** keccak256 hash of an arbitrary leaf payload, hex-encoded. */
export function leafHash(data: Uint8Array): string {
  return keccak256Hex(data);
}

/**
 * Combines two hashes into a parent hash. Pairs are sorted before hashing
 * so a proof is just a flat list of sibling hashes (no left/right bit
 * needed) — this is what lets a single `path: string[]` verify inclusion
 * both within a batch's claim tree AND across the ledger's block-to-block
 * hash chain (see ledger/hash.ts computeChainRoot, which reuses this).
 */
export function hashPair(a: string, b: string): string {
  const [x, y] = a.toLowerCase() <= b.toLowerCase() ? [a, b] : [b, a];
  return keccak256Hex(concatBytes(hexToBytes(x), hexToBytes(y)));
}

const EMPTY_TREE_ROOT = keccak256Hex(utf8ToBytes(""));

/**
 * Builds a Merkle tree over pre-hashed leaves (hex strings). Odd layers
 * duplicate the final node so every level has a well-defined pairing,
 * matching the proof construction in getMerkleProof.
 */
export function buildMerkleTree(leaves: string[]): MerkleTree {
  if (leaves.length === 0) {
    return { root: EMPTY_TREE_ROOT, layers: [[EMPTY_TREE_ROOT]] };
  }

  const layers: string[][] = [leaves.slice()];
  let current: string[] = leaves;

  while (current.length > 1) {
    const next: string[] = [];
    for (let i = 0; i < current.length; i += 2) {
      const left = current[i] as string;
      const right = i + 1 < current.length ? (current[i + 1] as string) : left;
      next.push(hashPair(left, right));
    }
    layers.push(next);
    current = next;
  }

  return { root: current[0] as string, layers };
}

/** Builds a flat sibling-hash proof of inclusion for the leaf at leafIndex. */
export function getMerkleProof(layers: string[][], leafIndex: number): string[] {
  if (leafIndex < 0 || leafIndex >= (layers[0]?.length ?? 0)) {
    throw new Error(`leafIndex ${leafIndex} out of range`);
  }

  const proof: string[] = [];
  let index = leafIndex;

  for (let level = 0; level < layers.length - 1; level++) {
    const layer = layers[level] as string[];
    const isRightNode = index % 2 === 1;
    const siblingIndex = isRightNode ? index - 1 : index + 1;
    const sibling = siblingIndex < layer.length ? (layer[siblingIndex] as string) : (layer[index] as string);
    proof.push(sibling);
    index = Math.floor(index / 2);
  }

  return proof;
}

/** Recomputes the root from a leaf + proof and compares against the expected root. */
export function verifyMerkleProof(leaf: string, proof: string[], root: string): boolean {
  let computed = leaf;
  for (const sibling of proof) {
    computed = hashPair(computed, sibling);
  }
  return computed.toLowerCase() === root.toLowerCase();
}
