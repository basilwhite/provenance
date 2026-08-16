import type { OriginalClaimInfo } from "../ledger/store.js";
import type { StoredLedgerEvent } from "../ledger/types.js";

/** Overturns must land within this window of the original submission to count toward slashing. */
export const SLASH_WINDOW_MS = 7 * 24 * 60 * 60 * 1000;

export interface PendingAudit {
  audit_verdict: boolean;
  timestamp: number;
}

/**
 * F5.1: slash once a claim accumulates >= 2 independent overturns within
 * 7 days of the original submission. Slashing is a one-time event per
 * claim — evaluated by checking whether any existing audit on the claim
 * already recorded a non-zero stake_slashed. Called with the pending
 * audit's own verdict/timestamp so the triggering audit itself can carry
 * the slash amount atomically.
 */
export function shouldSlashForClaim(
  original: OriginalClaimInfo,
  existingAudits: StoredLedgerEvent[],
  pending: PendingAudit,
): boolean {
  const alreadySlashed = existingAudits.some((a) => a.stake_slashed > 0);
  if (alreadySlashed) return false;

  const windowEnd = original.timestamp + SLASH_WINDOW_MS;

  const qualifyingOverturns = existingAudits.filter(
    (a) => a.audit_verdict === false && a.timestamp <= windowEnd,
  ).length;

  const pendingQualifies = pending.audit_verdict === false && pending.timestamp <= windowEnd;

  return qualifyingOverturns + (pendingQualifies ? 1 : 0) >= 2;
}
