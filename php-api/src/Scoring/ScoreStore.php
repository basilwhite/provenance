<?php

declare(strict_types=1);

namespace Provenance\Scoring;

/** Persistence for the F4.2 validator_scores table. Mirrors src/scoring/scores.ts. */
final class ScoreStore
{
    public function __construct(private readonly \PDO $db)
    {
    }

    public function get(string $pubkey): array
    {
        $stmt = $this->db->prepare('SELECT * FROM validator_scores WHERE validator_pubkey = ?');
        $stmt->execute([$pubkey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return $this->defaultRecord($pubkey);
        }
        return $this->normalize($row);
    }

    public function upsert(string $pubkey, int $n, int $confirmations, int $overturns, float $score): array
    {
        $updatedAt = (int)(microtime(true) * 1000);
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO validator_scores (validator_pubkey, n, confirmations, overturns, score, updated_at)
            VALUES (:pubkey, :n, :confirmations, :overturns, :score, :updated_at)
            ON DUPLICATE KEY UPDATE
                n = VALUES(n),
                confirmations = VALUES(confirmations),
                overturns = VALUES(overturns),
                score = VALUES(score),
                updated_at = VALUES(updated_at)
        SQL);
        $stmt->execute([
            'pubkey' => $pubkey,
            'n' => $n,
            'confirmations' => $confirmations,
            'overturns' => $overturns,
            'score' => $score,
            'updated_at' => $updatedAt,
        ]);
        return $this->get($pubkey);
    }

    /**
     * F1.2 continuity: searches every key in a rotation lineage and returns
     * whichever holds the most recently updated score row — correct
     * regardless of how many rotations happened after the score was last
     * computed. See src/scoring/scores.ts getMostRecentAcrossKeys.
     * @param string[] $pubkeys
     */
    public function getMostRecentAcrossKeys(array $pubkeys): array
    {
        $best = null;
        foreach ($pubkeys as $pubkey) {
            $record = $this->get($pubkey);
            if ($best === null || $record['updated_at'] > $best['updated_at']) {
                $best = $record;
            }
        }
        return $best ?? $this->defaultRecord($pubkeys[0] ?? '');
    }

    private function defaultRecord(string $pubkey): array
    {
        return ['validator_pubkey' => $pubkey, 'n' => 0, 'confirmations' => 0, 'overturns' => 0, 'score' => 0.5, 'updated_at' => 0];
    }

    private function normalize(array $row): array
    {
        return [
            'validator_pubkey' => $row['validator_pubkey'],
            'n' => (int)$row['n'],
            'confirmations' => (int)$row['confirmations'],
            'overturns' => (int)$row['overturns'],
            'score' => (float)$row['score'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
