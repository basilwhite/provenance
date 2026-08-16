/** z-score for a 95% confidence bound, fixed by spec. */
const Z = 1.96;
const Z_SQUARED = Z * Z;

/** Below this many total audits, a validator's track record is too thin to score. */
const MIN_AUDITS_FOR_SCORING = 5;

/**
 * Wilson-lower-bound style score with Laplace (+1/+2) smoothing baked into
 * p_hat, per spec. n must equal confirmations + overturns; overturns count
 * as failures. Returns 0.5 (neutral prior) when n < 5.
 */
export function computeScore(n: number, confirmations: number, overturns: number): number {
  if (!Number.isInteger(n) || !Number.isInteger(confirmations) || !Number.isInteger(overturns)) {
    throw new Error("computeScore expects integer counts");
  }
  if (n < 0 || confirmations < 0 || overturns < 0) {
    throw new Error("computeScore expects non-negative counts");
  }
  if (confirmations + overturns !== n) {
    throw new Error("n must equal confirmations + overturns");
  }

  if (n < MIN_AUDITS_FOR_SCORING) {
    return 0.5;
  }

  const pHat = (confirmations + 1) / (n + 2);
  const numerator =
    pHat + Z_SQUARED / (2 * n) - Z * Math.sqrt((pHat * (1 - pHat) + Z_SQUARED / (4 * n)) / n);
  const denominator = 1 + Z_SQUARED / n;
  const score = numerator / denominator;

  // Guard against floating-point overshoot at the [0,1] boundary.
  return Math.min(1, Math.max(0, score));
}

/** Convenience wrapper for the common case where n isn't tracked separately. */
export function computeScoreFromCounts(confirmations: number, overturns: number): number {
  return computeScore(confirmations + overturns, confirmations, overturns);
}
