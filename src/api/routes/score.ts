import { Router } from "express";
import type { Db } from "../../db/index.js";
import { LedgerStore } from "../../ledger/store.js";
import { ScoreStore } from "../../scoring/scores.js";
import { asyncHandler } from "../asyncHandler.js";
import { requirePubkeyHex } from "../validators.js";

export function createScoreRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);
  const scoreStore = new ScoreStore(db);

  router.get(
    "/validators/:pubkey/score",
    asyncHandler(async (req, res) => {
      const pubkey = requirePubkeyHex(req.params["pubkey"], "pubkey");
      // Resolve to the current key in this validator's rotation lineage
      // (F1.2) so querying a retired old key still returns the continuous
      // score rather than a fresh 0.5.
      const lineage = ledgerStore.resolveIdentityLineage(pubkey);
      const currentIdentity = lineage[lineage.length - 1] as string;
      const record = scoreStore.getMostRecentAcrossKeys(lineage);
      res.json({ ...record, validator_pubkey: currentIdentity });
    }),
  );

  // Not one of the spec's named endpoints, but F6.2's CLI is specified to
  // take only --pubkey and --ledger-url (no claim_hash) — it needs some
  // way to fetch a validator's raw event history by pubkey alone. Reuses
  // the same lineage-aware, submitter-scoped event set as /verify.
  router.get(
    "/validators/:pubkey/events",
    asyncHandler(async (req, res) => {
      const pubkey = requirePubkeyHex(req.params["pubkey"], "pubkey");
      const lineage = ledgerStore.resolveIdentityLineage(pubkey);
      const currentIdentity = lineage[lineage.length - 1] as string;
      const events = ledgerStore.getEventsForIdentity(pubkey);
      const record = scoreStore.getMostRecentAcrossKeys(lineage);
      res.json({
        validator_pubkey: currentIdentity,
        events,
        reported_score: record.score,
      });
    }),
  );

  return router;
}
