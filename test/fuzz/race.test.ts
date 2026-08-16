import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";

/**
 * T7.2: race conditions in concurrent scoring/stake updates. The server
 * has no `await` between reading state (existing audits, stake balance)
 * and writing it (better-sqlite3 calls are synchronous, Node is single
 * threaded), so a request handler always runs to completion atomically
 * relative to other requests. These tests fire many requests concurrently
 * via Promise.all and assert the final state is exactly what sequential
 * execution would produce — no lost updates, no double-slashing.
 */
describe("fuzz: concurrent audit/score updates (T7.2)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("N concurrent overturning audits from distinct auditors slash exactly once and count all N audits", async () => {
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(app).post("/submit").send(submitBody);

    const N = 8;
    const auditors = Array.from({ length: N }, () => makeValidator());

    const responses = await Promise.all(
      auditors.map((auditor, i) =>
        request(app)
          .post("/audit")
          .send(buildAuditBody(auditor, claimHash, false, submitBody.timestamp + 1000 + i)),
      ),
    );

    for (const res of responses) {
      expect(res.status).toBe(201);
    }

    const slashedResponses = responses.filter((r) => r.body.slashed_amount > 0);
    // Exactly one response should have triggered the (one-time-per-claim) slash.
    expect(slashedResponses).toHaveLength(1);

    const scoreRes = await request(app).get(`/validators/${submitter.publicKey}/score`);
    expect(scoreRes.body.n).toBe(N);
    expect(scoreRes.body.overturns).toBe(N);
    expect(scoreRes.body.confirmations).toBe(0);
  });

  it("N concurrent audits across distinct claims each finalize independently with correct counts", async () => {
    const submitter = makeValidator();
    const claims = await Promise.all(
      Array.from({ length: 5 }, async (_, i) => {
        const { body, claimHash } = buildSubmitBody(submitter, { evidenceUri: `https://example.com/race/${i}` });
        const res = await request(app).post("/submit").send(body);
        expect(res.status).toBe(201);
        return { claimHash, timestamp: body.timestamp };
      }),
    );

    const auditorPairs = claims.map(() => [makeValidator(), makeValidator()] as const);

    const allAuditRequests = claims.flatMap((claim, i) => {
      const [a1, a2] = auditorPairs[i] as readonly [ReturnType<typeof makeValidator>, ReturnType<typeof makeValidator>];
      return [
        request(app).post("/audit").send(buildAuditBody(a1, claim.claimHash, true, claim.timestamp + 1000)),
        request(app).post("/audit").send(buildAuditBody(a2, claim.claimHash, true, claim.timestamp + 2000)),
      ];
    });

    const responses = await Promise.all(allAuditRequests);
    for (const res of responses) {
      expect(res.status).toBe(201);
    }

    const scoreRes = await request(app).get(`/validators/${submitter.publicKey}/score`);
    // 5 claims x 2 confirming audits each = 10 total confirmations, n=10.
    expect(scoreRes.body.n).toBe(10);
    expect(scoreRes.body.confirmations).toBe(10);
    expect(scoreRes.body.overturns).toBe(0);
  });

  it("exactly MAX_SUBMISSIONS_PER_24H succeed when more than the limit are fired concurrently", async () => {
    const validator = makeValidator();
    const attempts = 15;

    const responses = await Promise.all(
      Array.from({ length: attempts }, (_, i) => {
        const { body } = buildSubmitBody(validator, { evidenceUri: `https://example.com/race-limit/${i}` });
        return request(app).post("/submit").send(body);
      }),
    );

    const succeeded = responses.filter((r) => r.status === 201);
    const rateLimited = responses.filter((r) => r.status === 429);

    expect(succeeded).toHaveLength(10);
    expect(rateLimited).toHaveLength(attempts - 10);
  });

  it("a validator cannot double-audit the same claim even via concurrent duplicate requests", async () => {
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(app).post("/submit").send(submitBody);

    const auditor = makeValidator();
    const auditBody = buildAuditBody(auditor, claimHash, true, submitBody.timestamp + 1000);

    const responses = await Promise.all([
      request(app).post("/audit").send(auditBody),
      request(app).post("/audit").send(auditBody),
      request(app).post("/audit").send(auditBody),
    ]);

    const succeeded = responses.filter((r) => r.status === 201);
    const rejected = responses.filter((r) => r.status === 409);
    expect(succeeded).toHaveLength(1);
    expect(rejected).toHaveLength(2);
  });
});
