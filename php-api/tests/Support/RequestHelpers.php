<?php

declare(strict_types=1);

namespace Provenance\Tests\Support;

use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Domain\ClaimHash;
use Provenance\Ledger\Merkle;

final class RequestHelpers
{
    /** @return array{publicKey: string, privateKey: string, publicKeyHex: string} */
    public static function makeValidator(): array
    {
        $keys = Keys::generateKeyPair();
        return [
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
            'publicKeyHex' => Encoding::bytesToHex($keys['publicKey']),
        ];
    }

    public static function longText(int $n = 520): string
    {
        $unit = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ';
        $repeated = str_repeat($unit, (int)ceil($n / strlen($unit)));
        return substr($repeated, 0, $n);
    }

    /** @return array{body: array, claimHash: string} */
    public static function buildSubmitBody(array $validator, array $opts = []): array
    {
        $claimText = $opts['claimText'] ?? self::longText();
        $evidenceUri = $opts['evidenceUri'] ?? 'https://example.com/evidence/1';
        $timestamp = $opts['timestamp'] ?? (int)(microtime(true) * 1000);

        $claimHash = ClaimHash::compute($claimText, $evidenceUri, $timestamp);
        $signature = Keys::sign(Messages::claimTimestampMessage($claimHash, $timestamp), $validator['privateKey']);

        return [
            'body' => [
                'claim_text' => $claimText,
                'evidence_uri' => $evidenceUri,
                'timestamp' => $timestamp,
                'validator_pubkey' => $validator['publicKeyHex'],
                'signature' => Encoding::bytesToHex($signature),
            ],
            'claimHash' => $claimHash,
        ];
    }

    public static function buildAuditBody(array $validator, string $claimHash, bool $verdict, int $timestamp): array
    {
        $signature = Keys::sign(Messages::claimTimestampMessage($claimHash, $timestamp), $validator['privateKey']);
        return [
            'claim_hash' => $claimHash,
            'audit_verdict' => $verdict,
            'timestamp' => $timestamp,
            'validator_pubkey' => $validator['publicKeyHex'],
            'signature' => Encoding::bytesToHex($signature),
        ];
    }

    /** @return array{body: array, claimHashes: string[], batchRoot: string} */
    public static function buildBatchBody(array $validator, array $claimsSpec, int $batchTimestamp): array
    {
        $leaves = [];
        foreach ($claimsSpec as $i => $spec) {
            $claimText = $spec['claimText'] ?? self::longText();
            $evidenceUri = $spec['evidenceUri'] ?? "https://example.com/evidence/batch-{$i}";
            $timestamp = $spec['timestamp'] ?? $batchTimestamp;
            $claimHash = ClaimHash::compute($claimText, $evidenceUri, $timestamp);
            $signature = Keys::sign(Messages::claimTimestampMessage($claimHash, $timestamp), $validator['privateKey']);
            $leaves[] = [
                'claim_text' => $claimText,
                'evidence_uri' => $evidenceUri,
                'timestamp' => $timestamp,
                'signature' => Encoding::bytesToHex($signature),
                'claimHash' => $claimHash,
            ];
        }

        $claimHashes = array_column($leaves, 'claimHash');
        $batchRoot = Merkle::buildMerkleTree($claimHashes)['root'];
        $batchSignature = Keys::sign(Messages::batchMessage($batchRoot, $batchTimestamp), $validator['privateKey']);

        return [
            'body' => [
                'validator_pubkey' => $validator['publicKeyHex'],
                'timestamp' => $batchTimestamp,
                'batch_signature' => Encoding::bytesToHex($batchSignature),
                'claims' => array_map(
                    static fn(array $l) => [
                        'claim_text' => $l['claim_text'],
                        'evidence_uri' => $l['evidence_uri'],
                        'timestamp' => $l['timestamp'],
                        'signature' => $l['signature'],
                    ],
                    $leaves,
                ),
            ],
            'claimHashes' => $claimHashes,
            'batchRoot' => $batchRoot,
        ];
    }
}
