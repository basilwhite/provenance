import type { Db } from "../db/index.js";

export interface ScoreRecord {
  validator_pubkey: string;
  n: number;
  confirmations: number;
  overturns: number;
  score: number;
  updated_at: number;
}

function defaultRecord(pubkey: string): ScoreRecord {
  return { validator_pubkey: pubkey, n: 0, confirmations: 0, overturns: 0, score: 0.5, updated_at: 0 };
}

/** Persistence for the F4.2 validator_scores table. */
export class ScoreStore {
  constructor(private readonly db: Db) {}

  get(pubkey: string): ScoreRecord {
    const row = this.db
      .prepare("SELECT * FROM validator_scores WHERE validator_pubkey = ?")
      .get(pubkey) as ScoreRecord | undefined;
    return row ?? defaultRecord(pubkey);
  }

  /**
   * F1.2 continuity: finalizeClaimIfReady writes the score under whichever
   * key was "current" in the lineage AT THAT TIME. If a rotation happens
   * later with no new audits, that row is now keyed under a retired key.
   * Rather than migrating rows on every rotation, lookups search the whole
   * lineage and return whichever key holds the most recently updated
   * record — correct regardless of how many rotations happened after the
   * score was last computed.
   */
  getMostRecentAcrossKeys(pubkeys: string[]): ScoreRecord {
    let best: ScoreRecord | null = null;
    for (const pubkey of pubkeys) {
      const record = this.get(pubkey);
      if (!best || record.updated_at > best.updated_at) {
        best = record;
      }
    }
    return best ?? defaultRecord(pubkeys[0] ?? "");
  }

  upsert(pubkey: string, n: number, confirmations: number, overturns: number, score: number): ScoreRecord {
    const updatedAt = Date.now();
    this.db
      .prepare(
        `INSERT INTO validator_scores (validator_pubkey, n, confirmations, overturns, score, updated_at)
         VALUES (@pubkey, @n, @confirmations, @overturns, @score, @updated_at)
         ON CONFLICT(validator_pubkey) DO UPDATE SET
           n = excluded.n,
           confirmations = excluded.confirmations,
           overturns = excluded.overturns,
           score = excluded.score,
           updated_at = excluded.updated_at`,
      )
      .run({ pubkey, n, confirmations, overturns, score, updated_at: updatedAt });
    return this.get(pubkey);
  }
}
