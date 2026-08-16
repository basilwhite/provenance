import { beforeEach, describe, expect, it } from "vitest";
import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { createDb, type Db } from "../../src/db/index.js";
import { createApp } from "../../src/api/server.js";
import { buildAuditBody, buildSubmitBody, makeValidator } from "../helpers.js";
import { parseArgs, validateSignatures, recomputeScore, fetchMirror, type MirrorPayload } from "../../cli/verify.js";
import { computeScore } from "../../src/scoring/wilson.js";

describe("cli/verify: parseArgs", () => {
  it("parses --pubkey and --ledger-url", () => {
    const args = parseArgs(["--pubkey", "0xabc", "--ledger-url", "http://localhost:3000"]);
    expect(args.pubkey).toBe("0xabc");
    expect(args.ledgerUrl).toBe("http://localhost:3000");
  });

  it("parses --ledger-file and --expected-score", () => {
    const args = parseArgs(["--pubkey", "0xabc", "--ledger-file", "./mirror.json", "--expected-score", "0.73"]);
    expect(args.ledgerFile).toBe("./mirror.json");
    expect(args.expectedScore).toBe(0.73);
  });

  it("throws if --pubkey is missing", () => {
    expect(() => parseArgs(["--ledger-url", "http://localhost:3000"])).toThrow();
  });

  it("throws if neither --ledger-url nor --ledger-file is given", () => {
    expect(() => parseArgs(["--pubkey", "0xabc"])).toThrow();
  });
});

describe("cli/verify: validateSignatures + recomputeScore (offline, no network)", () => {
  let db: Db;

  beforeEach(() => {
    db = createDb(":memory:");
  });

  it("validates and recomputes a score matching the server for a mirrored payload", async () => {
    const { LedgerStore } = await import("../../src/ledger/store.js");
    const { ScoreStore } = await import("../../src/scoring/scores.js");
    const { finalizeClaimIfReady } = await import("../../src/protocol/finalize.js");

    const ledger = new LedgerStore(db);
    const scores = new ScoreStore(db);
    const submitter = makeValidator();
    const auditors = Array.from({ length: 5 }, () => makeValidator());

    const { claimTimestampMessage } = await import("../../src/crypto/messages.js");
    const { sign } = await import("../../src/crypto/keys.js");
    const { bytesToHex } = await import("../../src/crypto/encoding.js");

    const claimHash = "0x" + "01".repeat(32);
    const submitTimestamp = 1000;
    const submitSig = bytesToHex(sign(claimTimestampMessage(claimHash, submitTimestamp), submitter.privateKey));

    const claim = ledger.appendEvent({
      type: "submission",
      claim_hash: claimHash,
      evidence_uri: "u",
      timestamp: submitTimestamp,
      validator_pubkey: submitter.publicKey,
      signature: submitSig,
    });

    const verdicts = [true, true, true, true, false];
    for (let i = 0; i < auditors.length; i++) {
      const auditor = auditors[i] as ReturnType<typeof makeValidator>;
      const timestamp = 2000 + i;
      const sig = bytesToHex(sign(claimTimestampMessage(claim.claim_hash, timestamp), auditor.privateKey));
      ledger.appendEvent({
        type: "audit",
        claim_hash: claim.claim_hash,
        evidence_uri: "u",
        timestamp,
        validator_pubkey: auditor.publicKey,
        signature: sig,
        audit_ref: claim.claim_hash,
        audit_verdict: verdicts[i] as boolean,
      });
    }
    finalizeClaimIfReady(ledger, scores, claim.claim_hash);

    const events = ledger.getEventsForIdentity(submitter.publicKey);
    const sigResult = validateSignatures(events);
    expect(sigResult.valid).toBe(true);

    const recomputed = recomputeScore(events);
    expect(recomputed.n).toBe(5);
    expect(recomputed.confirmations).toBe(4);
    expect(recomputed.overturns).toBe(1);
    expect(recomputed.score).toBe(computeScore(5, 4, 1));
    expect(recomputed.score).toBe(scores.get(submitter.publicKey).score);
  });

  it("detects a forged signature", async () => {
    const { LedgerStore } = await import("../../src/ledger/store.js");
    const ledger = new LedgerStore(db);
    const submitter = makeValidator();

    const claim = ledger.appendEvent({
      type: "submission",
      claim_hash: "0x" + "02".repeat(32),
      evidence_uri: "u",
      timestamp: 1000,
      validator_pubkey: submitter.publicKey,
      signature: "0x" + "ff".repeat(64), // garbage, does not match validator_pubkey
    });

    const result = validateSignatures([claim]);
    expect(result.valid).toBe(false);
    expect(result.failures).toContain(claim.id);
  });
});

describe("cli/verify: end-to-end against a live server", () => {
  let db: Db;
  let server: Server;
  let baseUrl: string;

  beforeEach(async () => {
    db = createDb(":memory:");
    const app = createApp(db);
    server = app.listen(0);
    await new Promise<void>((resolve) => server.once("listening", resolve));
    const port = (server.address() as AddressInfo).port;
    baseUrl = `http://127.0.0.1:${port}`;
  });

  it("PASSes end-to-end via --ledger-url against a running server", async () => {
    const request = (await import("supertest")).default;
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(server).post("/submit").send(submitBody);

    const auditors = Array.from({ length: 5 }, () => makeValidator());
    const verdicts = [true, true, true, true, false];
    for (let i = 0; i < auditors.length; i++) {
      await request(server)
        .post("/audit")
        .send(buildAuditBody(auditors[i] as ReturnType<typeof makeValidator>, claimHash, verdicts[i] as boolean, submitBody.timestamp + 1000 * (i + 1)));
    }

    const args = parseArgs(["--pubkey", submitter.publicKey, "--ledger-url", baseUrl]);
    const mirror = await fetchMirror(args);

    const sigResult = validateSignatures(mirror.events);
    expect(sigResult.valid).toBe(true);

    const recomputed = recomputeScore(mirror.events);
    expect(recomputed.score).toBeCloseTo(mirror.reported_score, 12);

    server.close();
  });

  it("PASSes via --ledger-file using a previously downloaded mirror (fully offline)", async () => {
    const request = (await import("supertest")).default;
    const submitter = makeValidator();
    const { body: submitBody, claimHash } = buildSubmitBody(submitter);
    await request(server).post("/submit").send(submitBody);
    const auditor1 = makeValidator();
    const auditor2 = makeValidator();
    await request(server).post("/audit").send(buildAuditBody(auditor1, claimHash, true, submitBody.timestamp + 1000));
    await request(server).post("/audit").send(buildAuditBody(auditor2, claimHash, true, submitBody.timestamp + 2000));

    const args = parseArgs(["--pubkey", submitter.publicKey, "--ledger-url", baseUrl]);
    const mirror = await fetchMirror(args);
    server.close();

    const filePath = join(tmpdir(), `provenance-mirror-${Date.now()}.json`);
    writeFileSync(filePath, JSON.stringify(mirror satisfies MirrorPayload));

    const fileArgs = parseArgs(["--pubkey", submitter.publicKey, "--ledger-file", filePath]);
    const mirrorFromFile = await fetchMirror(fileArgs);

    const sigResult = validateSignatures(mirrorFromFile.events);
    expect(sigResult.valid).toBe(true);
  });
});
