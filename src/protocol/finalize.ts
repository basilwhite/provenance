import type { LedgerStore } from "../ledger/store.js";
import type { ScoreStore } from "../scoring/scores.js";
import { computeScore } from "../scoring/wilson.js";

export interface FinalizeResult {
  updated: boolean;
  score?: number;
  n?: number;
  confirmations?: number;
  overturns?: number;
}

const MIN_AUDITS_TO_FINALIZE_CLAIM = 2;

/**
 * F3.3: a claim only contributes to its submitter's score once it has
 * received >= 2 independent audits. Once a claim clears that bar, ALL of
 * its audits count (including later ones) — so this recomputes the
 * validator's aggregate confirmations/overturns from scratch across every
 * one of their claims that has reached the finalization threshold, rather
 * than incrementally tracking just this claim.
 */
export function finalizeClaimIfReady(
  ledgerStore: LedgerStore,
  scoreStore: ScoreStore,
  claimHash: string,
): FinalizeResult {
  const original = ledgerStore.findOriginalClaim(claimHash);
  if (!original) {
    return { updated: false };
  }

  const auditsForThisClaim = ledgerStore.getAuditsForClaim(claimHash);
  if (auditsForThisClaim.length < MIN_AUDITS_TO_FINALIZE_CLAIM) {
    return { updated: false };
  }

  // Aggregate across the validator's full key-rotation lineage (F1.2) so a
  // rotated key continues its predecessor's track record instead of
  // resetting to a fresh 0.5 prior.
  const lineage = ledgerStore.resolveIdentityLineage(original.validator_pubkey);
  const currentIdentity = lineage[lineage.length - 1] as string;

  let confirmations = 0;
  let overturns = 0;
  for (const pubkey of lineage) {
    for (const claim of ledgerStore.getAllClaimsForValidator(pubkey)) {
      const audits = ledgerStore.getAuditsForClaim(claim.claim_hash);
      if (audits.length < MIN_AUDITS_TO_FINALIZE_CLAIM) continue;
      for (const audit of audits) {
        if (audit.audit_verdict === true) confirmations++;
        else if (audit.audit_verdict === false) overturns++;
      }
    }
  }

  const n = confirmations + overturns;
  const score = computeScore(n, confirmations, overturns);
  scoreStore.upsert(currentIdentity, n, confirmations, overturns, score);

  return { updated: true, score, n, confirmations, overturns };
}
