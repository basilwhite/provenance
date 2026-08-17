<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Ledger\Merkle;
use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class BatchRouteTest extends DbTestCase
{
    public function testAcceptsABatchOfClaims(): void
    {
        $validator = RequestHelpers::makeValidator();
        $specs = array_map(static fn($i) => ['evidenceUri' => "https://example.com/batch/{$i}"], range(0, 4));
        ['body' => $body, 'batchRoot' => $batchRoot] = RequestHelpers::buildBatchBody($validator, $specs, 1_772_000_000_000);

        $res = $this->dispatch('POST', '/submit/batch', $body);

        $this->assertSame(201, $res['status']);
        $this->assertSame($batchRoot, $res['body']['event']['batch_root']);
        $this->assertSame('batch', $res['body']['event']['type']);
        $this->assertCount(5, $res['body']['claim_hashes']);
    }

    public function testRejectsBatchLargerThan50(): void
    {
        $validator = RequestHelpers::makeValidator();
        $specs = array_map(static fn($i) => ['evidenceUri' => "https://example.com/batch/{$i}"], range(0, 50));
        ['body' => $body] = RequestHelpers::buildBatchBody($validator, $specs, 1_772_000_000_000);

        $res = $this->dispatch('POST', '/submit/batch', $body);
        $this->assertSame(400, $res['status']);
    }

    public function testRejectsBatchWithInvalidLeafSignature(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildBatchBody($validator, [[], [], []], 1_772_000_000_000);
        $body['claims'][1]['signature'] = '0x' . str_repeat('00', 64);

        $res = $this->dispatch('POST', '/submit/batch', $body);
        $this->assertSame(401, $res['status']);
    }

    public function testRejectsBatchWithShortClaimText(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildBatchBody($validator, [[], ['claimText' => RequestHelpers::longText(50)]], 1_772_000_000_000);

        $res = $this->dispatch('POST', '/submit/batch', $body);
        $this->assertSame(422, $res['status']);
    }

    public function testRejectsInvalidBatchSignature(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildBatchBody($validator, [[], []], 1_772_000_000_000);
        $body['batch_signature'] = '0x' . str_repeat('12', 64);

        $res = $this->dispatch('POST', '/submit/batch', $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('invalid_batch_signature', $res['body']['error']['code']);
    }

    public function testMerkleProofForAnyLeafValidatesAgainstBatchRoot(): void
    {
        $validator = RequestHelpers::makeValidator();
        $specs = array_map(static fn($i) => ['evidenceUri' => "https://example.com/batch/{$i}"], range(0, 6));
        ['body' => $body, 'claimHashes' => $claimHashes] = RequestHelpers::buildBatchBody($validator, $specs, 1_772_000_000_000);

        $submitRes = $this->dispatch('POST', '/submit/batch', $body);
        $this->assertSame(201, $submitRes['status']);

        foreach ($claimHashes as $claimHash) {
            $verifyRes = $this->dispatch('GET', "/verify/{$claimHash}");
            $this->assertSame(200, $verifyRes['status']);
            $valid = Merkle::verifyMerkleProof(
                $claimHash,
                $verifyRes['body']['merkle_proof']['path'],
                $verifyRes['body']['merkle_proof']['root'],
            );
            $this->assertTrue($valid);
        }
    }

    public function testCountsWholeBatchAsSingleSubmissionForRateLimit(): void
    {
        $validator = RequestHelpers::makeValidator();
        $specs1 = array_map(static fn($i) => ['evidenceUri' => "https://example.com/batch/{$i}"], range(0, 19));
        ['body' => $body1] = RequestHelpers::buildBatchBody($validator, $specs1, 1_772_000_000_000);
        $first = $this->dispatch('POST', '/submit/batch', $body1);
        $this->assertSame(201, $first['status']);

        $specs2 = array_map(static fn($i) => ['evidenceUri' => "https://example.com/batch2/{$i}"], range(0, 4));
        ['body' => $body2] = RequestHelpers::buildBatchBody($validator, $specs2, 1_772_000_000_001);
        $second = $this->dispatch('POST', '/submit/batch', $body2);
        $this->assertSame(201, $second['status']);
    }
}
