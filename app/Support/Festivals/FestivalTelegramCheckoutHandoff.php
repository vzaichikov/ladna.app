<?php

namespace App\Support\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\TelegramChatAuthorization;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FestivalTelegramCheckoutHandoff
{
    private const TokenTtlMinutes = 5;

    public function __construct(private readonly FestivalTelegramAuthorizationResolver $authorizations) {}

    public function issueUrl(FestivalSeries $series, FestivalEdition $edition, TelegramChatAuthorization $authorization): string
    {
        if ((int) $edition->account_id !== (int) $series->account_id
            || (int) $edition->festival_series_id !== (int) $series->id
            || ! $this->authorizations->byId($series, $authorization->id)) {
            throw new InvalidArgumentException('Festival Telegram checkout authorization does not match the edition.');
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TokenTtlMinutes);
        Cache::put($this->cacheKey($token), [
            'account_id' => (int) $series->account_id,
            'series_id' => (int) $series->id,
            'edition_id' => (int) $edition->id,
            'telegram_chat_authorization_id' => (int) $authorization->id,
        ], $expiresAt);

        return URL::temporarySignedRoute('public.festival-telegram.checkout.consume', $expiresAt, [
            'accountSlug' => $series->account()->value('slug'),
            'seriesSlug' => $series->slug,
            'editionSlug' => $edition->slug,
            'token' => $token,
        ]);
    }

    public function consumeIntoSession(Request $request, FestivalSeries $series, FestivalEdition $edition, string $token): bool
    {
        $payload = $this->pull($token);

        if (! $payload
            || (int) ($payload['account_id'] ?? 0) !== (int) $series->account_id
            || (int) ($payload['series_id'] ?? 0) !== (int) $series->id
            || (int) ($payload['edition_id'] ?? 0) !== (int) $edition->id
            || ! $this->authorizations->byId($series, (int) ($payload['telegram_chat_authorization_id'] ?? 0))) {
            return false;
        }

        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($edition), [
            'telegram_chat_authorization_id' => (int) $payload['telegram_chat_authorization_id'],
            'expires_at' => now()->addMinutes(self::TokenTtlMinutes)->timestamp,
        ]);

        return true;
    }

    public function pullSessionAuthorization(Request $request, FestivalEdition $edition): ?TelegramChatAuthorization
    {
        $payload = $request->session()->pull($this->sessionKey($edition));

        if (! is_array($payload) || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $series = $edition->series;

        return $series
            ? $this->authorizations->byId($series, (int) ($payload['telegram_chat_authorization_id'] ?? 0))
            : null;
    }

    public function hasSession(Request $request, FestivalEdition $edition): bool
    {
        return $request->session()->has($this->sessionKey($edition));
    }

    /** @return array<string, mixed>|null */
    private function pull(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        try {
            $payload = Cache::lock($this->lockKey($token), 10)->block(
                2,
                fn (): mixed => Cache::pull($this->cacheKey($token)),
            );
        } catch (LockTimeoutException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function sessionKey(FestivalEdition $edition): string
    {
        return 'festival-telegram-checkout:'.$edition->id;
    }

    private function cacheKey(string $token): string
    {
        return 'festival-telegram-checkout:'.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return 'festival-telegram-checkout-lock:'.hash('sha256', $token);
    }
}
