import { beforeEach, describe, expect, it } from "vitest";
import request from "supertest";
import type { Express } from "express";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";
import { rotationMessage } from "../../src/crypto/messages.js";
import { sign } from "../../src/crypto/keys.js";
import { bytesToHex } from "../../src/crypto/encoding.js";

function buildRotationBody(oldValidator: ReturnType<typeof makeValidator>, newValidator: ReturnType<typeof makeValidator>) {
  const signature = bytesToHex(
    sign(rotationMessage(oldValidator.publicKey, newValidator.publicKey), oldValidator.privateKey),
  );
  return {
    old_pubkey: oldValidator.publicKey,
    new_pubkey: newValidator.publicKey,
    rotation_signature: signature,
  };
}

describe("POST /keys/rotate (F1.2)", () => {
  let db: Db;
  let app: Express;

  beforeEach(() => {
    db = createDb(":memory:");
    app = createApp(db);
  });

  it("accepts a validly-signed rotation", async () => {
    const oldV = makeValidator();
    const newV = makeValidator();
    const res = await request(app).post("/keys/rotate").send(buildRotationBody(oldV, newV));

    expect(res.status).toBe(201);
    expect(res.body.event.old_pubkey).toBe(oldV.publicKey);
    expect(res.body.event.new_pubkey).toBe(newV.publicKey);
  });

  it("rejects a rotation with an invalid signature", async () => {
    const oldV = makeValidator();
    const newV = makeValidator();
    const body = buildRotationBody(oldV, newV);
    body.rotation_signature = "0x" + "00".repeat(64);

    const res = await request(app).post("/keys/rotate").send(body);
    expect(res.status).toBe(401);
  });

  it("rejects a duplicate rotation of the same old key", async () => {
    const oldV = makeValidator();
    const newV = makeValidator();
    const anotherNewV = makeValidator();

    const first = await request(app).post("/keys/rotate").send(buildRotationBody(oldV, newV));
    expect(first.status).toBe(201);

    // Re-sign using the same old key toward a different new key.
    const second = await request(app).post("/keys/rotate").send(buildRotationBody(oldV, anotherNewV));
    expect(second.status).toBe(409);
    expect(second.body.error.code).toBe("duplicate_rotation");
  });

  it("treats history across old and new keys as continuous for scoring", async () => {
    const oldV = makeValidator();
    const newV = makeValidator();

    // Build up a finalized track record under the OLD key first.
    const { body: submitBody, claimHash } = buildSubmitBody(oldV);
    await request(app).post("/submit").send(submitBody);
    const auditors = Array.from({ length: 5 }, () => makeValidator());
    for (let i = 0; i < auditors.length; i++) {
      await request(app)
        .post("/audit")
        .send(buildAuditBody(auditors[i] as ReturnType<typeof makeValidator>, claimHash, true, submitBody.timestamp + 1000 * (i + 1)));
    }

    const preRotationScore = await request(app).get(`/validators/${oldV.publicKey}/score`);
    expect(preRotationScore.body.n).toBe(5);

    await request(app).post("/keys/rotate").send(buildRotationBody(oldV, newV));

    // Querying either the retired old key or the new key returns the same
    // continuous score, not a fresh 0.5.
    const byOld = await request(app).get(`/validators/${oldV.publicKey}/score`);
    const byNew = await request(app).get(`/validators/${newV.publicKey}/score`);
    expect(byOld.body.n).toBe(5);
    expect(byNew.body.n).toBe(5);
    expect(byOld.body.score).toBe(byNew.body.score);
  });

  it("rejects rotating a key to itself", async () => {
    const v = makeValidator();
    const res = await request(app).post("/keys/rotate").send(buildRotationBody(v, v));
    expect(res.status).toBe(400);
  });
});
