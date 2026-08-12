<?php

namespace App\Support\Festivals;

use App\Enums\FestivalPortalRole;
use App\Enums\SmsDeliveryPurpose;
use App\Models\Account;
use App\Models\FestivalOtpChallenge;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\PhoneNumberNormalizer;
use App\Support\Sms\StudioSmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FestivalOtpService
{
    public function __construct(
        private readonly CustomerAuthAvailability $availability,
        private readonly StudioSmsSender $smsSender,
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    public function send(Account $account, FestivalPortalRole $role, string $phone, ?string $ipAddress = null, ?string $userAgent = null): FestivalOtpResult
    {
        $normalizedPhone = $this->phones->normalize($phone, $account->country_code ?? 'UA');

        if (! $normalizedPhone || ! $this->phones->isValid($normalizedPhone, $account->country_code ?? 'UA')) {
            return FestivalOtpResult::failed(__('app.customer_auth_phone_invalid'));
        }

        $settings = $this->availability->settingsFor($account);
        $smsSetting = $settings->allow_otp ? $this->availability->smsSettingFor($account, $settings) : null;

        if (! $smsSetting) {
            return FestivalOtpResult::failed(__('app.customer_auth_method_unavailable'));
        }

        $existing = $this->activeChallenge($account, $role, $normalizedPhone);
        $now = now();

        if ($existing && $existing->resend_available_at?->isFuture()) {
            $seconds = $existing->resend_available_at->diffInSeconds($now);

            return FestivalOtpResult::failed(__('app.customer_otp_resend_wait', ['seconds' => $seconds]), $existing, $seconds);
        }

        if ($existing && $existing->send_count >= (int) config('customer_auth.otp.max_sends')) {
            return FestivalOtpResult::failed(__('app.customer_otp_too_many_sends'), $existing);
        }

        $code = $this->generateCode();
        $message = __('app.festival_otp_sms_message', [
            'code' => $code,
            'studio' => $account->name,
        ]);
        $sendResult = $this->smsSender->send(
            account: $account,
            phone: $normalizedPhone,
            message: $message,
            purpose: SmsDeliveryPurpose::FestivalOtp,
            idempotencyKey: 'festival-otp:'.$account->id.':'.$role->value.':'.Str::uuid(),
        );

        if (! $sendResult->accepted()) {
            return FestivalOtpResult::failed(__('app.customer_otp_send_failed'));
        }

        $challenge = $existing ?: new FestivalOtpChallenge([
            'account_id' => $account->id,
            'role' => $role,
            'phone' => $normalizedPhone,
        ]);
        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'expires_at' => $now->copy()->addMinutes((int) config('customer_auth.otp.ttl_minutes')),
            'consumed_at' => null,
            'resend_available_at' => $now->copy()->addSeconds((int) config('customer_auth.otp.resend_seconds')),
            'attempts' => 0,
            'send_count' => $existing ? $existing->send_count + 1 : 1,
            'last_sent_at' => $now,
            'provider_scope' => $settings->sms_sending_mode->value,
            'provider' => $sendResult->delivery->provider,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();
        $sendResult->delivery->source()->associate($challenge);
        $sendResult->delivery->save();

        return FestivalOtpResult::ok(
            $challenge,
            (int) config('customer_auth.otp.resend_seconds'),
            app()->environment('testing') ? $code : null,
        );
    }

    public function verify(Account $account, FestivalPortalRole $role, string $phone, string $code): FestivalOtpResult
    {
        $normalizedPhone = $this->phones->normalize($phone, $account->country_code ?? 'UA');

        if (! $normalizedPhone || blank($code)) {
            return FestivalOtpResult::failed(__('app.customer_otp_invalid'));
        }

        return DB::transaction(function () use ($account, $role, $normalizedPhone, $code): FestivalOtpResult {
            $challenge = $this->activeChallenge($account, $role, $normalizedPhone, true);

            if (! $challenge) {
                return FestivalOtpResult::failed(__('app.customer_otp_expired'));
            }

            if ($challenge->attempts >= (int) config('customer_auth.otp.max_attempts')) {
                return FestivalOtpResult::failed(__('app.customer_otp_too_many_attempts'), $challenge);
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                return FestivalOtpResult::failed(__('app.customer_otp_invalid'), $challenge);
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return FestivalOtpResult::ok($challenge, 0);
        }, 3);
    }

    private function activeChallenge(Account $account, FestivalPortalRole $role, string $phone, bool $lock = false): ?FestivalOtpChallenge
    {
        return FestivalOtpChallenge::query()
            ->whereBelongsTo($account)
            ->where('role', $role->value)
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    private function generateCode(): string
    {
        if (app()->environment('testing')) {
            return (string) config('customer_auth.otp.testing_code');
        }

        $max = (10 ** (int) config('customer_auth.otp.code_digits')) - 1;

        return str_pad((string) random_int(0, $max), (int) config('customer_auth.otp.code_digits'), '0', STR_PAD_LEFT);
    }
}
