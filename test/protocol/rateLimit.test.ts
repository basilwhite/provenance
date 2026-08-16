import { beforeEach, describe, expect, it } from "vitest";
import { createDb, type Db } from "../../src/db/index.js";
import { LedgerStore } from "../../src/ledger/store.js";
import {
  countRecentSubmissions,
  MAX_SUBMISSIONS_PER_24H,
  meetsEvidenceComplexity,
  MIN_CLAIM_TEXT_CHARS,
  RATE_LIMIT_WINDOW_MS,
} from "../../src/protocol/rateLimit.js";

describe("protocol/rateLimit", () => {
  describe("meetsEvidenceComplexity", () => {
    it("rejects text shorter than the minimum", () => {
      expect(meetsEvidenceComplexity("a".repeat(MIN_CLAIM_TEXT_CHARS - 1))).toBe(false);
    });

    it("accepts text at exactly the minimum", () => {
      expect(meetsEvidenceComplexity("a".repeat(MIN_CLAIM_TEXT_CHARS))).toBe(true);
    });

    it("accepts text longer than the minimum", () => {
      expect(meetsEvidenceComplexity("a".repeat(MIN_CLAIM_TEXT_CHARS + 100))).toBe(true);
    });
  });

  describe("countRecentSubmissions", () => {
    let db: Db;
    let store: LedgerStore;
    const pubkey = "0x" + "cc".repeat(32);
    const now = 1_000_000_000_000;

    beforeEach(() => {
      db = createDb(":memory:");
      store = new LedgerStore(db);
    });

    it("is zero for a validator with no submissions", () => {
      expect(countRecentSubmissions(db, pubkey, now)).toBe(0);
    });

    it("counts submissions within the trailing 24h window", () => {
      for (let i = 0; i < 3; i++) {
        store.appendEvent({
          type: "submission",
          claim_hash: `0x${i.toString().padStart(2, "0").repeat(32)}`,
          evidence_uri: "u",
          timestamp: now - 1000,
          validator_pubkey: pubkey,
          signature: "0x" + "11".repeat(64),
        });
      }
      expect(countRecentSubmissions(db, pubkey, now)).toBe(3);
    });

    it("excludes submissions older than the window", () => {
      store.appendEvent({
        type: "submission",
        claim_hash: "0x" + "01".repeat(32),
        evidence_uri: "u",
        timestamp: now - RATE_LIMIT_WINDOW_MS - 1,
        validator_pubkey: pubkey,
        signature: "0x" + "11".repeat(64),
      });
      expect(countRecentSubmissions(db, pubkey, now)).toBe(0);
    });

    it("counts a batch container event as exactly one submission regardless of leaf count", () => {
      store.appendEvent({
        type: "batch",
        claim_hash: "0x" + "02".repeat(32),
        evidence_uri: "batch:50-claims",
        timestamp: now - 1000,
        validator_pubkey: pubkey,
        signature: "0x" + "11".repeat(64),
        batch_root: "0x" + "02".repeat(32),
      });
      expect(countRecentSubmissions(db, pubkey, now)).toBe(1);
    });

    it("does not count audit events toward the submission rate limit", () => {
      store.appendEvent({
        type: "audit",
        claim_hash: "0x" + "03".repeat(32),
        evidence_uri: "u",
        timestamp: now - 1000,
        validator_pubkey: pubkey,
        signature: "0x" + "11".repeat(64),
        audit_ref: "0x" + "03".repeat(32),
        audit_verdict: true,
      });
      expect(countRecentSubmissions(db, pubkey, now)).toBe(0);
    });

    it("MAX_SUBMISSIONS_PER_24H is 10 per spec", () => {
      expect(MAX_SUBMISSIONS_PER_24H).toBe(10);
    });
  });
});
