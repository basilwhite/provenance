import type { Db } from "../db/index.js";

/** Minimum locked stake required to submit a claim. */
export const MIN_STAKE_REQUIRED = 1;

/** New validators are auto-provisioned with this much locked stake on their first submission. */
export const DEFAULT_INITIAL_STAKE = 10;

/** Fraction of locked stake burned when a claim is slashed. */
export const SLASH_FRACTION = 0.5;

export interface StakeRecord {
  validator_pubkey: string;
  amount_locked: number;
  amount_slashed: number;
}

/** Persistence + mutation for the F5.1 stakes table. This is a simulation: there is no real
 *  token custody, deposits are auto-provisioned so /submit is exercisable without a funding step. */
export class StakeStore {
  constructor(private readonly db: Db) {}

  get(pubkey: string): StakeRecord {
    const row = this.db.prepare("SELECT * FROM stakes WHERE validator_pubkey = ?").get(pubkey) as
      | StakeRecord
      | undefined;
    return row ?? { validator_pubkey: pubkey, amount_locked: 0, amount_slashed: 0 };
  }

  /** Ensures a validator has at least MIN_STAKE_REQUIRED locked, auto-provisioning on first contact. */
  ensureProvisioned(pubkey: string): StakeRecord {
    const existing = this.db.prepare("SELECT * FROM stakes WHERE validator_pubkey = ?").get(pubkey) as
      | StakeRecord
      | undefined;
    if (existing) return existing;

    this.db
      .prepare("INSERT INTO stakes (validator_pubkey, amount_locked, amount_slashed) VALUES (?, ?, 0)")
      .run(pubkey, DEFAULT_INITIAL_STAKE);
    return this.get(pubkey);
  }

  hasMinimumStake(pubkey: string): boolean {
    return this.get(pubkey).amount_locked >= MIN_STAKE_REQUIRED;
  }

  /** Burns SLASH_FRACTION of the validator's currently locked stake. Returns the amount burned. */
  slash(pubkey: string, fraction: number = SLASH_FRACTION): number {
    const current = this.get(pubkey);
    // Math.floor alone can round a fractional cut down to 0 once locked
    // stake is small (e.g. floor(1 * 0.5) = 0), letting a validator sit
    // forever just above zero and keep passing the minimum-stake check.
    // Rounding up to at least 1 guarantees slashing eventually reaches 0.
    const amount = current.amount_locked > 0 ? Math.max(1, Math.floor(current.amount_locked * fraction)) : 0;
    this.db
      .prepare(
        `INSERT INTO stakes (validator_pubkey, amount_locked, amount_slashed)
         VALUES (@pubkey, @locked, @slashed)
         ON CONFLICT(validator_pubkey) DO UPDATE SET
           amount_locked = excluded.amount_locked,
           amount_slashed = excluded.amount_slashed`,
      )
      .run({
        pubkey,
        locked: current.amount_locked - amount,
        slashed: current.amount_slashed + amount,
      });
    return amount;
  }
}
