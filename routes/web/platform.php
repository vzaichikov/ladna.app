<?php

use App\Http\Controllers\Platform\AccountBillingEnrollmentController;
use App\Http\Controllers\Platform\AccountBillingTariffController;
use App\Http\Controllers\Platform\AccountFestivalCapabilityController;
use App\Http\Controllers\Platform\AccountPromoTariffController;
use App\Http\Controllers\Platform\AccountSmsController as PlatformAccountSmsController;
use App\Http\Controllers\Platform\AiProviderModelController as PlatformAiProviderModelController;
use App\Http\Controllers\Platform\AiUsageController as PlatformAiUsageController;
use App\Http\Controllers\Platform\CustomerNotificationController as PlatformCustomerNotificationController;
use App\Http\Controllers\Platform\EmailDeliveryController as PlatformEmailDeliveryController;
use App\Http\Controllers\Platform\EmailScenarioController as PlatformEmailScenarioController;
use App\Http\Controllers\Platform\IntegrationController as PlatformIntegrationController;
use App\Http\Controllers\Platform\OwnerTelegramWebhookController as PlatformOwnerTelegramWebhookController;
use App\Http\Controllers\Platform\PaymentController as PlatformPaymentController;
use App\Http\Controllers\Platform\PlatformAccountController;
use App\Http\Controllers\Platform\PlatformController;
use App\Http\Controllers\Platform\ProfileController as PlatformProfileController;
use App\Http\Controllers\Platform\ScheduledTaskController;
use App\Http\Controllers\Platform\SmsDeliveryController as PlatformSmsDeliveryController;
use App\Http\Controllers\Platform\SmsPaymentController as PlatformSmsPaymentController;
use App\Http\Controllers\Platform\StudioPossibilitiesController;
use App\Http\Controllers\Platform\SubscriptionPlanController;
use App\Http\Controllers\Platform\SubscriptionPriceVersionController;
use App\Http\Controllers\Platform\SystemSettingsController;
use App\Http\Controllers\Platform\TelegramSupportController as PlatformTelegramSupportController;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use App\Http\Middleware\RecordAccountActivity;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'can:accessPlatform', PreventReadOnlyDemoMutations::class])
    ->prefix('app/platform')
    ->name('platform.')
    ->group(function (): void {
        Route::get('/', PlatformController::class)->name('index');
        Route::get('account', [PlatformProfileController::class, 'edit'])->name('account.edit');
        Route::put('account', [PlatformProfileController::class, 'update'])->name('account.update');
        Route::get('settings', [SystemSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SystemSettingsController::class, 'update'])->name('settings.update');
        Route::get('settings/ai-usage', [PlatformAiUsageController::class, 'index'])->name('ai-usage.index');
        Route::put('settings/ai-usage', [PlatformAiUsageController::class, 'update'])->name('ai-usage.update');
        Route::post('settings/ai-usage/users/{user}/reset', [PlatformAiUsageController::class, 'resetUser'])->name('ai-usage.users.reset');
        Route::post('settings/ai-usage/accounts/{account}/reset', [PlatformAiUsageController::class, 'resetAccount'])->name('ai-usage.accounts.reset');
        Route::get('settings/ai-provider-models', PlatformAiProviderModelController::class)->name('settings.ai-provider-models');
        Route::get('settings/owner-telegram-bot/webhook-status', [PlatformOwnerTelegramWebhookController::class, 'show'])->name('settings.owner-telegram-bot.webhook-status');
        Route::post('settings/owner-telegram-bot/register-webhook', [PlatformOwnerTelegramWebhookController::class, 'store'])->name('settings.owner-telegram-bot.register-webhook');
        Route::delete('settings/owner-telegram-bot/webhook', [PlatformOwnerTelegramWebhookController::class, 'destroy'])->name('settings.owner-telegram-bot.delete-webhook');
        Route::get('telegram-support', [PlatformTelegramSupportController::class, 'index'])->name('telegram-support.index');
        Route::post('telegram-support/authorizations/{telegramAuthorization}/reset', [PlatformTelegramSupportController::class, 'reset'])->name('telegram-support.authorizations.reset');
        Route::delete('telegram-support/authorizations/{telegramAuthorization}', [PlatformTelegramSupportController::class, 'revoke'])->name('telegram-support.authorizations.revoke');
        Route::get('customer-notifications', [PlatformCustomerNotificationController::class, 'index'])->name('customer-notifications.index');
        Route::get('sms-deliveries', [PlatformSmsDeliveryController::class, 'index'])->name('sms-deliveries.index');
        Route::get('email-deliveries', [PlatformEmailDeliveryController::class, 'index'])->name('email-deliveries.index');
        Route::get('email-deliveries/{emailDelivery}/preview', [PlatformEmailDeliveryController::class, 'preview'])->name('email-deliveries.preview');
        Route::get('email-scenarios', [PlatformEmailScenarioController::class, 'index'])->name('email-scenarios.index');
        Route::put('email-scenarios', [PlatformEmailScenarioController::class, 'update'])->name('email-scenarios.update');
        Route::get('email-scenarios/{scenario}/{locale}/preview', [PlatformEmailScenarioController::class, 'preview'])
            ->name('email-scenarios.preview');
        Route::get('integrations', [PlatformIntegrationController::class, 'index'])->name('integrations.index');
        Route::put('integrations/central-sms-provider', [PlatformIntegrationController::class, 'updateCentralSmsProvider'])
            ->name('integrations.central-sms-provider.update');
        Route::put('integrations/{provider}', [PlatformIntegrationController::class, 'update'])->name('integrations.update');
        Route::get('scheduled-tasks', ScheduledTaskController::class)->name('scheduled-tasks.index');
        Route::get('payments', [PlatformPaymentController::class, 'index'])->name('payments.index');
        Route::get('sms-payments', [PlatformSmsPaymentController::class, 'index'])->name('sms-payments.index');
        Route::resource('accounts', PlatformAccountController::class);
        Route::post('accounts/{account}/billing/enroll', AccountBillingEnrollmentController::class)
            ->name('accounts.billing.enroll');
        Route::patch('accounts/{account}/billing/tariff', AccountBillingTariffController::class)
            ->name('accounts.billing.tariff.update');
        Route::patch('accounts/{account}/billing/promo-tariff', AccountPromoTariffController::class)
            ->name('accounts.billing.promo-tariff.update');
        Route::patch('accounts/{account}/festival-capability', AccountFestivalCapabilityController::class)
            ->name('accounts.festival-capability.update');
        Route::get('accounts/{account}/studio-possibilities', [StudioPossibilitiesController::class, 'edit'])
            ->name('accounts.studio-possibilities.edit');
        Route::put('accounts/{account}/studio-possibilities', [StudioPossibilitiesController::class, 'update'])
            ->middleware(RecordAccountActivity::class)
            ->name('accounts.studio-possibilities.update');
        Route::put('accounts/{account}/studio-possibilities/festival-templates', [StudioPossibilitiesController::class, 'updateFestivalTemplates'])
            ->middleware(RecordAccountActivity::class)
            ->name('accounts.studio-possibilities.festival-templates.update');
        Route::get('accounts/{account}/customer-auth', function (Request $request, Account $account): RedirectResponse {
            return redirect()->route('platform.accounts.studio-possibilities.edit', ['account' => $account] + $request->query());
        })->name('accounts.customer-auth.redirect');
        Route::get('accounts/{account}/sms-account', [PlatformAccountSmsController::class, 'show'])
            ->name('accounts.sms-account.show');
        Route::post('accounts/{account}/sms-account/adjustments', [PlatformAccountSmsController::class, 'adjust'])
            ->name('accounts.sms-account.adjust');
        Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['show']);
        Route::prefix('subscription-plans/{subscriptionPlan}/price-versions')
            ->name('subscription-plans.price-versions.')
            ->group(function (): void {
                Route::get('/', [SubscriptionPriceVersionController::class, 'index'])->name('index');
                Route::get('create', [SubscriptionPriceVersionController::class, 'create'])->name('create');
                Route::post('/', [SubscriptionPriceVersionController::class, 'store'])->name('store');
                Route::get('{priceVersion}/edit', [SubscriptionPriceVersionController::class, 'edit'])->name('edit');
                Route::put('{priceVersion}', [SubscriptionPriceVersionController::class, 'update'])->name('update');
                Route::get('{priceVersion}/preview', [SubscriptionPriceVersionController::class, 'preview'])->name('preview');
                Route::post('{priceVersion}/schedule', [SubscriptionPriceVersionController::class, 'schedule'])->name('schedule');
                Route::post('{priceVersion}/publish', [SubscriptionPriceVersionController::class, 'publish'])->name('publish');
                Route::post('{priceVersion}/retire', [SubscriptionPriceVersionController::class, 'retire'])->name('retire');
                Route::delete('{priceVersion}', [SubscriptionPriceVersionController::class, 'destroy'])->name('destroy');
            });
    });
