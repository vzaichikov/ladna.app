<?php

namespace App\Http\Controllers;

use App\Enums\SmsSendingMode;
use App\Http\Requests\UpdateSmsAutoTopUpRequest;
use App\Models\Account;
use App\Support\Sms\AccountSmsPricing;
use App\Support\Sms\SmsServiceSettings;
use App\Support\Sms\SmsWalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AccountSmsAutoTopUpController extends Controller
{
    public function update(
        UpdateSmsAutoTopUpRequest $request,
        Account $account,
        SmsWalletService $wallets,
        AccountSmsPricing $pricing,
        SmsServiceSettings $settings,
    ): RedirectResponse {
        $this->authorize('view', $account);
        $account->loadMissing(['customerAuthSetting', 'subscription.paymentMethod']);
        $values = $request->autoTopUpValues();

        if ($values['enabled']) {
            if (
                ! $settings->enabled()
                || $account->customerAuthSetting?->sms_sending_mode !== SmsSendingMode::LadnaService
                || ($pricing->segmentPriceCents($account) ?? 0) <= 0
            ) {
                throw ValidationException::withMessages([
                    'auto_top_up_enabled' => __('app.sms_auto_top_up_unavailable'),
                ]);
            }

            if (! $account->subscription?->paymentMethod?->isActive()) {
                throw ValidationException::withMessages([
                    'auto_top_up_enabled' => __('app.sms_auto_top_up_requires_saved_card'),
                ]);
            }
        }

        $wallets->walletFor($account)->forceFill([
            'auto_top_up_enabled' => $values['enabled'],
            'auto_top_up_threshold_cents' => $values['threshold_cents'],
            'auto_top_up_target_cents' => $values['target_cents'],
            'auto_top_up_monthly_cap_cents' => $values['monthly_cap_cents'],
            'auto_top_up_suspended_at' => null,
            'last_auto_top_up_failure_warning_at' => null,
        ])->save();

        return redirect()
            ->route('dashboard.accounts.sms-account.show', $account)
            ->with('status', __('app.sms_auto_top_up_saved'));
    }
}
