<?php

declare(strict_types=1);

namespace Provenance\Api\Routes;

use Provenance\Api\ApiException;
use Provenance\Api\Validators;
use Provenance\Crypto\Encoding;
use Provenance\Crypto\Keys;
use Provenance\Crypto\Messages;
use Provenance\Ledger\Store as LedgerStore;

/** Mirrors src/api/routes/keys.ts (F1.2). */
final class KeysRoute
{
    public static function handle(\PDO $db, array $body): array
    {
        $oldPubkey = Validators::requirePubkeyHex($body['old_pubkey'] ?? null, 'old_pubkey');
        $newPubkey = Validators::requirePubkeyHex($body['new_pubkey'] ?? null, 'new_pubkey');
        $rotationSignature = Validators::requireSignatureHex($body['rotation_signature'] ?? null, 'rotation_signature');

        if ($oldPubkey === $newPubkey) {
            throw new ApiException(400, 'invalid_request', 'new_pubkey must differ from old_pubkey');
        }

        $message = Messages::rotationMessage($oldPubkey, $newPubkey);
        $sigValid = Keys::verify($message, Encoding::hexToBytes($rotationSignature), Encoding::hexToBytes($oldPubkey));
        if (!$sigValid) {
            throw new ApiException(401, 'invalid_signature', 'rotation_signature does not verify against old_pubkey');
        }

        $ledgerStore = new LedgerStore($db);

        if ($ledgerStore->findRotationByOldPubkey($oldPubkey) !== null) {
            throw new ApiException(409, 'duplicate_rotation', 'old_pubkey has already been rotated');
        }
        if ($ledgerStore->findRotationByNewPubkey($newPubkey) !== null || $ledgerStore->findRotationByOldPubkey($newPubkey) !== null) {
            throw new ApiException(409, 'duplicate_rotation', 'new_pubkey is already part of another rotation lineage');
        }

        $timestamp = (int)(microtime(true) * 1000);
        $claimHash = Encoding::keccak256Hex("{$oldPubkey}:{$newPubkey}:{$timestamp}");

        $event = $ledgerStore->appendEvent([
            'type' => 'key_rotation',
            'claim_hash' => $claimHash,
            'evidence_uri' => 'key-rotation',
            'timestamp' => $timestamp,
            'validator_pubkey' => $newPubkey,
            'signature' => $rotationSignature,
            'old_pubkey' => $oldPubkey,
            'new_pubkey' => $newPubkey,
        ]);

        return ['status' => 201, 'body' => ['event' => $event]];
    }
}
