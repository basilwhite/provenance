<?php

declare(strict_types=1);

namespace Provenance\Crypto;

/**
 * Ed25519 via libsodium (ext-sodium), cross-verified byte-for-byte against
 * the TS reference's @noble/curves implementation before this was trusted
 * (see php-api/verify-ed25519.php). "privateKey" here is always the raw
 * 32-byte seed, matching noble/curves' convention — NOT libsodium's own
 * 64-byte (seed||pubkey) secret key format, which is an internal detail
 * this class hides.
 */
final class Keys
{
    /** @return array{publicKey: string, privateKey: string} raw byte strings */
    public static function generateKeyPair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        // libsodium's Ed25519 secret key format is (32-byte seed || 32-byte
        // pubkey); there's no dedicated sk_to_seed function in PHP's ext-sodium
        // (verified directly — see php-api/verification/verify-ed25519.php),
        // so the seed is just the first 32 bytes. Confirmed via round-trip:
        // seed_keypair(seed) -> secretkey -> substr(0,32) reproduces the seed.
        $seed = substr($secretKey, 0, 32);

        return ['publicKey' => $publicKey, 'privateKey' => $seed];
    }

    public static function sign(string $message, string $privateKeySeed): string
    {
        $keypair = sodium_crypto_sign_seed_keypair($privateKeySeed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        return sodium_crypto_sign_detached($message, $secretKey);
    }

    /** Never throws — malformed signatures/keys are treated as a failed verification, not an error. */
    public static function verify(string $message, string $signature, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    public static function getPublicKey(string $privateKeySeed): string
    {
        $keypair = sodium_crypto_sign_seed_keypair($privateKeySeed);
        return sodium_crypto_sign_publickey($keypair);
    }
}
