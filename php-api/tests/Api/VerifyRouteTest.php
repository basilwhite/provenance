<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Ledger\Hash;
use Provenance\Ledger\Merkle;
use Provenance\Scoring\Wilson;
use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class VerifyRouteTest extends DbTestCase
{
    public function test404sForUnknownClaimHash(): void
    {
        $res = $this->dispatch('GET', '/verify/' . $this->hex('ee'));
        $this->assertSame(404, $res['status']);
    }

    public function testReturnsUnfilteredHistoryScoreAndValidProof(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $this->dispatch('POST', '/submit', $submitBody);

        $a1 = RequestHelpers::makeValidator();
        $a2 = RequestHelpers::makeValidator();
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a1, $claimHash, true, $submitBody['timestamp'] + 1000));
        $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($a2, $claimHash, false, $submitBody['timestamp'] + 2000));

        $res = $this->dispatch('GET', "/verify/{$claimHash}");

        $this->assertSame(200, $res['status']);
        $this->assertSame($submitter['publicKeyHex'], $res['body']['validator_pubkey']);
        $this->assertCount(3, $res['body']['events']);
        $this->assertSame($claimHash, $res['body']['merkle_proof']['claim_hash']);
        $this->assertEqualsWithDelta(Wilson::computeScore(2, 1, 1), $res['body']['current_score'], 1e-12);
    }

    public function testMerkleProofVerifiesAgainstReportedRoot(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $this->dispatch('POST', '/submit', $submitBody);

        $res = $this->dispatch('GET', "/verify/{$claimHash}");
        $path = $res['body']['merkle_proof']['path'];
        $root = $res['body']['merkle_proof']['root'];

        $event = null;
        foreach ($res['body']['events'] as $e) {
            if ($e['claim_hash'] === $claimHash && $e['type'] === 'submission') {
                $event = $e;
                break;
            }
        }
        $this->assertNotNull($event);
        $leaf = Hash::computeLedgerLeaf($event);

        $this->assertTrue(Merkle::verifyMerkleProof($leaf, $path, $root));
    }

    public function testEventsListIncludesBothSubmissionsUnfiltered(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody1, 'claimHash' => $claim1] = RequestHelpers::buildSubmitBody($submitter, ['evidenceUri' => 'https://example.com/1']);
        $this->dispatch('POST', '/submit', $submitBody1);
        ['body' => $submitBody2] = RequestHelpers::buildSubmitBody($submitter, ['evidenceUri' => 'https://example.com/2']);
        $this->dispatch('POST', '/submit', $submitBody2);

        $res = $this->dispatch('GET', "/verify/{$claim1}");
        $this->assertCount(2, $res['body']['events']);
    }
}
