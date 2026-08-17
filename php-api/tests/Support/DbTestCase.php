<?php

declare(strict_types=1);

namespace Provenance\Tests\Support;

use PHPUnit\Framework\TestCase;
use Provenance\Api\ApiException;
use Provenance\Api\Router;
use Provenance\Db\Connection;

abstract class DbTestCase extends TestCase
{
    protected \PDO $db;

    /**
     * Mirrors index.php's ApiException -> HTTP response mapping, so route
     * tests can assert on status codes the same way an HTTP client would.
     * @return array{status: int, body: array}
     */
    protected function dispatch(string $method, string $path, array $body = []): array
    {
        try {
            return Router::dispatch($this->db, $method, $path, $body);
        } catch (ApiException $e) {
            return ['status' => $e->status, 'body' => ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Connection::create();
        self::resetTables($this->db);
    }

    /** Shared with ConcurrencyTest, which can't extend this class (it manages its own class-level server lifecycle). */
    public static function resetTables(\PDO $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->exec('DROP TRIGGER IF EXISTS ledger_events_no_update');
        $db->exec('DROP TRIGGER IF EXISTS ledger_events_no_delete');
        foreach (['batch_leaves', 'claim_texts', 'validator_scores', 'stakes', 'ledger_events'] as $table) {
            $db->exec("DELETE FROM {$table}");
        }
        $db->exec('ALTER TABLE ledger_events AUTO_INCREMENT = 1');
        $db->exec(
            "CREATE TRIGGER ledger_events_no_update BEFORE UPDATE ON ledger_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: UPDATE is not permitted'"
        );
        $db->exec(
            "CREATE TRIGGER ledger_events_no_delete BEFORE DELETE ON ledger_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: DELETE is not permitted'"
        );
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function hex(string $prefix = '01', int $bytes = 32): string
    {
        return '0x' . str_repeat($prefix, $bytes);
    }
}
