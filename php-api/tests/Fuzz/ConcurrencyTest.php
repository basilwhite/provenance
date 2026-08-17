<?php

declare(strict_types=1);

namespace Provenance\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use Provenance\Db\Connection;
use Provenance\Tests\Support\RequestHelpers;

/**
 * Real concurrent HTTP requests (via curl_multi) against a running PHP
 * built-in server backed by real MySQL — not simulated. This is the PHP
 * analogue of test/fuzz/race.test.ts, but for a genuinely different
 * threat model: PHP handles each request in its own process, so the
 * TS server's "no await inside handlers => atomic per request" argument
 * doesn't apply here. These tests exercise the DB-level protections added
 * in AuditRoute (row lock via LedgerStore::lockOriginalClaim) and the
 * schema (uniq_audit_ref_validator) instead.
 */
final class ConcurrencyTest extends TestCase
{
    private static ?\PDO $db = null;
    private static int $port = 8199;
    private static $serverProcess = null;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$db = Connection::create();
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;

        $phpBinary = PHP_BINARY;
        $docRoot = dirname(__DIR__, 2);
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d -t %s %s',
            escapeshellarg($phpBinary),
            self::$port,
            escapeshellarg($docRoot),
            escapeshellarg($docRoot . DIRECTORY_SEPARATOR . 'index.php'),
        );

        // On Windows, proc_open with a custom (non-null) $env REPLACES the
        // child's entire environment rather than extending it — without
        // SYSTEMROOT and friends, Winsock init fails and `php -S` can't
        // bind at all ("Failed to listen ... reason: ?", confirmed via
        // php-api/verification/debug-proc-open.php). Start from the real
        // environment and overlay just the DB config on top.
        $env = getenv();
        $env['PROVENANCE_DB_HOST'] = getenv('PROVENANCE_DB_HOST');
        $env['PROVENANCE_DB_PORT'] = getenv('PROVENANCE_DB_PORT');
        $env['PROVENANCE_DB_NAME'] = getenv('PROVENANCE_DB_NAME');
        $env['PROVENANCE_DB_USER'] = getenv('PROVENANCE_DB_USER');
        $env['PROVENANCE_DB_PASS'] = getenv('PROVENANCE_DB_PASS');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open($cmd, $descriptors, $pipes, $docRoot, $env);

        if (self::$serverProcess === false) {
            self::fail('Failed to start PHP built-in server for concurrency tests');
        }

        // Wait for the server to actually accept connections.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $ch = curl_init(self::$baseUrl . '/health');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200);
            $result = curl_exec($ch);
            $ok = $result !== false;
            curl_close($ch);
            if ($ok) {
                return;
            }
            usleep(50_000);
        }
        self::fail('PHP built-in server did not become ready in time');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess === null) {
            return;
        }

        $status = proc_get_status(self::$serverProcess);

        // proc_terminate() is unreliable at actually killing the process
        // tree on Windows (observed: it can leave the dev server running
        // as an orphan, which then holds the port and keeps stdout/stderr
        // pipes open). taskkill /T (kill the whole tree) is the reliable
        // way to actually stop it there.
        if (PHP_OS_FAMILY === 'Windows' && isset($status['pid'])) {
            exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' 2>NUL');
        } else {
            proc_terminate(self::$serverProcess);
        }

        proc_close(self::$serverProcess);
    }

    protected function setUp(): void
    {
        \Provenance\Tests\Support\DbTestCase::resetTables(self::$db);
    }

    private function postJson(string $path, array $body): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => json_decode((string)$raw, true)];
    }

    /** Fires all requests concurrently via curl_multi; returns responses in the same order. */
    private function postJsonConcurrently(string $path, array $bodies): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($bodies as $i => $body) {
            $ch = curl_init(self::$baseUrl . $path);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        $deadline = microtime(true) + 15;
        do {
            curl_multi_exec($mh, $running);
            // Explicit timeout, not the default block-until-activity: on
            // some platforms curl_multi_select can return early/late in
            // ways that turn an unbounded wait into an effective hang.
            curl_multi_select($mh, 0.2);
            if (microtime(true) > $deadline) {
                self::fail('postJsonConcurrently exceeded its 15s safety deadline');
            }
        } while ($running > 0);

        $results = [];
        foreach ($handles as $i => $ch) {
            $raw = curl_multi_getcontent($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $results[$i] = ['status' => $status, 'body' => json_decode((string)$raw, true)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    }

    public function testConcurrentOverturningAuditsSlashExactlyOnce(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $submitRes = $this->postJson('/submit', $submitBody);
        $this->assertSame(201, $submitRes['status']);

        $n = 8;
        $auditors = array_map(static fn() => RequestHelpers::makeValidator(), range(1, $n));
        $bodies = [];
        foreach ($auditors as $i => $auditor) {
            $bodies[] = RequestHelpers::buildAuditBody($auditor, $claimHash, false, $submitBody['timestamp'] + 1000 + $i);
        }

        $responses = $this->postJsonConcurrently('/audit', $bodies);

        foreach ($responses as $res) {
            $this->assertSame(201, $res['status'], json_encode($res));
        }

        $slashedResponses = array_filter($responses, static fn($r) => ($r['body']['slashed_amount'] ?? 0) > 0);
        $this->assertCount(1, $slashedResponses, 'expected exactly one concurrent audit to trigger the slash');

        $scoreCh = curl_init(self::$baseUrl . "/validators/{$submitter['publicKeyHex']}/score");
        curl_setopt($scoreCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($scoreCh, CURLOPT_TIMEOUT, 10);
        $scoreBody = json_decode((string)curl_exec($scoreCh), true);
        curl_close($scoreCh);

        $this->assertSame($n, $scoreBody['n']);
        $this->assertSame($n, $scoreBody['overturns']);
    }

    public function testConcurrentDuplicateAuditsFromSameValidatorOnlyOneSucceeds(): void
    {
        $submitter = RequestHelpers::makeValidator();
        ['body' => $submitBody, 'claimHash' => $claimHash] = RequestHelpers::buildSubmitBody($submitter);
        $this->postJson('/submit', $submitBody);

        $auditor = RequestHelpers::makeValidator();
        $auditBody = RequestHelpers::buildAuditBody($auditor, $claimHash, true, $submitBody['timestamp'] + 1000);

        $responses = $this->postJsonConcurrently('/audit', [$auditBody, $auditBody, $auditBody]);

        $succeeded = array_filter($responses, static fn($r) => $r['status'] === 201);
        $rejected = array_filter($responses, static fn($r) => $r['status'] === 409);

        $this->assertCount(1, $succeeded);
        $this->assertCount(2, $rejected);
    }

    public function testConcurrentSubmissionsRespectExactRateLimit(): void
    {
        $validator = RequestHelpers::makeValidator();
        $attempts = 15;
        $bodies = [];
        for ($i = 0; $i < $attempts; $i++) {
            ['body' => $body] = RequestHelpers::buildSubmitBody($validator, ['evidenceUri' => "https://example.com/race-limit/{$i}"]);
            $bodies[] = $body;
        }

        $responses = $this->postJsonConcurrently('/submit', $bodies);

        $succeeded = array_filter($responses, static fn($r) => $r['status'] === 201);
        $rateLimited = array_filter($responses, static fn($r) => $r['status'] === 429);

        $this->assertCount(10, $succeeded);
        $this->assertCount($attempts - 10, $rateLimited);
    }
}
