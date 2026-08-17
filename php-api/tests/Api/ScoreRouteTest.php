<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Scoring\Wilson;
use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class ScoreRouteTest extends DbTestCase
{
    public function testReturnsNeutralPriorForUnknownValidator(): void
    {
        $validator = RequestHelpers::makeValidator();
        $res = $this->dispatch('GET', "/validators/{$validator['publicKeyHex']}/score");
        $this->assertSame(200, $res['status']);
        $this->assertSame(0.5, $res['body']['score']);
        $this->assertSame(0, $res['body']['n']);
    }

    public function testRejectsMalformedPubkey(): void
    {
        $res = $this->dispatch('GET', '/validators/not-a-pubkey/score');
        $this->assertSame(400, $res['status']);
    }

    public function testMatchesOfflineRecomputableScoreAfterSubmitAndAudits(): void
    {
        $submitter = RequestHelpers::makeValidator();
        $auditors = array_map(static fn() => RequestHelpers::makeValidator(), range(0, 4));

        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $this->dispatch('POST', '/submit', $submitBody);

        $verdicts = [true, true, true, true, false];
        foreach ($auditors as $i => $auditor) {
            $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, $verdicts[$i], $submitBody['timestamp'] + 1000 * ($i + 1)));
        }

        $res = $this->dispatch('GET', "/validators/{$submitter['publicKeyHex']}/score");
        $this->assertSame(5, $res['body']['n']);
        $this->assertSame(4, $res['body']['confirmations']);
        $this->assertSame(1, $res['body']['overturns']);
        $this->assertEqualsWithDelta(Wilson::computeScore(5, 4, 1), $res['body']['score'], 1e-12);
    }

    public function testEventsEndpointReturnsHistoryAndReportedScore(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $this->dispatch('POST', '/submit', $submitBody);

        $a1 = RequestHelpers::makeValidator();
        $a2 = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a1, $claimHash, true, $submitBody['timestamp'] + 1000));
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a2, $claimHash, true, $submitBody['timestamp'] + 2000));

        $res = $this->dispatch('GET', "/validators/{$submitter['publicKeyHex']}/events");

        $this->assertSame(200, $res['status']);
        $this->assertSame($submitter['publicKeyHex'], $res['body']['validator_pubkey']);
        $this->assertCount(3, $res['body']['events']);
        $this->assertSame(0.5, $res['body']['reported_score']);
    }

    public function testEventsEndpointRejectsMalformedPubkey(): void
    {
        $res = $this->dispatch('GET', '/validators/not-a-pubkey/events');
        $this->assertSame(400, $res['status']);
    }

    public function testEventsEndpointEmptyForUnknownValidator(): void
    {
        $validator = RequestHelpers::makeValidator();
        $res = $this->dispatch('GET', "/validators/{$validator['publicKeyHex']}/events");
        $this->assertSame(200, $res['status']);
        $this->assertSame([], $res['body']['events']);
    }
}
