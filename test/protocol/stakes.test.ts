import { beforeEach, describe, expect, it } from "vitest";
import { createDb, type Db } from "../../src/db/index.js";
import { DEFAULT_INITIAL_STAKE, MIN_STAKE_REQUIRED, SLASH_FRACTION, StakeStore } from "../../src/protocol/stakes.js";

describe("protocol/stakes", () => {
  let db: Db;
  let stakes: StakeStore;
  const pubkey = "0x" + "aa".repeat(32);

  beforeEach(() => {
    db = createDb(":memory:");
    stakes = new StakeStore(db);
  });

  it("returns a zeroed record for an unknown validator", () => {
    expect(stakes.get(pubkey)).toEqual({ validator_pubkey: pubkey, amount_locked: 0, amount_slashed: 0 });
  });

  it("ensureProvisioned auto-funds a new validator above the minimum", () => {
    const record = stakes.ensureProvisioned(pubkey);
    expect(record.amount_locked).toBe(DEFAULT_INITIAL_STAKE);
    expect(record.amount_locked).toBeGreaterThanOrEqual(MIN_STAKE_REQUIRED);
    expect(stakes.hasMinimumStake(pubkey)).toBe(true);
  });

  it("ensureProvisioned is idempotent (does not re-fund an existing validator)", () => {
    stakes.ensureProvisioned(pubkey);
    stakes.slash(pubkey);
    const afterSlash = stakes.get(pubkey).amount_locked;
    const record = stakes.ensureProvisioned(pubkey);
    expect(record.amount_locked).toBe(afterSlash);
  });

  it("slash burns SLASH_FRACTION of locked stake into amount_slashed", () => {
    stakes.ensureProvisioned(pubkey);
    const slashed = stakes.slash(pubkey);
    expect(slashed).toBe(Math.floor(DEFAULT_INITIAL_STAKE * SLASH_FRACTION));
    const record = stakes.get(pubkey);
    expect(record.amount_locked).toBe(DEFAULT_INITIAL_STAKE - slashed);
    expect(record.amount_slashed).toBe(slashed);
  });

  it("a validator with no stake at all fails the minimum-stake check", () => {
    expect(stakes.hasMinimumStake(pubkey)).toBe(false);
  });

  it("repeated slashing can drive locked stake to (and keep it at) zero", () => {
    stakes.ensureProvisioned(pubkey);
    for (let i = 0; i < 10; i++) {
      stakes.slash(pubkey);
    }
    expect(stakes.get(pubkey).amount_locked).toBe(0);
    expect(stakes.hasMinimumStake(pubkey)).toBe(false);
  });
});
