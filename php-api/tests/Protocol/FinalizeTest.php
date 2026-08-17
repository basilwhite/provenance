<?php

declare(strict_types=1);

namespace Provenance\Tests\Protocol;

use Provenance\Ledger\Store as LedgerStore;
use Provenance\Protocol\Finalize;
use Provenance\Scoring\ScoreStore;
use Provenance\Scoring\Wilson;
use Provenance\Tests\Support\DbTestCase;

final class FinalizeTest extends DbTestCase
{
    private LedgerStore $ledger;
    private ScoreStore $scores;
    private string $submitter;
    private string $auditor1;
    private string $auditor2;
    private string $auditor3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new LedgerStore($this->db);
        $this->scores = new ScoreStore($this->db);
        $this->submitter = $this->hex('aa');
        $this->auditor1 = $this->hex('b1');
        $this->auditor2 = $this->hex('b2');
        $this->auditor3 = $this->hex('b3');
    }

    private function submit(string $claimHash): array
    {
        return $this->ledger->appendEvent([
            'type' => 'submission',
            'claim_hash' => $claimHash,
            'evidence_uri' => 'u',
            'timestamp' => 1000,
            'validator_pubkey' => $this->submitter,
            'signature' => '0x' . str_repeat('11', 64),
        ]);
    }

    private function audit(string $claimHash, string $auditor, bool $verdict): array
    {
        return $this->ledger->appendEvent([
            'type' => 'audit',
            'claim_hash' => $claimHash,
            'evidence_uri' => 'u',
            'timestamp' => 2000,
            'validator_pubkey' => $auditor,
            'signature' => '0x' . str_repeat('22', 64),
            'audit_ref' => $claimHash,
            'audit_verdict' => $verdict,
        ]);
    }

    public function testDoesNotUpdateScoreWithZeroAudits(): void
    {
        $claim = $this->submit($this->hex('01'));
        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claim['claim_hash']);
        $this->assertFalse($result['updated']);
        $this->assertSame(0.5, $this->scores->get($this->submitter)['score']);
    }

    public function testDoesNotUpdateScoreWithOnlyOneAudit(): void
    {
        $claim = $this->submit($this->hex('02'));
        $this->audit($claim['claim_hash'], $this->auditor1, true);
        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claim['claim_hash']);
        $this->assertFalse($result['updated']);
        $this->assertSame(0, $this->scores->get($this->submitter)['n']);
    }

    public function testUpdatesScoreOnceClaimReaches2Audits(): void
    {
        $claim = $this->submit($this->hex('03'));
        $this->audit($claim['claim_hash'], $this->auditor1, true);
        $this->audit($claim['claim_hash'], $this->auditor2, true);
        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claim['claim_hash']);
        $this->assertTrue($result['updated']);
        $this->assertSame(2, $result['n']);
        $this->assertSame(2, $result['confirmations']);
        $this->assertSame(0, $result['overturns']);
        $this->assertEqualsWithDelta(Wilson::computeScore(2, 2, 0), $this->scores->get($this->submitter)['score'], 1e-12);
    }

    public function testAdditionalAuditsAfterFinalizationStillAffectScore(): void
    {
        $claim = $this->submit($this->hex('04'));
        $auditor4 = $this->hex('b4');
        $auditor5 = $this->hex('b5');

        $this->audit($claim['claim_hash'], $this->auditor1, true);
        $this->audit($claim['claim_hash'], $this->auditor2, true);
        $first = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claim['claim_hash']);
        $this->assertSame(2, $first['n']);
        $this->assertSame(0.5, $first['score']);

        $this->audit($claim['claim_hash'], $this->auditor3, true);
        $this->audit($claim['claim_hash'], $auditor4, true);
        $this->audit($claim['claim_hash'], $auditor5, false);
        $second = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claim['claim_hash']);

        $this->assertTrue($second['updated']);
        $this->assertSame(5, $second['n']);
        $this->assertSame(4, $second['confirmations']);
        $this->assertSame(1, $second['overturns']);
        $this->assertNotSame($first['score'], $second['score']);
        $this->assertEqualsWithDelta(Wilson::computeScore(5, 4, 1), $second['score'], 1e-12);
    }

    public function testAggregatesAcrossMultipleFinalizedClaims(): void
    {
        $claimA = $this->submit($this->hex('05'));
        $this->audit($claimA['claim_hash'], $this->auditor1, true);
        $this->audit($claimA['claim_hash'], $this->auditor2, true);
        Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claimA['claim_hash']);

        $claimB = $this->submit($this->hex('06'));
        $this->audit($claimB['claim_hash'], $this->auditor1, false);
        $this->audit($claimB['claim_hash'], $this->auditor2, false);
        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claimB['claim_hash']);

        $this->assertSame(4, $result['n']);
        $this->assertSame(2, $result['confirmations']);
        $this->assertSame(2, $result['overturns']);
    }

    public function testUnfinalizedClaimDoesNotContributeToAggregate(): void
    {
        $claimA = $this->submit($this->hex('07'));
        $this->audit($claimA['claim_hash'], $this->auditor1, true);
        $this->audit($claimA['claim_hash'], $this->auditor2, true);
        Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claimA['claim_hash']);

        $claimB = $this->submit($this->hex('08'));
        $this->audit($claimB['claim_hash'], $this->auditor1, false);

        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $claimB['claim_hash']);
        $this->assertFalse($result['updated']);
        $this->assertSame(2, $this->scores->get($this->submitter)['n']);
    }

    public function testReturnsNotUpdatedForUnknownClaimHash(): void
    {
        $result = Finalize::finalizeClaimIfReady($this->ledger, $this->scores, $this->hex('ee'));
        $this->assertFalse($result['updated']);
    }
}
