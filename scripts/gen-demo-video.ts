import { generateKeyPair, sign } from "../src/crypto/keys.js";
import { bytesToHex } from "../src/crypto/encoding.js";
import { computeClaimHash } from "../src/domain/claimHash.js";
import { claimTimestampMessage } from "../src/crypto/messages.js";

// Demo-video data generator (D9.1).
//
// Produces two claims from the SAME submitter so the aggregate n on
// validator_scores crosses the n>=5 threshold (F4.1) and a real,
// non-neutral score is visible on camera — plus a genuine overturn so
// the slashing path (F5.1) is demonstrated, not just confirms.
//
// Claim 1: submit + 2 confirms   (mirrors the README walkthrough)
// Claim 2: submit + 2 confirms + 1 overturn
//   -> submitter totals: n=5, confirmations=4, overturns=1
//
// Run: npx tsx scripts/gen-demo-video.ts

const submitter = generateKeyPair();
const auditorA = generateKeyPair(); // claim 1, confirm
const auditorB = generateKeyPair(); // claim 1, confirm
const auditorC = generateKeyPair(); // claim 2, confirm
const auditorD = generateKeyPair(); // claim 2, confirm
const auditorE = generateKeyPair(); // claim 2, OVERTURN

const claim1Text =
  "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite " +
  "split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved " +
  "217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 " +
  "percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw " +
  "per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried " +
  "beyond the harness's standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 " +
  "minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached " +
  "artifacts.";
const claim1Evidence = "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json";
const claim1Timestamp = 1_772_000_000_000;

const claim2Text =
  "On 2026-03-04, the same checkpoint gpt-audit-7b was evaluated against a second held-out split, " +
  "HumanEval-plus (250 tasks), using the identical agentic scaffold and a 50-step budget. The run " +
  "resolved 231/250 tasks (92.4% pass@1). Full transcripts, the evaluation harness commit hash " +
  "(a1b2c3d), and the raw per-task pass/fail matrix are attached at the evidence URI. No tasks were " +
  "excluded or retried beyond the harness's standard single-attempt protocol. Hardware: 8x A100 " +
  "80GB, wall-clock 33 minutes. This claim asserts the reported pass rate is accurate and " +
  "reproducible from the attached artifacts, and that no test-set contamination occurred between " +
  "the harness commit and the evaluation run.";
const claim2Evidence = "https://evidence.example.org/runs/gpt-audit-7b-humaneval-plus-2026-03-04.json";
const claim2Timestamp = 1_772_100_000_000;

function claim(text: string, evidenceUri: string, timestamp: number) {
  const hash = computeClaimHash(text, evidenceUri, timestamp);
  const sig = bytesToHex(sign(claimTimestampMessage(hash, timestamp), submitter.privateKey));
  return { text, evidenceUri, timestamp, hash, sig };
}

function audit(claimHash: string, baseTimestamp: number, offsetMs: number, auditor: ReturnType<typeof generateKeyPair>) {
  const timestamp = baseTimestamp + offsetMs;
  const sig = bytesToHex(sign(claimTimestampMessage(claimHash, timestamp), auditor.privateKey));
  return { timestamp, signature: sig, pubkey: bytesToHex(auditor.publicKey) };
}

const c1 = claim(claim1Text, claim1Evidence, claim1Timestamp);
const c2 = claim(claim2Text, claim2Evidence, claim2Timestamp);

const out = {
  submitter_pubkey: bytesToHex(submitter.publicKey),
  claim1: {
    claim_text: c1.text,
    evidence_uri: c1.evidenceUri,
    timestamp: c1.timestamp,
    claim_hash: c1.hash,
    signature: c1.sig,
    audits: [
      { ...audit(c1.hash, claim1Timestamp, 60_000, auditorA), verdict: true, label: "confirm #1" },
      { ...audit(c1.hash, claim1Timestamp, 120_000, auditorB), verdict: true, label: "confirm #2" },
    ],
  },
  claim2: {
    claim_text: c2.text,
    evidence_uri: c2.evidenceUri,
    timestamp: c2.timestamp,
    claim_hash: c2.hash,
    signature: c2.sig,
    audits: [
      { ...audit(c2.hash, claim2Timestamp, 60_000, auditorC), verdict: true, label: "confirm #3" },
      { ...audit(c2.hash, claim2Timestamp, 120_000, auditorD), verdict: true, label: "confirm #4" },
      { ...audit(c2.hash, claim2Timestamp, 180_000, auditorE), verdict: false, label: "OVERTURN #1" },
    ],
  },
};

console.log(JSON.stringify(out, null, 2));
