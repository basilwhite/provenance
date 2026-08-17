<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Protocol\Slashing;
use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class AuditRouteTest extends DbTestCase
{
    private function submitClaim(?array $submitter = null, ?int $timestamp = null): array
    {
        $submitter ??= RequestHelpers::makeValidator();
        $opts = $timestamp !== null ? ['timestamp' => $timestamp] : [];
        ['body' => $body, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter, $opts);
        $res = $this->dispatch('POST', '/submit', $body);
        $this->assertSame(201, $res['status']);
        return ['submitter' => $submitter, 'claimHash' => $claimHash, 'submitTimestamp' => $body['timestamp']];
    }

    public function testRejectsSelfAudit(): void
    {
        ['submitter' => $submitter, 'claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditBody = RequestHelpers::buildAuditBody($submitter, $claimHash, true, $ts + 1000);

        $res = $this->dispatch('POST', '/audit', $auditBody);
        $this->assertSame(403, $res['status']);
        $this->assertSame('self_audit_forbidden', $res['body']['error']['code']);
    }

    public function testAcceptsAValidConfirmingAudit(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, true, $ts + 1000));

        $this->assertSame(201, $res['status']);
        $this->assertSame($claimHash, $res['body']['event']['audit_ref']);
        $this->assertTrue($res['body']['event']['audit_verdict']);
    }

    public function testAcceptsAValidOverturningAudit(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, false, $ts + 1000));

        $this->assertSame(201, $res['status']);
        $this->assertFalse($res['body']['event']['audit_verdict']);
    }

    public function testRejectsInvalidSignature(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $other = RequestHelpers::makeValidator();
        $auditBody = RequestHelpers::buildAuditBody($auditor, $claimHash, true, $ts + 1000);
        $auditBody['validator_pubkey'] = $other['publicKeyHex'];

        $res = $this->dispatch('POST', '/audit', $auditBody);
        $this->assertSame(401, $res['status']);
    }

    public function test404sOnUnknownClaimHash(): void
    {
        $auditor = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $this->hex('ee'), true, 1000));
        $this->assertSame(404, $res['status']);
    }

    public function testRejectsDuplicateAuditFromSameValidator(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $first = RequestHelpers::buildAuditBody($auditor, $claimHash, true, $ts + 1000);
        $this->dispatch('POST', '/audit', $first);

        $second = RequestHelpers::buildAuditBody($auditor, $claimHash, false, $ts + 2000);
        $res = $this->dispatch('POST', '/audit', $second);

        $this->assertSame(409, $res['status']);
        $this->assertSame('duplicate_audit', $res['body']['error']['code']);
    }

    public function testDoesNotChangeScoreAfterOnlyOneAudit(): void
    {
        ['submitter' => $submitter, 'claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, true, $ts + 1000));

        $scoreRes = $this->dispatch('GET', "/validators/{$submitter['publicKeyHex']}/score");
        $this->assertSame(0, $scoreRes['body']['n']);
        $this->assertSame(0.5, $scoreRes['body']['score']);
    }

    public function testChangesNAfterSecondAudit(): void
    {
        ['submitter' => $submitter, 'claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $a1 = RequestHelpers::makeValidator();
        $a2 = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a1, $claimHash, true, $ts + 1000));
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a2, $claimHash, true, $ts + 2000));

        $scoreRes = $this->dispatch('GET', "/validators/{$submitter['publicKeyHex']}/score");
        $this->assertSame(2, $scoreRes['body']['n']);
    }

    public function testDoesNotSlashAfterASingleOverturn(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $auditor = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, false, $ts + 1000));
        $this->assertSame(0, $res['body']['slashed_amount']);
    }

    public function testSlashesAfterTwoIndependentOverturnsWithinWindow(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $a1 = RequestHelpers::makeValidator();
        $a2 = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a1, $claimHash, false, $ts + 1000));
        $second = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a2, $claimHash, false, $ts + 2000));

        $this->assertGreaterThan(0, $second['body']['slashed_amount']);
    }

    public function testDoesNotSlashIfSecondOverturnArrivesAfter7DayWindow(): void
    {
        ['claimHash' => $claimHash, 'submitTimestamp' => $ts] = $this->submitClaim();
        $a1 = RequestHelpers::makeValidator();
        $a2 = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a1, $claimHash, false, $ts + 1000));
        $late = $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a2, $claimHash, false, $ts + Slashing::SLASH_WINDOW_MS + 10000));

        $this->assertSame(0, $late['body']['slashed_amount']);
    }
}
