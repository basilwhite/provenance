import { generateKeyPair, sign } from "../src/crypto/keys.js";
import { bytesToHex } from "../src/crypto/encoding.js";
import { computeClaimHash } from "../src/domain/claimHash.js";
import { claimTimestampMessage } from "../src/crypto/messages.js";

const submitter = generateKeyPair();
const auditor1 = generateKeyPair();
const auditor2 = generateKeyPair();

const claimText =
  "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite " +
  "split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved " +
  "217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 " +
  "percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw " +
  "per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried " +
  "beyond the harness's standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 " +
  "minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached " +
  "artifacts.";

const evidenceUri = "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json";
const timestamp = 1_772_000_000_000;

const claimHash = computeClaimHash(claimText, evidenceUri, timestamp);
const submitSig = bytesToHex(sign(claimTimestampMessage(claimHash, timestamp), submitter.privateKey));

const auditTimestamp1 = timestamp + 60_000;
const auditSig1 = bytesToHex(sign(claimTimestampMessage(claimHash, auditTimestamp1), auditor1.privateKey));

const auditTimestamp2 = timestamp + 120_000;
const auditSig2 = bytesToHex(sign(claimTimestampMessage(claimHash, auditTimestamp2), auditor2.privateKey));

console.log(
  JSON.stringify(
    {
      submitter_pubkey: bytesToHex(submitter.publicKey),
      auditor1_pubkey: bytesToHex(auditor1.publicKey),
      auditor2_pubkey: bytesToHex(auditor2.publicKey),
      claim_text: claimText,
      evidence_uri: evidenceUri,
      timestamp,
      claim_hash: claimHash,
      submit_signature: submitSig,
      audit1: { timestamp: auditTimestamp1, signature: auditSig1, verdict: true },
      audit2: { timestamp: auditTimestamp2, signature: auditSig2, verdict: true },
    },
    null,
    2,
  ),
);
