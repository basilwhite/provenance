import { ApiError } from "./errors.js";

const PUBKEY_HEX_RE = /^0x[0-9a-fA-F]{64}$/;
const SIGNATURE_HEX_RE = /^0x[0-9a-fA-F]{128}$/;
const HASH_HEX_RE = /^0x[0-9a-fA-F]{64}$/;

export function requireString(value: unknown, field: string): string {
  if (typeof value !== "string" || value.length === 0) {
    throw new ApiError(400, "invalid_request", `${field} is required and must be a non-empty string`);
  }
  return value;
}

export function requireNumber(value: unknown, field: string): number {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) {
    throw new ApiError(400, "invalid_request", `${field} is required and must be a positive number`);
  }
  return value;
}

export function requireBoolean(value: unknown, field: string): boolean {
  if (typeof value !== "boolean") {
    throw new ApiError(400, "invalid_request", `${field} is required and must be a boolean`);
  }
  return value;
}

export function requirePubkeyHex(value: unknown, field: string): string {
  const str = requireString(value, field);
  if (!PUBKEY_HEX_RE.test(str)) {
    throw new ApiError(400, "invalid_request", `${field} must be a 0x-prefixed 32-byte hex string`);
  }
  return str;
}

export function requireSignatureHex(value: unknown, field: string): string {
  const str = requireString(value, field);
  if (!SIGNATURE_HEX_RE.test(str)) {
    throw new ApiError(400, "invalid_request", `${field} must be a 0x-prefixed 64-byte hex string`);
  }
  return str;
}

export function requireClaimHashHex(value: unknown, field: string): string {
  const str = requireString(value, field);
  if (!HASH_HEX_RE.test(str)) {
    throw new ApiError(400, "invalid_request", `${field} must be a 0x-prefixed 32-byte hex string`);
  }
  return str;
}
