<?php

namespace App\Http\Controllers;

use App\Enums\FestivalStreamOverride;
use App\Enums\FestivalStreamProvider;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\FestivalOnlineStreamRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalStreamIpLease;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\Festivals\FestivalMediaMtxGateway;
use App\Support\Festivals\FestivalStreamAccessService;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Festivals\FestivalYouTubeVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class FestivalOnlineStreamController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalMediaMtxGateway $mediaMtx,
        private readonly FestivalStreamAccessService $streamAccess,
    ) {}

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $stream = $festivalEdition->onlineStream()->withCount('entitlements')->first();

        return view('festivals.staff.online-stream', [
            'activeStreamTab' => $stream?->is_enabled && $request->string('tab')->toString() === 'preview' ? 'preview' : 'settings',
            'account' => $account,
            'edition' => $festivalEdition,
            'stream' => $stream,
            'activeStreamConnections' => $stream ? $this->activeLeaseCount($stream) : 0,
            'streamStatus' => $this->streamStatus($stream),
            'workspacePermissions' => $permissions,
        ]);
    }

    public function status(Request $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $stream = FestivalOnlineStream::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($festivalEdition, 'edition')
            ->firstOrFail();
        $status = $this->streamStatus($stream, reportFailure: false);

        return response()->json([
            'provider' => $stream->provider->value,
            'playback_open' => $stream->isOpen(),
            'configured' => $this->providerConfigured($stream),
            'server_online' => $status !== null,
            'publisher_online' => $status['publisher_online'] ?? false,
            'readers' => $stream->provider === FestivalStreamProvider::MediaMtx
                ? ($status['readers'] ?? 0)
                : $this->activeLeaseCount($stream),
            'connected_at' => $status['connected_at'] ?? null,
            'tracks' => $status['tracks'] ?? [],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function update(FestivalOnlineStreamRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $request->validated();
        $provider = FestivalStreamProvider::from($data['provider']);
        $youtubeVideoId = filled($data['youtube_url'] ?? null)
            ? FestivalYouTubeVideo::idFromUrl((string) $data['youtube_url'])
            : null;

        $configured = DB::transaction(function () use ($account, $festivalEdition, $data, $provider, $youtubeVideoId): FestivalOnlineStream {
            $stream = FestivalOnlineStream::query()->where('festival_edition_id', $festivalEdition->id)->lockForUpdate()->first();
            $creating = $stream === null;
            if ($creating) {
                $publisherToken = Str::random(64);
                $stream = new FestivalOnlineStream([
                    'account_id' => $account->id,
                    'festival_edition_id' => $festivalEdition->id,
                    'path' => 'festival-'.Str::lower(Str::random(32)),
                    'publisher_token_encrypted' => $publisherToken,
                    'publisher_token_hash' => hash('sha256', $publisherToken),
                    'is_enabled' => false,
                    'provider' => $provider,
                    'youtube_video_id' => $youtubeVideoId,
                    'playback_override' => FestivalStreamOverride::Closed,
                ]);
            }

            $requestedEnabled = ! $creating && (bool) $data['is_enabled'];
            if ($stream->exists && $stream->playback_override === FestivalStreamOverride::Open) {
                if ($stream->provider !== $provider) {
                    throw ValidationException::withMessages(['provider' => __('app.festival_stream_stop_before_switching')]);
                }
                if ($provider === FestivalStreamProvider::YouTube && $stream->youtube_video_id !== $youtubeVideoId) {
                    throw ValidationException::withMessages(['youtube_url' => __('app.festival_stream_stop_before_editing_source')]);
                }
                if ($provider === FestivalStreamProvider::MediaMtx && $data['rotate_publisher_token']) {
                    throw ValidationException::withMessages(['rotate_publisher_token' => __('app.festival_stream_stop_before_editing_source')]);
                }
            }
            if ($requestedEnabled && ! $this->providerReady($provider, $youtubeVideoId)) {
                throw ValidationException::withMessages(['is_enabled' => __('app.festival_stream_infrastructure_unavailable')]);
            }
            if ($stream->exists
                && $stream->is_enabled
                && ! $requestedEnabled
                && ($this->hasOpenOnlineSales($stream) || $this->hasActiveOnlineOrders($stream))) {
                throw ValidationException::withMessages(['is_enabled' => __('app.festival_stream_disable_blocked')]);
            }

            if (! $creating && $data['rotate_publisher_token']) {
                $publisherToken = Str::random(64);
                $stream->publisher_token_encrypted = $publisherToken;
                $stream->publisher_token_hash = hash('sha256', $publisherToken);
            }

            $stream->fill([
                'is_enabled' => $requestedEnabled,
                'provider' => $provider,
                'youtube_video_id' => $youtubeVideoId,
            ]);
            if (! $requestedEnabled) {
                $stream->forceFill([
                    'playback_override' => FestivalStreamOverride::Closed,
                    'opens_at' => null,
                    'closes_at' => null,
                ]);
            }
            $stream->save();

            return $stream;
        }, 3);

        return redirect()->route('dashboard.accounts.festivals.online-stream.edit', [$account, $festivalEdition])
            ->with('status', $configured->wasRecentlyCreated ? __('app.festival_stream_configured') : __('app.festival_stream_saved'));
    }

    public function start(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        return $this->setPlayback($request, $account, $festivalEdition, FestivalStreamOverride::Open);
    }

    public function stop(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        return $this->setPlayback($request, $account, $festivalEdition, FestivalStreamOverride::Closed);
    }

    public function resetLeases(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        DB::transaction(function () use ($festivalEdition): void {
            $stream = FestivalOnlineStream::query()
                ->where('festival_edition_id', $festivalEdition->id)
                ->lockForUpdate()
                ->firstOrFail();
            $entitlementIds = FestivalStreamEntitlement::query()
                ->where('festival_online_stream_id', $stream->id)
                ->lockForUpdate()
                ->pluck('id');
            FestivalStreamIpLease::query()->whereIn('festival_stream_entitlement_id', $entitlementIds)->delete();
        }, 3);

        return back()->with('status', __('app.festival_stream_devices_released'));
    }

    public function preview(Request $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $stream = FestivalOnlineStream::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($festivalEdition, 'edition')
            ->firstOrFail();
        abort_unless($stream->is_enabled, 409, __('app.festival_stream_disabled'));
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $token = $this->streamAccess->staffPreviewBootstrapToken($stream, $user, (string) $request->ip());
        $gateway = rtrim((string) config('services.festival_stream.public_url'), '/');
        abort_if($gateway === '', 503, __('app.festival_stream_unavailable'));

        return redirect()->away($gateway.'/festival-stream/bootstrap?token='.rawurlencode($token));
    }

    private function hasActiveOnlineOrders(FestivalOnlineStream $stream): bool
    {
        return FestivalTicketOrder::query()
            ->where('festival_edition_id', $stream->festival_edition_id)
            ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
            ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now()))
            ->whereHas('items.admissionType', fn ($query) => $query->where('festival_online_stream_id', $stream->id))
            ->exists();
    }

    private function setPlayback(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalStreamOverride $override,
    ): RedirectResponse {
        $this->financePermissions($request, $account, $festivalEdition);

        DB::transaction(function () use ($account, $festivalEdition, $override): void {
            $stream = FestivalOnlineStream::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($festivalEdition, 'edition')
                ->lockForUpdate()
                ->firstOrFail();

            if ($override === FestivalStreamOverride::Open && ! $stream->is_enabled) {
                throw ValidationException::withMessages(['stream' => __('app.festival_stream_start_requires_enabled')]);
            }
            if ($override === FestivalStreamOverride::Open && ! $this->providerConfigured($stream)) {
                throw ValidationException::withMessages(['stream' => __('app.festival_stream_source_incomplete')]);
            }

            $stream->forceFill([
                'playback_override' => $override,
                'opens_at' => null,
                'closes_at' => null,
            ])->save();
        }, 3);

        return back()->with('status', __($override === FestivalStreamOverride::Open
            ? 'app.festival_stream_started'
            : 'app.festival_stream_stopped'));
    }

    private function hasOpenOnlineSales(FestivalOnlineStream $stream): bool
    {
        return $stream->admissionTypes()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('sales_starts_at')->orWhere('sales_starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('sales_ends_at')->orWhere('sales_ends_at', '>=', now()))
            ->exists();
    }

    /** @return array{publisher_online: bool, readers: int, connected_at: ?string, tracks: list<string>}|null */
    private function streamStatus(?FestivalOnlineStream $stream, bool $reportFailure = true): ?array
    {
        if (! $stream || $stream->provider !== FestivalStreamProvider::MediaMtx) {
            return null;
        }

        try {
            return $this->mediaMtx->status($stream);
        } catch (Throwable $exception) {
            if ($reportFailure) {
                report($exception);
            }

            return null;
        }
    }

    private function activeLeaseCount(FestivalOnlineStream $stream): int
    {
        if (! $stream->is_enabled || ! $stream->isOpen()) {
            return 0;
        }

        return FestivalStreamIpLease::query()
            ->where('expires_at', '>', now())
            ->whereHas('entitlement', fn ($query) => $query->where('festival_online_stream_id', $stream->id))
            ->count();
    }

    private function providerConfigured(FestivalOnlineStream $stream): bool
    {
        return $this->providerReady($stream->provider, $stream->youtube_video_id);
    }

    private function providerReady(FestivalStreamProvider $provider, ?string $youtubeVideoId): bool
    {
        if ($provider === FestivalStreamProvider::MediaMtx) {
            return $this->mediaMtx->configured();
        }

        return FestivalYouTubeVideo::embedUrl($youtubeVideoId) !== ''
            && collect(['public_url', 'internal_secret', 'ip_hmac_key'])
                ->every(fn (string $key): bool => trim((string) config("services.festival_stream.{$key}")) !== '');
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool, door_staff: bool, event_festival_staff: bool, timeline_operator: bool, stream_administrator: bool} */
    private function financePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['stream_administrator'], 403);

        return $permissions;
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }
}
