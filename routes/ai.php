<?php

use App\Http\Controllers\McpOAuthClientRegistrationController;
use App\Http\Middleware\AuthenticateAccountApiToken;
use App\Http\Middleware\PrepareMcpOAuthAuthorization;
use App\Http\Middleware\ResolveMcpOAuthConnection;
use App\Mcp\Servers\LadnaStudioServer;
use App\Models\Account;
use App\Support\Mcp\McpOAuthMetadata;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::get('/.well-known/oauth-protected-resource', static fn () => response()->json(
    app(McpOAuthMetadata::class)->legacyProtectedResource(),
))->name('mcp.oauth.protected-resource');

Route::get('/.well-known/oauth-protected-resource/{path}', static function (string $path) {
    if ($path === 'mcp/ladna-studio') {
        return response()->json(app(McpOAuthMetadata::class)->legacyProtectedResource());
    }

    abort_unless(preg_match('#^mcp/ladna-studio/([^/]+)$#', $path, $matches) === 1, 404);
    $account = Account::query()->where('slug', rawurldecode($matches[1]))->firstOrFail();

    return response()->json(app(McpOAuthMetadata::class)->protectedResource($account));
})->where('path', '.*')->name('mcp.oauth.protected-resource.nested');

Route::get('/.well-known/oauth-authorization-server', static fn () => response()->json(
    app(McpOAuthMetadata::class)->globalAuthorizationServer(),
))->name('mcp.oauth.authorization-server');

Route::get('/.well-known/oauth-authorization-server/{path}', static function (string $path) {
    abort_unless(preg_match('#^oauth/mcp/([^/]+)$#', $path, $matches) === 1, 404);
    $account = Account::query()->where('slug', rawurldecode($matches[1]))->firstOrFail();

    return response()->json(app(McpOAuthMetadata::class)->authorizationServer($account));
})->where('path', '.*')->name('mcp.oauth.authorization-server.nested');

Route::post('/oauth/mcp/{account:slug}/register', McpOAuthClientRegistrationController::class)
    ->middleware([SubstituteBindings::class, 'throttle:mcp-oauth-register'])
    ->name('mcp.oauth.register');

Route::prefix('oauth')->name('passport.')->group(function (): void {
    Route::post('token', [AccessTokenController::class, 'issueToken'])
        ->middleware('throttle:mcp-oauth-token')
        ->name('token');
    Route::get('authorize', [AuthorizationController::class, 'authorize'])
        ->middleware(['web', PrepareMcpOAuthAuthorization::class])
        ->name('authorizations.authorize');
    Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])
        ->middleware(['web', 'auth:web'])
        ->name('authorizations.approve');
    Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])
        ->middleware(['web', 'auth:web'])
        ->name('authorizations.deny');
});

Mcp::web('/mcp/ladna-studio', LadnaStudioServer::class)
    ->middleware([
        AuthenticateAccountApiToken::class,
        'throttle:mcp',
    ])
    ->name('mcp.ladna-studio');

Mcp::web('/mcp/ladna-studio/{accountSlug}', LadnaStudioServer::class)
    ->middleware([
        'auth:api',
        ResolveMcpOAuthConnection::class,
        'throttle:mcp-oauth',
    ])
    ->name('mcp.ladna-studio.oauth');
