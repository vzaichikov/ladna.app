<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Http\Requests\FestivalEmailLoginRequest;
use App\Http\Requests\FestivalOtpSendRequest;
use App\Http\Requests\FestivalOtpVerifyRequest;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\GoogleUserData;
use App\Support\CustomerAuth\TurnstileVerifier;
use App\Support\Festivals\FestivalGoogleOAuthClient;
use App\Support\Festivals\FestivalOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class FestivalPortalAuthController extends Controller
{
    public function show(Request $request, string $accountSlug, CustomerAuthAvailability $availability, string $festivalRole): View|RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        $portalUser = $request->user('festival');

        if ($portalUser instanceof FestivalPortalUser && $portalUser->account_id === $account->id && $portalUser->role === $role && $portalUser->is_active) {
            return redirect()->route($this->dashboardRoute($role), $account->slug);
        }

        return $this->loginView($account, $role, $availability);
    }

    public function emailLogin(FestivalEmailLoginRequest $request, string $accountSlug, CustomerAuthAvailability $availability, string $festivalRole): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        abort_unless($availability->methodsFor($account)->emailPassword, 404);
        $validated = $request->validated();

        $portalUser = DB::transaction(function () use ($account, $role, $validated): FestivalPortalUser {
            $portalUser = FestivalPortalUser::query()
                ->whereBelongsTo($account)
                ->forRole($role)
                ->where('email_normalized', $validated['email'])
                ->lockForUpdate()
                ->first();

            if (! $portalUser && $role !== FestivalPortalRole::Judge) {
                return FestivalPortalUser::query()->create([
                    'account_id' => $account->id,
                    'role' => $role,
                    'is_active' => true,
                    'email' => $validated['email'],
                    'email_normalized' => $validated['email'],
                    'password' => $validated['password'],
                    'locale' => $account->default_language,
                ]);
            }

            $this->assertLoginIdentity($portalUser, $role);

            if (blank($portalUser->password)) {
                throw ValidationException::withMessages(['email' => __('app.festival_password_not_available')]);
            }

            if (! Hash::check($validated['password'], $portalUser->password)) {
                throw ValidationException::withMessages(['email' => __('app.auth_failed')]);
            }

            return $portalUser;
        }, 3);

        return $this->login($request, $account, $portalUser);
    }

    public function sendOtp(
        FestivalOtpSendRequest $request,
        string $accountSlug,
        CustomerAuthAvailability $availability,
        TurnstileVerifier $turnstile,
        FestivalOtpService $otp,
        string $festivalRole,
    ): RedirectResponse {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        $methods = $availability->methodsFor($account);
        abort_unless($methods->otp, 404);
        $turnstileSetting = $availability->turnstileSetting();

        if (! $turnstileSetting || ! $turnstile->verify(
            $request->validated('cf-turnstile-response'),
            (string) $request->ip(),
            $turnstileSetting->readableCredentials(),
        )) {
            throw ValidationException::withMessages(['cf-turnstile-response' => __('app.customer_captcha_failed')]);
        }

        $result = $otp->send(
            $account,
            $role,
            $request->validated('phone'),
            (string) $request->ip(),
            Str::limit((string) $request->userAgent(), 1000, ''),
        );

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages(['phone' => $result->message ?? __('app.customer_otp_send_failed')]);
        }

        $request->session()->put($this->otpPhoneSessionKey($account, $role), $result->challenge->phone);

        return redirect()->route($this->routeName($role, 'otp.challenge'), $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function otpChallenge(Request $request, string $accountSlug, CustomerAuthAvailability $availability, string $festivalRole): View|RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        $phone = $request->session()->get($this->otpPhoneSessionKey($account, $role));

        if (! is_string($phone) || $phone === '') {
            return redirect()->route($this->loginRoute($role), $account->slug);
        }

        return $this->loginView($account, $role, $availability, 'otp_code', $phone);
    }

    public function resendOtp(Request $request, string $accountSlug, CustomerAuthAvailability $availability, FestivalOtpService $otp, string $festivalRole): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        abort_unless($availability->methodsFor($account)->otp, 404);
        $phone = $request->session()->get($this->otpPhoneSessionKey($account, $role));

        if (! is_string($phone) || $phone === '') {
            return redirect()->route($this->loginRoute($role), $account->slug);
        }

        $result = $otp->send($account, $role, $phone, (string) $request->ip(), Str::limit((string) $request->userAgent(), 1000, ''));

        if (! $result->ok) {
            return redirect()->route($this->routeName($role, 'otp.challenge'), $account->slug)
                ->withErrors(['code' => $result->message ?? __('app.customer_otp_send_failed')])
                ->with('otp_resend_seconds', $result->secondsUntilResend);
        }

        return redirect()->route($this->routeName($role, 'otp.challenge'), $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function changeOtpPhone(Request $request, string $accountSlug, string $festivalRole): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        $request->session()->forget($this->otpPhoneSessionKey($account, $role));

        return redirect()->route($this->loginRoute($role), $account->slug);
    }

    public function verifyOtp(FestivalOtpVerifyRequest $request, string $accountSlug, FestivalOtpService $otp, string $festivalRole): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        $phone = $request->session()->get($this->otpPhoneSessionKey($account, $role));

        if (! is_string($phone) || $phone !== $request->validated('phone')) {
            throw ValidationException::withMessages(['code' => __('app.customer_otp_invalid')]);
        }

        $result = $otp->verify($account, $role, $phone, $request->validated('code'));

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages(['code' => $result->message ?? __('app.customer_otp_invalid')]);
        }

        $portalUser = DB::transaction(function () use ($account, $role, $result): FestivalPortalUser {
            $portalUser = FestivalPortalUser::query()
                ->whereBelongsTo($account)
                ->forRole($role)
                ->where('phone_normalized', $result->challenge->phone)
                ->lockForUpdate()
                ->first();

            if (! $portalUser && $role !== FestivalPortalRole::Judge) {
                return FestivalPortalUser::query()->create([
                    'account_id' => $account->id,
                    'role' => $role,
                    'is_active' => true,
                    'phone' => $result->challenge->phone,
                    'phone_normalized' => $result->challenge->phone,
                    'phone_verified_at' => now(),
                    'locale' => $account->default_language,
                ]);
            }

            $this->assertLoginIdentity($portalUser, $role);
            $portalUser->forceFill([
                'phone' => $result->challenge->phone,
                'phone_normalized' => $result->challenge->phone,
                'phone_verified_at' => $portalUser->phone_verified_at ?? now(),
            ])->save();

            return $portalUser;
        }, 3);

        $request->session()->forget($this->otpPhoneSessionKey($account, $role));

        return $this->login($request, $account, $portalUser);
    }

    public function googleRedirect(Request $request, string $accountSlug, CustomerAuthAvailability $availability, FestivalGoogleOAuthClient $google, string $festivalRole): RedirectResponse
    {
        $account = $this->account($request, $accountSlug);
        $role = $this->role($festivalRole);
        abort_unless($availability->methodsFor($account)->google, 404);

        return $google->redirect($account, $role);
    }

    public function googleCallback(Request $request, CustomerAuthAvailability $availability, FestivalGoogleOAuthClient $google): RedirectResponse
    {
        try {
            ['account' => $account, 'role' => $role, 'user' => $googleUser] = $google->userFromCallback($request);
            abort_unless(in_array($role, [FestivalPortalRole::Registrant, FestivalPortalRole::Judge], true), 404);
            abort_unless($availability->methodsFor($account)->google, 404);
            $portalUser = $this->resolveGoogleIdentity($account, $role, $googleUser);
        } catch (RuntimeException|ValidationException) {
            return redirect()->route('home')->withErrors(['google' => __('app.customer_google_failed')]);
        }

        return $this->login($request, $account, $portalUser);
    }

    public function logout(Request $request, string $accountSlug): RedirectResponse
    {
        $role = $request->user('festival')?->role ?? FestivalPortalRole::Registrant;
        Auth::guard('festival')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $role === FestivalPortalRole::Guest
            ? redirect()->route('public.festivals.index', $accountSlug)
            : redirect()->route($this->loginRoute($role), $accountSlug);
    }

    private function resolveGoogleIdentity(Account $account, FestivalPortalRole $role, GoogleUserData $googleUser): FestivalPortalUser
    {
        if (! $googleUser->emailVerified || blank($googleUser->email)) {
            throw ValidationException::withMessages(['google' => __('app.customer_google_failed')]);
        }

        $email = FestivalPortalUser::normalizeEmail((string) $googleUser->email);

        return DB::transaction(function () use ($account, $role, $googleUser, $email): FestivalPortalUser {
            $googleMatch = FestivalPortalUser::query()->whereBelongsTo($account)->forRole($role)->where('google_id', $googleUser->id)->lockForUpdate()->first();
            $emailMatch = FestivalPortalUser::query()->whereBelongsTo($account)->forRole($role)->where('email_normalized', $email)->lockForUpdate()->first();

            if ($googleMatch && $emailMatch && ! $googleMatch->is($emailMatch)) {
                throw ValidationException::withMessages(['google' => __('app.festival_google_identity_conflict')]);
            }

            $portalUser = $googleMatch ?? $emailMatch;

            if (! $portalUser && $role === FestivalPortalRole::Judge) {
                throw ValidationException::withMessages(['google' => __('app.auth_failed')]);
            }

            if (! $portalUser) {
                [$firstName, $lastName] = $this->googleNames($googleUser->name);
                $portalUser = FestivalPortalUser::query()->create([
                    'account_id' => $account->id,
                    'role' => $role,
                    'is_active' => true,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'email_normalized' => $email,
                    'email_verified_at' => now(),
                    'google_id' => $googleUser->id,
                    'locale' => $account->default_language,
                ]);

                return $portalUser;
            }

            $this->assertLoginIdentity($portalUser, $role);

            if (filled($portalUser->google_id) && $portalUser->google_id !== $googleUser->id) {
                throw ValidationException::withMessages(['google' => __('app.festival_google_identity_conflict')]);
            }

            $portalUser->forceFill([
                'email' => $email,
                'email_normalized' => $email,
                'email_verified_at' => $portalUser->email_verified_at ?? now(),
                'google_id' => $googleUser->id,
            ])->save();

            return $portalUser;
        }, 3);
    }

    private function assertLoginIdentity(?FestivalPortalUser $portalUser, FestivalPortalRole $role): void
    {
        if (! $portalUser || $portalUser->role !== $role) {
            throw ValidationException::withMessages(['email' => __('app.auth_failed')]);
        }

        if (! $portalUser->is_active) {
            throw ValidationException::withMessages(['email' => __('app.festival_profile_inactive')]);
        }
    }

    private function login(Request $request, Account $account, FestivalPortalUser $portalUser): RedirectResponse
    {
        Auth::guard('festival')->login($portalUser, true);
        $request->session()->regenerate();
        $portalUser->forceFill(['last_login_at' => now()])->save();
        $intended = $request->session()->pull($this->intendedSessionKey($account, $portalUser->role));

        return is_string($intended) && Str::startsWith($intended, url('/').'/'.$account->slug.'/festival-portal')
            ? redirect()->to($intended)
            : redirect()->route($this->dashboardRoute($portalUser->role), $account->slug);
    }

    private function loginView(Account $account, FestivalPortalRole $role, CustomerAuthAvailability $availability, string $stage = 'methods', ?string $phone = null): View
    {
        return view('festivals.portal.login', [
            'account' => $account,
            'role' => $role,
            'methods' => $availability->methodsFor($account),
            'stage' => $stage,
            'phone' => $phone,
        ]);
    }

    /** @return array{?string, ?string} */
    private function googleNames(?string $name): array
    {
        $parts = explode(' ', Str::squish((string) $name), 2);

        return [$parts[0] !== '' ? $parts[0] : null, $parts[1] ?? null];
    }

    private function account(Request $request, string $slug): Account
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $slug, 404);

        return $account;
    }

    private function role(string $role): FestivalPortalRole
    {
        $portalRole = FestivalPortalRole::tryFrom($role);
        abort_unless(in_array($portalRole, [FestivalPortalRole::Registrant, FestivalPortalRole::Judge], true), 404);

        return $portalRole;
    }

    private function loginRoute(FestivalPortalRole $role): string
    {
        return match ($role) {
            FestivalPortalRole::Registrant => 'festival.login',
            FestivalPortalRole::Judge => 'festival.judge.login',
            FestivalPortalRole::Guest => 'public.festivals.index',
        };
    }

    private function dashboardRoute(FestivalPortalRole $role): string
    {
        return match ($role) {
            FestivalPortalRole::Registrant => 'festival.portal.dashboard',
            FestivalPortalRole::Judge => 'festival.portal.judge.dashboard',
            FestivalPortalRole::Guest => 'public.festivals.index',
        };
    }

    private function routeName(FestivalPortalRole $role, string $suffix): string
    {
        return match ($role) {
            FestivalPortalRole::Registrant => 'festival.login.'.$suffix,
            FestivalPortalRole::Judge => 'festival.judge.login.'.$suffix,
            FestivalPortalRole::Guest => 'public.festivals.index',
        };
    }

    private function otpPhoneSessionKey(Account $account, FestivalPortalRole $role): string
    {
        return 'festival_otp_phone.'.$account->id.'.'.$role->value;
    }

    private function intendedSessionKey(Account $account, FestivalPortalRole $role): string
    {
        return 'festival_intended.'.$account->id.'.'.$role->value;
    }
}
