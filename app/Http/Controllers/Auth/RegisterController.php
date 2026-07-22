<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Support\CustomerAuth\TurnstileVerifier;
use App\Support\Onboarding\PublicOwnerOnboardingAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(PublicOwnerOnboardingAvailability $availability): View
    {
        abort_unless($availability->isAvailable(), 404);

        return view('auth.register', [
            'turnstileSiteKey' => $availability->turnstileSiteKey(),
        ]);
    }

    public function store(
        RegisterRequest $request,
        PublicOwnerOnboardingAvailability $availability,
        TurnstileVerifier $turnstile,
    ): RedirectResponse {
        abort_unless($availability->isAvailable(), 404);

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

        $acceptedAt = now();
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
            'terms_accepted_at' => $acceptedAt,
            'privacy_accepted_at' => $acceptedAt,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('onboarding.show', ['step' => 1]);
    }
}
