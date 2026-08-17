<?php

declare(strict_types=1);

namespace Provenance\Protocol;

/** Mirrors src/protocol/rateLimit.ts. */
final class RateLimit
{
    public const MAX_SUBMISSIONS_PER_24H = 10;
    public const RATE_LIMIT_WINDOW_MS = 24 * 60 * 60 * 1000;
    public const MIN_CLAIM_TEXT_CHARS = 500;

    /**
     * A batch counts as ONE submission regardless of leaf count, measured
     * from server-observed "now" (not client-supplied timestamps).
     */
    public static function countRecentSubmissions(\PDO $db, string $pubkey, ?int $now = null): int
    {
        $now ??= (int)(microtime(true) * 1000);
        $since = $now - self::RATE_LIMIT_WINDOW_MS;

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS n FROM ledger_events WHERE validator_pubkey = ? AND type IN ('submission','batch') AND timestamp >= ?"
        );
        $stmt->execute([$pubkey, $since]);
        return (int)$stmt->fetch()['n'];
    }

    public static function meetsEvidenceComplexity(string $claimText): bool
    {
        return mb_strlen($claimText, 'UTF-8') >= self::MIN_CLAIM_TEXT_CHARS;
    }
}
