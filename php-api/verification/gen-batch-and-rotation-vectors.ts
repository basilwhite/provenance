import { generateKeyPair, sign } from "../../src/crypto/keys.js";
import { bytesToHex } from "../../src/crypto/encoding.js";
import { computeClaimHash } from "../../src/domain/claimHash.js";
import { claimTimestampMessage, batchMessage, rotationMessage } from "../../src/crypto/messages.js";
import { buildMerkleTree } from "../../src/ledger/merkle.js";

const validator = generateKeyPair();
const pubHex = bytesToHex(validator.publicKey);

const longText = (n: number) => "Lorem ipsum dolor sit amet, consectetur adipiscing elit. ".repeat(Math.ceil(n / 58)).slice(0, n);

const batchTimestamp = 1772200000000;
const claims = [0, 1, 2].map((i) => {
  const claimText = longText(520) + ` batch-item-${i}`;
  const evidenceUri = `https://evidence.example.org/batch/${i}.json`;
  const timestamp = batchTimestamp + i;
  const claimHash = computeClaimHash(claimText, evidenceUri, timestamp);
  const signature = bytesToHex(sign(claimTimestampMessage(claimHash, timestamp), validator.privateKey));
  return { claim_text: claimText, evidence_uri: evidenceUri, timestamp, signature, claimHash };
});

const batchRoot = buildMerkleTree(claims.map((c) => c.claimHash)).root;
const batchSignature = bytesToHex(sign(batchMessage(batchRoot, batchTimestamp), validator.privateKey));

const oldValidator = generateKeyPair();
const newValidator = generateKeyPair();
const rotationSignature = bytesToHex(
  sign(rotationMessage(bytesToHex(oldValidator.publicKey), bytesToHex(newValidator.publicKey)), oldValidator.privateKey),
);

console.log(JSON.stringify({
  batch: {
    validator_pubkey: pubHex,
    timestamp: batchTimestamp,
    batch_signature: batchSignature,
    claims: claims.map(({ claim_text, evidence_uri, timestamp, signature }) => ({ claim_text, evidence_uri, timestamp, signature })),
    expected_batch_root: batchRoot,
    expected_claim_hashes: claims.map((c) => c.claimHash),
  },
  rotation: {
    old_pubkey: bytesToHex(oldValidator.publicKey),
    new_pubkey: bytesToHex(newValidator.publicKey),
    rotation_signature: rotationSignature,
  },
}, null, 2));
