import { bytesToHex as _bytesToHex, hexToBytes as _hexToBytes } from "@noble/hashes/utils";
import { keccak_256 } from "@noble/hashes/sha3";

export function bytesToHex(bytes: Uint8Array): string {
  return `0x${_bytesToHex(bytes)}`;
}

export function hexToBytes(hex: string): Uint8Array {
  const clean = hex.startsWith("0x") ? hex.slice(2) : hex;
  if (clean.length % 2 !== 0) {
    throw new Error(`Invalid hex string length: ${hex}`);
  }
  return _hexToBytes(clean);
}

export function utf8ToBytes(str: string): Uint8Array {
  return new TextEncoder().encode(str);
}

export function keccak256Hex(input: Uint8Array): string {
  return bytesToHex(keccak_256(input));
}

/** Concatenates byte arrays for canonical hash/sign input construction. */
export function concatBytes(...arrays: Uint8Array[]): Uint8Array {
  const total = arrays.reduce((sum, a) => sum + a.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const a of arrays) {
    out.set(a, offset);
    offset += a.length;
  }
  return out;
}
