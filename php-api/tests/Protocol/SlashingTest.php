<?php

declare(strict_types=1);

namespace Provenance\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Provenance\Protocol\Slashing;

final class SlashingTest extends TestCase
{
    private array $original;

    protected function setUp(): void
    {
        $this->original = [
            'claim_hash' => '0x' . str_repeat('01', 32),
            'validator_pubkey' => '0x' . str_repeat('aa', 32),
            'timestamp' => 1_000_000,
            'evidence_uri' => 'u',
        ];
    }

    private function auditEvent(array $overrides = []): array
    {
        return array_merge([
            'audit_verdict' => true,
            'timestamp' => $this->original['timestamp'],
            'stake_slashed' => 0,
        ], $overrides);
    }

    public function testDoesNotSlashWithZeroExistingOverturnsAndConfirmingPending(): void
    {
        $this->assertFalse(Slashing::shouldSlashForClaim($this->original, [], [
            'audit_verdict' => true, 'timestamp' => $this->original['timestamp'],
        ]));
    }

    public function testDoesNotSlashOnASingleOverturn(): void
    {
        $this->assertFalse(Slashing::shouldSlashForClaim($this->original, [], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'],
        ]));
    }

    public function testSlashesOnTheSecondOverturnWithinWindow(): void
    {
        $first = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 1000]);
        $result = Slashing::shouldSlashForClaim($this->original, [$first], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 2000,
        ]);
        $this->assertTrue($result);
    }

    public function testAConfirmDoesNotCountTowardTheThreshold(): void
    {
        $confirm = $this->auditEvent(['audit_verdict' => true, 'timestamp' => $this->original['timestamp'] + 1000]);
        $result = Slashing::shouldSlashForClaim($this->original, [$confirm], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 2000,
        ]);
        $this->assertFalse($result);
    }

    public function testDoesNotSlashWhenSecondOverturnFallsOutsideWindow(): void
    {
        $first = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 1000]);
        $result = Slashing::shouldSlashForClaim($this->original, [$first], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + Slashing::SLASH_WINDOW_MS + 1,
        ]);
        $this->assertFalse($result);
    }

    public function testSlashesWhenBothOverturnsLandExactlyAtWindowBoundary(): void
    {
        $first = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + Slashing::SLASH_WINDOW_MS]);
        $result = Slashing::shouldSlashForClaim($this->original, [$first], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + Slashing::SLASH_WINDOW_MS,
        ]);
        $this->assertTrue($result);
    }

    public function testDoesNotSlashAgainOnceAlreadySlashed(): void
    {
        $first = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 1000]);
        $second = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 2000, 'stake_slashed' => 5]);
        $result = Slashing::shouldSlashForClaim($this->original, [$first, $second], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 3000,
        ]);
        $this->assertFalse($result);
    }

    public function testThreeOrMoreQualifyingOverturnsStillTrigger(): void
    {
        $first = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 1000]);
        $second = $this->auditEvent(['audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 2000]);
        $result = Slashing::shouldSlashForClaim($this->original, [$first, $second], [
            'audit_verdict' => false, 'timestamp' => $this->original['timestamp'] + 3000,
        ]);
        $this->assertTrue($result);
    }
}
