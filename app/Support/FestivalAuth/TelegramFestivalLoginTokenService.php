<?php

namespace App\Support\FestivalAuth;

use App\Enums\FestivalPortalRole;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\TelegramChatAuthorization;
use App\Support\Festivals\FestivalTelegramAuthorizationResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramFestivalLoginTokenService
{
    private const TokenTtlMinutes = 5;

    /** @var array<int, string> */
    private const RegistrantDestinations = ['dashboard', 'profile', 'entries', 'entry', 'create_entry'];

    public function __construct(private readonly FestivalTelegramAuthorizationResolver $authorizations) {}

    public function issueRegistrantUrl(
        FestivalSeries $series,
        TelegramChatAuthorization $authorization,
        FestivalPortalUser $registrant,
        string $destination,
        ?int $targetId = null,
    ): string {
        if (! in_array($destination, self::RegistrantDestinations, true)
            || $registrant->role !== FestivalPortalRole::Registrant
            || ! $registrant->is_active
            || (int) $registrant->account_id !== (int) $series->account_id
            || ! $this->linked($series, $authorization, $registrant)) {
            throw new InvalidArgumentException('Festival Telegram authorization does not match the Registrant destination.');
        }

        $this->resolveRegistrantRoute($series, $registrant, $destination, $targetId);

        return $this->issue($series, [
            'kind' => 'registrant',
            'account_id' => (int) $series->account_id,
            'series_id' => (int) $series->id,
            'telegram_chat_authorization_id' => (int) $authorization->id,
            'festival_portal_user_id' => (int) $registrant->id,
            'destination' => $destination,
            'target_id' => $targetId,
        ], 'public.festival-telegram.login.consume');
    }

    public function issueOrderUrl(FestivalSeries $series, TelegramChatAuthorization $authorization, FestivalTicketOrder $order): string
    {
        $order->loadMissing(['edition', 'portalUser']);
        $guest = $order->portalUser;

        if (! $guest
            || $guest->role !== FestivalPortalRole::Guest
            || (int) $order->account_id !== (int) $series->account_id
            || (int) $order->edition->festival_series_id !== (int) $series->id
            || ! $this->linked($series, $authorization, $guest)) {
            throw new InvalidArgumentException('Festival Telegram authorization does not match the Guest order.');
        }

        return $this->issue($series, [
            'kind' => 'order',
            'account_id' => (int) $series->account_id,
            'series_id' => (int) $series->id,
            'telegram_chat_authorization_id' => (int) $authorization->id,
            'festival_portal_user_id' => (int) $guest->id,
            'festival_ticket_order_id' => (int) $order->id,
        ], 'public.festival-telegram.order.consume');
    }

    /** @return array{portal_user: FestivalPortalUser, route_name: string, route_parameters: array<int|string, mixed>}|null */
    public function consumeRegistrant(FestivalSeries $series, string $token): ?array
    {
        $payload = $this->consume($series, $token, 'registrant');

        if (! $payload) {
            return null;
        }

        $authorization = $this->authorizations->byId($series, (int) $payload['telegram_chat_authorization_id']);
        $registrant = $authorization
            ? $this->authorizations->linkedPortalUser($authorization, FestivalPortalRole::Registrant)
            : null;

        if (! $registrant || (int) $registrant->id !== (int) $payload['festival_portal_user_id']) {
            return null;
        }

        [$routeName, $routeParameters] = $this->resolveRegistrantRoute(
            $series,
            $registrant,
            (string) $payload['destination'],
            isset($payload['target_id']) ? (int) $payload['target_id'] : null,
        );

        return ['portal_user' => $registrant, 'route_name' => $routeName, 'route_parameters' => $routeParameters];
    }

    public function consumeOrder(FestivalSeries $series, string $token): ?FestivalTicketOrder
    {
        $payload = $this->consume($series, $token, 'order');

        if (! $payload) {
            return null;
        }

        $authorization = $this->authorizations->byId($series, (int) $payload['telegram_chat_authorization_id']);
        $guest = $authorization
            ? $this->authorizations->linkedPortalUser($authorization, FestivalPortalRole::Guest)
            : null;

        if (! $guest || (int) $guest->id !== (int) $payload['festival_portal_user_id']) {
            return null;
        }

        return FestivalTicketOrder::query()
            ->whereKey((int) $payload['festival_ticket_order_id'])
            ->where('account_id', $series->account_id)
            ->where('festival_portal_user_id', $guest->id)
            ->whereHas('edition', fn ($query) => $query->where('festival_series_id', $series->id))
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function issue(FestivalSeries $series, array $payload, string $routeName): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TokenTtlMinutes);
        Cache::put($this->cacheKey($token), $payload, $expiresAt);

        return URL::temporarySignedRoute($routeName, $expiresAt, [
            'accountSlug' => $series->account()->value('slug'),
            'seriesSlug' => $series->slug,
            'token' => $token,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function consume(FestivalSeries $series, string $token, string $kind): ?array
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

        if (! is_array($payload)
            || ($payload['kind'] ?? null) !== $kind
            || (int) ($payload['account_id'] ?? 0) !== (int) $series->account_id
            || (int) ($payload['series_id'] ?? 0) !== (int) $series->id) {
            return null;
        }

        return $payload;
    }

    /** @return array{string, array<int|string, mixed>} */
    private function resolveRegistrantRoute(FestivalSeries $series, FestivalPortalUser $registrant, string $destination, ?int $targetId): array
    {
        $accountSlug = (string) $series->account()->value('slug');

        return match ($destination) {
            'dashboard' => ['festival.portal.dashboard', [$accountSlug]],
            'profile' => ['festival.portal.profile.edit', [$accountSlug]],
            'entries' => ['festival.portal.entries.index', [$accountSlug]],
            'entry' => $this->entryRoute($series, $registrant, $accountSlug, $targetId),
            'create_entry' => $this->createEntryRoute($series, $accountSlug, $targetId),
            default => throw new InvalidArgumentException('Unsupported Festival Telegram portal destination.'),
        };
    }

    /** @return array{string, array<int|string, mixed>} */
    private function entryRoute(FestivalSeries $series, FestivalPortalUser $registrant, string $accountSlug, ?int $targetId): array
    {
        $entry = FestivalEntry::query()
            ->whereKey($targetId)
            ->where('account_id', $series->account_id)
            ->where('festival_portal_user_id', $registrant->id)
            ->whereHas('edition', fn ($query) => $query->where('festival_series_id', $series->id))
            ->firstOrFail();

        return ['festival.portal.entries.show', [$accountSlug, $entry]];
    }

    /** @return array{string, array<int|string, mixed>} */
    private function createEntryRoute(FestivalSeries $series, string $accountSlug, ?int $targetId): array
    {
        $edition = FestivalEdition::query()
            ->whereKey($targetId)
            ->where('account_id', $series->account_id)
            ->where('festival_series_id', $series->id)
            ->published()
            ->firstOrFail();

        return ['festival.portal.entries.create', [$accountSlug, $edition->slug]];
    }

    private function linked(FestivalSeries $series, TelegramChatAuthorization $authorization, FestivalPortalUser $portalUser): bool
    {
        $current = $this->authorizations->byId($series, $authorization->id);

        return $current !== null
            && $portalUser->telegramFestivalLinks()
                ->where('account_id', $portalUser->account_id)
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->exists();
    }

    private function cacheKey(string $token): string
    {
        return 'festival-telegram-login:'.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return 'festival-telegram-login-lock:'.hash('sha256', $token);
    }
}
