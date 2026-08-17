<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class SubmitRouteTest extends DbTestCase
{
    public function testAcceptsAValidSubmission(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($validator);

        $res = $this->dispatch('POST', '/submit', $body);

        $this->assertSame(201, $res['status']);
        $this->assertSame($claimHash, $res['body']['event']['claim_hash']);
        $this->assertNull($res['body']['event']['audit_ref']);
        $this->assertNull($res['body']['event']['audit_verdict']);
        $this->assertSame($validator['publicKeyHex'], $res['body']['event']['validator_pubkey']);
    }

    public function testRejectsInvalidSignature(): void
    {
        $validator = RequestHelpers::makeValidator();
        $other = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildSubmitBody($validator);
        $body['validator_pubkey'] = $other['publicKeyHex'];

        $res = $this->dispatch('POST', '/submit', $body);

        $this->assertSame(401, $res['status']);
        $this->assertSame('invalid_signature', $res['body']['error']['code']);
    }

    public function testRejectsShortClaimText(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildSubmitBody($validator, ['claimText' => RequestHelpers::longText(100)]);

        $res = $this->dispatch('POST', '/submit', $body);

        $this->assertSame(422, $res['status']);
        $this->assertSame('evidence_too_short', $res['body']['error']['code']);
    }

    public function testEnforcesRateLimit(): void
    {
        $validator = RequestHelpers::makeValidator();

        for ($i = 0; $i < 10; $i++) {
            ['body' => $body] = RequestHelpers::buildSubmitBody($validator, ['evidenceUri' => "https://example.com/e/{$i}"]);
            $res = $this->dispatch('POST', '/submit', $body);
            $this->assertSame(201, $res['status']);
        }

        ['body' => $eleventh] = RequestHelpers::buildSubmitBody($validator, ['evidenceUri' => 'https://example.com/e/11']);
        $res = $this->dispatch('POST', '/submit', $eleventh);

        $this->assertSame(429, $res['status']);
        $this->assertSame('rate_limit_exceeded', $res['body']['error']['code']);
    }

    public function testRejectsRequestMissingRequiredFields(): void
    {
        $res = $this->dispatch('POST', '/submit', ['claim_text' => 'too short a body']);
        $this->assertSame(400, $res['status']);
    }

    public function testComputesClaimHashAsKeccak256(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($validator, [
            'claimText' => RequestHelpers::longText(),
            'evidenceUri' => 'https://example.com/fixed',
            'timestamp' => 1700000000000,
        ]);

        $res = $this->dispatch('POST', '/submit', $body);
        $this->assertSame(201, $res['status']);
        $this->assertSame($claimHash, $res['body']['event']['claim_hash']);
    }

    public function testAutoProvisionsStakeForFirstTimeValidator(): void
    {
        $validator = RequestHelpers::makeValidator();
        ['body' => $body] = RequestHelpers::buildSubmitBody($validator);
        $res = $this->dispatch('POST', '/submit', $body);
        $this->assertSame(201, $res['status']);
        $this->assertGreaterThan(0, $res['body']['event']['stake_locked']);
    }
}
