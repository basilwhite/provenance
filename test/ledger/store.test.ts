import { beforeEach, describe, expect, it } from "vitest";
import { createDb, type Db } from "../../src/db/index.js";
import { LedgerStore } from "../../src/ledger/store.js";
import { GENESIS_ROOT } from "../../src/ledger/hash.js";
import { replayChain } from "../../src/ledger/replay.js";

function submissionInput(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    type: "submission" as const,
    claim_hash: "0x" + "11".repeat(32),
    evidence_uri: "https://example.com/e",
    timestamp: 1000,
    validator_pubkey: "0x" + "22".repeat(32),
    signature: "0x" + "33".repeat(64),
    ...overrides,
  };
}

describe("ledger/store", () => {
  let db: Db;
  let store: LedgerStore;

  beforeEach(() => {
    db = createDb(":memory:");
    store = new LedgerStore(db);
  });

  it("starts with GENESIS_ROOT as the latest root", () => {
    expect(store.getLatestRoot()).toBe(GENESIS_ROOT);
  });

  it("appendEvent chains prev_root to the prior latest root", () => {
    const e1 = store.appendEvent(submissionInput({ claim_hash: "0x" + "01".repeat(32) }));
    expect(e1.prev_root).toBe(GENESIS_ROOT);

    const e2 = store.appendEvent(submissionInput({ claim_hash: "0x" + "02".repeat(32) }));
    expect(e2.prev_root).toBe(e1.root);
    expect(e2.root).not.toBe(e1.root);
  });

  it("getLatestRoot reflects the most recently appended event", () => {
    const e1 = store.appendEvent(submissionInput());
    expect(store.getLatestRoot()).toBe(e1.root);
  });

  it("round-trips a full event through insert and getById (serialization)", () => {
    const input = submissionInput({
      audit_ref: null,
      audit_verdict: null,
      stake_locked: 42,
      stake_slashed: 0,
      batch_root: null,
    });
    const stored = store.appendEvent(input);
    const fetched = store.getById(stored.id);

    expect(fetched).not.toBeNull();
    expect(fetched?.claim_hash).toBe(input.claim_hash);
    expect(fetched?.evidence_uri).toBe(input.evidence_uri);
    expect(fetched?.timestamp).toBe(input.timestamp);
    expect(fetched?.validator_pubkey).toBe(input.validator_pubkey);
    expect(fetched?.signature).toBe(input.signature);
    expect(fetched?.stake_locked).toBe(42);
    expect(fetched?.audit_verdict).toBeNull();
  });

  it("round-trips audit_verdict true/false/null correctly", () => {
    const t = store.appendEvent(submissionInput({ claim_hash: "0x" + "a1".repeat(32) }));
    const confirmed = store.appendEvent(
      submissionInput({ claim_hash: "0x" + "a2".repeat(32), type: "audit", audit_verdict: true, audit_ref: t.claim_hash }),
    );
    const overturned = store.appendEvent(
      submissionInput({ claim_hash: "0x" + "a3".repeat(32), type: "audit", audit_verdict: false, audit_ref: t.claim_hash }),
    );
    expect(store.getById(confirmed.id)?.audit_verdict).toBe(true);
    expect(store.getById(overturned.id)?.audit_verdict).toBe(false);
  });

  it("getEventsByPubKey returns only that validator's events, in order", () => {
    const pkA = "0x" + "aa".repeat(32);
    const pkB = "0x" + "bb".repeat(32);
    store.appendEvent(submissionInput({ claim_hash: "0x" + "01".repeat(32), validator_pubkey: pkA }));
    store.appendEvent(submissionInput({ claim_hash: "0x" + "02".repeat(32), validator_pubkey: pkB }));
    store.appendEvent(submissionInput({ claim_hash: "0x" + "03".repeat(32), validator_pubkey: pkA }));

    const eventsA = store.getEventsByPubKey(pkA);
    expect(eventsA).toHaveLength(2);
    expect(eventsA.every((e) => e.validator_pubkey === pkA)).toBe(true);
  });

  it("getEventByClaimHash returns the original plus any audits referencing it", () => {
    const original = store.appendEvent(submissionInput({ claim_hash: "0x" + "05".repeat(32) }));
    const audit = store.appendEvent(
      submissionInput({
        claim_hash: original.claim_hash,
        type: "audit",
        audit_ref: original.claim_hash,
        audit_verdict: true,
      }),
    );

    const results = store.getEventByClaimHash(original.claim_hash);
    expect(results.map((e) => e.id).sort()).toEqual([original.id, audit.id].sort());
  });

  describe("tamper detection via chain replay (I2.2)", () => {
    it("recomputes the same root as stored when nothing has been tampered with", () => {
      store.appendEvent(submissionInput({ claim_hash: "0x" + "01".repeat(32) }));
      store.appendEvent(submissionInput({ claim_hash: "0x" + "02".repeat(32) }));
      const last = store.appendEvent(submissionInput({ claim_hash: "0x" + "03".repeat(32) }));

      const result = replayChain(store.getAllEvents());
      expect(result.valid).toBe(true);
      expect(result.recomputedLatestRoot).toBe(last.root);
      expect(result.recomputedLatestRoot).toBe(store.getLatestRoot());
    });

    it("detects tampering with a historical event's field", () => {
      store.appendEvent(submissionInput({ claim_hash: "0x" + "01".repeat(32) }));
      const second = store.appendEvent(submissionInput({ claim_hash: "0x" + "02".repeat(32) }));
      store.appendEvent(submissionInput({ claim_hash: "0x" + "03".repeat(32) }));

      // Directly mutate stored data, bypassing the store's API - simulating
      // an attacker editing the database out from under the append-only log.
      db.prepare("UPDATE ledger_events SET evidence_uri = ? WHERE id = ?").run("tampered!", second.id);

      const result = replayChain(store.getAllEvents());
      expect(result.valid).toBe(false);
      expect(result.mismatchAtEventId).toBe(second.id);
    });

    it("detects a deleted historical event breaking the chain", () => {
      store.appendEvent(submissionInput({ claim_hash: "0x" + "01".repeat(32) }));
      const second = store.appendEvent(submissionInput({ claim_hash: "0x" + "02".repeat(32) }));
      store.appendEvent(submissionInput({ claim_hash: "0x" + "03".repeat(32) }));

      db.prepare("DELETE FROM ledger_events WHERE id = ?").run(second.id);

      const result = replayChain(store.getAllEvents());
      expect(result.valid).toBe(false);
    });
  });
});
