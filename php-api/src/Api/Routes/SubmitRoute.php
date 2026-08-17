<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\ApiException;
use Provenance\Api\Validators;
use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Domain\ClaimHash;
use Provenance\Ledger\Store as LedgerStore;
use Provenance\Protocol\RateLimit;
use Provenance\Protocol\Stakes;

/** Mirrors src/api/routes/submit.ts (F3.1). */
final class SubmitRoute
{
    /** @return array{status: int, body: array} */
    public static function handle(\PDO $db, array $body): array
    {
        $claimText = Validators::requireString($body['claim_text'] ?? null, 'claim_text');
        $evidenceUri = Validators::requireString($body['evidence_uri'] ?? null, 'evidence_uri');
        $timestamp = Validators::requireNumber($body['timestamp'] ?? null, 'timestamp');
        $validatorPubkey = Validators::requirePubkeyHex($body['validator_pubkey'] ?? null, 'validator_pubkey');
        $signature = Validators::requireSignatureHex($body['signature'] ?? null, 'signature');

        $claimHash = ClaimHash::compute($claimText, $evidenceUri, $timestamp);
        $message = Messages::claimTimestampMessage($claimHash, $timestamp);
        $sigValid = Keys::verify($message, Encoding::hexToBytes($signature), Encoding::hexToBytes($validatorPubkey));
        if (!$sigValid) {
            throw new ApiException(401, 'invalid_signature', 'signature does not verify against validator_pubkey');
        }

        if (!RateLimit::meetsEvidenceComplexity($claimText)) {
            throw new ApiException(
                422,
                'evidence_too_short',
                sprintf('claim_text must be at least %d characters (got %d)', RateLimit::MIN_CLAIM_TEXT_CHARS, mb_strlen($claimText, 'UTF-8')),
            );
        }

        $recentCount = RateLimit::countRecentSubmissions($db, $validatorPubkey);
        if ($recentCount >= RateLimit::MAX_SUBMISSIONS_PER_24H) {
            throw new ApiException(
                429,
                'rate_limit_exceeded',
                sprintf('validator has already submitted %d claims in the last 24h (max %d)', $recentCount, RateLimit::MAX_SUBMISSIONS_PER_24H),
            );
        }

        $stakeStore = new Stakes($db);
        $stake = $stakeStore->ensureProvisioned($validatorPubkey);
        if ($stake['amount_locked'] < Stakes::MIN_STAKE_REQUIRED) {
            throw new ApiException(403, 'insufficient_stake', 'validator does not have the minimum required stake locked');
        }

        $ledgerStore = new LedgerStore($db);
        $event = $ledgerStore->appendEvent([
            'type' => 'submission',
            'claim_hash' => $claimHash,
            'evidence_uri' => $evidenceUri,
            'timestamp' => $timestamp,
            'validator_pubkey' => $validatorPubkey,
            'signature' => $signature,
            'stake_locked' => $stake['amount_locked'],
        ]);

        $stmt = $db->prepare('INSERT INTO claim_texts (claim_hash, claim_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE claim_text = VALUES(claim_text)');
        $stmt->execute([$claimHash, $claimText]);

        return ['status' => 201, 'body' => ['event' => $event]];
    }
}
