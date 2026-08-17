<?php

declare(strict_types=1);

namespace Provenance\Db;

/**
 * MySQL schema mirroring src/db/index.ts's SQLite schema. InnoDB (for
 * transactions/foreign keys), utf8mb4. ledger_events has no application
 * code path that ever issues UPDATE/DELETE against it — see
 * Ledger\Store, which only ever INSERTs — enforced further here with a
 * trigger that rejects any UPDATE/DELETE attempt outright, as a
 * belt-and-suspenders guarantee beyond what the TS reference has.
 *
 * uniq_audit_ref_validator also exists for a reason the TS reference
 * doesn't need: PHP under Apache/PHP-FPM handles each HTTP request in a
 * separate process, so two concurrent duplicate-audit requests can both
 * pass the application-level "already audited?" check before either
 * commits (unlike the TS server's single-threaded, no-await handlers,
 * which are atomic per request by construction). MySQL treats each NULL
 * as distinct, so this only constrains actual audit rows (audit_ref
 * non-null) - see Api\Routes\AuditRoute for how the resulting unique-key
 * violation is converted into the same 409 duplicate_audit response.
 */
final class Connection
{
    private static ?\PDO $instance = null;

    public static function create(?array $config = null): \PDO
    {
        $config ??= self::configFromEnv();

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? '3306',
            $config['database'],
        );

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        self::applySchema($pdo);

        return $pdo;
    }

    /** Lazily-created singleton for the HTTP front controller. */
    public static function getDefault(): \PDO
    {
        if (self::$instance === null) {
            self::$instance = self::create();
        }
        return self::$instance;
    }

    private static function configFromEnv(): array
    {
        return [
            'host' => getenv('PROVENANCE_DB_HOST') ?: 'localhost',
            'port' => getenv('PROVENANCE_DB_PORT') ?: '3306',
            'database' => getenv('PROVENANCE_DB_NAME') ?: 'provenance',
            'username' => getenv('PROVENANCE_DB_USER') ?: 'root',
            'password' => getenv('PROVENANCE_DB_PASS') ?: '',
        ];
    }

    private static function applySchema(\PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS ledger_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(20) NOT NULL,
                claim_hash CHAR(66) NOT NULL,
                evidence_uri TEXT NOT NULL,
                timestamp BIGINT NOT NULL,
                validator_pubkey CHAR(66) NOT NULL,
                signature CHAR(130) NOT NULL,
                audit_ref CHAR(66) NULL,
                audit_verdict TINYINT(1) NULL,
                stake_locked BIGINT NOT NULL DEFAULT 0,
                stake_slashed BIGINT NOT NULL DEFAULT 0,
                batch_root CHAR(66) NULL,
                prev_root CHAR(66) NOT NULL,
                root CHAR(66) NOT NULL,
                old_pubkey CHAR(66) NULL,
                new_pubkey CHAR(66) NULL,
                created_at BIGINT NOT NULL,
                INDEX idx_ledger_events_pubkey (validator_pubkey),
                INDEX idx_ledger_events_claim_hash (claim_hash),
                INDEX idx_ledger_events_audit_ref (audit_ref),
                UNIQUE KEY uniq_audit_ref_validator (audit_ref, validator_pubkey)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS claim_texts (
                claim_hash CHAR(66) PRIMARY KEY,
                claim_text LONGTEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS batch_leaves (
                batch_event_id BIGINT UNSIGNED NOT NULL,
                leaf_index INT NOT NULL,
                claim_hash CHAR(66) NOT NULL,
                evidence_uri TEXT NOT NULL,
                timestamp BIGINT NOT NULL,
                validator_pubkey CHAR(66) NOT NULL,
                signature CHAR(130) NOT NULL,
                PRIMARY KEY (batch_event_id, leaf_index),
                INDEX idx_batch_leaves_claim_hash (claim_hash),
                CONSTRAINT fk_batch_leaves_event FOREIGN KEY (batch_event_id)
                    REFERENCES ledger_events(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS validator_scores (
                validator_pubkey CHAR(66) PRIMARY KEY,
                n INT NOT NULL DEFAULT 0,
                confirmations INT NOT NULL DEFAULT 0,
                overturns INT NOT NULL DEFAULT 0,
                score DOUBLE NOT NULL DEFAULT 0.5,
                updated_at BIGINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS stakes (
                validator_pubkey CHAR(66) PRIMARY KEY,
                amount_locked BIGINT NOT NULL DEFAULT 0,
                amount_slashed BIGINT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        // Defense-in-depth: the append-only guarantee already holds because
        // no application code path ever runs UPDATE/DELETE against this
        // table, but a DB-level trigger makes that structurally enforced
        // rather than merely convention.
        $pdo->exec('DROP TRIGGER IF EXISTS ledger_events_no_update');
        $pdo->exec(<<<SQL
            CREATE TRIGGER ledger_events_no_update
            BEFORE UPDATE ON ledger_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: UPDATE is not permitted'
        SQL);

        $pdo->exec('DROP TRIGGER IF EXISTS ledger_events_no_delete');
        $pdo->exec(<<<SQL
            CREATE TRIGGER ledger_events_no_delete
            BEFORE DELETE ON ledger_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: DELETE is not permitted'
        SQL);
    }
}
