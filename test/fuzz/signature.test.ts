import { describe, expect, it } from "vitest";
import request from "supertest";
import { createDb } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { sign, verify } from "../../src/crypto/keys.js";
import { bytesToHex } from "../../src/crypto/encoding.js";
import { buildSubmitBody, makeValidator } from "../helpers.js";

function randomBytes(n: number): Uint8Array {
  const out = new Uint8Array(n);
  for (let i = 0; i < n; i++) out[i] = Math.floor(Math.random() * 256);
  return out;
}

const FUZZ_ITERATIONS = 500;

describe("fuzz: signature malleability (T7.2)", () => {
  it(`verify() never throws across ${FUZZ_ITERATIONS} random (message, signature, pubkey) combinations`, () => {
    const message = new TextEncoder().encode("fixed message");
    const { publicKeyBytes } = makeValidator();

    for (let i = 0; i < FUZZ_ITERATIONS; i++) {
      // Mix signature lengths: correct (64), too short, too long, empty.
      const lengths = [64, 0, 1, 32, 63, 65, 128, 200];
      const len = lengths[i % lengths.length] as number;
      const garbageSig = randomBytes(len);

      expect(() => verify(message, garbageSig, publicKeyBytes)).not.toThrow();
      // A random 64-byte signature verifying against a fixed message/key is
      // cryptographically negligible (~2^-252); treat any pass as a bug.
      if (len === 64) {
        expect(verify(message, garbageSig, publicKeyBytes)).toBe(false);
      }
    }
  });

  it(`verify() never throws across ${FUZZ_ITERATIONS} random pubkeys`, () => {
    const message = new TextEncoder().encode("fixed message");
    const signature = randomBytes(64);

    for (let i = 0; i < FUZZ_ITERATIONS; i++) {
      const lengths = [32, 0, 16, 31, 33, 64];
      const len = lengths[i % lengths.length] as number;
      const garbagePubkey = randomBytes(len);
      expect(() => verify(message, signature, garbagePubkey)).not.toThrow();
      expect(verify(message, signature, garbagePubkey)).toBe(false);
    }
  });

  it("the API never 500s on a garbage signature hex string on /submit", async () => {
    const db = createDb(":memory:");
    const app = createApp(db);
    const validator = makeValidator();
    const { body } = buildSubmitBody(validator);

    for (let i = 0; i < 50; i++) {
      const garbage = "0x" + bytesToHex(randomBytes(64)).slice(2);
      const res = await request(app)
        .post("/submit")
        .send({ ...body, signature: garbage, evidence_uri: `https://example.com/fuzz/${i}` });
      expect(res.status).not.toBe(500);
      expect([401]).toContain(res.status);
    }
  });

  it("the API never 500s on a malformed (wrong-length) signature field on /submit", async () => {
    const db = createDb(":memory:");
    const app = createApp(db);
    const validator = makeValidator();
    const { body } = buildSubmitBody(validator);

    const malformedSignatures = ["0xabc", "not-hex-at-all", "0x", "", "0x" + "ff".repeat(200)];
    for (const sig of malformedSignatures) {
      const res = await request(app).post("/submit").send({ ...body, signature: sig });
      expect(res.status).not.toBe(500);
      expect(res.status).toBe(400);
    }
  });

  it("a signature valid for one message does not verify against a mutated message (malleability check)", () => {
    const validator = makeValidator();
    const message = new TextEncoder().encode("original");
    const sig = sign(message, validator.privateKey);

    for (let i = 0; i < 100; i++) {
      const mutated = new TextEncoder().encode(`original${i}`);
      expect(verify(mutated, sig, validator.publicKeyBytes)).toBe(false);
    }
  });
});
