<?php

declare(strict_types=1);

namespace Provenance\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Provenance\Domain\ClaimHash;

final class ClaimHashTest extends TestCase
{
    /**
     * Ground truth: keccak256(claim_text + "\x00" + evidence_uri + "\x00" +
     * timestamp), verified against the live TS reference server's actual
     * response — see php-api/verification/verify-keccak.php and
     * verify-ledger-chain.php for how this was established, including the
     * discovery that the delimiter is a real NUL byte, not the space its
     * own comment in src/domain/claimHash.ts claims.
     */
    public function testMatchesLiveTsServerGroundTruth(): void
    {
        $claimText =
            "On 2026-03-01, model checkpoint gpt-audit-7b was evaluated against the held-out SWE-bench-lite " .
            "split (300 tasks) using the standard agentic scaffold with a 50-step budget. The run resolved " .
            "217/300 tasks (72.3% pass@1), matching the previously reported internal benchmark within 0.4 " .
            "percentage points. Full transcripts, the evaluation harness commit hash (a1b2c3d), and the raw " .
            "per-task pass/fail matrix are attached at the evidence URI. No tasks were excluded or retried " .
            "beyond the harness's standard single-attempt protocol. Hardware: 8x A100 80GB, wall-clock 41 " .
            "minutes. This claim asserts the reported pass rate is accurate and reproducible from the attached " .
            "artifacts.";
        $evidenceUri = "https://evidence.example.org/runs/gpt-audit-7b-swebench-lite-2026-03-01.json";
        $timestamp = 1772000000000;

        $this->assertSame(
            '0xd3c4cede626e025ec901d22491b42e654ebe4d698023ff973fa267de9d32aa72',
            ClaimHash::compute($claimText, $evidenceUri, $timestamp),
        );
    }

    public function testDeterministic(): void
    {
        $a = ClaimHash::compute('text', 'uri', 1000);
        $b = ClaimHash::compute('text', 'uri', 1000);
        $this->assertSame($a, $b);
    }

    public function testDifferentInputsProduceDifferentHashes(): void
    {
        $a = ClaimHash::compute('text', 'uri', 1000);
        $b = ClaimHash::compute('text2', 'uri', 1000);
        $this->assertNotSame($a, $b);
    }

    public function testReturnsA32ByteHexHash(): void
    {
        $hash = ClaimHash::compute('text', 'uri', 1000);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $hash);
    }
}
