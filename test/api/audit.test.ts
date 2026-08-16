import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";
import { SLASH_WINDOW_MS } from "../../src/protocol/slashing.js";

describe("POST /audit (F3.2, F3.3, F5.1)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  async function submitClaim(submitter = makeValidator(), timestamp?: number) {
    const { body, claimHash } = buildSubmitBody(submitter, timestamp !== undefined ? { timestamp } : {});
    const res = await request(app).post("/submit").send(body);
    expect(res.status).toBe(201);
    return { submitter, claimHash, submitTimestamp: body.timestamp };
  }

  it("rejects self-audit", async () => {
    const { submitter, claimHash, submitTimestamp } = await submitClaim();
    const auditBody = buildAuditBody(submitter, claimHash, true, submitTimestamp + 1000);

    const res = await request(app).post("/audit").send(auditBody);

    expect(res.status).toBe(403);
    expect(res.body.error.code).toBe("self_audit_forbidden");
  });

  it("accepts a valid confirming audit", async () => {
    const { claimHash, submitTimestamp } = await submitClaim();
    const auditor = makeValidator();
    const auditBody = buildAuditBody(auditor, claimHash, true, submitTimestamp + 1000);

    const res = await request(app).post("/audit").send(auditBody);

    expect(res.status).toBe(201);
    expect(res.body.event.audit_ref).toBe(claimHash);
    expect(res.body.event.audit_verdict).toBe(true);
  });

  it("accepts a valid overturning audit", async () => {
    const { claimHash, submitTimestamp } = await submitClaim();
    const auditor = makeValidator();
    const auditBody = buildAuditBody(auditor, claimHash, false, submitTimestamp + 1000);

    const res = await request(app).post("/audit").send(auditBody);

    expect(res.status).toBe(201);
    expect(res.body.event.audit_verdict).toBe(false);
  });

  it("rejects an audit with an invalid signature", async () => {
    const { claimHash, submitTimestamp } = await submitClaim();
    const auditor = makeValidator();
    const other = makeValidator();
    const auditBody = buildAuditBody(auditor, claimHash, true, submitTimestamp + 1000);
    auditBody.validator_pubkey = other.publicKey;

    const res = await request(app).post("/audit").send(auditBody);
    expect(res.status).toBe(401);
  });

  it("404s when auditing an unknown claim_hash", async () => {
    const auditor = makeValidator();
    const auditBody = buildAuditBody(auditor, "0x" + "ee".repeat(32), true, Date.now());
    const res = await request(app).post("/audit").send(auditBody);
    expect(res.status).toBe(404);
  });

  it("rejects a duplicate audit from the same validator on the same claim", async () => {
    const { claimHash, submitTimestamp } = await submitClaim();
    const auditor = makeValidator();
    const first = buildAuditBody(auditor, claimHash, true, submitTimestamp + 1000);
    await request(app).post("/audit").send(first);

    const second = buildAuditBody(auditor, claimHash, false, submitTimestamp + 2000);
    const res = await request(app).post("/audit").send(second);

    expect(res.status).toBe(409);
    expect(res.body.error.code).toBe("duplicate_audit");
  });

  describe("finalization (F3.3)", () => {
    it("does not change the submitter's score after only 1 audit", async () => {
      const { submitter, claimHash, submitTimestamp } = await submitClaim();
      const auditor = makeValidator();
      await request(app).post("/audit").send(buildAuditBody(auditor, claimHash, true, submitTimestamp + 1000));

      const scoreRes = await request(app).get(`/validators/${submitter.publicKey}/score`);
      expect(scoreRes.body.n).toBe(0);
      expect(scoreRes.body.score).toBe(0.5);
    });

    it("changes n after the 2nd audit", async () => {
      const { submitter, claimHash, submitTimestamp } = await submitClaim();
      const auditor1 = makeValidator();
      const auditor2 = makeValidator();
      await request(app).post("/audit").send(buildAuditBody(auditor1, claimHash, true, submitTimestamp + 1000));
      await request(app).post("/audit").send(buildAuditBody(auditor2, claimHash, true, submitTimestamp + 2000));

      const scoreRes = await request(app).get(`/validators/${submitter.publicKey}/score`);
      expect(scoreRes.body.n).toBe(2);
    });
  });

  describe("slashing (F5.1)", () => {
    it("does not slash the submitter's stake after a single overturn", async () => {
      const { submitter, claimHash, submitTimestamp } = await submitClaim();
      const auditor = makeValidator();
      await request(app).post("/audit").send(buildAuditBody(auditor, claimHash, false, submitTimestamp + 1000));

      const scoreEventsRes = await request(app).get(`/verify/${claimHash}`);
      const submitterEvents: Array<{ type: string; stake_slashed: number }> = scoreEventsRes.body.events;
      expect(submitterEvents.some((e) => e.stake_slashed > 0)).toBe(false);
      void submitter;
    });

    it("slashes the submitter's stake after two independent overturns within 7 days", async () => {
      const { claimHash, submitTimestamp } = await submitClaim();
      const auditor1 = makeValidator();
      const auditor2 = makeValidator();

      await request(app).post("/audit").send(buildAuditBody(auditor1, claimHash, false, submitTimestamp + 1000));
      const secondAudit = await request(app)
        .post("/audit")
        .send(buildAuditBody(auditor2, claimHash, false, submitTimestamp + 2000));

      expect(secondAudit.body.slashed_amount).toBeGreaterThan(0);
    });

    it("does not slash if the second overturn arrives after the 7-day window", async () => {
      const { claimHash, submitTimestamp } = await submitClaim();
      const auditor1 = makeValidator();
      const auditor2 = makeValidator();

      await request(app).post("/audit").send(buildAuditBody(auditor1, claimHash, false, submitTimestamp + 1000));
      const late = await request(app)
        .post("/audit")
        .send(buildAuditBody(auditor2, claimHash, false, submitTimestamp + SLASH_WINDOW_MS + 10_000));

      expect(late.body.slashed_amount).toBe(0);
    });
  });
});
