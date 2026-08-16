import type { Db } from "../db/index.js";

export const MAX_SUBMISSIONS_PER_24H = 10;
export const RATE_LIMIT_WINDOW_MS = 24 * 60 * 60 * 1000;

/**
 * Minimum length of claim_text required per submission. The spec allows
 * either fetching evidence_uri or inspecting text; this implementation
 * inspects claim_text directly rather than server-side fetching arbitrary
 * evidence_uri values, which would open an SSRF vector (see ATTACK_REPORT).
 */
export const MIN_CLAIM_TEXT_CHARS = 500;

/**
 * Counts a validator's submissions in the trailing 24h window, measured
 * from server-observed "now" (not client-supplied timestamps, so the
 * limit can't be bypassed by backdating requests). A batch counts as ONE
 * submission regardless of how many claims it bundles — consistent with
 * F5.3's "one ledger event per batch" write-cost reduction, batching also
 * gets a validator 50 claims out of a single rate-limit slot.
 */
export function countRecentSubmissions(db: Db, pubkey: string, now: number = Date.now()): number {
  const since = now - RATE_LIMIT_WINDOW_MS;

  const row = db
    .prepare(
      "SELECT COUNT(*) as n FROM ledger_events WHERE validator_pubkey = ? AND type IN ('submission','batch') AND timestamp >= ?",
    )
    .get(pubkey, since) as { n: number };

  return row.n;
}

export function meetsEvidenceComplexity(claimText: string): boolean {
  return claimText.length >= MIN_CLAIM_TEXT_CHARS;
}
