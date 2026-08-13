<?php

namespace App\Support\Festivals;

use App\Enums\AccountStatus;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalStreamOverride;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FestivalTicketStatus;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalStreamIpLease;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class FestivalStreamAccessService
{
    public function __construct(private readonly AccountSubscriptionAccess $subscriptionAccess) {}

    public function acquireLease(FestivalStreamEntitlement $entitlement, FestivalPortalUser $portalUser, string $ip): FestivalStreamEntitlement
    {
        $normalizedIp = $this->normalizeIp($ip);
        $ipHash = $this->hashIp($normalizedIp);

        return DB::transaction(function () use ($entitlement, $portalUser, $ipHash): FestivalStreamEntitlement {
            $stream = FestivalOnlineStream::query()->whereKey($entitlement->festival_online_stream_id)->lockForUpdate()->firstOrFail();
            $entitlement = FestivalStreamEntitlement::query()
                ->whereKey($entitlement->id)
                ->where('festival_online_stream_id', $stream->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->setRelation('stream', $stream);
            $this->assertAccessible($entitlement, $portalUser);
            FestivalStreamIpLease::query()
                ->where('festival_stream_entitlement_id', $entitlement->id)
                ->where('expires_at', '<=', now())
                ->delete();

            $lease = FestivalStreamIpLease::query()
                ->where('festival_stream_entitlement_id', $entitlement->id)
                ->where('ip_hash', $ipHash)
                ->lockForUpdate()
                ->first();
            $expiresAt = now()->addSeconds($this->leaseSeconds());
            if ($lease) {
                $lease->forceFill(['last_seen_at' => now(), 'expires_at' => $expiresAt])->save();
            } else {
                $activeLeases = FestivalStreamIpLease::query()
                    ->where('festival_stream_entitlement_id', $entitlement->id)
                    ->where('expires_at', '>', now())
                    ->count();
                if ($activeLeases >= (int) config('services.festival_stream.max_ip_leases', 3)) {
                    throw ValidationException::withMessages(['stream' => __('app.festival_stream_device_limit')]);
                }
                FestivalStreamIpLease::query()->create([
                    'account_id' => $entitlement->account_id,
                    'festival_stream_entitlement_id' => $entitlement->id,
                    'ip_hash' => $ipHash,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'expires_at' => $expiresAt,
                ]);
            }

            return $entitlement->load(['stream', 'ticket.order', 'ticket.admissionType', 'portalUser']);
        }, 3);
    }

    public function bootstrapToken(FestivalStreamEntitlement $entitlement, string $ip): string
    {
        $entitlement->loadMissing('stream');

        return $this->encrypt([
            'type' => 'bootstrap',
            'entitlement_id' => $entitlement->id,
            'portal_user_id' => $entitlement->festival_portal_user_id,
            'path' => $entitlement->stream->path,
            'ip_hash' => $this->hashIp($this->normalizeIp($ip)),
            'expires_at' => now()->addSeconds((int) config('services.festival_stream.bootstrap_seconds', 30))->getTimestamp(),
        ]);
    }

    public function consumeBootstrapToken(string $token, string $ip): FestivalStreamEntitlement
    {
        $payload = $this->decrypt($token, 'bootstrap', $ip);
        $entitlement = FestivalStreamEntitlement::query()->findOrFail((int) $payload['entitlement_id']);
        if ($entitlement->festival_portal_user_id !== (int) $payload['portal_user_id']) {
            $this->deny();
        }
        $this->assertAccessible($entitlement);
        $this->assertLease($entitlement, (string) $payload['ip_hash']);

        return $entitlement->loadMissing('stream');
    }

    public function viewerCookie(FestivalStreamEntitlement $entitlement, string $ip): string
    {
        $entitlement->loadMissing('stream');

        return $this->encrypt([
            'type' => 'viewer',
            'entitlement_id' => $entitlement->id,
            'portal_user_id' => $entitlement->festival_portal_user_id,
            'path' => $entitlement->stream->path,
            'ip_hash' => $this->hashIp($this->normalizeIp($ip)),
            'expires_at' => now()->addSeconds((int) config('services.festival_stream.session_seconds', 28800))->getTimestamp(),
        ]);
    }

    public function authorizeViewerCookie(string $token, string $path, string $ip): FestivalStreamEntitlement
    {
        $payload = $this->decrypt($token, 'viewer', $ip);
        if (! hash_equals((string) $payload['path'], $path)) {
            $this->deny();
        }

        $streamId = FestivalStreamEntitlement::query()
            ->whereKey((int) $payload['entitlement_id'])
            ->value('festival_online_stream_id');

        return DB::transaction(function () use ($payload, $streamId): FestivalStreamEntitlement {
            $stream = FestivalOnlineStream::query()->whereKey($streamId)->lockForUpdate()->firstOrFail();
            $entitlement = FestivalStreamEntitlement::query()
                ->whereKey((int) $payload['entitlement_id'])
                ->where('festival_online_stream_id', $stream->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->setRelation('stream', $stream);
            if ($entitlement->festival_portal_user_id !== (int) $payload['portal_user_id']) {
                $this->deny();
            }
            $this->assertAccessible($entitlement);
            $lease = $this->assertLease($entitlement, (string) $payload['ip_hash'], true);
            if ($lease->last_seen_at->lte(now()->subSeconds(30))) {
                $lease->forceFill([
                    'last_seen_at' => now(),
                    'expires_at' => now()->addSeconds($this->leaseSeconds()),
                ])->save();
            }

            return $entitlement->loadMissing('stream');
        }, 3);
    }

    public function releaseLeases(FestivalStreamEntitlement $entitlement, FestivalPortalUser $portalUser): void
    {
        DB::transaction(function () use ($entitlement, $portalUser): void {
            $stream = FestivalOnlineStream::query()->whereKey($entitlement->festival_online_stream_id)->lockForUpdate()->firstOrFail();
            $entitlement = FestivalStreamEntitlement::query()
                ->whereKey($entitlement->id)
                ->where('festival_online_stream_id', $stream->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->setRelation('stream', $stream);
            $this->assertAccessible($entitlement, $portalUser, false);
            $entitlement->leases()->delete();
        }, 3);
    }

    public function assertAccessible(FestivalStreamEntitlement $entitlement, ?FestivalPortalUser $portalUser = null, bool $requireOpen = true): void
    {
        $entitlement->loadMissing(['stream.account.subscription.plan', 'ticket.order', 'ticket.admissionType', 'portalUser']);
        $ticket = $entitlement->ticket;
        $stream = $entitlement->stream;

        if (! $ticket
            || ! $stream
            || ! $entitlement->portalUser?->is_active
            || ($portalUser && ($portalUser->id !== $entitlement->festival_portal_user_id || $portalUser->account_id !== $entitlement->account_id))
            || $ticket->account_id !== $entitlement->account_id
            || $ticket->status !== FestivalTicketStatus::Valid
            || $ticket->order?->status !== FestivalTicketOrderStatus::Paid
            || $ticket->admissionType?->delivery_mode !== FestivalAdmissionDeliveryMode::OnlineStream
            || $ticket->admissionType?->festival_online_stream_id !== $stream->id) {
            $this->deny();
        }
        $this->assertCapabilityAvailable($stream);
        if (! $stream->is_enabled) {
            throw ValidationException::withMessages(['stream' => __('app.festival_stream_disabled')]);
        }
        if ($requireOpen && ! $stream->isOpen()) {
            $message = $stream->playback_override === FestivalStreamOverride::Closed || $stream->closes_at?->isPast()
                ? __('app.festival_stream_closed')
                : __('app.festival_stream_not_open');
            throw ValidationException::withMessages(['stream' => $message]);
        }
    }

    public function assertCapabilityAvailable(FestivalOnlineStream $stream): void
    {
        $stream->loadMissing('account.subscription.plan');
        $account = $stream->account;

        if (! $account
            || $account->status !== AccountStatus::Active
            || ! $account->enable_festivals
            || ! $this->subscriptionAccess->canUsePublicFeatures($account)) {
            $this->deny();
        }
    }

    public function normalizeIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            $this->deny();
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }

        return (string) inet_ntop($packed);
    }

    public function viewerCookieName(string $path): string
    {
        $base = (string) config('services.festival_stream.cookie_name', 'ladna_festival_stream');

        return $base.'_'.substr(hash_hmac('sha256', $path, $this->hmacKey()), 0, 16);
    }

    private function assertLease(FestivalStreamEntitlement $entitlement, string $ipHash, bool $lock = false): FestivalStreamIpLease
    {
        $query = FestivalStreamIpLease::query()
            ->where('festival_stream_entitlement_id', $entitlement->id)
            ->where('ip_hash', $ipHash)
            ->where('expires_at', '>', now());
        $lease = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $lease) {
            $this->deny();
        }

        return $lease;
    }

    /** @return array<string, mixed> */
    private function decrypt(string $token, string $expectedType, string $ip): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->deny();
        }
        if (! is_array($payload)
            || ($payload['type'] ?? null) !== $expectedType
            || ! is_numeric($payload['entitlement_id'] ?? null)
            || ! is_numeric($payload['portal_user_id'] ?? null)
            || ! is_string($payload['path'] ?? null)
            || ! is_string($payload['ip_hash'] ?? null)
            || ! is_numeric($payload['expires_at'] ?? null)
            || (int) $payload['expires_at'] < now()->getTimestamp()
            || ! hash_equals((string) $payload['ip_hash'], $this->hashIp($this->normalizeIp($ip)))) {
            $this->deny();
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function encrypt(array $payload): string
    {
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $configured = (string) config('services.festival_stream.ip_hmac_key');

        return $configured !== '' ? $configured : (string) config('app.key');
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('services.festival_stream.lease_seconds', 120));
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['stream' => __('app.festival_stream_unavailable')]);
    }
}
