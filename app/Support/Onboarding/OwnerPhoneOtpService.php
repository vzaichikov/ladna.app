<?php

namespace App\Support\Onboarding;

use App\Models\User;
use App\Models\UserPhoneOtpChallenge;
use App\Support\CustomerAuth\SmsGatewayResolver;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Throwable;

class OwnerPhoneOtpService
{
    public function __construct(
        private readonly PublicOwnerOnboardingAvailability $availability,
        private readonly SmsGatewayResolver $gateways,
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    public function send(User $user, string $phone, ?string $ipAddress = null, ?string $userAgent = null): OwnerPhoneOtpResult
    {
        $normalizedPhone = $this->phones->normalize($phone, 'UA');

        if (! $normalizedPhone || ! $this->phones->isValid($normalizedPhone, 'UA')) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.phone_invalid'));
        }

        try {
            return Cache::lock(
                'owner-onboarding-otp:'.$user->id,
                30,
            )->block(5, fn (): OwnerPhoneOtpResult => $this->sendLocked(
                $user,
                $normalizedPhone,
                $ipAddress,
                $userAgent,
            ));
        } catch (LockTimeoutException) {
            return OwnerPhoneOtpResult::failed(
                __('app.onboarding.otp_resend_wait', ['seconds' => 5]),
                secondsUntilResend: 5,
            );
        }
    }

    private function sendLocked(
        User $user,
        string $normalizedPhone,
        ?string $ipAddress,
        ?string $userAgent,
    ): OwnerPhoneOtpResult {
        $smsSetting = $this->availability->platformSmsSetting();

        if (! $smsSetting) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_unavailable'));
        }

        $existing = $this->activeChallengeFor($user, $normalizedPhone);
        $now = now();

        if ($existing && $existing->resend_available_at?->isFuture()) {
            $seconds = $existing->resend_available_at->diffInSeconds($now);

            return OwnerPhoneOtpResult::failed(
                __('app.onboarding.otp_resend_wait', ['seconds' => $seconds]),
                $existing,
                $seconds,
            );
        }

        if ($existing && $existing->send_count >= (int) config('customer_auth.otp.max_sends')) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_too_many_sends'), $existing);
        }

        $code = $this->generateCode();
        $message = __('app.onboarding.otp_sms_message', ['code' => $code]);

        try {
            $result = $this->gateways->resolve($smsSetting)->sendOtp($normalizedPhone, $message);
        } catch (Throwable) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_send_failed'));
        }

        if (! $result->sent) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_send_failed'));
        }

        $challenge = $existing ?: new UserPhoneOtpChallenge([
            'user_id' => $user->id,
            'phone' => $normalizedPhone,
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
            'provider' => $smsSetting->provider->value,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ])->save();

        if ($user->phone !== $normalizedPhone || $user->phone_verified_at !== null) {
            $user->forceFill([
                'phone' => $normalizedPhone,
                'phone_verified_at' => null,
            ])->save();
        }

        return OwnerPhoneOtpResult::ok(
            $challenge,
            (int) config('customer_auth.otp.resend_seconds'),
            app()->environment('testing') ? $code : null,
        );
    }

    public function verify(User $user, string $code): OwnerPhoneOtpResult
    {
        $phone = $this->phones->normalize($user->phone, 'UA');

        if (! $phone || blank($code)) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_invalid'));
        }

        $challenge = $this->activeChallengeFor($user, $phone);

        if (! $challenge) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_expired'));
        }

        if ($challenge->attempts >= (int) config('customer_auth.otp.max_attempts')) {
            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_too_many_attempts'), $challenge);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            return OwnerPhoneOtpResult::failed(__('app.onboarding.otp_invalid'), $challenge);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        $user->forceFill(['phone_verified_at' => now()])->save();

        return OwnerPhoneOtpResult::ok($challenge, 0);
    }

    public function activeChallengeFor(User $user, ?string $phone = null): ?UserPhoneOtpChallenge
    {
        return UserPhoneOtpChallenge::query()
            ->whereBelongsTo($user)
            ->when($phone, fn ($query) => $query->where('phone', $phone))
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
