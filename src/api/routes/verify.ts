import { Router } from "express";
import type { Db } from "../../db/index.js";
import { LedgerStore } from "../../ledger/store.js";
import { ScoreStore } from "../../scoring/scores.js";
import { buildChainProof, findBatchLeafProof } from "../../ledger/proof.js";
import { ApiError } from "../errors.js";
import { asyncHandler } from "../asyncHandler.js";
import { requireClaimHashHex } from "../validators.js";

/**
 * F6.1: everything needed to independently re-derive a validator's score
 * and confirm one specific claim's place in the tamper-evident chain,
 * without trusting this server. See ledger/proof.ts for how the two proof
 * shapes (batch inclusion, chain-of-custody) compose into one flat path.
 */
export function createVerifyRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);
  const scoreStore = new ScoreStore(db);

  router.get(
    "/verify/:claim_hash",
    asyncHandler(async (req, res) => {
      const claimHash = requireClaimHashHex(req.params["claim_hash"], "claim_hash");

      const original = ledgerStore.findOriginalClaim(claimHash);
      if (!original) {
        throw new ApiError(404, "claim_not_found", `no claim found for claim_hash ${claimHash}`);
      }

      // Report under the validator's *current* identity (F1.2 rotation
      // continuity), with events spanning every key in its lineage.
      const lineage = ledgerStore.resolveIdentityLineage(original.validator_pubkey);
      const currentIdentity = lineage[lineage.length - 1] as string;
      const events = ledgerStore.getEventsForIdentity(original.validator_pubkey);
      const scoreRecord = scoreStore.getMostRecentAcrossKeys(lineage);

      const batchLeaf = findBatchLeafProof(db, claimHash);
      let innerPath: string[];
      let originEventId: number;

      if (batchLeaf) {
        innerPath = batchLeaf.path;
        originEventId = batchLeaf.batchEventId;
      } else {
        const submission = ledgerStore.getSubmissionEvent(claimHash);
        if (!submission) {
          throw new ApiError(404, "claim_not_found", `no submission event found for claim_hash ${claimHash}`);
        }
        innerPath = [];
        originEventId = submission.id;
      }

      const chainProof = buildChainProof(ledgerStore, originEventId);

      res.json({
        validator_pubkey: currentIdentity,
        events,
        current_score: scoreRecord.score,
        merkle_proof: {
          claim_hash: claimHash,
          path: [...innerPath, ...chainProof.path],
          root: chainProof.root,
        },
      });
    }),
  );

  return router;
}
