import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";
import { computeScore } from "../../src/scoring/wilson.js";

describe("GET /validators/:pubkey/score (F4.2)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("returns the neutral prior for an unknown validator", async () => {
    const validator = makeValidator();
    const res = await request(app).get(`/validators/${validator.publicKey}/score`);
    expect(res.status).toBe(200);
    expect(res.body.score).toBe(0.5);
    expect(res.body.n).toBe(0);
  });

  it("rejects a malformed pubkey", async () => {
    const res = await request(app).get("/validators/not-a-pubkey/score");
    expect(res.status).toBe(400);
  });

  it("matches the offline-recomputable score after submit + audits (integration)", async () => {
    const submitter = makeValidator();
    const auditors = Array.from({ length: 5 }, () => makeValidator());

    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(app).post("/submit").send(submitBody);

    const verdicts = [true, true, true, true, false];
    for (let i = 0; i < auditors.length; i++) {
      await request(app)
        .post("/audit")
        .send(buildAuditBody(auditors[i] as ReturnType<typeof makeValidator>, claimHash, verdicts[i] as boolean, submitBody.timestamp + 1000 * (i + 1)));
    }

    const res = await request(app).get(`/validators/${submitter.publicKey}/score`);
    expect(res.body.n).toBe(5);
    expect(res.body.confirmations).toBe(4);
    expect(res.body.overturns).toBe(1);
    expect(res.body.score).toBe(computeScore(5, 4, 1));
  });
});
