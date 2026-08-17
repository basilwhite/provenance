<?php

declare(strict_types=1);

namespace Provenance\Api;

use Provenance\Api\Routes\AuditRoute;
use Provenance\Api\Routes\BatchRoute;
use Provenance\Api\Routes\KeysRoute;
use Provenance\Api\Routes\ScoreRoute;
use Provenance\Api\Routes\SubmitRoute;
use Provenance\Api\Routes\VerifyRoute;

/**
 * Front-controller router. Mirrors the route surface of src/api/server.ts.
 * Path matching is done against the request path with the front
 * controller's own base directory stripped, so this works unchanged
 * whether deployed at the domain root or under a subdirectory like
 * /provenance/ (see public/index.php for how the base path is computed).
 */
final class Router
{
    /** @return array{status: int, body: array} */
    public static function dispatch(\PDO $db, string $method, string $path, array $body): array
    {
        $path = '/' . trim($path, '/');

        if ($method === 'GET' && $path === '/health') {
            return ['status' => 200, 'body' => ['status' => 'ok']];
        }

        if ($method === 'POST' && $path === '/submit') {
            return SubmitRoute::handle($db, $body);
        }

        if ($method === 'POST' && $path === '/audit') {
            return AuditRoute::handle($db, $body);
        }

        if ($method === 'POST' && $path === '/submit/batch') {
            return BatchRoute::handle($db, $body);
        }

        if ($method === 'POST' && $path === '/keys/rotate') {
            return KeysRoute::handle($db, $body);
        }

        if ($method === 'GET' && preg_match('#^/verify/([^/]+)$#', $path, $m)) {
            return VerifyRoute::handle($db, urldecode($m[1]));
        }

        if ($method === 'GET' && preg_match('#^/validators/([^/]+)/score$#', $path, $m)) {
            return ScoreRoute::handleScore($db, urldecode($m[1]));
        }

        if ($method === 'GET' && preg_match('#^/validators/([^/]+)/events$#', $path, $m)) {
            return ScoreRoute::handleEvents($db, urldecode($m[1]));
        }

        return [
            'status' => 404,
            'body' => ['error' => ['code' => 'not_found', 'message' => "no route for {$method} {$path}"]],
        ];
    }
}
