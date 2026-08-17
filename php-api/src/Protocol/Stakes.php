<?php

declare(strict_types=1);

namespace Provenance\Protocol;

/** Mirrors src/protocol/stakes.ts. Simulation only — no real token custody. */
final class Stakes
{
    public const MIN_STAKE_REQUIRED = 1;
    public const DEFAULT_INITIAL_STAKE = 10;
    public const SLASH_FRACTION = 0.5;

    public function __construct(private readonly \PDO $db)
    {
    }

    public function get(string $pubkey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stakes WHERE validator_pubkey = ?');
        $stmt->execute([$pubkey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['validator_pubkey' => $pubkey, 'amount_locked' => 0, 'amount_slashed' => 0];
        }
        return [
            'validator_pubkey' => $row['validator_pubkey'],
            'amount_locked' => (int)$row['amount_locked'],
            'amount_slashed' => (int)$row['amount_slashed'],
        ];
    }

    public function ensureProvisioned(string $pubkey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stakes WHERE validator_pubkey = ?');
        $stmt->execute([$pubkey]);
        if ($stmt->fetch() !== false) {
            return $this->get($pubkey);
        }

        $stmt = $this->db->prepare('INSERT INTO stakes (validator_pubkey, amount_locked, amount_slashed) VALUES (?, ?, 0)');
        $stmt->execute([$pubkey, self::DEFAULT_INITIAL_STAKE]);
        return $this->get($pubkey);
    }

    public function hasMinimumStake(string $pubkey): bool
    {
        return $this->get($pubkey)['amount_locked'] >= self::MIN_STAKE_REQUIRED;
    }

    /** Burns SLASH_FRACTION of currently locked stake. Returns the amount burned. */
    public function slash(string $pubkey, float $fraction = self::SLASH_FRACTION): int
    {
        $current = $this->get($pubkey);
        // Math.max(1, floor(...)) guarantees slashing eventually reaches 0 —
        // floor alone can round a fractional cut to 0 once stake is small
        // (floor(1 * 0.5) = 0), stranding a validator forever above zero.
        $amount = $current['amount_locked'] > 0
            ? max(1, (int)floor($current['amount_locked'] * $fraction))
            : 0;

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO stakes (validator_pubkey, amount_locked, amount_slashed)
            VALUES (:pubkey, :locked, :slashed)
            ON DUPLICATE KEY UPDATE
                amount_locked = VALUES(amount_locked),
                amount_slashed = VALUES(amount_slashed)
        SQL);
        $stmt->execute([
            'pubkey' => $pubkey,
            'locked' => $current['amount_locked'] - $amount,
            'slashed' => $current['amount_slashed'] + $amount,
        ]);

        return $amount;
    }
}
