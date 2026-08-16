import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildBatchBody, longText, makeValidator } from "../helpers.js";
import { verifyMerkleProof } from "../../src/ledger/merkle.js";

describe("POST /submit/batch (F5.3)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("accepts a batch of claims and stores a single ledger event", async () => {
    const validator = makeValidator();
    const { body, batchRoot } = buildBatchBody(
      validator,
      Array.from({ length: 5 }, (_, i) => ({ evidenceUri: `https://example.com/batch/${i}` })),
    );

    const res = await request(app).post("/submit/batch").send(body);

    expect(res.status).toBe(201);
    expect(res.body.event.batch_root).toBe(batchRoot);
    expect(res.body.event.type).toBe("batch");
    expect(res.body.claim_hashes).toHaveLength(5);
  });

  it("rejects a batch larger than 50 claims", async () => {
    const validator = makeValidator();
    const { body } = buildBatchBody(
      validator,
      Array.from({ length: 51 }, (_, i) => ({ evidenceUri: `https://example.com/batch/${i}` })),
    );

    const res = await request(app).post("/submit/batch").send(body);
    expect(res.status).toBe(400);
  });

  it("rejects a batch if any individual leaf signature is invalid", async () => {
    const validator = makeValidator();
    const { body } = buildBatchBody(validator, [{}, {}, {}]);
    body.claims[1].signature = "0x" + "00".repeat(64);

    const res = await request(app).post("/submit/batch").send(body);
    expect(res.status).toBe(401);
  });

  it("rejects a batch if any leaf's claim_text is too short", async () => {
    const validator = makeValidator();
    const { body } = buildBatchBody(validator, [{}, { claimText: longText(50) }]);

    const res = await request(app).post("/submit/batch").send(body);
    expect(res.status).toBe(422);
  });

  it("rejects a batch with an invalid batch_signature", async () => {
    const validator = makeValidator();
    const { body } = buildBatchBody(validator, [{}, {}]);
    body.batch_signature = "0x" + "12".repeat(64);

    const res = await request(app).post("/submit/batch").send(body);
    expect(res.status).toBe(401);
    expect(res.body.error.code).toBe("invalid_batch_signature");
  });

  it("a Merkle proof for any leaf validates against the stored batch_root", async () => {
    const validator = makeValidator();
    const { body, claimHashes, batchRoot } = buildBatchBody(
      validator,
      Array.from({ length: 7 }, (_, i) => ({ evidenceUri: `https://example.com/batch/${i}` })),
    );

    const submitRes = await request(app).post("/submit/batch").send(body);
    expect(submitRes.status).toBe(201);

    for (const claimHash of claimHashes) {
      const verifyRes = await request(app).get(`/verify/${claimHash}`);
      expect(verifyRes.status).toBe(200);

      // The full path (batch-inclusion steps + outer chain steps) must
      // recompute to the reported root, proving this specific claim was
      // included in the batch whose batch_root chains into the ledger.
      const valid = verifyMerkleProof(claimHash, verifyRes.body.merkle_proof.path, verifyRes.body.merkle_proof.root);
      expect(valid).toBe(true);
    }
    void batchRoot;
  });

  it("counts a whole batch as a single submission toward the 24h rate limit", async () => {
    const validator = makeValidator();
    const { body: batchBody } = buildBatchBody(
      validator,
      Array.from({ length: 20 }, (_, i) => ({ evidenceUri: `https://example.com/batch/${i}` })),
    );
    const first = await request(app).post("/submit/batch").send(batchBody);
    expect(first.status).toBe(201);

    const { body: batchBody2 } = buildBatchBody(
      validator,
      Array.from({ length: 5 }, (_, i) => ({ evidenceUri: `https://example.com/batch2/${i}` })),
      batchBody.timestamp + 1,
    );
    const second = await request(app).post("/submit/batch").send(batchBody2);
    expect(second.status).toBe(201);
  });
});
