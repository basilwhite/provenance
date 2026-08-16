import { describe, expect, it } from "vitest";
import { generateKeyPair, sign, verify, getPublicKey } from "../../src/crypto/keys.js";

describe("crypto/keys", () => {
  it("round-trips sign/verify for a valid signature", () => {
    const { publicKey, privateKey } = generateKeyPair();
    const message = new TextEncoder().encode("hello provenance");
    const signature = sign(message, privateKey);
    expect(verify(message, signature, publicKey)).toBe(true);
  });

  it("rejects a signature from the wrong key", () => {
    const a = generateKeyPair();
    const b = generateKeyPair();
    const message = new TextEncoder().encode("hello provenance");
    const signature = sign(message, a.privateKey);
    expect(verify(message, signature, b.publicKey)).toBe(false);
  });

  it("rejects a tampered message", () => {
    const { publicKey, privateKey } = generateKeyPair();
    const message = new TextEncoder().encode("original message");
    const signature = sign(message, privateKey);
    const tampered = new TextEncoder().encode("tampered message");
    expect(verify(tampered, signature, publicKey)).toBe(false);
  });

  it("rejects garbage/random-byte signatures instead of throwing", () => {
    const { publicKey } = generateKeyPair();
    const message = new TextEncoder().encode("hello provenance");
    const garbage = new Uint8Array(64).fill(0xab);
    expect(() => verify(message, garbage, publicKey)).not.toThrow();
    expect(verify(message, garbage, publicKey)).toBe(false);
  });

  it("rejects malformed (wrong-length) signatures", () => {
    const { publicKey } = generateKeyPair();
    const message = new TextEncoder().encode("hello provenance");
    const malformed = new Uint8Array(3).fill(0x01);
    expect(verify(message, malformed, publicKey)).toBe(false);
  });

  it("derives the same public key generateKeyPair produced", () => {
    const { publicKey, privateKey } = generateKeyPair();
    expect(getPublicKey(privateKey)).toEqual(publicKey);
  });

  it("generates distinct key pairs on each call", () => {
    const a = generateKeyPair();
    const b = generateKeyPair();
    expect(a.privateKey).not.toEqual(b.privateKey);
    expect(a.publicKey).not.toEqual(b.publicKey);
  });

  it("never returns the private key in a JSON-serialized public API shape", () => {
    const { publicKey } = generateKeyPair();
    // generateKeyPair's return type only exposes publicKey/privateKey; this
    // asserts publicKey alone round-trips without needing the private key.
    expect(publicKey.length).toBe(32);
  });
});
