import { describe, expect, it } from "vitest";
import { shouldSlashForClaim, SLASH_WINDOW_MS } from "../../src/protocol/slashing.js";
import type { OriginalClaimInfo } from "../../src/ledger/store.js";
import type { StoredLedgerEvent } from "../../src/ledger/types.js";

const original: OriginalClaimInfo = {
  claim_hash: "0x" + "01".repeat(32),
  validator_pubkey: "0x" + "aa".repeat(32),
  timestamp: 1_000_000,
  evidence_uri: "u",
};

function auditEvent(overrides: Partial<StoredLedgerEvent>): StoredLedgerEvent {
  return {
    id: 1,
    type: "audit",
    claim_hash: original.claim_hash,
    evidence_uri: "u",
    timestamp: original.timestamp,
    validator_pubkey: "0x" + "bb".repeat(32),
    signature: "0x" + "cc".repeat(64),
    audit_ref: original.claim_hash,
    audit_verdict: true,
    stake_locked: 0,
    stake_slashed: 0,
    batch_root: null,
    prev_root: "0x" + "00".repeat(32),
    root: "0x" + "ff".repeat(32),
    old_pubkey: null,
    new_pubkey: null,
    ...overrides,
  };
}

describe("protocol/slashing", () => {
  it("does not slash with zero existing overturns and a confirming pending audit", () => {
    expect(shouldSlashForClaim(original, [], { audit_verdict: true, timestamp: original.timestamp })).toBe(false);
  });

  it("does not slash on a single overturn", () => {
    expect(shouldSlashForClaim(original, [], { audit_verdict: false, timestamp: original.timestamp })).toBe(false);
  });

  it("slashes on the second overturn within the window", () => {
    const first = auditEvent({ audit_verdict: false, timestamp: original.timestamp + 1000 });
    const result = shouldSlashForClaim(original, [first], {
      audit_verdict: false,
      timestamp: original.timestamp + 2000,
    });
    expect(result).toBe(true);
  });

  it("a confirm does not count toward the two-overturn threshold", () => {
    const confirm = auditEvent({ audit_verdict: true, timestamp: original.timestamp + 1000 });
    const result = shouldSlashForClaim(original, [confirm], {
      audit_verdict: false,
      timestamp: original.timestamp + 2000,
    });
    expect(result).toBe(false);
  });

  it("does not slash when the second overturn falls outside the 7-day window", () => {
    const first = auditEvent({ audit_verdict: false, timestamp: original.timestamp + 1000 });
    const result = shouldSlashForClaim(original, [first], {
      audit_verdict: false,
      timestamp: original.timestamp + SLASH_WINDOW_MS + 1,
    });
    expect(result).toBe(false);
  });

  it("slashes when both overturns land exactly at the window boundary", () => {
    const first = auditEvent({ audit_verdict: false, timestamp: original.timestamp + SLASH_WINDOW_MS });
    const result = shouldSlashForClaim(original, [first], {
      audit_verdict: false,
      timestamp: original.timestamp + SLASH_WINDOW_MS,
    });
    expect(result).toBe(true);
  });

  it("does not slash again once a prior audit already recorded a slash (idempotent)", () => {
    const first = auditEvent({ audit_verdict: false, timestamp: original.timestamp + 1000 });
    const second = auditEvent({
      audit_verdict: false,
      timestamp: original.timestamp + 2000,
      stake_slashed: 5,
    });
    const result = shouldSlashForClaim(original, [first, second], {
      audit_verdict: false,
      timestamp: original.timestamp + 3000,
    });
    expect(result).toBe(false);
  });

  it("three or more qualifying overturns still trigger (>= 2, not == 2)", () => {
    const first = auditEvent({ audit_verdict: false, timestamp: original.timestamp + 1000 });
    const second = auditEvent({ audit_verdict: false, timestamp: original.timestamp + 2000 });
    const result = shouldSlashForClaim(original, [first, second], {
      audit_verdict: false,
      timestamp: original.timestamp + 3000,
    });
    expect(result).toBe(true);
  });
});
