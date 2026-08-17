<?php

declare(strict_types=1);

namespace Provenance\Tests\Api;

use Provenance\Tests\Support\DbTestCase;

final class RouterTest extends DbTestCase
{
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
