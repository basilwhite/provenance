<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\ApiException;
use Provenance\Api\Validators;
use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Domain\ClaimHash;
use Provenance\Ledger\Merkle;
use Provenance\Ledger\Store as LedgerStore;
use Provenance\Protocol\RateLimit;
use Provenance\Protocol\Stakes;

/** Mirrors src/api/routes/batch.ts (F5.3). */
final class BatchRoute
{
    private const MAX_BATCH_SIZE = 50;

    public static function handle(\PDO $db, array $body): array
    {
        $validatorPubkey = Validators::requirePubkeyHex($body['validator_pubkey'] ?? null, 'validator_pubkey');
        $timestamp = Validators::requireNumber($body['timestamp'] ?? null, 'timestamp');
        $batchSignature = Validators::requireSignatureHex($body['batch_signature'] ?? null, 'batch_signature');

        $rawClaims = $body['claims'] ?? null;
        if (!is_array($rawClaims) || count($rawClaims) === 0) {
            throw new ApiException(400, 'invalid_request', 'claims must be a non-empty array');
        }
        if (count($rawClaims) > self::MAX_BATCH_SIZE) {
            throw new ApiException(400, 'invalid_request', 'claims cannot exceed ' . self::MAX_BATCH_SIZE . ' items');
        }

        $claims = [];
        foreach (array_values($rawClaims) as $i => $raw) {
            try {
                $claims[] = [
                    'claim_text' => Validators::requireString($raw['claim_text'] ?? null, 'claim_text'),
                    'evidence_uri' => Validators::requireString($raw['evidence_uri'] ?? null, 'evidence_uri'),
                    'timestamp' => Validators::requireNumber($raw['timestamp'] ?? null, 'timestamp'),
                    'signature' => Validators::requireSignatureHex($raw['signature'] ?? null, 'signature'),
                ];
            } catch (ApiException $e) {
                throw new ApiException($e->status, $e->errorCode, "claims[{$i}]: {$e->getMessage()}");
            }
        }

        $claimHashes = array_map(
            static fn(array $c) => ClaimHash::compute($c['claim_text'], $c['evidence_uri'], $c['timestamp']),
            $claims,
        );

        foreach ($claims as $i => $c) {
            $msg = Messages::claimTimestampMessage($claimHashes[$i], $c['timestamp']);
            $ok = Keys::verify($msg, Encoding::hexToBytes($c['signature']), Encoding::hexToBytes($validatorPubkey));
            if (!$ok) {
                throw new ApiException(401, 'invalid_signature', "claims[{$i}]: signature does not verify against validator_pubkey");
            }
        }

        foreach ($claims as $i => $c) {
            if (!RateLimit::meetsEvidenceComplexity($c['claim_text'])) {
                throw new ApiException(
                    422,
                    'evidence_too_short',
                    sprintf('claims[%d]: claim_text must be at least %d characters (got %d)', $i, RateLimit::MIN_CLAIM_TEXT_CHARS, mb_strlen($c['claim_text'], 'UTF-8')),
                );
            }
        }

        $batchRoot = Merkle::buildMerkleTree($claimHashes)['root'];

        $batchSigValid = Keys::verify(
            Messages::batchMessage($batchRoot, $timestamp),
            Encoding::hexToBytes($batchSignature),
            Encoding::hexToBytes($validatorPubkey),
        );
        if (!$batchSigValid) {
            throw new ApiException(401, 'invalid_batch_signature', 'batch_signature does not verify over (batch_root, timestamp)');
        }

        $recentCount = RateLimit::countRecentSubmissions($db, $validatorPubkey);
        if ($recentCount >= RateLimit::MAX_SUBMISSIONS_PER_24H) {
            throw new ApiException(
                429,
                'rate_limit_exceeded',
                sprintf('validator has already submitted %d claims/batches in the last 24h (max %d)', $recentCount, RateLimit::MAX_SUBMISSIONS_PER_24H),
            );
        }

        $stakeStore = new Stakes($db);
        $stake = $stakeStore->ensureProvisioned($validatorPubkey);
        if ($stake['amount_locked'] < Stakes::MIN_STAKE_REQUIRED) {
            throw new ApiException(403, 'insufficient_stake', 'validator does not have the minimum required stake locked');
        }

        $ledgerStore = new LedgerStore($db);
        $containerEvent = $ledgerStore->appendEvent([
            'type' => 'batch',
            'claim_hash' => $batchRoot,
            'evidence_uri' => sprintf('batch:%d-claims', count($claims)),
            'timestamp' => $timestamp,
            'validator_pubkey' => $validatorPubkey,
            'signature' => $batchSignature,
            'batch_root' => $batchRoot,
            'stake_locked' => $stake['amount_locked'],
        ]);

        $insertLeaf = $db->prepare(
            'INSERT INTO batch_leaves (batch_event_id, leaf_index, claim_hash, evidence_uri, timestamp, validator_pubkey, signature) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertClaimText = $db->prepare(
            'INSERT INTO claim_texts (claim_hash, claim_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE claim_text = VALUES(claim_text)'
        );

        $db->beginTransaction();
        try {
            foreach ($claims as $i => $c) {
                $insertLeaf->execute([$containerEvent['id'], $i, $claimHashes[$i], $c['evidence_uri'], $c['timestamp'], $validatorPubkey, $c['signature']]);
                $insertClaimText->execute([$claimHashes[$i], $c['claim_text']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['status' => 201, 'body' => ['event' => $containerEvent, 'batch_root' => $batchRoot, 'claim_hashes' => $claimHashes]];
    }
}
