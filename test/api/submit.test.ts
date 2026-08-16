import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildSubmitBody, longText, makeValidator } from "../helpers.js";

describe("POST /submit (F3.1)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("accepts a valid submission and appends a ledger event", async () => {
    const validator = makeValidator();
    const { body, claimHash } = buildSubmitBody(validator);

    const res = await request(app).post("/submit").send(body);

    expect(res.status).toBe(201);
    expect(res.body.event.claim_hash).toBe(claimHash);
    expect(res.body.event.audit_ref).toBeNull();
    expect(res.body.event.audit_verdict).toBeNull();
    expect(res.body.event.validator_pubkey).toBe(validator.publicKey);
  });

  it("rejects a submission with an invalid signature", async () => {
    const validator = makeValidator();
    const other = makeValidator();
    const { body } = buildSubmitBody(validator);
    body.validator_pubkey = other.publicKey; // signature no longer matches this key

    const res = await request(app).post("/submit").send(body);

    expect(res.status).toBe(401);
    expect(res.body.error.code).toBe("invalid_signature");
  });

  it("rejects a submission whose claim_text is shorter than 500 characters", async () => {
    const validator = makeValidator();
    const { body } = buildSubmitBody(validator, { claimText: longText(100) });

    const res = await request(app).post("/submit").send(body);

    expect(res.status).toBe(422);
    expect(res.body.error.code).toBe("evidence_too_short");
  });

  it("enforces the 10-submissions-per-24h rate limit", async () => {
    const validator = makeValidator();

    for (let i = 0; i < 10; i++) {
      const { body } = buildSubmitBody(validator, { evidenceUri: `https://example.com/e/${i}` });
      const res = await request(app).post("/submit").send(body);
      expect(res.status).toBe(201);
    }

    const { body: eleventh } = buildSubmitBody(validator, { evidenceUri: "https://example.com/e/11" });
    const res = await request(app).post("/submit").send(eleventh);

    expect(res.status).toBe(429);
    expect(res.body.error.code).toBe("rate_limit_exceeded");
  });

  it("rejects a request missing required fields", async () => {
    const res = await request(app).post("/submit").send({ claim_text: "too short a body" });
    expect(res.status).toBe(400);
  });

  it("computes claim_hash as keccak256(claim_text + evidence_uri + timestamp)", async () => {
    const validator = makeValidator();
    const { body, claimHash } = buildSubmitBody(validator, {
      claimText: longText(),
      evidenceUri: "https://example.com/fixed",
      timestamp: 1_700_000_000_000,
    });

    const res = await request(app).post("/submit").send(body);
    expect(res.status).toBe(201);
    expect(res.body.event.claim_hash).toBe(claimHash);
  });

  it("auto-provisions stake for a first-time validator so the submission succeeds", async () => {
    const validator = makeValidator();
    const { body } = buildSubmitBody(validator);
    const res = await request(app).post("/submit").send(body);
    expect(res.status).toBe(201);
    expect(res.body.event.stake_locked).toBeGreaterThan(0);
  });
});
