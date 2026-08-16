import { Router } from "express";
import type { Db } from "../../db/index.js";
import { LedgerStore } from "../../ledger/store.js";
import { StakeStore, MIN_STAKE_REQUIRED } from "../../protocol/stakes.js";
import {
  countRecentSubmissions,
  meetsEvidenceComplexity,
  MAX_SUBMISSIONS_PER_24H,
  MIN_CLAIM_TEXT_CHARS,
} from "../../protocol/rateLimit.js";
import { computeClaimHash } from "../../domain/claimHash.js";
import { batchMessage, claimTimestampMessage } from "../../crypto/messages.js";
import { verify } from "../../crypto/keys.js";
import { hexToBytes } from "../../crypto/encoding.js";
import { buildMerkleTree } from "../../ledger/merkle.js";
import { ApiError } from "../errors.js";
import { asyncHandler } from "../asyncHandler.js";
import { requireNumber, requirePubkeyHex, requireSignatureHex, requireString } from "../validators.js";

const MAX_BATCH_SIZE = 50;

interface RawClaim {
  claim_text: string;
  evidence_uri: string;
  timestamp: number;
  signature: string;
}

function parseClaim(raw: unknown, index: number): RawClaim {
  const b = (raw ?? {}) as Record<string, unknown>;
  try {
    return {
      claim_text: requireString(b["claim_text"], "claim_text"),
      evidence_uri: requireString(b["evidence_uri"], "evidence_uri"),
      timestamp: requireNumber(b["timestamp"], "timestamp"),
      signature: requireSignatureHex(b["signature"], "signature"),
    };
  } catch (err) {
    if (err instanceof ApiError) {
      throw new ApiError(err.status, err.code, `claims[${index}]: ${err.message}`);
    }
    throw err;
  }
}

function parseBody(body: unknown) {
  const b = (body ?? {}) as Record<string, unknown>;
  const validator_pubkey = requirePubkeyHex(b["validator_pubkey"], "validator_pubkey");
  const timestamp = requireNumber(b["timestamp"], "timestamp");
  const batch_signature = requireSignatureHex(b["batch_signature"], "batch_signature");

  const rawClaims = b["claims"];
  if (!Array.isArray(rawClaims) || rawClaims.length === 0) {
    throw new ApiError(400, "invalid_request", "claims must be a non-empty array");
  }
  if (rawClaims.length > MAX_BATCH_SIZE) {
    throw new ApiError(400, "invalid_request", `claims cannot exceed ${MAX_BATCH_SIZE} items`);
  }
  const claims = rawClaims.map(parseClaim);

  return { validator_pubkey, timestamp, batch_signature, claims };
}

/**
 * F5.3: bundles up to 50 claims from a single validator into one ledger
 * event. Each claim is still individually signed (claim_hash + timestamp)
 * exactly like /submit, so per-claim audits and slashing work unchanged;
 * the client additionally signs (batch_root + timestamp) to authenticate
 * the bundle as a whole. Leaves are per-claim claim_hashes, so the Merkle
 * proof for any leaf can be rebuilt later straight from batch_leaves.
 */
export function createBatchRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);
  const stakeStore = new StakeStore(db);

  router.post(
    "/submit/batch",
    asyncHandler(async (req, res) => {
      const body = parseBody(req.body);

      const claimHashes = body.claims.map((c) => computeClaimHash(c.claim_text, c.evidence_uri, c.timestamp));

      body.claims.forEach((c, i) => {
        const msg = claimTimestampMessage(claimHashes[i] as string, c.timestamp);
        const ok = verify(msg, hexToBytes(c.signature), hexToBytes(body.validator_pubkey));
        if (!ok) {
          throw new ApiError(
            401,
            "invalid_signature",
            `claims[${i}]: signature does not verify against validator_pubkey`,
          );
        }
      });

      body.claims.forEach((c, i) => {
        if (!meetsEvidenceComplexity(c.claim_text)) {
          throw new ApiError(
            422,
            "evidence_too_short",
            `claims[${i}]: claim_text must be at least ${MIN_CLAIM_TEXT_CHARS} characters (got ${c.claim_text.length})`,
          );
        }
      });

      const { root: batchRoot } = buildMerkleTree(claimHashes);

      const batchSigValid = verify(
        batchMessage(batchRoot, body.timestamp),
        hexToBytes(body.batch_signature),
        hexToBytes(body.validator_pubkey),
      );
      if (!batchSigValid) {
        throw new ApiError(
          401,
          "invalid_batch_signature",
          "batch_signature does not verify over (batch_root, timestamp)",
        );
      }

      const recentCount = countRecentSubmissions(db, body.validator_pubkey);
      if (recentCount >= MAX_SUBMISSIONS_PER_24H) {
        throw new ApiError(
          429,
          "rate_limit_exceeded",
          `validator has already submitted ${recentCount} claims/batches in the last 24h (max ${MAX_SUBMISSIONS_PER_24H})`,
        );
      }

      const stake = stakeStore.ensureProvisioned(body.validator_pubkey);
      if (stake.amount_locked < MIN_STAKE_REQUIRED) {
        throw new ApiError(403, "insufficient_stake", "validator does not have the minimum required stake locked");
      }

      const containerEvent = ledgerStore.appendEvent({
        type: "batch",
        claim_hash: batchRoot,
        evidence_uri: `batch:${body.claims.length}-claims`,
        timestamp: body.timestamp,
        validator_pubkey: body.validator_pubkey,
        signature: body.batch_signature,
        batch_root: batchRoot,
        stake_locked: stake.amount_locked,
      });

      const insertLeaf = db.prepare(
        `INSERT INTO batch_leaves (batch_event_id, leaf_index, claim_hash, evidence_uri, timestamp, validator_pubkey, signature)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
      );
      const insertClaimText = db.prepare(
        `INSERT OR REPLACE INTO claim_texts (claim_hash, claim_text) VALUES (?, ?)`,
      );

      const insertAll = db.transaction((claims: RawClaim[], hashes: string[]) => {
        claims.forEach((c, i) => {
          insertLeaf.run(containerEvent.id, i, hashes[i], c.evidence_uri, c.timestamp, body.validator_pubkey, c.signature);
          insertClaimText.run(hashes[i], c.claim_text);
        });
      });
      insertAll(body.claims, claimHashes);

      res.status(201).json({ event: containerEvent, batch_root: batchRoot, claim_hashes: claimHashes });
    }),
  );

  return router;
}
