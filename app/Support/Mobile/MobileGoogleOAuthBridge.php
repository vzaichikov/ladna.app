<?php

namespace App\Support\Mobile;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MobileOAuthLoginCode;
use App\Models\MobileOAuthState;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\GoogleUserData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileGoogleOAuthBridge
{
    public function __construct(private CustomerAuthAvailability $availability) {}

    public function redirect(Account $account, ?string $returnUrl = null): RedirectResponse
    {
        abort_if($account->isReadOnlyDemo(), 404);

        $setting = $this->availability->googleSetting();

        if (! $setting) {
            abort(404);
        }

        $returnUrl = $this->trustedReturnUrl($returnUrl);
        $state = Str::random(48);
        MobileOAuthState::where('expires_at', '<=', now())->delete();
        MobileOAuthState::create([
            'account_id' => $account->id,
            'state_hash' => $this->hash($state),
            'return_url' => $returnUrl,
            'expires_at' => now()->addMinutes((int) config('mobile.google_oauth.state_ttl_minutes')),
        ]);

        $credentials = $setting->readableCredentials();
        $query = http_build_query([
            'client_id' => (string) $credentials['client_id'],
            'redirect_uri' => route('api.v1.mobile.auth.customer.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $state = $this->consumeState((string) $request->query('state'));
            $googleUser = $this->googleUserFromCallback($request);
            $customer = $this->customerFromGoogle($state->account, $googleUser);
            $plainCode = $this->issueLoginCode($state->account, $customer);
            $returnUrl = $state->return_url ?: (string) config('mobile.google_oauth.default_return_url');

            return redirect()->away($this->returnUrlWith($returnUrl, [
                'code' => $plainCode,
                'account_slug' => $state->account->slug,
            ]));
        } catch (RuntimeException) {
            return redirect()->away($this->returnUrlWith((string) config('mobile.google_oauth.default_return_url'), [
                'error' => 'google_oauth_failed',
            ]));
        }
    }

    public function consumeLoginCode(string $code): MobileOAuthLoginCode
    {
        $loginCode = MobileOAuthLoginCode::with(['account', 'customer'])
            ->where('code_hash', $this->hash($code))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $loginCode) {
            throw new RuntimeException('Invalid mobile login code.');
        }

        if ($loginCode->account?->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        $loginCode->forceFill(['consumed_at' => now()])->save();

        return $loginCode;
    }

    private function consumeState(string $state): MobileOAuthState
    {
        if ($state === '') {
            throw new RuntimeException('Missing OAuth state.');
        }

        $mobileState = MobileOAuthState::with('account')
            ->where('state_hash', $this->hash($state))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $mobileState || ! $mobileState->account) {
            throw new RuntimeException('Invalid OAuth state.');
        }

        if ($mobileState->account->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        $mobileState->forceFill(['consumed_at' => now()])->save();

        return $mobileState;
    }

    private function googleUserFromCallback(Request $request): GoogleUserData
    {
        if (blank($request->query('code'))) {
            throw new RuntimeException('Missing OAuth code.');
        }

        $setting = $this->availability->googleSetting();

        if (! $setting) {
            throw new RuntimeException('Google OAuth is not configured.');
        }

        $credentials = $setting->readableCredentials();

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => (string) $credentials['client_id'],
                    'client_secret' => (string) $credentials['client_secret'],
                    'code' => (string) $request->query('code'),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => route('api.v1.mobile.auth.customer.google.callback'),
                ]);
            $accessToken = $tokenResponse->json('access_token');

            if (! $tokenResponse->successful() || blank($accessToken)) {
                throw new RuntimeException('Google OAuth token exchange failed.');
            }

            $userResponse = Http::withToken((string) $accessToken)
                ->acceptJson()
                ->timeout(10)
                ->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Google OAuth request failed.', previous: $exception);
        }

        if (! $userResponse->successful() || blank($userResponse->json('sub'))) {
            throw new RuntimeException('Google OAuth userinfo failed.');
        }

        return new GoogleUserData(
            id: (string) $userResponse->json('sub'),
            email: $userResponse->json('email'),
            emailVerified: (bool) $userResponse->json('email_verified'),
            name: $userResponse->json('name'),
        );
    }

    private function customerFromGoogle(Account $account, GoogleUserData $googleUser): Customer
    {
        $customer = $account->customers()->where('google_id', $googleUser->id)->first();

        if (! $customer && $googleUser->email) {
            $customer = $account->customers()->where('email', $googleUser->email)->first();
        }

        if (! $customer) {
            return $account->customers()->create([
                'google_id' => $googleUser->id,
                'email' => $googleUser->email,
                'email_verified_at' => $googleUser->emailVerified ? now() : null,
                'name' => $googleUser->name,
                'default_language' => $account->default_language,
            ]);
        }

        $customer->forceFill([
            'google_id' => $customer->google_id ?: $googleUser->id,
            'email_verified_at' => $customer->email_verified_at ?: ($googleUser->emailVerified ? now() : null),
            'name' => $customer->name ?: $googleUser->name,
        ])->save();

        return $customer;
    }

    private function issueLoginCode(Account $account, Customer $customer): string
    {
        do {
            $code = 'ladna_mobile_google_'.Str::random(80);
            $codeHash = $this->hash($code);
        } while (MobileOAuthLoginCode::where('code_hash', $codeHash)->exists());

        MobileOAuthLoginCode::where('expires_at', '<=', now())->delete();
        MobileOAuthLoginCode::create([
            'account_id' => $account->id,
            'customer_id' => $customer->id,
            'code_hash' => $codeHash,
            'expires_at' => now()->addMinutes((int) config('mobile.google_oauth.login_code_ttl_minutes')),
        ]);

        return $code;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function returnUrlWith(string $returnUrl, array $query): string
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return $returnUrl.$separator.http_build_query($query);
    }

    private function trustedReturnUrl(?string $returnUrl): string
    {
        $defaultReturnUrl = (string) config('mobile.google_oauth.default_return_url');

        if (blank($returnUrl)) {
            return $defaultReturnUrl;
        }

        $allowedReturnUrls = collect(config('mobile.google_oauth.allowed_return_urls', []))
            ->map(fn (mixed $url): string => trim((string) $url))
            ->filter()
            ->push($defaultReturnUrl)
            ->unique()
            ->values();

        abort_unless(
            $allowedReturnUrls->contains(fn (string $allowedReturnUrl): bool => hash_equals($allowedReturnUrl, $returnUrl)),
            422
        );

        return $returnUrl;
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
