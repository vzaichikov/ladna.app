<?php

namespace App\Support\Events;

use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Event;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EventGoogleEmailPrefill
{
    public function __construct(private readonly CustomerAuthAvailability $availability) {}

    /**
     * @param  array<string, mixed>  $checkoutDraft
     */
    public function redirect(Account $account, Event $event, array $checkoutDraft): RedirectResponse
    {
        $setting = $this->availability->googleSetting();
        abort_unless($setting, 404);
        abort_unless($event->account_id === $account->id && $event->status === EventStatus::Published && $event->starts_at->isFuture(), 404);

        $credentials = $setting->readableCredentials();
        $state = Str::random(40);

        session()->put('event_google_email_prefill.'.$state, [
            'account_slug' => $account->slug,
            'event_slug' => $event->slug,
            'created_at' => now()->timestamp,
            'checkout_draft' => $checkoutDraft,
        ]);

        $query = http_build_query([
            'client_id' => (string) ($credentials['client_id'] ?? ''),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    /**
     * @return array{account: Account, event: Event, checkout_draft: array<string, mixed>}
     */
    public function consumeState(Request $request): array
    {
        $state = (string) $request->query('state');
        $payload = session()->pull('event_google_email_prefill.'.$state);
        $createdAt = is_array($payload) ? (int) ($payload['created_at'] ?? 0) : 0;

        if (! is_array($payload) || $createdAt < now()->subMinutes(10)->timestamp) {
            throw new RuntimeException('Invalid Event Google email-prefill state.');
        }

        $account = Account::active()
            ->where('slug', $payload['account_slug'] ?? null)
            ->firstOrFail();
        $event = $account->events()
            ->where('slug', $payload['event_slug'] ?? null)
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->firstOrFail();
        $checkoutDraft = is_array($payload['checkout_draft'] ?? null) ? $payload['checkout_draft'] : [];

        return [
            'account' => $account,
            'event' => $event,
            'checkout_draft' => $checkoutDraft,
        ];
    }

    /**
     * @return array{email: string, name: string|null}
     */
    public function verifiedProfile(Request $request): array
    {
        if (blank($request->query('code'))) {
            throw new RuntimeException('Google OAuth authorization code is missing.');
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
                    'client_id' => (string) ($credentials['client_id'] ?? ''),
                    'client_secret' => (string) ($credentials['client_secret'] ?? ''),
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

        $email = mb_strtolower(trim((string) $userResponse->json('email')));

        if (! $userResponse->successful()
            || blank($userResponse->json('sub'))
            || $userResponse->json('email_verified') !== true
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google OAuth did not return a verified email.');
        }

        $name = Str::of((string) $userResponse->json('name'))
            ->trim()
            ->limit(255, '')
            ->toString();

        return [
            'email' => $email,
            'name' => filled($name) ? $name : null,
        ];
    }

    private function callbackUrl(): string
    {
        return route('public.event-checkout.google.callback');
    }
}
