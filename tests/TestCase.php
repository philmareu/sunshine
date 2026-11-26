<?php

namespace Tests;

use App\Ai\PageCheck;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Searches read their pages from the web, so no test reaches it: requests need a fake, and hosts resolve to a
     * public address without DNS, or a private one for "internal." hosts.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        PageCheck::resolveHostsUsing(fn (string $host): array => str_starts_with($host, 'internal.') ? ['10.0.0.5'] : ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        PageCheck::resolveHostsUsing(null);

        parent::tearDown();
    }
}
