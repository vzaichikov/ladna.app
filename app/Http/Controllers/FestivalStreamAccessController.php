<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalStreamEntitlement;
use App\Support\Festivals\FestivalStreamAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalStreamAccessController extends Controller
{
    public function __construct(private readonly FestivalStreamAccessService $access) {}

    public function watch(Request $request, string $accountSlug, FestivalStreamEntitlement $festivalStreamEntitlement): RedirectResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $this->guest($request, $account, $accountSlug);
        abort_unless($festivalStreamEntitlement->account_id === $account->id
            && $festivalStreamEntitlement->festival_portal_user_id === $portalUser->id, 404);
        $entitlement = $this->access->acquireLease($festivalStreamEntitlement, $portalUser, (string) $request->ip());
        $token = $this->access->bootstrapToken($entitlement, (string) $request->ip());
        $gateway = rtrim((string) config('services.festival_stream.public_url'), '/');
        abort_if($gateway === '', 503, __('app.festival_stream_unavailable'));

        return redirect()->away($gateway.'/festival-stream/bootstrap?token='.rawurlencode($token));
    }

    public function bootstrap(Request $request): RedirectResponse
    {
        try {
            $entitlement = $this->access->consumeBootstrapToken((string) $request->query('token'), (string) $request->ip());
        } catch (ModelNotFoundException|ValidationException) {
            abort(403, __('app.festival_stream_unavailable'));
        }
        $cookie = cookie(
            $this->access->viewerCookieName($entitlement->stream->path),
            $this->access->viewerCookie($entitlement, (string) $request->ip()),
            (int) ceil(((int) config('services.festival_stream.session_seconds', 28800)) / 60),
            '/',
            null,
            true,
            true,
            false,
            'lax',
        );

        return redirect('/festival-stream/watch/'.rawurlencode($entitlement->stream->path))->withCookie($cookie);
    }

    public function player(Request $request, string $path): View
    {
        $entitlement = $this->authorizeCookie($request, $path);
        $publicUrl = rtrim((string) config('services.festival_stream.public_url'), '/');

        return view('festivals.portal.stream-player', [
            'stream' => $entitlement->stream,
            'playlistUrl' => $publicUrl.'/hls/'.rawurlencode($path).'/index.m3u8',
            'heartbeatUrl' => $publicUrl.'/festival-stream/heartbeat/'.rawurlencode($path),
        ]);
    }

    public function heartbeat(Request $request, string $path): Response
    {
        $this->authorizeCookie($request, $path);

        return response()->noContent();
    }

    public function release(Request $request, string $accountSlug, FestivalStreamEntitlement $festivalStreamEntitlement): RedirectResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $this->guest($request, $account, $accountSlug);
        abort_unless($festivalStreamEntitlement->account_id === $account->id
            && $festivalStreamEntitlement->festival_portal_user_id === $portalUser->id, 404);
        $this->access->releaseLeases($festivalStreamEntitlement, $portalUser);

        return back()->with('status', __('app.festival_stream_devices_released'));
    }

    public function gatewayAuthorize(Request $request): Response
    {
        if (! $this->validInternalSecret((string) $request->header('X-Festival-Stream-Secret'))) {
            return response('', 401);
        }
        $path = (string) $request->header('X-Festival-Stream-Path');
        $ip = (string) $request->header('X-Original-Client-IP');
        $cookie = (string) $request->cookie($this->access->viewerCookieName($path));
        if ($path === '' || $ip === '' || $cookie === '') {
            return response('', 401);
        }
        try {
            $this->access->authorizeViewerCookie($cookie, $path, $ip);
        } catch (ModelNotFoundException|ValidationException) {
            return response('', 403);
        }

        return response()->noContent();
    }

    public function publisherAuthorize(Request $request): Response
    {
        $secret = (string) $request->header('X-Festival-Stream-Secret');
        if (! $this->validInternalSecret($secret)
            || $request->input('action') !== 'publish'
            || $request->input('protocol') !== 'rtmp') {
            return response('', 401);
        }
        $stream = FestivalOnlineStream::query()
            ->where('path', (string) $request->input('path'))
            ->where('is_enabled', true)
            ->first();
        if (! $stream) {
            return response('', 401);
        }
        try {
            $this->access->assertCapabilityAvailable($stream);
        } catch (ValidationException) {
            return response('', 401);
        }
        $query = [];
        parse_str(ltrim((string) $request->input('query'), '?'), $query);
        $publisherToken = (string) ($query['token'] ?? $request->input('password', ''));
        if ($publisherToken === '' || ! hash_equals($stream->publisher_token_hash, hash('sha256', $publisherToken))) {
            return response('', 401);
        }

        return response()->noContent();
    }

    private function authorizeCookie(Request $request, string $path): FestivalStreamEntitlement
    {
        $cookie = (string) $request->cookie($this->access->viewerCookieName($path));
        abort_if($cookie === '', 403, __('app.festival_stream_unavailable'));

        try {
            return $this->access->authorizeViewerCookie($cookie, $path, (string) $request->ip());
        } catch (ModelNotFoundException|ValidationException) {
            abort(403, __('app.festival_stream_unavailable'));
        }
    }

    private function validInternalSecret(string $secret): bool
    {
        $configured = (string) config('services.festival_stream.internal_secret');

        return $configured !== '' && $secret !== '' && hash_equals($configured, $secret);
    }

    private function guest(Request $request, mixed $account, string $accountSlug): FestivalPortalUser
    {
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);
        $portalUser = $request->user('festival');
        abort_unless($portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id
            && $portalUser->role === FestivalPortalRole::Guest
            && $portalUser->is_active, 403);

        return $portalUser;
    }
}
