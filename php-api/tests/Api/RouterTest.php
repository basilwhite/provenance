<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Api\LandingPage;
use Provenance\Tests\Support\DbTestCase;

final class RouterTest extends DbTestCase
{
    public function testRootReturnsHtmlLandingPage(): void
    {
        $res = $this->dispatch('GET', '/');
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('html', $res);
        foreach (LandingPage::ENDPOINT_PATHS as $endpoint) {
            // ENDPOINT_PATHS entries are "METHOD /path" (e.g. "GET
            // /verify/{claim_hash}"); the page renders method and path in
            // separate table cells/anchors, so only the path half is
            // guaranteed to appear as a contiguous string.
            [, $path] = explode(' ', $endpoint, 2);
            $this->assertStringContainsString($path, $res['html'], "landing page missing endpoint path: {$path}");
        }
    }

    public function testRootWithoutLeadingSlashAlsoReturnsHtmlLandingPage(): void
    {
        // Mirrors how index.php normalizes a bare "GET /provenance" (no
        // trailing slash) once its own script directory has been stripped
        // down to an empty string, and "GET /provenance//" down to "//".
        foreach (['', '//'] as $rawPath) {
            $res = $this->dispatch('GET', $rawPath);
            $this->assertSame(200, $res['status']);
            $this->assertArrayHasKey('html', $res);
        }
    }

    public function testOtherUnmatchedPathsStillReturnJson404NotTheLandingPage(): void
    {
        $res = $this->dispatch('GET', '/nonsense');
        $this->assertSame(404, $res['status']);
        $this->assertArrayNotHasKey('html', $res);
        $this->assertSame('not_found', $res['body']['error']['code']);
    }

    public function testHealthCheck(): void
    {
        $res = $this->dispatch('GET', '/health');
        $this->assertSame(200, $res['status']);
        $this->assertSame('ok', $res['body']['status']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $res = $this->dispatch('GET', '/nonexistent');
        $this->assertSame(404, $res['status']);
        $this->assertSame('not_found', $res['body']['error']['code']);
    }

    public function testUnknownMethodOnKnownPathReturns404(): void
    {
        $res = $this->dispatch('DELETE', '/submit');
        $this->assertSame(404, $res['status']);
    }

    public function testPathNormalizationHandlesTrailingSlashes(): void
    {
        $res = $this->dispatch('GET', '/health/');
        $this->assertSame(200, $res['status']);
    }
}
