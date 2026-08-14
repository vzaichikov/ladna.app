<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OwnerOnboardingController;
use App\Http\Controllers\OwnerOnboardingOtpController;
use App\Http\Controllers\PublicDemoSignupController;
use App\Http\Middleware\EnsurePublicOwnerOnboardingEnabled;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$appCompatibilityRedirect = static function (Request $request, string $path): RedirectResponse {
    $query = $request->getQueryString();
    $target = '/app'.($path !== '' ? '/'.ltrim($path, '/') : '');

    return redirect()->to($target.($query ? '?'.$query : ''), 308);
};

Route::middleware('guest:web')
    ->prefix('app')
    ->group(function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::get('en/login', [LoginController::class, 'createEnglish'])->name('login.en');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
        Route::get('register', [RegisterController::class, 'create'])
            ->middleware(EnsurePublicOwnerOnboardingEnabled::class)
            ->name('register');
        Route::post('register', [RegisterController::class, 'store'])
            ->middleware([EnsurePublicOwnerOnboardingEnabled::class, 'throttle:owner-registration']);
    });

Route::middleware('guest:web')->group(function (): void {
    Route::any('/register', function (): void {
        abort(404);
    });
    Route::get('/demo', [LoginController::class, 'demo'])->name('demo.login');
});

Route::get('/demo/{accountSignupRequest}/return', [PublicDemoSignupController::class, 'returned'])->name('demo.return');

Route::any('/login', fn (Request $request): RedirectResponse => $appCompatibilityRedirect($request, 'login'));
Route::any('/en/login', fn (Request $request): RedirectResponse => $appCompatibilityRedirect($request, 'en/login'));
Route::any('/dashboard/{legacyPath?}', fn (Request $request, ?string $legacyPath = null): RedirectResponse => $appCompatibilityRedirect($request, 'dashboard'.($legacyPath ? '/'.$legacyPath : '')))
    ->where('legacyPath', '.*');
Route::any('/platform/{legacyPath?}', fn (Request $request, ?string $legacyPath = null): RedirectResponse => $appCompatibilityRedirect($request, 'platform'.($legacyPath ? '/'.$legacyPath : '')))
    ->where('legacyPath', '.*');
Route::get('/help/{legacyPath?}', fn (Request $request, ?string $legacyPath = null): RedirectResponse => $appCompatibilityRedirect($request, 'help'.($legacyPath ? '/'.$legacyPath : '')))
    ->where('legacyPath', '.*');
Route::post('/logout', fn (Request $request): RedirectResponse => $appCompatibilityRedirect($request, 'logout'))
    ->middleware('auth:web');

Route::post('/app/logout', [LoginController::class, 'destroy'])
    ->middleware('auth:web')
    ->name('logout');

Route::middleware('auth:web')
    ->prefix('app/onboarding')
    ->name('onboarding.')
    ->group(function (): void {
        Route::get('success', [OwnerOnboardingController::class, 'success'])->name('success');
        Route::post('share', [OwnerOnboardingController::class, 'trackShare'])
            ->middleware('throttle:owner-onboarding')
            ->name('share');
        Route::post('otp/send', [OwnerOnboardingOtpController::class, 'send'])
            ->middleware('throttle:owner-otp')
            ->name('otp.send');
        Route::post('otp/verify', [OwnerOnboardingOtpController::class, 'verify'])
            ->middleware('throttle:owner-otp-verify')
            ->name('otp.verify');
        Route::post('publish', [OwnerOnboardingController::class, 'publish'])
            ->middleware('throttle:owner-onboarding')
            ->name('publish');
        Route::get('{step?}', [OwnerOnboardingController::class, 'show'])
            ->whereNumber('step')
            ->name('show');
        Route::post('{step}', [OwnerOnboardingController::class, 'store'])
            ->whereNumber('step')
            ->middleware('throttle:owner-onboarding')
            ->name('store');
    });

Route::post('/locale', LocaleController::class)->name('locale.update');
