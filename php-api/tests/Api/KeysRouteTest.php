<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Tests\Support\DbTestCase;
use Provenance\Tests\Support\RequestHelpers;

final class KeysRouteTest extends DbTestCase
{
    private function buildRotationBody(array $oldValidator, array $newValidator): array
    {
        $signature = Keys::sign(
            Messages::rotationMessage($oldValidator['publicKeyHex'], $newValidator['publicKeyHex']),
            $oldValidator['privateKey'],
        );
        return [
            'old_pubkey' => $oldValidator['publicKeyHex'],
            'new_pubkey' => $newValidator['publicKeyHex'],
            'rotation_signature' => Encoding::bytesToHex($signature),
        ];
    }

    public function testAcceptsAValidlySignedRotation(): void
    {
        $oldV = RequestHelpers::makeValidator();
        $newV = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/keys/rotate', $this->buildRotationBody($oldV, $newV));

        $this->assertSame(201, $res['status']);
        $this->assertSame($oldV['publicKeyHex'], $res['body']['event']['old_pubkey']);
        $this->assertSame($newV['publicKeyHex'], $res['body']['event']['new_pubkey']);
    }

    public function testRejectsRotationWithInvalidSignature(): void
    {
        $oldV = RequestHelpers::makeValidator();
        $newV = RequestHelpers::makeValidator();
        $body = $this->buildRotationBody($oldV, $newV);
        $body['rotation_signature'] = '0x' . str_repeat('00', 64);

        $res = $this->dispatch('POST', '/keys/rotate', $body);
        $this->assertSame(401, $res['status']);
    }

    public function testRejectsDuplicateRotationOfSameOldKey(): void
    {
        $oldV = RequestHelpers::makeValidator();
        $newV = RequestHelpers::makeValidator();
        $anotherNewV = RequestHelpers::makeValidator();

        $first = $this->dispatch('POST', '/keys/rotate', $this->buildRotationBody($oldV, $newV));
        $this->assertSame(201, $first['status']);

        $second = $this->dispatch('POST', '/keys/rotate', $this->buildRotationBody($oldV, $anotherNewV));
        $this->assertSame(409, $second['status']);
        $this->assertSame('duplicate_rotation', $second['body']['error']['code']);
    }

    public function testTreatsHistoryAcrossOldAndNewKeysAsContinuous(): void
    {
        $oldV = RequestHelpers::makeValidator();
        $newV = RequestHelpers::makeValidator();

        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($oldV);
        $this->dispatch('POST', '/submit', $submitBody);

        $auditors = array_map(static fn() => RequestHelpers::makeValidator(), range(0, 4));
        foreach ($auditors as $i => $auditor) {
            $this->dispatch('POST', '/audit', RequestHelpers::buildAuditBody($auditor, $claimHash, true, $submitBody['timestamp'] + 1000 * ($i + 1)));
        }

        $preRotation = $this->dispatch('GET', "/validators/{$oldV['publicKeyHex']}/score");
        $this->assertSame(5, $preRotation['body']['n']);

        $this->dispatch('POST', '/keys/rotate', $this->buildRotationBody($oldV, $newV));

        $byOld = $this->dispatch('GET', "/validators/{$oldV['publicKeyHex']}/score");
        $byNew = $this->dispatch('GET', "/validators/{$newV['publicKeyHex']}/score");
        $this->assertSame(5, $byOld['body']['n']);
        $this->assertSame(5, $byNew['body']['n']);
        $this->assertSame($byOld['body']['score'], $byNew['body']['score']);
    }

    public function testRejectsRotatingAKeyToItself(): void
    {
        $v = RequestHelpers::makeValidator();
        $res = $this->dispatch('POST', '/keys/rotate', $this->buildRotationBody($v, $v));
        $this->assertSame(400, $res['status']);
    }
}
