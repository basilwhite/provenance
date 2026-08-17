<?php

declare(strict_types=1);

namespace Provenance\Tests\Protocol;

use Provenance\Ledger\Store;
use Provenance\Protocol\RateLimit;
use Provenance\Tests\Support\DbTestCase;

final class RateLimitTest extends DbTestCase
{
    public function testRejectsTextShorterThanMinimum(): void
    {
        $this->assertFalse(RateLimit::meetsEvidenceComplexity(str_repeat('a', RateLimit::MIN_CLAIM_TEXT_CHARS - 1)));
    }

    public function testAcceptsTextAtExactlyMinimum(): void
    {
        $this->assertTrue(RateLimit::meetsEvidenceComplexity(str_repeat('a', RateLimit::MIN_CLAIM_TEXT_CHARS)));
    }

    public function testAcceptsTextLongerThanMinimum(): void
    {
        $this->assertTrue(RateLimit::meetsEvidenceComplexity(str_repeat('a', RateLimit::MIN_CLAIM_TEXT_CHARS + 100)));
    }

    public function testIsZeroForAValidatorWithNoSubmissions(): void
    {
        $this->assertSame(0, RateLimit::countRecentSubmissions($this->db, $this->hex('cc'), 1_000_000_000_000));
    }

    public function testCountsSubmissionsWithinTrailing24hWindow(): void
    {
        $store = new Store($this->db);
        $pubkey = $this->hex('cc');
        $now = 1_000_000_000_000;
        for ($i = 0; $i < 3; $i++) {
            $store->appendEvent([
                'type' => 'submission',
                'claim_hash' => '0x' . str_pad((string)$i, 2, '0', STR_PAD_LEFT) . str_repeat('0', 62),
                'evidence_uri' => 'u',
                'timestamp' => $now - 1000,
                'validator_pubkey' => $pubkey,
                'signature' => '0x' . str_repeat('11', 64),
            ]);
        }
        $this->assertSame(3, RateLimit::countRecentSubmissions($this->db, $pubkey, $now));
    }

    public function testExcludesSubmissionsOlderThanWindow(): void
    {
        $store = new Store($this->db);
        $pubkey = $this->hex('cc');
        $now = 1_000_000_000_000;
        $store->appendEvent([
            'type' => 'submission',
            'claim_hash' => $this->hex('01'),
            'evidence_uri' => 'u',
            'timestamp' => $now - RateLimit::RATE_LIMIT_WINDOW_MS - 1,
            'validator_pubkey' => $pubkey,
            'signature' => '0x' . str_repeat('11', 64),
        ]);
        $this->assertSame(0, RateLimit::countRecentSubmissions($this->db, $pubkey, $now));
    }

    public function testBatchCountsAsExactlyOneSubmission(): void
    {
        $store = new Store($this->db);
        $pubkey = $this->hex('cc');
        $now = 1_000_000_000_000;
        $store->appendEvent([
            'type' => 'batch',
            'claim_hash' => $this->hex('02'),
            'evidence_uri' => 'batch:50-claims',
            'timestamp' => $now - 1000,
            'validator_pubkey' => $pubkey,
            'signature' => '0x' . str_repeat('11', 64),
            'batch_root' => $this->hex('02'),
        ]);
        $this->assertSame(1, RateLimit::countRecentSubmissions($this->db, $pubkey, $now));
    }

    public function testAuditEventsDoNotCountTowardSubmissionRateLimit(): void
    {
        $store = new Store($this->db);
        $pubkey = $this->hex('cc');
        $now = 1_000_000_000_000;
        $store->appendEvent([
            'type' => 'audit',
            'claim_hash' => $this->hex('03'),
            'evidence_uri' => 'u',
            'timestamp' => $now - 1000,
            'validator_pubkey' => $pubkey,
            'signature' => '0x' . str_repeat('11', 64),
            'audit_ref' => $this->hex('03'),
            'audit_verdict' => true,
        ]);
        $this->assertSame(0, RateLimit::countRecentSubmissions($this->db, $pubkey, $now));
    }

    public function testMax10PerSpec(): void
    {
        $this->assertSame(10, RateLimit::MAX_SUBMISSIONS_PER_24H);
    }
}
