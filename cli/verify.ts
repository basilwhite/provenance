#!/usr/bin/env node
import { readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";
import { verify } from "../src/crypto/keys.js";
import { hexToBytes } from "../src/crypto/encoding.js";
import { batchMessage, claimTimestampMessage, rotationMessage } from "../src/crypto/messages.js";
import { computeScore } from "../src/scoring/wilson.js";
import type { LedgerEventType } from "../src/ledger/types.js";

export interface RawEvent {
  id: number;
  type: LedgerEventType;
  claim_hash: string;
  evidence_uri: string;
  timestamp: number;
  validator_pubkey: string;
  signature: string;
  audit_ref: string | null;
  audit_verdict: boolean | null;
  stake_locked: number;
  stake_slashed: number;
  batch_root: string | null;
  prev_root: string;
  root: string;
  old_pubkey: string | null;
  new_pubkey: string | null;
}

export interface MirrorPayload {
  validator_pubkey: string;
  events: RawEvent[];
  reported_score: number;
}

export interface Args {
  pubkey: string;
  ledgerUrl?: string;
  ledgerFile?: string;
  expectedScore?: number;
}

export function parseArgs(argv: string[]): Args {
  const args: Partial<Args> = {};
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i];
    const value = argv[i + 1];
    switch (flag) {
      case "--pubkey":
        args.pubkey = value;
        i++;
        break;
      case "--ledger-url":
        args.ledgerUrl = value;
        i++;
        break;
      case "--ledger-file":
        args.ledgerFile = value;
        i++;
        break;
      case "--expected-score":
        args.expectedScore = value !== undefined ? Number(value) : undefined;
        i++;
        break;
      default:
        break;
    }
  }

  if (!args.pubkey) {
    throw new Error("--pubkey is required");
  }
  if (!args.ledgerUrl && !args.ledgerFile) {
    throw new Error("one of --ledger-url or --ledger-file is required");
  }
  return args as Args;
}

export async function fetchMirror(args: Args): Promise<MirrorPayload> {
  if (args.ledgerFile) {
    const content = readFileSync(args.ledgerFile, "utf8");
    return JSON.parse(content) as MirrorPayload;
  }
  const url = `${args.ledgerUrl}/validators/${args.pubkey}/events`;
  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`GET ${url} failed: ${res.status} ${res.statusText}`);
  }
  return (await res.json()) as MirrorPayload;
}

/** Rebuilds the exact message each event type signs, per crypto/messages.ts. */
export function messageForEvent(event: RawEvent): { message: Uint8Array; signerPubkey: string } {
  switch (event.type) {
    case "submission":
    case "audit":
      return { message: claimTimestampMessage(event.claim_hash, event.timestamp), signerPubkey: event.validator_pubkey };
    case "batch":
      if (!event.batch_root) throw new Error(`batch event ${event.id} missing batch_root`);
      return { message: batchMessage(event.batch_root, event.timestamp), signerPubkey: event.validator_pubkey };
    case "key_rotation":
      if (!event.old_pubkey || !event.new_pubkey) {
        throw new Error(`key_rotation event ${event.id} missing old_pubkey/new_pubkey`);
      }
      // The OLD key signs the rotation, not validator_pubkey (= new_pubkey).
      return { message: rotationMessage(event.old_pubkey, event.new_pubkey), signerPubkey: event.old_pubkey };
  }
}

export function validateSignatures(events: RawEvent[]): { valid: boolean; failures: number[] } {
  const failures: number[] = [];
  for (const event of events) {
    const { message, signerPubkey } = messageForEvent(event);
    const ok = verify(message, hexToBytes(event.signature), hexToBytes(signerPubkey));
    if (!ok) failures.push(event.id);
  }
  return { valid: failures.length === 0, failures };
}

/**
 * Mirrors protocol/finalize.ts's aggregation, but working only from the
 * flat event list a client can fetch/mirror: every 'audit' event in that
 * list is guaranteed (by the server's getEventsForIdentity query) to
 * target one of this validator's own claims, so grouping by audit_ref
 * alone is sufficient — no separate claim inventory is needed.
 */
export function recomputeScore(events: RawEvent[]): { score: number; n: number; confirmations: number; overturns: number } {
  const auditsByRef = new Map<string, boolean[]>();
  for (const event of events) {
    if (event.type === "audit" && event.audit_ref && event.audit_verdict !== null) {
      const group = auditsByRef.get(event.audit_ref) ?? [];
      group.push(event.audit_verdict);
      auditsByRef.set(event.audit_ref, group);
    }
  }

  let confirmations = 0;
  let overturns = 0;
  for (const verdicts of auditsByRef.values()) {
    if (verdicts.length < 2) continue; // not finalized (F3.3)
    for (const v of verdicts) {
      if (v) confirmations++;
      else overturns++;
    }
  }

  const n = confirmations + overturns;
  return { score: computeScore(n, confirmations, overturns), n, confirmations, overturns };
}

async function main(): Promise<void> {
  const args = parseArgs(process.argv.slice(2));
  const mirror = await fetchMirror(args);

  console.log(`Fetched ${mirror.events.length} event(s) for ${args.pubkey}`);

  const sigResult = validateSignatures(mirror.events);
  if (!sigResult.valid) {
    console.log(`Signature validation FAILED for event id(s): ${sigResult.failures.join(", ")}`);
    console.log("FAIL");
    process.exitCode = 1;
    return;
  }
  console.log(`All ${mirror.events.length} signature(s) valid.`);

  const recomputed = recomputeScore(mirror.events);
  console.log(
    `Recomputed score: ${recomputed.score.toFixed(6)} (n=${recomputed.n}, confirmations=${recomputed.confirmations}, overturns=${recomputed.overturns})`,
  );

  const reference = args.expectedScore ?? mirror.reported_score;
  if (reference === undefined) {
    console.log("No server-reported or --expected-score provided; skipping score comparison.");
    console.log("PASS");
    return;
  }

  const EPSILON = 1e-9;
  const matches = Math.abs(reference - recomputed.score) < EPSILON;
  console.log(`Reference score: ${reference.toFixed(6)}`);

  if (!matches) {
    console.log("Score MISMATCH between recomputed and reference value.");
    console.log("FAIL");
    process.exitCode = 1;
    return;
  }

  console.log("PASS");
}

const isMainModule =
  process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href;

if (isMainModule) {
  main().catch((err: unknown) => {
    console.error(err instanceof Error ? err.message : err);
    console.log("FAIL");
    process.exitCode = 1;
  });
}
