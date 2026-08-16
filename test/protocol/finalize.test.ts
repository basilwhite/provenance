import { beforeEach, describe, expect, it } from "vitest";
import { createDb, type Db } from "../../src/db/index.js";
import { LedgerStore } from "../../src/ledger/store.js";
import { ScoreStore } from "../../src/scoring/scores.js";
import { finalizeClaimIfReady } from "../../src/protocol/finalize.js";
import { computeScore } from "../../src/scoring/wilson.js";

describe("protocol/finalize (F3.3)", () => {
  let db: Db;
  let ledger: LedgerStore;
  let scores: ScoreStore;
  const submitter = "0x" + "aa".repeat(32);
  const auditor1 = "0x" + "b1".repeat(32);
  const auditor2 = "0x" + "b2".repeat(32);
  const auditor3 = "0x" + "b3".repeat(32);

  beforeEach(() => {
    db = createDb(":memory:");
    ledger = new LedgerStore(db);
    scores = new ScoreStore(db);
  });

  function submit(claimHash: string) {
    return ledger.appendEvent({
      type: "submission",
      claim_hash: claimHash,
      evidence_uri: "u",
      timestamp: 1000,
      validator_pubkey: submitter,
      signature: "0x" + "11".repeat(64),
    });
  }

  function audit(claimHash: string, auditor: string, verdict: boolean) {
    return ledger.appendEvent({
      type: "audit",
      claim_hash: claimHash,
      evidence_uri: "u",
      timestamp: 2000,
      validator_pubkey: auditor,
      signature: "0x" + "22".repeat(64),
      audit_ref: claimHash,
      audit_verdict: verdict,
    });
  }

  it("does not update the score with zero audits", () => {
    const claim = submit("0x" + "01".repeat(32));
    const result = finalizeClaimIfReady(ledger, scores, claim.claim_hash);
    expect(result.updated).toBe(false);
    expect(scores.get(submitter).score).toBe(0.5);
  });

  it("does not update the score with only one audit", () => {
    const claim = submit("0x" + "02".repeat(32));
    audit(claim.claim_hash, auditor1, true);
    const result = finalizeClaimIfReady(ledger, scores, claim.claim_hash);
    expect(result.updated).toBe(false);
    expect(scores.get(submitter).n).toBe(0);
  });

  it("updates the score once a claim reaches 2 audits", () => {
    const claim = submit("0x" + "03".repeat(32));
    audit(claim.claim_hash, auditor1, true);
    audit(claim.claim_hash, auditor2, true);
    const result = finalizeClaimIfReady(ledger, scores, claim.claim_hash);
    expect(result.updated).toBe(true);
    expect(result.n).toBe(2);
    expect(result.confirmations).toBe(2);
    expect(result.overturns).toBe(0);
    expect(scores.get(submitter).score).toBeCloseTo(computeScore(2, 2, 0), 12);
  });

  it("additional audits after finalization still affect score (via total n)", () => {
    const claim = submit("0x" + "04".repeat(32));
    const auditor4 = "0x" + "b4".repeat(32);
    const auditor5 = "0x" + "b5".repeat(32);

    audit(claim.claim_hash, auditor1, true);
    audit(claim.claim_hash, auditor2, true);
    const firstFinalize = finalizeClaimIfReady(ledger, scores, claim.claim_hash);
    expect(firstFinalize.n).toBe(2);
    // n < 5 still returns the flat neutral prior, per F4.1.
    expect(firstFinalize.score).toBe(0.5);

    audit(claim.claim_hash, auditor3, true);
    audit(claim.claim_hash, auditor4, true);
    audit(claim.claim_hash, auditor5, false);
    const secondFinalize = finalizeClaimIfReady(ledger, scores, claim.claim_hash);

    expect(secondFinalize.updated).toBe(true);
    expect(secondFinalize.n).toBe(5);
    expect(secondFinalize.confirmations).toBe(4);
    expect(secondFinalize.overturns).toBe(1);
    // n now clears the F4.1 threshold, so the Wilson formula actually runs
    // and the score moves off the flat 0.5 prior used while n < 5.
    expect(secondFinalize.score).not.toBe(firstFinalize.score);
    expect(secondFinalize.score).toBe(computeScore(5, 4, 1));
  });

  it("aggregates confirmations/overturns across multiple finalized claims from the same validator", () => {
    const claimA = submit("0x" + "05".repeat(32));
    audit(claimA.claim_hash, auditor1, true);
    audit(claimA.claim_hash, auditor2, true);
    finalizeClaimIfReady(ledger, scores, claimA.claim_hash);

    const claimB = submit("0x" + "06".repeat(32));
    audit(claimB.claim_hash, auditor1, false);
    audit(claimB.claim_hash, auditor2, false);
    const result = finalizeClaimIfReady(ledger, scores, claimB.claim_hash);

    expect(result.n).toBe(4);
    expect(result.confirmations).toBe(2);
    expect(result.overturns).toBe(2);
  });

  it("an unfinalized claim (only 1 audit) does not contribute to the aggregate", () => {
    const claimA = submit("0x" + "07".repeat(32));
    audit(claimA.claim_hash, auditor1, true);
    audit(claimA.claim_hash, auditor2, true);
    finalizeClaimIfReady(ledger, scores, claimA.claim_hash);

    const claimB = submit("0x" + "08".repeat(32));
    audit(claimB.claim_hash, auditor1, false); // only one audit, not finalized

    const result = finalizeClaimIfReady(ledger, scores, claimB.claim_hash);
    expect(result.updated).toBe(false);
    expect(scores.get(submitter).n).toBe(2);
  });

  it("returns updated:false for an unknown claim_hash", () => {
    const result = finalizeClaimIfReady(ledger, scores, "0x" + "ee".repeat(32));
    expect(result.updated).toBe(false);
  });
});
