<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\DismissFestivalPoweredBannerController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\McpConnectionGuideController;
use App\Http\Controllers\PwaController;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [HomeController::class, 'ukrainian'])->name('home');
Route::get('/en', [HomeController::class, 'english'])->name('home.en');
Route::get('/features', [HomeController::class, 'featuresUkrainian'])->name('features');
Route::get('/en/features', [HomeController::class, 'featuresEnglish'])->name('features.en');
Route::get('/founders', [HomeController::class, 'foundersUkrainian'])->name('founders');
Route::get('/en/founders', [HomeController::class, 'foundersEnglish'])->name('founders.en');
Route::post('/festival-powered-banner/dismiss', DismissFestivalPoweredBannerController::class)->name('festival-powered-banner.dismiss');

Route::get('/changelog.en.html', [ChangelogController::class, 'english'])->name('changelog.en');
Route::get('/changelog.ua.html', [ChangelogController::class, 'ukrainian'])->name('changelog.ua');
Route::get('/terms.en.html', [LegalPageController::class, 'termsEnglish'])->name('terms.en');
Route::get('/terms.ua.html', [LegalPageController::class, 'termsUkrainian'])->name('terms.ua');
Route::get('/privacy.en.html', [LegalPageController::class, 'privacyEnglish'])->name('privacy.en');
Route::get('/privacy.ua.html', [LegalPageController::class, 'privacyUkrainian'])->name('privacy.ua');
Route::get('/api-docs', [ApiDocumentationController::class, 'show'])->name('api-docs.show');
Route::get('/api-docs/openapi.json', [ApiDocumentationController::class, 'openApi'])->name('api-docs.openapi');
Route::controller(McpConnectionGuideController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, SetLocale::class])
    ->middleware('cache.headers:public;max_age=300;stale_while_revalidate=600;etag')
    ->group(function (): void {
        Route::get('/connect-ai/{account:slug}', 'show')
            ->name('mcp.connection-guide.show');
        Route::get('/connect-ai/{account:slug}/instructions.md', 'markdown')
            ->name('mcp.connection-guide.markdown');
    });
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, SetLocale::class])
    ->group(function (): void {
        Route::get('/app/app-version.json', [PwaController::class, 'version'])->name('pwa.version');
        Route::get('/app/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
        Route::get('/app/offline.html', [PwaController::class, 'offline'])->name('pwa.offline');
        Route::get('/app/service-worker', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');

        Route::get('/app-version.json', [PwaController::class, 'version'])->name('pwa.legacy-version');
        Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.legacy-manifest');
        Route::get('/offline.html', [PwaController::class, 'offline'])->name('pwa.legacy-offline');
        Route::get('/service-worker', [PwaController::class, 'retiringServiceWorker'])->name('pwa.retiring-service-worker');

        Route::get('/{accountSlug}/app-version.json', [PwaController::class, 'version'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->name('pwa.studio.version');
        Route::get('/{accountSlug}/manifest.webmanifest', [PwaController::class, 'studioManifest'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->name('pwa.studio.manifest');
        Route::get('/{accountSlug}/offline.html', [PwaController::class, 'studioOffline'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->name('pwa.studio.offline');
        Route::get('/{accountSlug}/service-worker', [PwaController::class, 'studioServiceWorker'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->name('pwa.studio.service-worker');
        Route::get('/{accountSlug}/pwa/icon-{size}', [PwaController::class, 'studioIcon'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->where('size', '180|192|512')
            ->name('pwa.studio.icon');
        Route::get('/{accountSlug}/pwa/{asset}', [PwaController::class, 'studioAsset'])
            ->where('accountSlug', '[A-Za-z0-9-]+')
            ->where('asset', 'maskable-icon-(192|512)|screenshot-(wide|narrow)')
            ->name('pwa.studio.asset');
    });
Route::get('/app', [HomeController::class, 'app'])->name('app.index');

Route::get('/app/help', [HelpController::class, 'index'])->name('help.index');
Route::get('/app/help/{slug}', [HelpController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->name('help.show');
