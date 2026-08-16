import { Router } from "express";
import type { Db } from "../../db/index.js";
import { LedgerStore } from "../../ledger/store.js";
import { StakeStore } from "../../protocol/stakes.js";
import { ScoreStore } from "../../scoring/scores.js";
import { finalizeClaimIfReady } from "../../protocol/finalize.js";
import { shouldSlashForClaim } from "../../protocol/slashing.js";
import { claimTimestampMessage } from "../../crypto/messages.js";
import { verify } from "../../crypto/keys.js";
import { hexToBytes } from "../../crypto/encoding.js";
import { ApiError } from "../errors.js";
import { asyncHandler } from "../asyncHandler.js";
import { requireBoolean, requireClaimHashHex, requireNumber, requirePubkeyHex, requireSignatureHex } from "../validators.js";

function parseBody(body: unknown) {
  const b = (body ?? {}) as Record<string, unknown>;
  return {
    claim_hash: requireClaimHashHex(b["claim_hash"], "claim_hash"),
    audit_verdict: requireBoolean(b["audit_verdict"], "audit_verdict"),
    timestamp: requireNumber(b["timestamp"], "timestamp"),
    validator_pubkey: requirePubkeyHex(b["validator_pubkey"], "validator_pubkey"),
    signature: requireSignatureHex(b["signature"], "signature"),
  };
}

export function createAuditRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);
  const stakeStore = new StakeStore(db);
  const scoreStore = new ScoreStore(db);

  router.post(
    "/audit",
    asyncHandler(async (req, res) => {
      const body = parseBody(req.body);

      const original = ledgerStore.findOriginalClaim(body.claim_hash);
      if (!original) {
        throw new ApiError(404, "claim_not_found", `no claim found for claim_hash ${body.claim_hash}`);
      }

      if (original.validator_pubkey === body.validator_pubkey) {
        throw new ApiError(403, "self_audit_forbidden", "a validator cannot audit its own claim");
      }

      const message = claimTimestampMessage(body.claim_hash, body.timestamp);
      const sigValid = verify(message, hexToBytes(body.signature), hexToBytes(body.validator_pubkey));
      if (!sigValid) {
        throw new ApiError(401, "invalid_signature", "signature does not verify against validator_pubkey");
      }

      const existingAudits = ledgerStore.getAuditsForClaim(body.claim_hash);
      const alreadyAudited = existingAudits.some((a) => a.validator_pubkey === body.validator_pubkey);
      if (alreadyAudited) {
        throw new ApiError(409, "duplicate_audit", "this validator has already audited this claim");
      }

      const willSlash = shouldSlashForClaim(original, existingAudits, {
        audit_verdict: body.audit_verdict,
        timestamp: body.timestamp,
      });
      const slashedAmount = willSlash ? stakeStore.slash(original.validator_pubkey) : 0;

      const event = ledgerStore.appendEvent({
        type: "audit",
        claim_hash: body.claim_hash,
        evidence_uri: original.evidence_uri,
        timestamp: body.timestamp,
        validator_pubkey: body.validator_pubkey,
        signature: body.signature,
        audit_ref: body.claim_hash,
        audit_verdict: body.audit_verdict,
        stake_slashed: slashedAmount,
      });

      const finalizeResult = finalizeClaimIfReady(ledgerStore, scoreStore, body.claim_hash);

      res.status(201).json({ event, slashed_amount: slashedAmount, finalize: finalizeResult });
    }),
  );

  return router;
}
