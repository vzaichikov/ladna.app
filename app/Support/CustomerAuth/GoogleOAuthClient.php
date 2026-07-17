<?php

namespace App\Support\CustomerAuth;

use App\Models\Account;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleOAuthClient
{
    public function __construct(private CustomerAuthAvailability $availability) {}

    public function redirect(Account $account): RedirectResponse
    {
        abort_if($account->isReadOnlyDemo(), 404);

        $setting = $this->availability->googleSetting();

        if (! $setting) {
            abort(404);
        }

        $credentials = $setting->readableCredentials();
        $state = Str::random(40);

        session()->put('customer_google_oauth.'.$state, [
            'account_slug' => $account->slug,
            'created_at' => now()->timestamp,
        ]);

        $query = http_build_query([
            'client_id' => (string) $credentials['client_id'],
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    /**
     * @return array{account: Account, user: GoogleUserData}
     */
    public function userFromCallback(Request $request): array
    {
        $state = (string) $request->query('state');
        $statePayload = session()->pull('customer_google_oauth.'.$state);

        if (! is_array($statePayload) || blank($request->query('code'))) {
            throw new RuntimeException('Invalid Google OAuth state.');
        }

        $account = Account::active()->where('slug', $statePayload['account_slug'] ?? null)->firstOrFail();

        if ($account->isReadOnlyDemo()) {
            throw new RuntimeException('Customer Google OAuth is unavailable for the read-only demo.');
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
                    'redirect_uri' => $this->callbackUrl(),
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

        return [
            'account' => $account,
            'user' => new GoogleUserData(
                id: (string) $userResponse->json('sub'),
                email: $userResponse->json('email'),
                emailVerified: (bool) $userResponse->json('email_verified'),
                name: $userResponse->json('name'),
            ),
        ];
    }

    private function callbackUrl(): string
    {
        return route('customer.google.callback');
    }
}
