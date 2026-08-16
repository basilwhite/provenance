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
import { claimTimestampMessage } from "../../crypto/messages.js";
import { verify } from "../../crypto/keys.js";
import { hexToBytes } from "../../crypto/encoding.js";
import { ApiError } from "../errors.js";
import { asyncHandler } from "../asyncHandler.js";
import { requireNumber, requirePubkeyHex, requireSignatureHex, requireString } from "../validators.js";

function parseBody(body: unknown) {
  const b = (body ?? {}) as Record<string, unknown>;
  return {
    claim_text: requireString(b["claim_text"], "claim_text"),
    evidence_uri: requireString(b["evidence_uri"], "evidence_uri"),
    timestamp: requireNumber(b["timestamp"], "timestamp"),
    validator_pubkey: requirePubkeyHex(b["validator_pubkey"], "validator_pubkey"),
    signature: requireSignatureHex(b["signature"], "signature"),
  };
}

export function createSubmitRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);
  const stakeStore = new StakeStore(db);

  router.post(
    "/submit",
    asyncHandler(async (req, res) => {
      const body = parseBody(req.body);

      const claimHash = computeClaimHash(body.claim_text, body.evidence_uri, body.timestamp);
      const message = claimTimestampMessage(claimHash, body.timestamp);
      const sigValid = verify(message, hexToBytes(body.signature), hexToBytes(body.validator_pubkey));
      if (!sigValid) {
        throw new ApiError(401, "invalid_signature", "signature does not verify against validator_pubkey");
      }

      if (!meetsEvidenceComplexity(body.claim_text)) {
        throw new ApiError(
          422,
          "evidence_too_short",
          `claim_text must be at least ${MIN_CLAIM_TEXT_CHARS} characters (got ${body.claim_text.length})`,
        );
      }

      const recentCount = countRecentSubmissions(db, body.validator_pubkey);
      if (recentCount >= MAX_SUBMISSIONS_PER_24H) {
        throw new ApiError(
          429,
          "rate_limit_exceeded",
          `validator has already submitted ${recentCount} claims in the last 24h (max ${MAX_SUBMISSIONS_PER_24H})`,
        );
      }

      const stake = stakeStore.ensureProvisioned(body.validator_pubkey);
      if (stake.amount_locked < MIN_STAKE_REQUIRED) {
        throw new ApiError(403, "insufficient_stake", "validator does not have the minimum required stake locked");
      }

      const event = ledgerStore.appendEvent({
        type: "submission",
        claim_hash: claimHash,
        evidence_uri: body.evidence_uri,
        timestamp: body.timestamp,
        validator_pubkey: body.validator_pubkey,
        signature: body.signature,
        stake_locked: stake.amount_locked,
      });

      db.prepare("INSERT OR REPLACE INTO claim_texts (claim_hash, claim_text) VALUES (?, ?)").run(
        claimHash,
        body.claim_text,
      );

      res.status(201).json({ event });
    }),
  );

  return router;
}
