<?php

namespace App\Support\Sms;

use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\Account;
use App\Models\SmsTopUpPayment;
use App\Support\SaasBilling\MonopaySaasBilling;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class SmsAutoTopUpService
{
    public function __construct(
        private readonly SmsWalletService $wallets,
        private readonly CreateSmsTopUpPayment $createPayment,
        private readonly ChargeSmsTopUpPayment $chargePayment,
        private readonly MonopaySaasBilling $billing,
        private readonly SmsAccountNotifier $notifier,
    ) {}

    public function attempt(Account $account): ?SmsTopUpPayment
    {
        $lock = Cache::lock('sms-auto-top-up-account:'.$account->id, 60);

        if (! $lock->get()) {
            return $this->inFlightPayment($account);
        }

        try {
            return $this->attemptLocked($account);
        } finally {
            $lock->release();
        }
    }

    private function attemptLocked(Account $account): ?SmsTopUpPayment
    {
        $settings = $account->customerAuthSetting()->first();

        if (
            $account->isReadOnlyDemo()
            || $settings?->sms_sending_mode !== SmsSendingMode::LadnaService
        ) {
            return null;
        }

        $wallet = $account->smsWallet()->first();

        if (
            ! $wallet?->auto_top_up_enabled
            || $wallet->auto_top_up_suspended_at
            || $wallet->auto_top_up_threshold_cents === null
            || $wallet->auto_top_up_target_cents === null
            || $wallet->auto_top_up_monthly_cap_cents === null
        ) {
            return null;
        }

        $this->wallets->markAutomaticTopUpSpent($wallet, 0);
        $wallet->refresh();
        $spendableCents = $wallet->spendableBalanceCents();

        if ($spendableCents >= $wallet->auto_top_up_threshold_cents) {
            return null;
        }

        $amountCents = max(0, $wallet->auto_top_up_target_cents - $spendableCents);
        $remainingCapCents = max(
            0,
            $wallet->auto_top_up_monthly_cap_cents - $wallet->auto_top_up_monthly_spent_cents,
        );

        if ($amountCents === 0 || $amountCents > $remainingCapCents) {
            $wallet->forceFill(['auto_top_up_suspended_at' => now()])->save();
            $this->notifier->automaticTopUpFailed($account, 'monthly_cap_exceeded');

            return null;
        }

        $inFlight = $this->inFlightPayment($account);

        if ($inFlight) {
            return $inFlight;
        }

        $paymentMethod = $account->subscription?->paymentMethod()->first();
        $setting = $this->billing->platformSetting();

        if (! $paymentMethod?->isActive() || ! $setting) {
            $wallet->forceFill(['auto_top_up_suspended_at' => now()])->save();
            $this->notifier->automaticTopUpFailed($account, 'saved_card_or_payment_provider_unavailable');

            return null;
        }

        $payment = $this->createPayment->execute(
            account: $account,
            amountCents: $amountCents,
            kind: SmsTopUpKind::Automatic,
            idempotencyKey: implode(':', [
                'sms-auto-top-up',
                $wallet->id,
                Str::uuid(),
            ]),
        );

        try {
            $this->chargePayment->execute(
                payment: $payment,
                setting: $setting,
                redirectUrl: route('dashboard.accounts.sms-account.show', $account),
                ownerInitiated: false,
            );
        } catch (Throwable $exception) {
            report($exception);
            $wallet->forceFill(['auto_top_up_suspended_at' => now()])->save();
            $payment->forceFill([
                'status' => SmsTopUpPaymentStatus::PaymentFailed,
                'failure_reason' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();
            $this->notifier->automaticTopUpFailed($account, $exception->getMessage());
        }

        return $payment->refresh();
    }

    private function inFlightPayment(Account $account): ?SmsTopUpPayment
    {
        return SmsTopUpPayment::query()
            ->whereBelongsTo($account)
            ->where('kind', SmsTopUpKind::Automatic->value)
            ->whereIn('status', [
                SmsTopUpPaymentStatus::PaymentStarted->value,
                SmsTopUpPaymentStatus::PaymentPending->value,
            ])
            ->latest()
            ->first();
    }
}
