<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Http\Requests\SendOwnerPhoneOtpRequest;
use App\Http\Requests\VerifyOwnerPhoneOtpRequest;
use App\Models\AccountOnboarding;
use App\Models\User;
use App\Support\CustomerAuth\TurnstileVerifier;
use App\Support\Onboarding\OwnerPhoneOtpService;
use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OwnerOnboardingOtpController extends Controller
{
    public function send(
        SendOwnerPhoneOtpRequest $request,
        PublicOwnerOnboardingAvailability $availability,
        TurnstileVerifier $turnstile,
        OwnerPhoneOtpService $otpService,
    ): RedirectResponse {
        [$user, $onboarding] = $this->context($request->user());
        if ($availability->turnstileRequired()) {
            $turnstileSetting = $availability->turnstileSetting();

            if (! $turnstileSetting || ! $turnstile->verify(
                $request->validated('cf-turnstile-response'),
                (string) $request->ip(),
                $turnstileSetting->readableCredentials(),
            )) {
                throw ValidationException::withMessages([
                    'cf-turnstile-response' => __('app.onboarding.captcha_failed'),
                ]);
            }
        }

        $result = $otpService->send(
            $user,
            $request->validated('phone'),
            (string) $request->ip(),
            mb_substr((string) $request->userAgent(), 0, 1000),
        );

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'phone' => $result->message ?? __('app.onboarding.otp_send_failed'),
            ]);
        }

        $onboarding->recordMetric('otp_last_sent_at');

        return redirect()
            ->route('onboarding.show', ['step' => 6])
            ->with('status', __('app.onboarding.otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend)
            ->with('otp_debug_code', $result->debugCode);
    }

    public function verify(
        VerifyOwnerPhoneOtpRequest $request,
        OwnerPhoneOtpService $otpService,
    ): RedirectResponse {
        [$user, $onboarding] = $this->context($request->user());
        $result = $otpService->verify($user, $request->validated('otp_code'));

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'otp_code' => $result->message ?? __('app.onboarding.otp_invalid'),
            ]);
        }

        $onboarding->recordMetric('otp_verified_at');

        return redirect()
            ->route('onboarding.show', ['step' => 6])
            ->with('status', __('app.onboarding.phone_verified'));
    }

    /**
     * @return array{User, AccountOnboarding}
     */
    private function context(mixed $candidate): array
    {
        abort_unless($candidate instanceof User && ! $candidate->isPlatformAdmin(), 404);

        $onboarding = AccountOnboarding::query()
            ->whereHas('account.memberships', fn ($query) => $query
                ->where('user_id', $candidate->id)
                ->where('role', AccountRole::Owner->value))
            ->whereNull('completed_at')
            ->where('current_step', AccountOnboarding::LastStep)
            ->latest()
            ->first();

        abort_unless($onboarding, 404);

        return [$candidate, $onboarding];
    }
}
