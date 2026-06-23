<?php

namespace App\Support\CustomerAuth;

use App\Models\Account;
use App\Models\CustomerOtpChallenge;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Hash;
use Throwable;

class CustomerOtpService
{
    public function __construct(
        private CustomerAuthAvailability $availability,
        private SmsGatewayResolver $gateways,
        private PhoneNumberNormalizer $phones,
    ) {}

    public function send(Account $account, string $phone, ?string $ipAddress = null, ?string $userAgent = null): OtpChallengeResult
    {
        $normalizedPhone = $this->phones->normalize($phone, $account->country_code ?? 'UA');

        if (! $normalizedPhone || ! $this->phones->isValid($normalizedPhone, $account->country_code ?? 'UA')) {
            return OtpChallengeResult::failed(__('app.customer_auth_phone_invalid'));
        }

        $settings = $this->availability->settingsFor($account);

        if (! $settings->allow_otp) {
            return OtpChallengeResult::failed(__('app.customer_auth_method_unavailable'));
        }

        $smsSetting = $this->availability->smsSettingFor($account, $settings);

        if (! $smsSetting) {
            return OtpChallengeResult::failed(__('app.customer_auth_method_unavailable'));
        }

        $existing = $this->activeChallenge($account, $normalizedPhone);
        $now = now();

        if ($existing && $existing->resend_available_at?->isFuture()) {
            return OtpChallengeResult::failed(
                __('app.customer_otp_resend_wait', ['seconds' => $existing->resend_available_at->diffInSeconds($now)]),
                $existing,
                $existing->resend_available_at->diffInSeconds($now),
            );
        }

        if ($existing && $existing->send_count >= (int) config('customer_auth.otp.max_sends')) {
            return OtpChallengeResult::failed(__('app.customer_otp_too_many_sends'), $existing);
        }

        $code = $this->generateCode();
        $message = __('app.customer_otp_sms_message', [
            'code' => $code,
            'studio' => $account->name,
        ]);

        try {
            $result = $this->gateways->resolve($smsSetting)->sendOtp($normalizedPhone, $message);
        } catch (Throwable) {
            return OtpChallengeResult::failed(__('app.customer_otp_send_failed'));
        }

        if (! $result->sent) {
            return OtpChallengeResult::failed(__('app.customer_otp_send_failed'));
        }

        $challenge = $existing ?: new CustomerOtpChallenge([
            'account_id' => $account->id,
            'phone' => $normalizedPhone,
            'provider_scope' => $settings->otp_sender_scope->value,
            'provider' => $smsSetting->provider->value,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'expires_at' => $now->copy()->addMinutes((int) config('customer_auth.otp.ttl_minutes')),
            'consumed_at' => null,
            'resend_available_at' => $now->copy()->addSeconds((int) config('customer_auth.otp.resend_seconds')),
            'attempts' => 0,
            'send_count' => $existing ? $existing->send_count + 1 : 1,
            'last_sent_at' => $now,
            'provider_scope' => $settings->otp_sender_scope->value,
            'provider' => $smsSetting->provider->value,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();

        return OtpChallengeResult::ok(
            $challenge,
            (int) config('customer_auth.otp.resend_seconds'),
            app()->environment('testing') ? $code : null,
        );
    }

    public function verify(Account $account, string $phone, string $code): OtpChallengeResult
    {
        $normalizedPhone = $this->phones->normalize($phone, $account->country_code ?? 'UA');

        if (! $normalizedPhone || blank($code)) {
            return OtpChallengeResult::failed(__('app.customer_otp_invalid'));
        }

        $challenge = $this->activeChallenge($account, $normalizedPhone);

        if (! $challenge) {
            return OtpChallengeResult::failed(__('app.customer_otp_expired'));
        }

        if ($challenge->attempts >= (int) config('customer_auth.otp.max_attempts')) {
            return OtpChallengeResult::failed(__('app.customer_otp_too_many_attempts'), $challenge);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            return OtpChallengeResult::failed(__('app.customer_otp_invalid'), $challenge);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        return OtpChallengeResult::ok($challenge, 0);
    }

    private function activeChallenge(Account $account, string $phone): ?CustomerOtpChallenge
    {
        return CustomerOtpChallenge::query()
            ->whereBelongsTo($account)
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
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
