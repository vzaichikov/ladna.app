<?php

namespace App\Http\Controllers;

use App\Actions\LinkGoogleCustomerByVerifiedPhone;
use App\Http\Requests\CustomerEmailLoginRequest;
use App\Http\Requests\CustomerGooglePhoneOtpSendRequest;
use App\Http\Requests\CustomerOtpSendRequest;
use App\Http\Requests\CustomerOtpVerifyRequest;
use App\Http\Requests\UpdateCustomerProfileRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\CustomerOtpService;
use App\Support\CustomerAuth\CustomerRememberTokenService;
use App\Support\CustomerAuth\GoogleOAuthClient;
use App\Support\CustomerAuth\GoogleUserData;
use App\Support\CustomerAuth\TurnstileVerifier;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class CustomerAuthController extends Controller
{
    public function create(): RedirectResponse
    {
        return redirect()->route('home');
    }

    public function studioLogin(string $accountSlug, CustomerAuthAvailability $availability): View|RedirectResponse
    {
        $account = $this->account($accountSlug);

        if ($this->currentCustomerBelongsTo($account)) {
            return redirect()->route('customer.dashboard', $account->slug);
        }

        return $this->loginView($account, $availability);
    }

    public function emailLogin(
        CustomerEmailLoginRequest $request,
        string $accountSlug,
        CustomerAuthAvailability $availability,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->emailPassword, 404);

        $validated = $request->validated();
        $customer = $account->customers()->where('email', $validated['email'])->first();

        if ($customer) {
            if (blank($customer->password)) {
                throw ValidationException::withMessages([
                    'email' => __('app.customer_password_not_available'),
                ]);
            }

            if (! Hash::check($validated['password'], $customer->password)) {
                throw ValidationException::withMessages([
                    'email' => __('app.auth_failed'),
                ]);
            }
        } else {
            $customer = $account->customers()->create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'default_language' => $account->default_language,
            ]);
        }

        return $this->loginCustomer($customer, $account);
    }

    public function sendOtp(
        CustomerOtpSendRequest $request,
        string $accountSlug,
        CustomerAuthAvailability $availability,
        TurnstileVerifier $turnstile,
        CustomerOtpService $otp,
        PhoneNumberNormalizer $phones,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->otp, 404);

        $turnstileSetting = $availability->turnstileSetting();

        if (! $turnstileSetting || ! $turnstile->verify(
            $request->validated('cf-turnstile-response'),
            (string) $request->ip(),
            $turnstileSetting->readableCredentials(),
        )) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => __('app.customer_captcha_failed'),
            ]);
        }

        $result = $otp->send(
            $account,
            $request->validated('phone'),
            (string) $request->ip(),
            substr((string) $request->userAgent(), 0, 1000),
        );

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'phone' => $result->message ?? __('app.customer_otp_send_failed'),
            ]);
        }

        session()->put($this->otpPhoneSessionKey($account), $result->challenge?->phone ?? $phones->normalize($request->validated('phone'), $account->country_code));

        return redirect()
            ->route('customer.otp.challenge', $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function otpChallenge(string $accountSlug, CustomerAuthAvailability $availability): View|RedirectResponse
    {
        $account = $this->account($accountSlug);
        $phone = session($this->otpPhoneSessionKey($account));

        if (! is_string($phone) || $phone === '') {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        return $this->loginView($account, $availability, 'otp_code', $phone);
    }

    public function resendOtp(
        Request $request,
        string $accountSlug,
        CustomerAuthAvailability $availability,
        CustomerOtpService $otp,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->otp, 404);

        $phone = session($this->otpPhoneSessionKey($account));

        if (! is_string($phone) || $phone === '') {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        $result = $otp->send($account, $phone, (string) $request->ip(), substr((string) $request->userAgent(), 0, 1000));

        if (! $result->ok) {
            return redirect()
                ->route('customer.otp.challenge', $account->slug)
                ->withErrors(['code' => $result->message ?? __('app.customer_otp_send_failed')])
                ->with('otp_resend_seconds', $result->secondsUntilResend);
        }

        return redirect()
            ->route('customer.otp.challenge', $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function changeOtpPhone(string $accountSlug): RedirectResponse
    {
        $account = $this->account($accountSlug);
        session()->forget($this->otpPhoneSessionKey($account));

        return redirect()->route('customer.studio.login', $account->slug);
    }

    public function verifyOtp(
        CustomerOtpVerifyRequest $request,
        string $accountSlug,
        CustomerOtpService $otp,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $phone = session($this->otpPhoneSessionKey($account), $request->validated('phone'));

        if ($phone !== $request->validated('phone')) {
            throw ValidationException::withMessages([
                'code' => __('app.customer_otp_invalid'),
            ]);
        }

        $result = $otp->verify($account, $request->validated('phone'), $request->validated('code'));

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages([
                'code' => $result->message ?? __('app.customer_otp_invalid'),
            ]);
        }

        $customer = $account->customers()->firstOrCreate(
            ['phone' => $result->challenge->phone],
            [
                'phone_verified_at' => now(),
                'default_language' => $account->default_language,
            ],
        );

        $customer->forceFill([
            'phone_verified_at' => $customer->phone_verified_at ?? now(),
        ])->save();

        session()->forget($this->otpPhoneSessionKey($account));

        return $this->loginCustomer($customer, $account);
    }

    public function googleRedirect(
        string $accountSlug,
        CustomerAuthAvailability $availability,
        GoogleOAuthClient $google,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $methods = $availability->methodsFor($account);

        abort_unless($methods->google, 404);

        return $google->redirect($account);
    }

    public function googleCallback(
        Request $request,
        CustomerAuthAvailability $availability,
        GoogleOAuthClient $google,
    ): RedirectResponse {
        try {
            ['account' => $account, 'user' => $googleUser] = $google->userFromCallback($request);
        } catch (RuntimeException) {
            return redirect()->route('customer.login')->withErrors([
                'google' => __('app.customer_google_failed'),
            ]);
        }

        abort_unless($availability->methodsFor($account)->google, 404);

        $customer = $this->matchedCustomerFromGoogle($account, $googleUser);

        if ($customer && filled($customer->phone)) {
            return $this->loginCustomer($this->fillGoogleCustomer($customer, $googleUser), $account);
        }

        $this->storePendingGoogleCustomer($account, $googleUser, $customer);

        return redirect()->route('customer.google.phone', $account->slug);
    }

    public function googlePhone(string $accountSlug): View|RedirectResponse
    {
        $account = $this->account($accountSlug);

        if (! $this->pendingGoogleCustomer($account)) {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        return view('customer-auth.google-phone', [
            'account' => $account,
            'phone' => session($this->googlePhoneSessionKey($account)),
        ]);
    }

    public function sendGooglePhoneOtp(
        CustomerGooglePhoneOtpSendRequest $request,
        string $accountSlug,
        CustomerOtpService $otp,
        PhoneNumberNormalizer $phones,
    ): RedirectResponse {
        $account = $this->account($accountSlug);

        if (! $this->pendingGoogleCustomer($account)) {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        $result = $otp->send(
            $account,
            $request->validated('phone'),
            (string) $request->ip(),
            substr((string) $request->userAgent(), 0, 1000),
        );

        if (! $result->ok) {
            throw ValidationException::withMessages([
                'phone' => $result->message ?? __('app.customer_otp_send_failed'),
            ]);
        }

        session()->put($this->googlePhoneSessionKey($account), $result->challenge?->phone ?? $phones->normalize($request->validated('phone'), $account->country_code));

        return redirect()
            ->route('customer.google.phone', $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function resendGooglePhoneOtp(
        Request $request,
        string $accountSlug,
        CustomerOtpService $otp,
    ): RedirectResponse {
        $account = $this->account($accountSlug);

        if (! $this->pendingGoogleCustomer($account)) {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        $phone = session($this->googlePhoneSessionKey($account));

        if (! is_string($phone) || $phone === '') {
            return redirect()->route('customer.google.phone', $account->slug);
        }

        $result = $otp->send($account, $phone, (string) $request->ip(), substr((string) $request->userAgent(), 0, 1000));

        if (! $result->ok) {
            return redirect()
                ->route('customer.google.phone', $account->slug)
                ->withErrors(['code' => $result->message ?? __('app.customer_otp_send_failed')])
                ->with('otp_resend_seconds', $result->secondsUntilResend);
        }

        return redirect()
            ->route('customer.google.phone', $account->slug)
            ->with('status', __('app.customer_otp_sent'))
            ->with('otp_resend_seconds', $result->secondsUntilResend);
    }

    public function changeGooglePhone(string $accountSlug): RedirectResponse
    {
        $account = $this->account($accountSlug);
        session()->forget($this->googlePhoneSessionKey($account));

        return redirect()->route('customer.google.phone', $account->slug);
    }

    public function verifyGooglePhoneOtp(
        CustomerOtpVerifyRequest $request,
        string $accountSlug,
        CustomerOtpService $otp,
        LinkGoogleCustomerByVerifiedPhone $linkCustomer,
    ): RedirectResponse {
        $account = $this->account($accountSlug);
        $pendingGoogleCustomer = $this->pendingGoogleCustomer($account);

        if (! $pendingGoogleCustomer) {
            return redirect()->route('customer.studio.login', $account->slug);
        }

        $phone = session($this->googlePhoneSessionKey($account), $request->validated('phone'));

        if ($phone !== $request->validated('phone')) {
            throw ValidationException::withMessages([
                'code' => __('app.customer_otp_invalid'),
            ]);
        }

        $result = $otp->verify($account, $request->validated('phone'), $request->validated('code'));

        if (! $result->ok || ! $result->challenge) {
            throw ValidationException::withMessages([
                'code' => $result->message ?? __('app.customer_otp_invalid'),
            ]);
        }

        $customer = $linkCustomer->execute(
            $account,
            $pendingGoogleCustomer,
            $result->challenge->phone,
            $pendingGoogleCustomer['customer_id'] ?? null,
        );

        session()->forget([
            $this->pendingGoogleCustomerSessionKey($account),
            $this->googlePhoneSessionKey($account),
        ]);

        return $this->loginCustomer($customer, $account);
    }

    public function studioDashboard(string $accountSlug): View
    {
        $account = $this->account($accountSlug);
        $customer = $this->customerForAccount($account);
        $purchaseHistory = $customer->purchases()
            ->with(['customerClassPass', 'classPassPlan', 'location'])
            ->newestFirst()
            ->paginate(10, ['*'], 'purchases_page')
            ->withQueryString();

        $customer->load([
            'customerClassPasses.classPassPlan.classTypes',
            'customerClassPasses.classPassPlan.trainerTypes',
            'customerClassPasses.classPassPlan.rooms',
            'classBookings' => fn ($query) => $query
                ->notCorrectedRemoved()
                ->with([
                    'scheduledClass.classType',
                    'scheduledClass.trainer',
                    'classPassReservation.customerClassPass',
                ]),
        ]);

        return view('customer-auth.dashboard', [
            'account' => $account,
            'customer' => $customer,
            'purchaseHistory' => $purchaseHistory,
        ]);
    }

    public function editProfile(string $accountSlug): View
    {
        $account = $this->account($accountSlug);
        $customer = $this->customerForAccount($account);

        return view('customer-auth.profile', [
            'account' => $account,
            'customer' => $customer,
        ]);
    }

    public function updateProfile(UpdateCustomerProfileRequest $request, string $accountSlug): RedirectResponse
    {
        $account = $this->account($accountSlug);
        $customer = $this->customerForAccount($account);
        $validated = $request->validated();

        if ($customer->phone !== $validated['phone']) {
            $validated['phone_verified_at'] = null;
        }

        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        unset($validated['password_confirmation']);

        $customer->update($validated);

        if ($customer->profileIsComplete()) {
            return redirect()
                ->intended(route('customer.dashboard', $account->slug))
                ->with('status', __('app.customer_profile_updated'));
        }

        return redirect()
            ->route('customer.profile.complete', $account->slug)
            ->with('status', __('app.customer_profile_updated'));
    }

    public function logout(Request $request, CustomerRememberTokenService $rememberTokens, string $accountSlug): RedirectResponse
    {
        $account = $this->account($accountSlug);

        $rememberTokens->forget($request);
        Auth::guard('customer')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('customer.studio.login', $account->slug);
    }

    private function loginView(
        Account $account,
        CustomerAuthAvailability $availability,
        string $mode = 'login',
        ?string $phone = null,
    ): View {
        return view('customer-auth.login', [
            'account' => $account,
            'methods' => $availability->methodsFor($account),
            'mode' => $mode,
            'phone' => $phone,
        ]);
    }

    private function matchedCustomerFromGoogle(Account $account, GoogleUserData $googleUser): ?Customer
    {
        $customer = $account->customers()->where('google_id', $googleUser->id)->first();

        if (! $customer && $googleUser->email) {
            $customer = $account->customers()->where('email', $googleUser->email)->first();
        }

        return $customer;
    }

    private function fillGoogleCustomer(Customer $customer, GoogleUserData $googleUser): Customer
    {
        $customer->forceFill([
            'google_id' => $customer->google_id ?: $googleUser->id,
            'email_verified_at' => $customer->email_verified_at ?: ($googleUser->emailVerified ? now() : null),
            'name' => $customer->name ?: $googleUser->name,
        ])->save();

        return $customer;
    }

    private function storePendingGoogleCustomer(Account $account, GoogleUserData $googleUser, ?Customer $customer): void
    {
        session()->put($this->pendingGoogleCustomerSessionKey($account), [
            'customer_id' => $customer?->id,
            'google_id' => $googleUser->id,
            'email' => $googleUser->email,
            'email_verified' => $googleUser->emailVerified,
            'name' => $googleUser->name,
        ]);
    }

    /**
     * @return array{customer_id?: int|null, google_id: string, email?: string|null, email_verified?: bool, name?: string|null}|null
     */
    private function pendingGoogleCustomer(Account $account): ?array
    {
        $pending = session($this->pendingGoogleCustomerSessionKey($account));

        if (! is_array($pending) || blank($pending['google_id'] ?? null)) {
            return null;
        }

        return $pending;
    }

    private function loginCustomer(Customer $customer, Account $account): RedirectResponse
    {
        abort_unless($customer->account_id === $account->id, 404);

        Auth::guard('customer')->login($customer);
        app(CustomerRememberTokenService::class)->issue($customer);

        if ($customer->profileIsComplete()) {
            return redirect()->intended(route('customer.dashboard', $account->slug));
        }

        return redirect()->route('customer.profile.complete', $account->slug);
    }

    private function currentCustomerBelongsTo(Account $account): bool
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id;
    }

    private function customerForAccount(Account $account): Customer
    {
        $customer = Auth::guard('customer')->user();

        abort_unless($customer instanceof Customer && $customer->account_id === $account->id, 404);

        return $customer;
    }

    private function account(string $accountSlug): Account
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();

        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
        }

        return $account;
    }

    private function otpPhoneSessionKey(Account $account): string
    {
        return 'customer_otp_phone_'.$account->id;
    }

    private function pendingGoogleCustomerSessionKey(Account $account): string
    {
        return 'customer_google_pending_'.$account->id;
    }

    private function googlePhoneSessionKey(Account $account): string
    {
        return 'customer_google_phone_'.$account->id;
    }
}
