import { describe, expect, it } from "vitest";
import { computeScore, computeScoreFromCounts } from "../../src/scoring/wilson.js";

describe("scoring/wilson", () => {
  it("returns the neutral prior 0.5 when n < 5", () => {
    expect(computeScore(0, 0, 0)).toBe(0.5);
    expect(computeScore(4, 4, 0)).toBe(0.5);
    expect(computeScore(3, 1, 2)).toBe(0.5);
  });

  it("matches hand-computed reference values (n >= 5)", () => {
    // Reference values computed directly from the spec formula (see PR
    // description / scratch calc): p_hat=(c+1)/(n+2), z=1.96.
    expect(computeScore(10, 8, 2)).toBeCloseTo(0.44217614862729365, 12);
    expect(computeScore(10, 10, 0)).toBeCloseTo(0.6150840884238029, 12);
    expect(computeScore(10, 0, 10)).toBeCloseTo(0.013034229062650157, 12);
    expect(computeScore(5, 5, 0)).toBeCloseTo(0.423970045481152, 12);
    expect(computeScore(100, 90, 10)).toBeCloseTo(0.8162499375512294, 12);
    expect(computeScore(20, 10, 10)).toBeCloseTo(0.2992949144298199, 12);
  });

  it("computeScoreFromCounts matches computeScore(n=c+o, c, o)", () => {
    expect(computeScoreFromCounts(8, 2)).toBe(computeScore(10, 8, 2));
  });

  it("throws if n does not equal confirmations + overturns", () => {
    expect(() => computeScore(10, 8, 1)).toThrow();
  });

  it("throws on negative counts", () => {
    expect(() => computeScore(-1, 0, 0)).toThrow();
  });

  describe("property: score is always in [0, 1]", () => {
    it.each([
      [5, 0, 5],
      [5, 5, 0],
      [50, 25, 25],
      [1000, 999, 1],
      [1000, 1, 999],
      [7, 3, 4],
    ])("n=%i confirmations=%i overturns=%i", (n, c, o) => {
      const score = computeScore(n, c, o);
      expect(score).toBeGreaterThanOrEqual(0);
      expect(score).toBeLessThanOrEqual(1);
    });
  });

  describe("property: monotonic in confirmations for fixed n", () => {
    it("score strictly increases as confirmations increase (overturns decrease) for fixed n", () => {
      const n = 50;
      const scores: number[] = [];
      for (let c = 0; c <= n; c++) {
        scores.push(computeScore(n, c, n - c));
      }
      for (let i = 1; i < scores.length; i++) {
        expect(scores[i]).toBeGreaterThan(scores[i - 1] as number);
      }
    });

    it("holds across several fixed values of n", () => {
      for (const n of [5, 6, 10, 25, 100]) {
        let prev = -Infinity;
        for (let c = 0; c <= n; c++) {
          const score = computeScore(n, c, n - c);
          expect(score).toBeGreaterThan(prev);
          prev = score;
        }
      }
    });
  });
});
