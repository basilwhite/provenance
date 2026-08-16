import { generateKeyPair, sign as edSign } from "../src/crypto/keys.js";
import { bytesToHex } from "../src/crypto/encoding.js";
import { computeClaimHash } from "../src/domain/claimHash.js";
import { batchMessage, claimTimestampMessage } from "../src/crypto/messages.js";
import { buildMerkleTree } from "../src/ledger/merkle.js";

export interface TestValidator {
  publicKey: string;
  publicKeyBytes: Uint8Array;
  privateKey: Uint8Array;
}

export function makeValidator(): TestValidator {
  const { publicKey, privateKey } = generateKeyPair();
  return { publicKey: bytesToHex(publicKey), publicKeyBytes: publicKey, privateKey };
}

/** claim_text long enough to clear the 500-char evidence-complexity floor. */
export function longText(n = 520): string {
  const unit = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ";
  return unit.repeat(Math.ceil(n / unit.length)).slice(0, n);
}

export function buildSubmitBody(
  validator: TestValidator,
  opts: { claimText?: string; evidenceUri?: string; timestamp?: number } = {},
) {
  const claim_text = opts.claimText ?? longText();
  const evidence_uri = opts.evidenceUri ?? "https://example.com/evidence/1";
  const timestamp = opts.timestamp ?? Date.now();
  const claimHash = computeClaimHash(claim_text, evidence_uri, timestamp);
  const signature = bytesToHex(edSign(claimTimestampMessage(claimHash, timestamp), validator.privateKey));
  return {
    body: { claim_text, evidence_uri, timestamp, validator_pubkey: validator.publicKey, signature },
    claimHash,
  };
}

export function buildAuditBody(
  validator: TestValidator,
  claimHash: string,
  auditVerdict: boolean,
  timestamp: number = Date.now(),
) {
  const signature = bytesToHex(edSign(claimTimestampMessage(claimHash, timestamp), validator.privateKey));
  return {
    claim_hash: claimHash,
    audit_verdict: auditVerdict,
    timestamp,
    validator_pubkey: validator.publicKey,
    signature,
  };
}

export function buildBatchBody(
  validator: TestValidator,
  claims: Array<{ claimText?: string; evidenceUri?: string; timestamp?: number }>,
  batchTimestamp: number = Date.now(),
) {
  const leaves = claims.map((c, i) => {
    const claim_text = c.claimText ?? longText();
    const evidence_uri = c.evidenceUri ?? `https://example.com/evidence/batch-${i}`;
    const timestamp = c.timestamp ?? batchTimestamp;
    const claimHash = computeClaimHash(claim_text, evidence_uri, timestamp);
    const signature = bytesToHex(edSign(claimTimestampMessage(claimHash, timestamp), validator.privateKey));
    return { claim_text, evidence_uri, timestamp, signature, claimHash };
  });

  const batchRoot = buildMerkleTree(leaves.map((l) => l.claimHash)).root;
  const batch_signature = bytesToHex(
    edSign(batchMessage(batchRoot, batchTimestamp), validator.privateKey),
  );

  return {
    body: {
      validator_pubkey: validator.publicKey,
      timestamp: batchTimestamp,
      batch_signature,
      claims: leaves.map(({ claim_text, evidence_uri, timestamp, signature }) => ({
        claim_text,
        evidence_uri,
        timestamp,
        signature,
      })),
    },
    claimHashes: leaves.map((l) => l.claimHash),
    batchRoot,
  };
}
