<?php

declare(strict_types=1);

namespace Provenance\Scoring;

/** Byte-for-formula mirror of src/scoring/wilson.ts. */
final class Wilson
{
    private const Z = 1.96;
    private const Z_SQUARED = self::Z * self::Z;
    private const MIN_AUDITS_FOR_SCORING = 5;

    public static function computeScore(int $n, int $confirmations, int $overturns): float
    {
        if ($n < 0 || $confirmations < 0 || $overturns < 0) {
            throw new \InvalidArgumentException('computeScore expects non-negative counts');
        }
        if ($confirmations + $overturns !== $n) {
            throw new \InvalidArgumentException('n must equal confirmations + overturns');
        }

        if ($n < self::MIN_AUDITS_FOR_SCORING) {
            return 0.5;
        }

        $pHat = ($confirmations + 1) / ($n + 2);
        $numerator = $pHat + self::Z_SQUARED / (2 * $n)
            - self::Z * sqrt(($pHat * (1 - $pHat) + self::Z_SQUARED / (4 * $n)) / $n);
        $denominator = 1 + self::Z_SQUARED / $n;
        $score = $numerator / $denominator;

        return min(1.0, max(0.0, $score));
    }

    public static function computeScoreFromCounts(int $confirmations, int $overturns): float
    {
        return self::computeScore($confirmations + $overturns, $confirmations, $overturns);
    }
}
