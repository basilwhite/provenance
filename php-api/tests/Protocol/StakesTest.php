<?php

declare(strict_types=1);

namespace Provenance\Tests\Protocol;

use Provenance\Protocol\Stakes;
use Provenance\Tests\Support\DbTestCase;

final class StakesTest extends DbTestCase
{
    public function testReturnsAZeroedRecordForAnUnknownValidator(): void
    {
        $stakes = new Stakes($this->db);
        $pubkey = $this->hex('aa');
        $this->assertSame(
            ['validator_pubkey' => $pubkey, 'amount_locked' => 0, 'amount_slashed' => 0],
            $stakes->get($pubkey),
        );
    }

    public function testEnsureProvisionedAutoFundsANewValidatorAboveMinimum(): void
    {
        $stakes = new Stakes($this->db);
        $pubkey = $this->hex('aa');
        $record = $stakes->ensureProvisioned($pubkey);
        $this->assertSame(Stakes::DEFAULT_INITIAL_STAKE, $record['amount_locked']);
        $this->assertGreaterThanOrEqual(Stakes::MIN_STAKE_REQUIRED, $record['amount_locked']);
        $this->assertTrue($stakes->hasMinimumStake($pubkey));
    }

    public function testEnsureProvisionedIsIdempotent(): void
    {
        $stakes = new Stakes($this->db);
        $pubkey = $this->hex('aa');
        $stakes->ensureProvisioned($pubkey);
        $stakes->slash($pubkey);
        $afterSlash = $stakes->get($pubkey)['amount_locked'];
        $record = $stakes->ensureProvisioned($pubkey);
        $this->assertSame($afterSlash, $record['amount_locked']);
    }

    public function testSlashBurnsFractionOfLockedStake(): void
    {
        $stakes = new Stakes($this->db);
        $pubkey = $this->hex('aa');
        $stakes->ensureProvisioned($pubkey);
        $slashed = $stakes->slash($pubkey);
        $this->assertSame((int)floor(Stakes::DEFAULT_INITIAL_STAKE * Stakes::SLASH_FRACTION), $slashed);
        $record = $stakes->get($pubkey);
        $this->assertSame(Stakes::DEFAULT_INITIAL_STAKE - $slashed, $record['amount_locked']);
        $this->assertSame($slashed, $record['amount_slashed']);
    }

    public function testAValidatorWithNoStakeFailsTheMinimumCheck(): void
    {
        $stakes = new Stakes($this->db);
        $this->assertFalse($stakes->hasMinimumStake($this->hex('aa')));
    }

    public function testRepeatedSlashingReachesExactlyZero(): void
    {
        $stakes = new Stakes($this->db);
        $pubkey = $this->hex('aa');
        $stakes->ensureProvisioned($pubkey);
        for ($i = 0; $i < 10; $i++) {
            $stakes->slash($pubkey);
        }
        $this->assertSame(0, $stakes->get($pubkey)['amount_locked']);
        $this->assertFalse($stakes->hasMinimumStake($pubkey));
    }
}
