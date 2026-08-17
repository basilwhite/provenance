<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use PHPUnit\Framework\TestCase;
use Provenance\Api\LandingPage;
use Provenance\Db\Connection;
use Provenance\Tests\Support\DbTestCase;

/**
 * Router::dispatch() alone can't observe real HTTP response headers (those
 * are set by index.php, not the router) so the Content-Type contract the
 * landing page depends on needs an actual HTTP round-trip against the PHP
 * built-in server, same as tests/Fuzz/ConcurrencyTest.php.
 */
final class LandingPageHttpTest extends TestCase
{
    private static ?\PDO $db = null;
    private static int $port = 8198;
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

        $env = getenv();
        $env['PROVENANCE_DB_HOST'] = getenv('PROVENANCE_DB_HOST');
        $env['PROVENANCE_DB_PORT'] = getenv('PROVENANCE_DB_PORT');
        $env['PROVENANCE_DB_NAME'] = getenv('PROVENANCE_DB_NAME');
        $env['PROVENANCE_DB_USER'] = getenv('PROVENANCE_DB_USER');
        $env['PROVENANCE_DB_PASS'] = getenv('PROVENANCE_DB_PASS');

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$serverProcess = proc_open($cmd, $descriptors, $pipes, $docRoot, $env);

        if (self::$serverProcess === false) {
            self::fail('Failed to start PHP built-in server for landing page HTTP test');
        }

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

        if (PHP_OS_FAMILY === 'Windows' && isset($status['pid'])) {
            exec('taskkill /F /T /PID ' . (int)$status['pid'] . ' 2>NUL');
        } else {
            proc_terminate(self::$serverProcess);
        }

        proc_close(self::$serverProcess);
    }

    protected function setUp(): void
    {
        DbTestCase::resetTables(self::$db);
    }

    private function getRaw(string $path): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $raw = (string)curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];
    }

    public function testRootReturns200WithHtmlContentType(): void
    {
        $res = $this->getRaw('/');
        $this->assertSame(200, $res['status']);
        $this->assertMatchesRegularExpression('#^Content-Type:\s*text/html#im', $res['headers']);
        foreach (LandingPage::ENDPOINT_PATHS as $endpoint) {
            [, $path] = explode(' ', $endpoint, 2);
            $this->assertStringContainsString($path, $res['body'], "landing page missing endpoint path: {$path}");
        }
    }

    public function testUnmatchedPathStillReturnsJsonNotFound(): void
    {
        $res = $this->getRaw('/nonsense');
        $this->assertSame(404, $res['status']);
        $this->assertMatchesRegularExpression('#^Content-Type:\s*application/json#im', $res['headers']);
        $this->assertStringContainsString('"not_found"', $res['body']);
    }
}
