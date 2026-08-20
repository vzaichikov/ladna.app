<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class McpRouteCacheTest extends TestCase
{
    public function test_mcp_rate_limiters_are_registered_with_cached_routes(): void
    {
        $this->artisan('route:cache')->assertSuccessful();

        try {
            $this->refreshApplication();

            foreach (['mcp', 'mcp-oauth', 'mcp-oauth-register', 'mcp-oauth-token'] as $limiter) {
                $this->assertInstanceOf(Closure::class, RateLimiter::limiter($limiter));
            }
        } finally {
            $this->artisan('route:clear')->assertSuccessful();
        }
    }
}
