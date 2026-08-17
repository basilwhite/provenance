<?php

require __DIR__ . '/../vendor/autoload.php';

use Provenance\Db\Connection;

putenv('PROVENANCE_DB_HOST=127.0.0.1');
putenv('PROVENANCE_DB_PORT=3307');
putenv('PROVENANCE_DB_NAME=provenance');
putenv('PROVENANCE_DB_USER=root');
putenv('PROVENANCE_DB_PASS=');

$pdo = Connection::create();
echo "Connected and schema applied OK.\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tables: " . implode(', ', $tables) . "\n\n";

// Test the append-only trigger actually rejects UPDATE.
$pdo->exec("INSERT INTO ledger_events (type, claim_hash, evidence_uri, timestamp, validator_pubkey, signature, prev_root, root, created_at) VALUES ('submission', '0x01', 'u', 1000, '0xaa', '0xbb', '0x00', '0x11', 1000)");
$insertedId = (int)$pdo->lastInsertId();
echo "Insert OK, id={$insertedId}.\n";

try {
    $affected = $pdo->exec("UPDATE ledger_events SET evidence_uri = 'tampered' WHERE id = {$insertedId}");
    echo "UPDATE SUCCEEDED (BAD - trigger did not block it! rows affected: {$affected})\n";
} catch (\PDOException $e) {
    echo "UPDATE correctly blocked: " . $e->getMessage() . "\n";
}

try {
    $affected = $pdo->exec("DELETE FROM ledger_events WHERE id = {$insertedId}");
    echo "DELETE SUCCEEDED (BAD - trigger did not block it! rows affected: {$affected})\n";
} catch (\PDOException $e) {
    echo "DELETE correctly blocked: " . $e->getMessage() . "\n";
}

// cleanup for repeatable runs
$pdo->exec("DROP TRIGGER IF EXISTS ledger_events_no_delete");
$pdo->exec("DROP TRIGGER IF EXISTS ledger_events_no_update");
$pdo->exec("DELETE FROM batch_leaves");
$pdo->exec("DELETE FROM ledger_events");
$pdo->exec("ALTER TABLE ledger_events AUTO_INCREMENT = 1");
$pdo->exec("CREATE TRIGGER ledger_events_no_update BEFORE UPDATE ON ledger_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: UPDATE is not permitted'");
$pdo->exec("CREATE TRIGGER ledger_events_no_delete BEFORE DELETE ON ledger_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_events is append-only: DELETE is not permitted'");
echo "\nCleanup done for repeatable test runs.\n";
