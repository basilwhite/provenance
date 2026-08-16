import { Router } from "express";
import type { Db } from "../../db/index.js";
import { LedgerStore } from "../../ledger/store.js";
import { rotationMessage } from "../../crypto/messages.js";
import { verify } from "../../crypto/keys.js";
import { hexToBytes, keccak256Hex, utf8ToBytes } from "../../crypto/encoding.js";
import { ApiError } from "../errors.js";
import { asyncHandler } from "../asyncHandler.js";
import { requirePubkeyHex, requireSignatureHex } from "../validators.js";

function parseBody(body: unknown) {
  const b = (body ?? {}) as Record<string, unknown>;
  return {
    old_pubkey: requirePubkeyHex(b["old_pubkey"], "old_pubkey"),
    new_pubkey: requirePubkeyHex(b["new_pubkey"], "new_pubkey"),
    rotation_signature: requireSignatureHex(b["rotation_signature"], "rotation_signature"),
  };
}

/**
 * F1.2: the OLD key signs the NEW key, authorizing a hand-off. Rejects a
 * second rotation away from an already-rotated key so identity lineages
 * stay a simple chain (no branching), which is what
 * LedgerStore.resolveIdentityLineage relies on for score continuity.
 */
export function createKeysRouter(db: Db): Router {
  const router = Router();
  const ledgerStore = new LedgerStore(db);

  router.post(
    "/keys/rotate",
    asyncHandler(async (req, res) => {
      const body = parseBody(req.body);

      if (body.old_pubkey === body.new_pubkey) {
        throw new ApiError(400, "invalid_request", "new_pubkey must differ from old_pubkey");
      }

      const message = rotationMessage(body.old_pubkey, body.new_pubkey);
      const sigValid = verify(message, hexToBytes(body.rotation_signature), hexToBytes(body.old_pubkey));
      if (!sigValid) {
        throw new ApiError(401, "invalid_signature", "rotation_signature does not verify against old_pubkey");
      }

      if (ledgerStore.findRotationByOldPubkey(body.old_pubkey)) {
        throw new ApiError(409, "duplicate_rotation", "old_pubkey has already been rotated");
      }
      if (ledgerStore.findRotationByNewPubkey(body.new_pubkey) || ledgerStore.findRotationByOldPubkey(body.new_pubkey)) {
        throw new ApiError(409, "duplicate_rotation", "new_pubkey is already part of another rotation lineage");
      }

      const timestamp = Date.now();
      const claimHash = keccak256Hex(utf8ToBytes(`${body.old_pubkey}:${body.new_pubkey}:${timestamp}`));

      const event = ledgerStore.appendEvent({
        type: "key_rotation",
        claim_hash: claimHash,
        evidence_uri: "key-rotation",
        timestamp,
        validator_pubkey: body.new_pubkey,
        signature: body.rotation_signature,
        old_pubkey: body.old_pubkey,
        new_pubkey: body.new_pubkey,
      });

      res.status(201).json({ event });
    }),
  );

  return router;
}
