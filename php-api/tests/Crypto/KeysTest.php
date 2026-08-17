<?php

declare(strict_types=1);

namespace Provenance\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use Provenance\Crypto\Keys;

final class KeysTest extends TestCase
{
    public function testRoundTripsSignVerifyForAValidSignature(): void
    {
        $keys = Keys::generateKeyPair();
        $message = 'hello provenance';
        $signature = Keys::sign($message, $keys['privateKey']);
        $this->assertTrue(Keys::verify($message, $signature, $keys['publicKey']));
    }

    public function testRejectsASignatureFromTheWrongKey(): void
    {
        $a = Keys::generateKeyPair();
        $b = Keys::generateKeyPair();
        $message = 'hello provenance';
        $signature = Keys::sign($message, $a['privateKey']);
        $this->assertFalse(Keys::verify($message, $signature, $b['publicKey']));
    }

    public function testRejectsATamperedMessage(): void
    {
        $keys = Keys::generateKeyPair();
        $signature = Keys::sign('original message', $keys['privateKey']);
        $this->assertFalse(Keys::verify('tampered message', $signature, $keys['publicKey']));
    }

    public function testRejectsGarbageSignaturesInsteadOfThrowing(): void
    {
        $keys = Keys::generateKeyPair();
        $garbage = str_repeat("\xab", 64);
        $this->assertFalse(Keys::verify('hello provenance', $garbage, $keys['publicKey']));
    }

    public function testRejectsMalformedWrongLengthSignatures(): void
    {
        $keys = Keys::generateKeyPair();
        $malformed = str_repeat("\x01", 3);
        $this->assertFalse(Keys::verify('hello provenance', $malformed, $keys['publicKey']));
    }

    public function testRejectsMalformedWrongLengthPublicKey(): void
    {
        $keys = Keys::generateKeyPair();
        $signature = Keys::sign('hello provenance', $keys['privateKey']);
        $this->assertFalse(Keys::verify('hello provenance', $signature, str_repeat("\x02", 5)));
    }

    public function testDerivesTheSamePublicKeyGenerateKeyPairProduced(): void
    {
        $keys = Keys::generateKeyPair();
        $this->assertSame($keys['publicKey'], Keys::getPublicKey($keys['privateKey']));
    }

    public function testGeneratesDistinctKeyPairsOnEachCall(): void
    {
        $a = Keys::generateKeyPair();
        $b = Keys::generateKeyPair();
        $this->assertNotSame($a['privateKey'], $b['privateKey']);
        $this->assertNotSame($a['publicKey'], $b['publicKey']);
    }

    public function testPublicKeyIs32Bytes(): void
    {
        $keys = Keys::generateKeyPair();
        $this->assertSame(32, strlen($keys['publicKey']));
    }

    public function testSignaturesAreDeterministic(): void
    {
        $keys = Keys::generateKeyPair();
        $sig1 = Keys::sign('same message', $keys['privateKey']);
        $sig2 = Keys::sign('same message', $keys['privateKey']);
        $this->assertSame($sig1, $sig2);
    }
}
