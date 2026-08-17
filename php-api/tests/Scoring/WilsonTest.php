<?php

declare(strict_types=1);

namespace Provenance\Tests\Scoring;

use PHPUnit\Framework\TestCase;
use Provenance\Scoring\Wilson;

final class WilsonTest extends TestCase
{
    public function testNeutralPriorWhenNLessThan5(): void
    {
        $this->assertSame(0.5, Wilson::computeScore(0, 0, 0));
        $this->assertSame(0.5, Wilson::computeScore(4, 4, 0));
        $this->assertSame(0.5, Wilson::computeScore(3, 1, 2));
    }

    /** Same 6 hand-computed reference values as test/scoring/wilson.test.ts. */
    public function testMatchesHandComputedReferenceValues(): void
    {
        $this->assertEqualsWithDelta(0.44217614862729365, Wilson::computeScore(10, 8, 2), 1e-12);
        $this->assertEqualsWithDelta(0.6150840884238029, Wilson::computeScore(10, 10, 0), 1e-12);
        $this->assertEqualsWithDelta(0.013034229062650157, Wilson::computeScore(10, 0, 10), 1e-12);
        $this->assertEqualsWithDelta(0.423970045481152, Wilson::computeScore(5, 5, 0), 1e-12);
        $this->assertEqualsWithDelta(0.8162499375512294, Wilson::computeScore(100, 90, 10), 1e-12);
        $this->assertEqualsWithDelta(0.2992949144298199, Wilson::computeScore(20, 10, 10), 1e-12);
    }

    public function testComputeScoreFromCountsMatchesComputeScore(): void
    {
        $this->assertSame(Wilson::computeScore(10, 8, 2), Wilson::computeScoreFromCounts(8, 2));
    }

    public function testThrowsIfNDoesNotEqualConfirmationsPlusOverturns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Wilson::computeScore(10, 8, 1);
    }

    public function testThrowsOnNegativeCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Wilson::computeScore(-1, 0, 0);
    }

    /** @dataProvider boundedCases */
    public function testScoreIsAlwaysInZeroOneRange(int $n, int $c, int $o): void
    {
        $score = Wilson::computeScore($n, $c, $o);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public static function boundedCases(): array
    {
        return [
            [5, 0, 5],
            [5, 5, 0],
            [50, 25, 25],
            [1000, 999, 1],
            [1000, 1, 999],
            [7, 3, 4],
        ];
    }

    public function testMonotonicInConfirmationsForFixedN(): void
    {
        foreach ([5, 6, 10, 25, 100] as $n) {
            $prev = -INF;
            for ($c = 0; $c <= $n; $c++) {
                $score = Wilson::computeScore($n, $c, $n - $c);
                $this->assertGreaterThan($prev, $score, "n={$n} c={$c}");
                $prev = $score;
            }
        }
    }
}
