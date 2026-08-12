<?php

namespace App\Support\Sms;

use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\SmsDelivery;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\SmsGatewayAcceptanceStatus;
use App\Support\CustomerAuth\SmsGatewayDeliveryStatus;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\CustomerAuth\SmsGatewayStatusProvider;
use App\Support\CustomerAuth\SmsSegmentCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class StudioSmsSender
{
    public function __construct(
        private readonly CustomerAuthAvailability $availability,
        private readonly SmsGatewayResolver $gateways,
        private readonly SmsSegmentCalculator $segments,
        private readonly SmsWalletService $wallets,
        private readonly AccountSmsPricing $pricing,
        private readonly SmsServiceSettings $serviceSettings,
        private readonly SmsAccountNotifier $notifier,
    ) {}

    public function send(
        Account $account,
        string $phone,
        string $message,
        SmsDeliveryPurpose $purpose,
        ?Model $source = null,
        ?string $idempotencyKey = null,
    ): StudioSmsSendResult {
        $idempotencyKey ??= 'sms-delivery:'.Str::uuid();
        $existing = SmsDelivery::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return new StudioSmsSendResult($existing, $existing->status, $existing->last_error);
        }

        $settings = $this->availability->settingsFor($account);
        $mode = $settings->sms_sending_mode;
        $estimate = $this->segments->estimate($message);
        $providerSetting = $this->availability->smsSettingFor($account, $settings);
        $segmentPriceCents = $mode === SmsSendingMode::LadnaService
            ? $this->pricing->segmentPriceCents($account)
            : null;
        $subscriptionPlan = $account->subscription?->plan;
        $wallet = $mode === SmsSendingMode::LadnaService
            ? $account->smsWallet()->first()
            : null;

        if ($mode === SmsSendingMode::LadnaService && $segmentPriceCents !== null && $segmentPriceCents > 0 && ! $wallet) {
            $wallet = $this->wallets->walletFor($account);
        }
        $delivery = new SmsDelivery([
            'account_id' => $account->id,
            'account_sms_wallet_id' => $wallet?->id,
            'subscription_plan_id' => $subscriptionPlan?->id,
            'subscription_plan_name_snapshot' => $subscriptionPlan?->name,
            'purpose' => $purpose,
            'source_mode' => $mode,
            'provider' => $providerSetting?->provider->value,
            'status' => SmsDeliveryStatus::Pending,
            'recipient_phone' => $phone,
            'message_preview' => $purpose->isAuthenticationOtp()
                ? null
                : Str::limit($message, 255, ''),
            'idempotency_key' => $idempotencyKey,
            'estimated_segments' => max(1, $estimate->segments),
            'sms_segment_price_cents' => $segmentPriceCents,
            'currency' => 'UAH',
        ]);

        if ($source) {
            $delivery->source()->associate($source);
        }

        $delivery->save();

        if ($account->isReadOnlyDemo() || $mode === SmsSendingMode::Disabled) {
            return $this->failWithoutReservation($delivery, 'sms_disabled', 'SMS sending is disabled.');
        }

        if ($mode === SmsSendingMode::LadnaService && ! $this->serviceSettings->enabled()) {
            return $this->failWithoutReservation($delivery, 'ladna_sms_service_disabled', 'Ladna SMS service is unavailable.');
        }

        if ($mode === SmsSendingMode::LadnaService && $segmentPriceCents === null) {
            return $this->failWithoutReservation($delivery, 'sms_tariff_unavailable', 'SMS is unavailable for this tariff.');
        }

        if ($mode === SmsSendingMode::LadnaService && $wallet?->outstanding_cents > 0) {
            return $this->failWithoutReservation(
                $delivery,
                'outstanding_sms_debt',
                'Outstanding SMS credit must be settled before sending.',
            );
        }

        if (! $providerSetting) {
            return $this->failWithoutReservation($delivery, 'sms_provider_unavailable', 'SMS provider is unavailable.');
        }

        if ($purpose->isAuthenticationOtp() && $this->otpLimitExceeded($account)) {
            return $this->failWithoutReservation($delivery, 'otp_account_limit_reached', 'Account OTP sending limit reached.');
        }

        $estimatedAmountCents = (int) ($segmentPriceCents ?? 0) * max(1, $estimate->segments);

        if ($mode === SmsSendingMode::LadnaService && ! $this->wallets->reserve($delivery, $estimatedAmountCents)) {
            if ($purpose->isAuthenticationOtp()) {
                return $this->failWithoutReservation(
                    $delivery,
                    'insufficient_sms_credit',
                    'Insufficient SMS credit.',
                );
            }

            $delivery->forceFill([
                'status' => SmsDeliveryStatus::WaitingForCredit,
                'error_code' => 'insufficient_sms_credit',
                'last_error' => 'Insufficient SMS credit.',
            ])->save();
            $this->notifier->lowCredit($account);

            return new StudioSmsSendResult(
                $delivery->refresh(),
                SmsDeliveryStatus::WaitingForCredit,
                'Insufficient SMS credit.',
            );
        }

        if ($mode === SmsSendingMode::OwnGateway) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Reserved,
                'reserved_at' => now(),
            ])->save();
        }

        $gateway = $this->gateways->resolve($providerSetting);

        try {
            $result = $gateway->sendSms($phone, $message);
        } catch (Throwable $exception) {
            report($exception);
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Unknown,
                'error_code' => 'sms_provider_outcome_unknown',
                'last_error' => $this->sanitizedError($exception->getMessage()),
            ])->save();

            return new StudioSmsSendResult($delivery->refresh(), SmsDeliveryStatus::Unknown);
        }

        if ($result->acceptanceStatus === SmsGatewayAcceptanceStatus::Unknown) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Unknown,
                'error_code' => 'sms_provider_outcome_unknown',
                'last_error' => $this->sanitizedError($result->message),
            ])->save();

            return new StudioSmsSendResult($delivery->refresh(), SmsDeliveryStatus::Unknown, $result->message);
        }

        if (! $result->sent || $result->acceptanceStatus === SmsGatewayAcceptanceStatus::Rejected) {
            $delivery = $mode === SmsSendingMode::LadnaService
                ? $this->wallets->release(
                    $delivery,
                    SmsDeliveryStatus::Failed,
                    'sms_provider_rejected',
                    $result->message,
                )
                : $this->markOwnGatewayFailed($delivery, $result->message);

            return new StudioSmsSendResult($delivery, SmsDeliveryStatus::Failed, $result->message);
        }

        $billedSegments = max(1, $result->providerSegmentCount ?? $estimate->segments);
        $wholesaleCostCents = strtoupper((string) $result->wholesaleCostCurrency) === 'UAH'
            ? $result->wholesaleCostMinor
            : null;

        if ($mode === SmsSendingMode::LadnaService) {
            $delivery->forceFill([
                'provider_segments' => $result->providerSegmentCount,
                'provider_message_id' => $result->providerMessageId,
            ])->save();
            $delivery = $this->wallets->capture($delivery, $billedSegments, $wholesaleCostCents);

            if ($delivery->wallet?->outstanding_cents > 0) {
                $this->notifier->outstandingCredit($account);
            } elseif (
                $segmentPriceCents > 0
                && $delivery->wallet?->spendableBalanceCents() < $segmentPriceCents
            ) {
                $this->notifier->lowCredit($account);
            }
        } else {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Accepted,
                'provider_segments' => $result->providerSegmentCount,
                'billed_segments' => $billedSegments,
                'amount_cents' => null,
                'wholesale_cost_cents' => $wholesaleCostCents,
                'provider_message_id' => $result->providerMessageId,
                'accepted_at' => now(),
                'next_status_check_at' => now()->addMinutes(5),
                'status_polling_expires_at' => now()->addHours(48),
            ])->save();
        }

        if ($result->deliveryStatus === SmsGatewayDeliveryStatus::Delivered) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Delivered,
                'delivered_at' => now(),
                'next_status_check_at' => null,
            ])->save();
        } elseif (! ($gateway instanceof SmsGatewayStatusProvider) || blank($delivery->provider_message_id)) {
            $delivery->forceFill([
                'next_status_check_at' => null,
                'status_polling_expires_at' => null,
            ])->save();
        }

        if ($purpose->isAuthenticationOtp()) {
            $this->hitOtpLimits($account);
        }

        return new StudioSmsSendResult($delivery->refresh(), $delivery->status);
    }

    private function failWithoutReservation(
        SmsDelivery $delivery,
        string $errorCode,
        string $message,
    ): StudioSmsSendResult {
        $delivery->forceFill([
            'status' => SmsDeliveryStatus::Failed,
            'error_code' => $errorCode,
            'last_error' => $message,
            'failed_at' => now(),
        ])->save();

        return new StudioSmsSendResult($delivery->refresh(), SmsDeliveryStatus::Failed, $message);
    }

    private function markOwnGatewayFailed(SmsDelivery $delivery, ?string $message): SmsDelivery
    {
        $delivery->forceFill([
            'status' => SmsDeliveryStatus::Failed,
            'error_code' => 'sms_provider_rejected',
            'last_error' => $this->sanitizedError($message),
            'failed_at' => now(),
        ])->save();

        return $delivery->refresh();
    }

    private function otpLimitExceeded(Account $account): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->otpHourlyKey($account),
            $this->serviceSettings->otpHourlyLimit(),
        ) || RateLimiter::tooManyAttempts(
            $this->otpDailyKey($account),
            $this->serviceSettings->otpDailyLimit(),
        );
    }

    private function hitOtpLimits(Account $account): void
    {
        RateLimiter::hit($this->otpHourlyKey($account), 3600);
        RateLimiter::hit(
            $this->otpDailyKey($account),
            max(1, now($account->timezone ?: config('app.timezone'))->diffInSeconds(
                now($account->timezone ?: config('app.timezone'))->endOfDay(),
            )),
        );
    }

    private function otpHourlyKey(Account $account): string
    {
        return 'customer-otp-account-hour:'.$account->id;
    }

    private function otpDailyKey(Account $account): string
    {
        return 'customer-otp-account-day:'.$account->id.':'.now($account->timezone ?: config('app.timezone'))->toDateString();
    }

    private function sanitizedError(?string $error): ?string
    {
        return $error === null ? null : Str::limit(strip_tags($error), 1000, '');
    }
}
