<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\ApiException;
use Provenance\Api\Validators;
use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Ledger\Store as LedgerStore;
use Provenance\Protocol\Finalize;
use Provenance\Protocol\Slashing;
use Provenance\Protocol\Stakes;
use Provenance\Scoring\ScoreStore;

/**
 * Mirrors src/api/routes/audit.ts (F3.2, F3.3, F5.1).
 *
 * Unlike the TS server (single-threaded, no `await` inside handlers, so a
 * request runs to completion atomically relative to others), PHP under
 * Apache/PHP-FPM handles concurrent requests in separate processes. The
 * "read existing audits, decide whether to slash, then write" sequence
 * (F5.1) is wrapped in a transaction that takes a row lock on the original
 * claim first (LedgerStore::lockOriginalClaim), serializing concurrent
 * audits on the same claim so two simultaneous overturns can't both decide
 * "yes, slash" from the same stale read. Duplicate-audit detection has a
 * second, DB-level backstop too (uniq_audit_ref_validator).
 */
final class AuditRoute
{
    public static function handle(\PDO $db, array $body): array
    {
        $claimHash = Validators::requireClaimHashHex($body['claim_hash'] ?? null, 'claim_hash');
        $auditVerdict = Validators::requireBoolean($body['audit_verdict'] ?? null, 'audit_verdict');
        $timestamp = Validators::requireNumber($body['timestamp'] ?? null, 'timestamp');
        $validatorPubkey = Validators::requirePubkeyHex($body['validator_pubkey'] ?? null, 'validator_pubkey');
        $signature = Validators::requireSignatureHex($body['signature'] ?? null, 'signature');

        $ledgerStore = new LedgerStore($db);
        $stakeStore = new Stakes($db);
        $scoreStore = new ScoreStore($db);

        $original = $ledgerStore->findOriginalClaim($claimHash);
        if ($original === null) {
            throw new ApiException(404, 'claim_not_found', "no claim found for claim_hash {$claimHash}");
        }

        if ($original['validator_pubkey'] === $validatorPubkey) {
            throw new ApiException(403, 'self_audit_forbidden', 'a validator cannot audit its own claim');
        }

        $message = Messages::claimTimestampMessage($claimHash, $timestamp);
        $sigValid = Keys::verify($message, Encoding::hexToBytes($signature), Encoding::hexToBytes($validatorPubkey));
        if (!$sigValid) {
            throw new ApiException(401, 'invalid_signature', 'signature does not verify against validator_pubkey');
        }

        $db->beginTransaction();
        try {
            // Locks the original claim row, serializing concurrent audits
            // on this claim_hash for the remainder of this transaction.
            $ledgerStore->lockOriginalClaim($claimHash);

            $existingAudits = $ledgerStore->getAuditsForClaim($claimHash);
            foreach ($existingAudits as $audit) {
                if ($audit['validator_pubkey'] === $validatorPubkey) {
                    throw new ApiException(409, 'duplicate_audit', 'this validator has already audited this claim');
                }
            }

            $willSlash = Slashing::shouldSlashForClaim($original, $existingAudits, [
                'audit_verdict' => $auditVerdict,
                'timestamp' => $timestamp,
            ]);
            $slashedAmount = $willSlash ? $stakeStore->slash($original['validator_pubkey']) : 0;

            $event = $ledgerStore->appendEvent([
                'type' => 'audit',
                'claim_hash' => $claimHash,
                'evidence_uri' => $original['evidence_uri'],
                'timestamp' => $timestamp,
                'validator_pubkey' => $validatorPubkey,
                'signature' => $signature,
                'audit_ref' => $claimHash,
                'audit_verdict' => $auditVerdict,
                'stake_slashed' => $slashedAmount,
            ]);

            $finalizeResult = Finalize::finalizeClaimIfReady($ledgerStore, $scoreStore, $claimHash);

            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            if ((int)$e->getCode() === 23000) { // integrity constraint violation, e.g. uniq_audit_ref_validator
                throw new ApiException(409, 'duplicate_audit', 'this validator has already audited this claim');
            }
            throw $e;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return ['status' => 201, 'body' => ['event' => $event, 'slashed_amount' => $slashedAmount, 'finalize' => $finalizeResult]];
    }
}
