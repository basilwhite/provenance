import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";
import { verifyMerkleProof } from "../../src/ledger/merkle.js";
import { computeScore } from "../../src/scoring/wilson.js";

describe("GET /verify/:claim_hash (F6.1)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("404s for an unknown claim_hash", async () => {
    const res = await request(app).get(`/verify/0x${"ee".repeat(32)}`);
    expect(res.status).toBe(404);
  });

  it("returns the unfiltered event history, current score, and a valid merkle proof", async () => {
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(app).post("/submit").send(submitBody);

    const auditor1 = makeValidator();
    const auditor2 = makeValidator();
    await request(app)
      .post("/audit")
      .send(buildAuditBody(auditor1, claimHash, true, submitBody.timestamp + 1000));
    await request(app)
      .post("/audit")
      .send(buildAuditBody(auditor2, claimHash, false, submitBody.timestamp + 2000));

    const res = await request(app).get(`/verify/${claimHash}`);

    expect(res.status).toBe(200);
    expect(res.body.validator_pubkey).toBe(submitter.publicKey);
    // 1 submission + 2 audits, all unfiltered.
    expect(res.body.events).toHaveLength(3);
    expect(res.body.merkle_proof.claim_hash).toBe(claimHash);

    // n=2 (< 5) so score is the flat neutral prior; sanity-check against
    // the same recomputation the offline verifier would perform.
    expect(res.body.current_score).toBe(computeScore(2, 1, 1));
  });

  it("the returned merkle_proof independently verifies against the reported root", async () => {
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(app).post("/submit").send(submitBody);

    const res = await request(app).get(`/verify/${claimHash}`);
    const { path, root } = res.body.merkle_proof;

    // For a direct (non-batched) submission, the proof's starting leaf is
    // the ledger-level hash of the full event, not the raw claim_hash —
    // see ledger/hash.ts computeLedgerLeaf. Recompute it the same way the
    // offline verifier does, from the returned event.
    const event = res.body.events.find((e: { claim_hash: string; type: string }) => e.claim_hash === claimHash && e.type === "submission");
    const { computeLedgerLeaf } = await import("../../src/ledger/hash.js");
    const leaf = computeLedgerLeaf(event);

    expect(verifyMerkleProof(leaf, path, root)).toBe(true);
  });

  it("events list includes both submissions and audits for the validator, unfiltered", async () => {
    const submitter = makeValidator();
    const { body: submitBody1, claimHash: claim1 } = buildSubmitBody(submitter, {
      evidenceUri: "https://example.com/1",
    });
    await request(app).post("/submit").send(submitBody1);
    const { body: submitBody2 } = buildSubmitBody(submitter, { evidenceUri: "https://example.com/2" });
    await request(app).post("/submit").send(submitBody2);

    const res = await request(app).get(`/verify/${claim1}`);
    expect(res.body.events).toHaveLength(2);
  });
});
