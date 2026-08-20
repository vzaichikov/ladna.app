<?php

namespace Tests\Feature;

use App\Support\Mcp\McpOAuthMetadata;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpRouteCacheTest extends TestCase
{
    public function test_mcp_runtime_configuration_is_registered_for_cached_http_routes(): void
    {
        $this->artisan('route:cache')->assertSuccessful();
        $originalRunningInConsole = getenv('APP_RUNNING_IN_CONSOLE');

        try {
            $this->setRunningInConsoleEnvironment('false');
            Passport::tokensCan([]);
            $this->refreshApplication();

            $this->assertFalse(app()->runningInConsole());
            $this->assertSame(McpOAuthMetadata::AUTHORIZATION_SCOPES, Passport::scopeIds());

            foreach (['mcp', 'mcp-oauth', 'mcp-oauth-register', 'mcp-oauth-token'] as $limiter) {
                $this->assertInstanceOf(Closure::class, RateLimiter::limiter($limiter));
            }
        } finally {
            $this->restoreRunningInConsoleEnvironment($originalRunningInConsole);
            Passport::tokensCan([]);
            $this->refreshApplication();
            $this->artisan('route:clear')->assertSuccessful();
        }
    }

    private function setRunningInConsoleEnvironment(string $value): void
    {
        putenv('APP_RUNNING_IN_CONSOLE='.$value);
        $_ENV['APP_RUNNING_IN_CONSOLE'] = $value;
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = $value;
    }

    private function restoreRunningInConsoleEnvironment(string|false $value): void
    {
        if ($value === false) {
            putenv('APP_RUNNING_IN_CONSOLE');
            unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

            return;
        }

        $this->setRunningInConsoleEnvironment($value);
    }
}
