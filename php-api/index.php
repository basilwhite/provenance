<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

// Production DB credentials live only here, never in git (see .gitignore).
// Uploaded directly to the server via SFTP, alongside index.php. Locally,
// this file simply doesn't exist, so PROVENANCE_DB_* fall back to
// Db\Connection's defaults (or whatever the shell/test bootstrap set).
$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

use Provenance\Api\ApiException;
use Provenance\Api\Router;
use Provenance\Db\Connection;

// Never leak raw PHP errors/warnings into what's supposed to be a clean
// JSON API response body.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function provenance_send(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Base path handling: works whether deployed at the domain root or under a
// subdirectory (e.g. basilwhite.com/provenance/), by stripping this script's
// own directory from the request path rather than hardcoding a prefix.
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = $requestUri;
if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
    $path = substr($path, strlen($scriptDir));
}
if ($path === '' || $path === false) {
    $path = '/';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            provenance_send(400, ['error' => ['code' => 'invalid_json', 'message' => 'request body is not valid JSON']]);
        }
        $body = is_array($decoded) ? $decoded : [];
    }
}

try {
    $db = Connection::getDefault();
    $result = Router::dispatch($db, $method, $path, $body);
    provenance_send($result['status'], $result['body']);
} catch (ApiException $e) {
    provenance_send($e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
} catch (\Throwable $e) {
    error_log('[provenance] unhandled error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    provenance_send(500, ['error' => ['code' => 'internal_error', 'message' => 'unexpected server error']]);
}
