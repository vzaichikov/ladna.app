<?php

use App\Enums\ScheduleKind;
use App\Http\Controllers\AccountActivityLogController;
use App\Http\Controllers\AccountAiTelegramSettingsController;
use App\Http\Controllers\AccountApiTokenController;
use App\Http\Controllers\AccountAssistantController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountIntegrationController;
use App\Http\Controllers\AccountOwnerProfileController;
use App\Http\Controllers\AccountPaymentController;
use App\Http\Controllers\AccountTariffPaymentController;
use App\Http\Controllers\ActivityDirectionController;
use App\Http\Controllers\AdminCustomerLoginController;
use App\Http\Controllers\ApiDocumentationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ClassBookingController;
use App\Http\Controllers\ClassBookingPaymentController;
use App\Http\Controllers\ClassPassPlanController;
use App\Http\Controllers\ClassPassSegmentController;
use App\Http\Controllers\ClassTypeController;
use App\Http\Controllers\ClosedClassBookingCorrectionController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerBookingCancellationController;
use App\Http\Controllers\CustomerBulkTransferController;
use App\Http\Controllers\CustomerClassPassController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerNotificationSettingsController;
use App\Http\Controllers\CustomerPurchaseCorrectionController;
use App\Http\Controllers\CustomerPurchaseReturnController;
use App\Http\Controllers\CustomerSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ManualScheduledClassController;
use App\Http\Controllers\PeopleCounterReportController;
use App\Http\Controllers\PeopleCounterScreenshotController;
use App\Http\Controllers\Platform\AiProviderModelController as PlatformAiProviderModelController;
use App\Http\Controllers\Platform\CustomerAuthSettingsController as PlatformCustomerAuthSettingsController;
use App\Http\Controllers\Platform\CustomerNotificationController as PlatformCustomerNotificationController;
use App\Http\Controllers\Platform\IntegrationController as PlatformIntegrationController;
use App\Http\Controllers\Platform\OwnerTelegramWebhookController as PlatformOwnerTelegramWebhookController;
use App\Http\Controllers\Platform\PaymentController as PlatformPaymentController;
use App\Http\Controllers\Platform\PlatformAccountController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\ProfileController as PlatformProfileController;
use App\Http\Controllers\Platform\ScheduledTaskController;
use App\Http\Controllers\Platform\SubscriptionPlanController;
use App\Http\Controllers\Platform\SystemSettingsController;
use App\Http\Controllers\Platform\TelegramSupportController as PlatformTelegramSupportController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicClassPassPurchaseController;
use App\Http\Controllers\PublicDemoSignupController;
use App\Http\Controllers\PublicPriceController;
use App\Http\Controllers\PublicScheduleController;
use App\Http\Controllers\PublicStudioLandingController;
use App\Http\Controllers\PublicStudioRulesController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QuickBookingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoomCameraTestController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomPeopleCounterMaskController;
use App\Http\Controllers\ScheduledClassCancellationController;
use App\Http\Controllers\ScheduledClassController;
use App\Http\Controllers\ScheduledClassHistoryController;
use App\Http\Controllers\ScheduleSeriesController;
use App\Http\Controllers\ServiceRoomController;
use App\Http\Controllers\StudioCashEntryController;
use App\Http\Controllers\StudioSettingsController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\TrainerPrivateTimeframeController;
use App\Http\Controllers\TrainerReportController;
use App\Http\Controllers\TrainerSubstitutionController;
use App\Http\Controllers\TrainerTypeController;
use App\Http\Controllers\UnknownPresenceReportController;
use App\Http\Controllers\UnpaidClassPaymentReportController;
use App\Http\Controllers\WebsiteLeadController;
use App\Http\Middleware\EnsureCustomerIsAuthenticated;
use App\Http\Middleware\EnsureCustomerProfileIsComplete;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventExpiredSubscriptionMutations;
use App\Http\Middleware\RecordAccountActivity;
use App\Http\Middleware\SetLocale;
use App\Models\Account;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [HomeController::class, 'ukrainian'])->name('home');
Route::get('/en', [HomeController::class, 'english'])->name('home.en');

Route::get('/changelog.en.html', [ChangelogController::class, 'english'])->name('changelog.en');
Route::get('/changelog.ua.html', [ChangelogController::class, 'ukrainian'])->name('changelog.ua');
Route::get('/terms.en.html', [LegalPageController::class, 'termsEnglish'])->name('terms.en');
Route::get('/terms.ua.html', [LegalPageController::class, 'termsUkrainian'])->name('terms.ua');
Route::get('/privacy.en.html', [LegalPageController::class, 'privacyEnglish'])->name('privacy.en');
Route::get('/privacy.ua.html', [LegalPageController::class, 'privacyUkrainian'])->name('privacy.ua');
Route::get('/api-docs', [ApiDocumentationController::class, 'show'])->name('api-docs.show');
Route::get('/api-docs/openapi.json', [ApiDocumentationController::class, 'openApi'])->name('api-docs.openapi');
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

$appCompatibilityRedirect = static function (Request $request, string $path): RedirectResponse {
    $query = $request->getQueryString();
    $target = '/app'.($path !== '' ? '/'.ltrim($path, '/') : '');

    return redirect()->to($target.($query ? '?'.$query : ''), 308);
};

Route::get('/app', [HomeController::class, 'app'])->name('app.index');

Route::get('/app/help', [HelpController::class, 'index'])->name('help.index');
Route::get('/app/help/{slug}', [HelpController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->name('help.show');

Route::middleware('guest:web')
    ->prefix('app')
    ->group(function (): void {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::get('en/login', [LoginController::class, 'createEnglish'])->name('login.en');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
    });

Route::middleware('guest:web')->group(function (): void {
    Route::any('/register', function (): void {
        abort(404);
    });
    Route::get('/demo', [PublicDemoSignupController::class, 'create'])->name('demo.signup.create');
    Route::post('/demo', [PublicDemoSignupController::class, 'store'])->middleware('throttle:demo-signup')->name('demo.signup.store');
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

Route::post('/locale', LocaleController::class)->name('locale.update');
Route::get('/customer/login', [CustomerAuthController::class, 'create'])->name('customer.login');
Route::get('/customer/auth/google/callback', [CustomerAuthController::class, 'googleCallback'])->name('customer.google.callback');
Route::get('/customer/studios', [CustomerAuthController::class, 'studios'])->name('customer.studios.index');
Route::post('/customer/studios/{customerId}/switch', [CustomerAuthController::class, 'switchStudio'])
    ->whereNumber('customerId')
    ->name('customer.studios.switch');

Route::prefix('{accountSlug}/customer')
    ->name('customer.')
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->group(function (): void {
        Route::get('login', [CustomerAuthController::class, 'studioLogin'])->name('studio.login');
        Route::get('admin-login/{token}', [AdminCustomerLoginController::class, 'consume'])
            ->middleware(['signed', 'throttle:customer-login'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('admin-login.consume');
        Route::post('login/email', [CustomerAuthController::class, 'emailLogin'])->middleware('throttle:customer-login')->name('email.login');
        Route::post('login/otp', [CustomerAuthController::class, 'sendOtp'])->middleware('throttle:customer-otp')->name('otp.send');
        Route::get('login/otp', [CustomerAuthController::class, 'otpChallenge'])->name('otp.challenge');
        Route::post('login/otp/resend', [CustomerAuthController::class, 'resendOtp'])->middleware('throttle:customer-otp')->name('otp.resend');
        Route::post('login/otp/change-phone', [CustomerAuthController::class, 'changeOtpPhone'])->name('otp.change-phone');
        Route::post('login/otp/verify', [CustomerAuthController::class, 'verifyOtp'])->middleware('throttle:customer-login')->name('otp.verify');
        Route::get('auth/google', [CustomerAuthController::class, 'googleRedirect'])->name('google.redirect');
        Route::get('auth/google/phone', [CustomerAuthController::class, 'googlePhone'])->name('google.phone');
        Route::post('auth/google/phone', [CustomerAuthController::class, 'sendGooglePhoneOtp'])->middleware('throttle:customer-otp')->name('google.phone.send');
        Route::post('auth/google/phone/resend', [CustomerAuthController::class, 'resendGooglePhoneOtp'])->middleware('throttle:customer-otp')->name('google.phone.resend');
        Route::post('auth/google/phone/change-phone', [CustomerAuthController::class, 'changeGooglePhone'])->name('google.phone.change');
        Route::post('auth/google/phone/verify', [CustomerAuthController::class, 'verifyGooglePhoneOtp'])->middleware('throttle:customer-login')->name('google.phone.verify');

        Route::middleware(EnsureCustomerIsAuthenticated::class)->group(function (): void {
            Route::get('profile/complete', [CustomerAuthController::class, 'editProfile'])->name('profile.complete');
            Route::put('profile', [CustomerAuthController::class, 'updateProfile'])->name('profile.update');
            Route::post('profile/phone/send', [CustomerAuthController::class, 'sendProfilePhoneOtp'])->middleware('throttle:customer-otp')->name('profile.phone.send');
            Route::post('profile/phone/resend', [CustomerAuthController::class, 'resendProfilePhoneOtp'])->middleware('throttle:customer-otp')->name('profile.phone.resend');
            Route::post('profile/phone/change', [CustomerAuthController::class, 'changeProfilePhone'])->name('profile.phone.change');
            Route::post('profile/phone/verify', [CustomerAuthController::class, 'verifyProfilePhoneOtp'])->middleware('throttle:customer-login')->name('profile.phone.verify');
            Route::post('logout', [CustomerAuthController::class, 'logout'])->name('logout');

            Route::middleware(EnsureCustomerProfileIsComplete::class)->group(function (): void {
                Route::get('/', [CustomerAuthController::class, 'studioDashboard'])->name('dashboard');
                Route::get('profile', [CustomerAuthController::class, 'editProfile'])->name('profile.edit');
                Route::patch('bookings/{classBooking}/cancel', CustomerBookingCancellationController::class)->name('bookings.cancel');
                Route::get('purchases/{customerPurchase}/return', CustomerPurchaseReturnController::class)->name('purchases.return');
            });
        });
    });

Route::get('/{accountSlug}/client/login', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.studio.login', $accountSlug))
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('customer.legacy.login');
Route::get('/{accountSlug}/client', fn (string $accountSlug): RedirectResponse => redirect()->route('customer.dashboard', $accountSlug))
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('customer.studio.dashboard');

Route::middleware(['auth:web', 'can:accessPlatform'])
    ->prefix('app/platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('/', PlatformController::class)->name('index');
        Route::get('account', [PlatformProfileController::class, 'edit'])->name('account.edit');
        Route::put('account', [PlatformProfileController::class, 'update'])->name('account.update');
        Route::get('settings', [SystemSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::get('settings/ai-provider-models', PlatformAiProviderModelController::class)->name('settings.ai-provider-models');
        Route::get('settings/owner-telegram-bot/webhook-status', [PlatformOwnerTelegramWebhookController::class, 'show'])->name('settings.owner-telegram-bot.webhook-status');
        Route::post('settings/owner-telegram-bot/register-webhook', [PlatformOwnerTelegramWebhookController::class, 'store'])->name('settings.owner-telegram-bot.register-webhook');
        Route::delete('settings/owner-telegram-bot/webhook', [PlatformOwnerTelegramWebhookController::class, 'destroy'])->name('settings.owner-telegram-bot.delete-webhook');
        Route::get('telegram-support', [PlatformTelegramSupportController::class, 'index'])->name('telegram-support.index');
        Route::post('telegram-support/authorizations/{telegramAuthorization}/reset', [PlatformTelegramSupportController::class, 'reset'])->name('telegram-support.authorizations.reset');
        Route::delete('telegram-support/authorizations/{telegramAuthorization}', [PlatformTelegramSupportController::class, 'revoke'])->name('telegram-support.authorizations.revoke');
        Route::get('customer-notifications', [PlatformCustomerNotificationController::class, 'index'])->name('customer-notifications.index');
        Route::get('integrations', [PlatformIntegrationController::class, 'index'])->name('integrations.index');
        Route::put('integrations/{provider}', [PlatformIntegrationController::class, 'update'])->name('integrations.update');
        Route::get('scheduled-tasks', ScheduledTaskController::class)->name('scheduled-tasks.index');
        Route::get('payments', [PlatformPaymentController::class, 'index'])->name('payments.index');
        Route::resource('accounts', PlatformAccountController::class);
        Route::get('accounts/{account}/customer-auth', [PlatformCustomerAuthSettingsController::class, 'edit'])
            ->name('accounts.customer-auth.edit');
        Route::put('accounts/{account}/customer-auth', [PlatformCustomerAuthSettingsController::class, 'update'])
            ->name('accounts.customer-auth.update');
        Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
    });

Route::middleware(['auth:web', PreventExpiredSubscriptionMutations::class, RecordAccountActivity::class])
    ->prefix('app/dashboard')
    ->name('dashboard.')
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('index');
        Route::resource('accounts', AccountController::class);
        Route::get('accounts/{account}/general-settings', [AccountController::class, 'editBrand'])
            ->name('accounts.general-settings.edit');
        Route::get('accounts/{account}/brand', function (Request $request, Account $account): RedirectResponse {
            return redirect()->route('dashboard.accounts.general-settings.edit', ['account' => $account] + $request->query());
        })
            ->name('accounts.brand.edit');
        Route::get('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'edit'])
            ->name('accounts.owner-profile.edit');
        Route::put('accounts/{account}/owner-profile', [AccountOwnerProfileController::class, 'update'])
            ->name('accounts.owner-profile.update');
        Route::get('accounts/{account}/tariff-payments', [AccountTariffPaymentController::class, 'show'])
            ->name('accounts.tariff-payments.show');
        Route::post('accounts/{account}/tariff-payments/pay-now', [AccountTariffPaymentController::class, 'payNow'])
            ->name('accounts.tariff-payments.pay-now');
        Route::get('accounts/{account}/payments', [AccountPaymentController::class, 'index'])
            ->name('accounts.payments.index');
        Route::post('accounts/{account}/cash-entries', [StudioCashEntryController::class, 'store'])
            ->name('accounts.cash-entries.store');
        Route::post('accounts/{account}/payments/{customerPurchase}/corrections', [CustomerPurchaseCorrectionController::class, 'store'])
            ->name('accounts.payments.corrections.store');
        Route::get('accounts/{account}/reports', [ReportController::class, 'index'])
            ->name('accounts.reports.index');
        Route::get('accounts/{account}/reports/trainers', TrainerReportController::class)
            ->name('accounts.reports.trainers');
        Route::get('accounts/{account}/reports/unpaid-class-payments', UnpaidClassPaymentReportController::class)
            ->name('accounts.reports.unpaid-class-payments');
        Route::get('accounts/{account}/reports/people-counter', PeopleCounterReportController::class)
            ->name('accounts.reports.people-counter');
        Route::get('accounts/{account}/reports/unknown-presence', UnknownPresenceReportController::class)
            ->name('accounts.reports.unknown-presence');
        Route::get('accounts/{account}/people-counter-samples/{peopleCounterSample}/{variant}', PeopleCounterScreenshotController::class)
            ->whereIn('variant', ['original', 'masked'])
            ->name('accounts.people-counter-samples.image');
        Route::get('accounts/{account}/cameras', [CameraController::class, 'index'])
            ->name('accounts.cameras.index');
        Route::get('accounts/{account}/activity-logs', [AccountActivityLogController::class, 'index'])
            ->name('accounts.activity-logs.index');
        Route::post('accounts/{account}/api-tokens', [AccountApiTokenController::class, 'store'])
            ->name('accounts.api-tokens.store');
        Route::post('accounts/{account}/api-tokens/{accountApiToken}/regenerate', [AccountApiTokenController::class, 'regenerate'])
            ->name('accounts.api-tokens.regenerate');
        Route::delete('accounts/{account}/api-tokens/{accountApiToken}', [AccountApiTokenController::class, 'destroy'])
            ->name('accounts.api-tokens.destroy');
        Route::put('accounts/{account}/customer-notification-settings', [CustomerNotificationSettingsController::class, 'update'])
            ->name('accounts.customer-notification-settings.update');
        Route::put('accounts/{account}/ai-telegram-settings', [AccountAiTelegramSettingsController::class, 'update'])
            ->name('accounts.ai-telegram-settings.update');
        Route::get('accounts/{account}/assistant', [AccountAssistantController::class, 'show'])
            ->name('accounts.assistant.show');
        Route::post('accounts/{account}/assistant/messages', [AccountAssistantController::class, 'store'])
            ->name('accounts.assistant.messages.store');
        Route::delete('accounts/{account}/assistant', [AccountAssistantController::class, 'destroy'])
            ->name('accounts.assistant.destroy');
        Route::post('accounts/{account}/assistant/actions/{action}/confirm', [AccountAssistantController::class, 'confirm'])
            ->name('accounts.assistant.actions.confirm');
        Route::post('accounts/{account}/assistant/actions/{action}/cancel', [AccountAssistantController::class, 'cancel'])
            ->name('accounts.assistant.actions.cancel');
        Route::resource('accounts.locations', LocationController::class)
            ->except(['show'])
            ->scoped();
        Route::match(['post', 'put', 'patch'], 'accounts/{account}/rooms/test-camera', RoomCameraTestController::class)
            ->name('accounts.rooms.test-camera');
        Route::match(['post', 'put', 'patch'], 'accounts/{account}/service-rooms/test-camera', RoomCameraTestController::class)
            ->name('accounts.service-rooms.test-camera');
        Route::resource('accounts.service-rooms', ServiceRoomController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/rooms/{room}/people-counter-mask', [RoomPeopleCounterMaskController::class, 'edit'])
            ->name('accounts.rooms.people-counter-mask.edit');
        Route::post('accounts/{account}/rooms/{room}/people-counter-mask/snapshot', [RoomPeopleCounterMaskController::class, 'capture'])
            ->name('accounts.rooms.people-counter-mask.capture');
        Route::get('accounts/{account}/rooms/{room}/people-counter-mask/snapshot', [RoomPeopleCounterMaskController::class, 'snapshot'])
            ->name('accounts.rooms.people-counter-mask.snapshot');
        Route::put('accounts/{account}/rooms/{room}/people-counter-mask', [RoomPeopleCounterMaskController::class, 'update'])
            ->name('accounts.rooms.people-counter-mask.update');
        Route::resource('accounts.rooms', RoomController::class)
            ->except(['show'])
            ->scoped();
        Route::post('accounts/{account}/activity-directions/{activity_direction}/copy', [ActivityDirectionController::class, 'copy'])
            ->name('accounts.activity-directions.copy');
        Route::resource('accounts.activity-directions', ActivityDirectionController::class)
            ->except(['show'])
            ->scoped();

        foreach ([
            ['group-classes', 'group-classes', ScheduleKind::GroupClass],
            ['private-lessons', 'private-lessons', ScheduleKind::PrivateLesson],
            ['room-rentals', 'room-rentals', ScheduleKind::RoomRental],
        ] as [$uri, $name, $scheduleKind]) {
            Route::get("accounts/{account}/{$uri}", [ClassTypeController::class, 'index'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.index");
            Route::get("accounts/{account}/{$uri}/create", [ClassTypeController::class, 'create'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.create");
            Route::post("accounts/{account}/{$uri}", [ClassTypeController::class, 'store'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.store");
            Route::post("accounts/{account}/{$uri}/{class_type}/copy", [ClassTypeController::class, 'copy'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.copy");
            Route::get("accounts/{account}/{$uri}/{class_type}/edit", [ClassTypeController::class, 'edit'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.edit");
            Route::match(['put', 'patch'], "accounts/{account}/{$uri}/{class_type}", [ClassTypeController::class, 'update'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.update");
            Route::delete("accounts/{account}/{$uri}/{class_type}", [ClassTypeController::class, 'destroy'])
                ->defaults('schedule_kind', $scheduleKind->value)
                ->name("accounts.{$name}.destroy");
        }

        Route::get('accounts/{account}/class-types', fn (Account $account): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.index', $account))
            ->name('accounts.class-types.index');
        Route::get('accounts/{account}/class-types/create', fn (Account $account): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.create', $account))
            ->name('accounts.class-types.create');
        Route::get('accounts/{account}/class-types/{class_type}/edit', fn (Account $account, int $class_type): RedirectResponse => redirect()->route('dashboard.accounts.group-classes.edit', [$account, $class_type]))
            ->name('accounts.class-types.edit');
        Route::post('accounts/{account}/class-types/{class_type}/copy', [ClassTypeController::class, 'copy'])
            ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
            ->name('accounts.class-types.copy');
        Route::post('accounts/{account}/class-types', [ClassTypeController::class, 'store'])
            ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
            ->name('accounts.class-types.store');
        Route::match(['put', 'patch'], 'accounts/{account}/class-types/{class_type}', [ClassTypeController::class, 'update'])
            ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
            ->name('accounts.class-types.update');
        Route::delete('accounts/{account}/class-types/{class_type}', [ClassTypeController::class, 'destroy'])
            ->defaults('schedule_kind', ScheduleKind::GroupClass->value)
            ->name('accounts.class-types.destroy');

        Route::post('accounts/{account}/class-pass-plans/{class_pass_plan}/copy', [ClassPassPlanController::class, 'copy'])
            ->name('accounts.class-pass-plans.copy');
        Route::resource('accounts.class-pass-plans', ClassPassPlanController::class)
            ->except(['show'])
            ->scoped();
        Route::resource('accounts.class-pass-segments', ClassPassSegmentController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/customer-class-passes', [CustomerClassPassController::class, 'index'])
            ->name('accounts.customer-class-passes.index');
        Route::get('accounts/{account}/customer-class-passes/{customerClassPass}/edit', [CustomerClassPassController::class, 'edit'])
            ->name('accounts.customer-class-passes.edit');
        Route::put('accounts/{account}/customer-class-passes/{customerClassPass}', [CustomerClassPassController::class, 'update'])
            ->name('accounts.customer-class-passes.update');
        Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/payments', [CustomerClassPassController::class, 'storePayment'])
            ->name('accounts.customer-class-passes.payments.store');
        Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/adjustments', [CustomerClassPassController::class, 'storeAdjustment'])
            ->name('accounts.customer-class-passes.adjustments.store');
        Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/validity-adjustments', [CustomerClassPassController::class, 'storeValidityAdjustment'])
            ->name('accounts.customer-class-passes.validity-adjustments.store');
        Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/freeze', [CustomerClassPassController::class, 'freeze'])
            ->name('accounts.customer-class-passes.freeze');
        Route::post('accounts/{account}/customer-class-passes/{customerClassPass}/unfreeze', [CustomerClassPassController::class, 'unfreeze'])
            ->name('accounts.customer-class-passes.unfreeze');
        Route::get('accounts/{account}/trainers/{trainer}/substitutions/classes', [TrainerSubstitutionController::class, 'classes'])
            ->name('accounts.trainers.substitutions.classes');
        Route::post('accounts/{account}/trainers/{trainer}/substitutions', [TrainerSubstitutionController::class, 'store'])
            ->name('accounts.trainers.substitutions.store');
        Route::put('accounts/{account}/trainers/{trainer}/substitutions/{trainerSubstitution}', [TrainerSubstitutionController::class, 'update'])
            ->name('accounts.trainers.substitutions.update');
        Route::delete('accounts/{account}/trainers/{trainer}/substitutions/{trainerSubstitution}', [TrainerSubstitutionController::class, 'destroy'])
            ->name('accounts.trainers.substitutions.destroy');
        Route::get('accounts/{account}/trainer-private-timeframes', [TrainerPrivateTimeframeController::class, 'mine'])
            ->name('accounts.trainer-private-timeframes.mine');
        Route::get('accounts/{account}/trainers/{trainer}/private-timeframes', [TrainerPrivateTimeframeController::class, 'edit'])
            ->name('accounts.trainers.private-timeframes.edit');
        Route::post('accounts/{account}/trainers/{trainer}/private-timeframes/toggle', [TrainerPrivateTimeframeController::class, 'toggle'])
            ->name('accounts.trainers.private-timeframes.toggle');
        Route::resource('accounts.trainers', TrainerController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/studio-settings', [StudioSettingsController::class, 'index'])
            ->name('accounts.studio-settings.index');
        Route::resource('accounts.trainer-types', TrainerTypeController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->scoped();
        Route::get('accounts/{account}/customers/export', [CustomerBulkTransferController::class, 'export'])
            ->name('accounts.customers.export');
        Route::get('accounts/{account}/customers/import-example', [CustomerBulkTransferController::class, 'example'])
            ->name('accounts.customers.example');
        Route::post('accounts/{account}/customers/import/validate', [CustomerBulkTransferController::class, 'validateImport'])
            ->name('accounts.customers.import.validate');
        Route::post('accounts/{account}/customers/import', [CustomerBulkTransferController::class, 'import'])
            ->name('accounts.customers.import');
        Route::post('accounts/{account}/customers/{customer}/admin-login', [AdminCustomerLoginController::class, 'store'])
            ->name('accounts.customers.admin-login.store');
        Route::resource('accounts.customers', CustomerController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/website-leads', [WebsiteLeadController::class, 'index'])
            ->name('accounts.website-leads.index');
        Route::post('accounts/{account}/website-leads', [WebsiteLeadController::class, 'store'])
            ->name('accounts.website-leads.store');
        Route::patch('accounts/{account}/website-leads/{websiteLead}', [WebsiteLeadController::class, 'update'])
            ->name('accounts.website-leads.update');
        Route::delete('accounts/{account}/website-leads/{websiteLead}', [WebsiteLeadController::class, 'destroy'])
            ->name('accounts.website-leads.destroy');
        Route::post('accounts/{account}/customers/{customer}/class-passes', [CustomerClassPassController::class, 'store'])
            ->name('accounts.customers.class-passes.store');
        Route::post('accounts/{account}/customers/{customer}/class-passes/backfill', [CustomerClassPassController::class, 'backfill'])
            ->name('accounts.customers.class-passes.backfill');
        Route::get('accounts/{account}/customers/search', CustomerSearchController::class)
            ->name('accounts.customers.search');
        Route::resource('accounts.schedule-series', ScheduleSeriesController::class)
            ->except(['show'])
            ->scoped();
        Route::get('accounts/{account}/integrations', [AccountIntegrationController::class, 'index'])
            ->name('accounts.integrations.index');
        Route::put('accounts/{account}/integrations/{provider}', [AccountIntegrationController::class, 'update'])
            ->name('accounts.integrations.update');
        Route::get('accounts/{account}/scheduled-classes', ScheduledClassController::class)
            ->name('accounts.scheduled-classes.index');
        Route::get('accounts/{account}/scheduled-classes-history', ScheduledClassHistoryController::class)
            ->name('accounts.scheduled-classes-history.index');
        Route::post('accounts/{account}/quick-bookings', [QuickBookingController::class, 'store'])
            ->name('accounts.quick-bookings.store');
        Route::get('accounts/{account}/quick-bookings/group-availability', [QuickBookingController::class, 'groupAvailability'])
            ->name('accounts.quick-bookings.group-availability');
        Route::get('accounts/{account}/quick-bookings/manual-availability', [QuickBookingController::class, 'manualAvailability'])
            ->name('accounts.quick-bookings.manual-availability');
        Route::post('accounts/{account}/scheduled-classes/manual/{scheduleKind}', [ManualScheduledClassController::class, 'store'])
            ->name('accounts.scheduled-classes.manual.store');
        Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/cancel', [ScheduledClassCancellationController::class, 'cancel'])
            ->name('accounts.scheduled-classes.cancel');
        Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/cancel-closed', [ScheduledClassCancellationController::class, 'cancelClosed'])
            ->name('accounts.scheduled-classes.cancel-closed');
        Route::patch('accounts/{account}/scheduled-classes/{scheduledClass}/restore', [ScheduledClassCancellationController::class, 'restore'])
            ->name('accounts.scheduled-classes.restore');
        Route::get('accounts/{account}/scheduled-classes/{scheduledClass}/corrections/pass-preview', [ClosedClassBookingCorrectionController::class, 'preview'])
            ->name('accounts.scheduled-classes.corrections.pass-preview');
        Route::post('accounts/{account}/scheduled-classes/{scheduledClass}/corrections/bookings', [ClosedClassBookingCorrectionController::class, 'store'])
            ->name('accounts.scheduled-classes.corrections.bookings.store');
        Route::post('accounts/{account}/scheduled-classes/{scheduledClass}/bookings', [ClassBookingController::class, 'store'])
            ->name('accounts.scheduled-classes.bookings.store');
        Route::patch('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'update'])
            ->name('accounts.bookings.update');
        Route::post('accounts/{account}/bookings/{classBooking}/corrections/remove', [ClosedClassBookingCorrectionController::class, 'remove'])
            ->name('accounts.bookings.corrections.remove');
        Route::post('accounts/{account}/bookings/{classBooking}/payment', [ClassBookingPaymentController::class, 'store'])
            ->name('accounts.bookings.payment.store');
        Route::delete('accounts/{account}/bookings/{classBooking}', [ClassBookingController::class, 'destroy'])
            ->name('accounts.bookings.destroy');
    });

Route::get('/{accountSlug}', PublicStudioLandingController::class)
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.studio');
Route::get('/{accountSlug}/rules', PublicStudioRulesController::class)
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.studio-rules');
Route::get('/{accountSlug}/{locationSlug}/schedule', [PublicScheduleController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.schedule');
Route::get('/{accountSlug}/{locationSlug}/schedule/embed', [PublicScheduleController::class, 'embed'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.schedule.embed');
Route::get('/{accountSlug}/{locationSlug}/schedule/book', [PublicBookingController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.booking.show');
Route::post('/{accountSlug}/{locationSlug}/schedule/book', [PublicBookingController::class, 'store'])
    ->middleware([EnsurePublicSubscriptionIsActive::class, 'throttle:public-booking'])
    ->name('public.booking.store');
Route::get('/{accountSlug}/{locationSlug}/price', [PublicPriceController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.price');
Route::get('/{accountSlug}/{locationSlug}/price/embed', [PublicPriceController::class, 'embed'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.price.embed');
Route::get('/{accountSlug}/{locationSlug}/price/{classPassPlanSlug}/buy', [PublicClassPassPurchaseController::class, 'show'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.class-pass-plans.buy');
Route::post('/{accountSlug}/{locationSlug}/price/{classPassPlanSlug}/buy', [PublicClassPassPurchaseController::class, 'store'])
    ->middleware(EnsurePublicSubscriptionIsActive::class)
    ->name('public.class-pass-plans.purchase');
