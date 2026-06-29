<?php

use App\Http\Middleware\AuthenticateAccountApiToken;
use App\Mcp\Servers\LadnaStudioServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Facades\Mcp;

RateLimiter::for('mcp', fn ($request): Limit => Limit::perMinute(120)->by(
    $request->attributes->get('accountApiToken')?->id ?: $request->ip(),
));

Mcp::web('/mcp/ladna-studio', LadnaStudioServer::class)
    ->middleware([
        AuthenticateAccountApiToken::class,
        'throttle:mcp',
    ])
    ->name('mcp.ladna-studio');
