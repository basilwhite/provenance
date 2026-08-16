import { ed25519 } from "@noble/curves/ed25519";

export interface KeyPair {
  publicKey: Uint8Array;
  privateKey: Uint8Array;
}

/**
 * Generates a new Ed25519 key pair using a CSPRNG.
 * The private key is returned to the caller and must never be persisted
 * by this module or logged by any caller.
 */
export function generateKeyPair(): KeyPair {
  const privateKey = ed25519.utils.randomPrivateKey();
  const publicKey = ed25519.getPublicKey(privateKey);
  return { publicKey, privateKey };
}

/**
 * Signs an arbitrary message with an Ed25519 private key.
 */
export function sign(message: Uint8Array, privateKey: Uint8Array): Uint8Array {
  return ed25519.sign(message, privateKey);
}

/**
 * Verifies an Ed25519 signature against a message and public key.
 * Returns false (never throws) for malformed signatures or keys so callers
 * can treat verification as a pure predicate.
 */
export function verify(
  message: Uint8Array,
  signature: Uint8Array,
  publicKey: Uint8Array,
): boolean {
  try {
    return ed25519.verify(signature, message, publicKey);
  } catch {
    return false;
  }
}

/**
 * Derives the public key for a given private key. Exposed for tooling
 * (e.g. CLI key rotation helpers) that only holds a private key.
 */
export function getPublicKey(privateKey: Uint8Array): Uint8Array {
  return ed25519.getPublicKey(privateKey);
}
