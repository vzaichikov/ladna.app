<?php

namespace App\Support\Festivals;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\CustomerAuth\GoogleUserData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FestivalGoogleOAuthClient
{
    public function __construct(private readonly CustomerAuthAvailability $availability) {}

    public function redirect(Account $account, FestivalPortalRole $role): RedirectResponse
    {
        abort_if($account->isReadOnlyDemo(), 404);
        $setting = $this->availability->googleSetting();
        abort_unless($setting, 404);

        $credentials = $setting->readableCredentials();
        $state = Str::random(40);
        session()->put('festival_google_oauth.'.$state, [
            'account_slug' => $account->slug,
            'role' => $role->value,
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

    /** @return array{account: Account, role: FestivalPortalRole, user: GoogleUserData} */
    public function userFromCallback(Request $request): array
    {
        $state = (string) $request->query('state');
        $statePayload = session()->pull('festival_google_oauth.'.$state);
        $createdAt = is_array($statePayload) ? (int) ($statePayload['created_at'] ?? 0) : 0;
        $role = is_array($statePayload) ? FestivalPortalRole::tryFrom((string) ($statePayload['role'] ?? '')) : null;

        if (! is_array($statePayload) || ! $role || blank($request->query('code')) || $createdAt < now()->subMinutes(10)->timestamp) {
            throw new RuntimeException('Invalid Festival Google OAuth state.');
        }

        $account = Account::active()
            ->where('enable_festivals', true)
            ->where('slug', $statePayload['account_slug'] ?? null)
            ->firstOrFail();

        if ($account->isReadOnlyDemo()) {
            throw new RuntimeException('Festival Google OAuth is unavailable for the read-only demo.');
        }

        $setting = $this->availability->googleSetting();

        if (! $setting) {
            throw new RuntimeException('Google OAuth is not configured.');
        }

        $credentials = $setting->readableCredentials();

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
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
                ->connectTimeout(5)
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
            'role' => $role,
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
        return route('festival.google.callback');
    }
}
