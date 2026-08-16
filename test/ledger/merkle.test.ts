import { describe, expect, it } from "vitest";
import { buildMerkleTree, getMerkleProof, verifyMerkleProof, hashPair } from "../../src/ledger/merkle.js";
import { keccak256Hex, utf8ToBytes } from "../../src/crypto/encoding.js";

function leaf(s: string): string {
  return keccak256Hex(utf8ToBytes(s));
}

describe("ledger/merkle", () => {
  it("produces a valid inclusion proof for every leaf in an odd-sized tree", () => {
    const leaves = ["a", "b", "c", "d", "e"].map(leaf);
    const tree = buildMerkleTree(leaves);

    leaves.forEach((l, i) => {
      const proof = getMerkleProof(tree.layers, i);
      expect(verifyMerkleProof(l, proof, tree.root)).toBe(true);
    });
  });

  it("produces a valid inclusion proof for every leaf in an even-sized tree", () => {
    const leaves = ["a", "b", "c", "d"].map(leaf);
    const tree = buildMerkleTree(leaves);

    leaves.forEach((l, i) => {
      const proof = getMerkleProof(tree.layers, i);
      expect(verifyMerkleProof(l, proof, tree.root)).toBe(true);
    });
  });

  it("handles a single-leaf tree (root equals the leaf)", () => {
    const l = leaf("only");
    const tree = buildMerkleTree([l]);
    expect(tree.root).toBe(l);
    expect(verifyMerkleProof(l, getMerkleProof(tree.layers, 0), tree.root)).toBe(true);
  });

  it("rejects a proof against a tampered leaf", () => {
    const leaves = ["a", "b", "c", "d"].map(leaf);
    const tree = buildMerkleTree(leaves);
    const proof = getMerkleProof(tree.layers, 1);
    expect(verifyMerkleProof(leaf("tampered"), proof, tree.root)).toBe(false);
  });

  it("rejects a proof against a tampered root", () => {
    const leaves = ["a", "b", "c", "d"].map(leaf);
    const tree = buildMerkleTree(leaves);
    const proof = getMerkleProof(tree.layers, 1);
    const fakeRoot = leaf("fake-root");
    expect(verifyMerkleProof(leaves[1] as string, proof, fakeRoot)).toBe(false);
  });

  it("changing any single leaf changes the root (tamper evidence)", () => {
    const leaves = ["a", "b", "c", "d", "e"].map(leaf);
    const original = buildMerkleTree(leaves).root;

    for (let i = 0; i < leaves.length; i++) {
      const tampered = [...leaves];
      tampered[i] = leaf(`${leaves[i]}-tampered`);
      const tamperedRoot = buildMerkleTree(tampered).root;
      expect(tamperedRoot).not.toBe(original);
    }
  });

  it("hashPair is order-independent (sorted-pair hashing)", () => {
    const x = leaf("x");
    const y = leaf("y");
    expect(hashPair(x, y)).toBe(hashPair(y, x));
  });

  it("is deterministic given the same leaves", () => {
    const leaves = ["a", "b", "c"].map(leaf);
    expect(buildMerkleTree(leaves).root).toBe(buildMerkleTree(leaves).root);
  });
});
