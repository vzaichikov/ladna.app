<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\MobileDeviceTokenRequest;
use App\Models\MobileDeviceToken;
use App\Models\MobileSession;
use Illuminate\Http\JsonResponse;

class MobileDeviceTokenController extends Controller
{
    public function store(MobileDeviceTokenRequest $request): JsonResponse
    {
        $session = $request->attributes->get('mobileSession');
        abort_unless($session instanceof MobileSession, 403);

        $validated = $request->validated();
        $provider = $validated['provider'] ?? 'fcm';
        $tokenHash = hash('sha256', (string) $validated['token']);

        $deviceToken = MobileDeviceToken::updateOrCreate(
            [
                'provider' => $provider,
                'token_hash' => $tokenHash,
            ],
            [
                'account_id' => $session->account_id,
                'mobile_session_id' => $session->id,
                'user_id' => $session->user_id,
                'customer_id' => $session->customer_id,
                'platform' => $validated['platform'],
                'encrypted_token' => $validated['token'],
                'device_name' => $validated['device_name'] ?? $session->device_name,
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $deviceToken->id,
                'provider' => $deviceToken->provider,
                'platform' => $deviceToken->platform,
                'last_seen_at' => $deviceToken->last_seen_at?->toIso8601String(),
            ],
        ]);
    }
}
