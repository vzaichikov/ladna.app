<?php

namespace App\Http\Controllers\Platform;

use App\Enums\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\PlatformAiProviderCredential;
use App\Support\Ai\AiProviderModelDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AiProviderModelController extends Controller
{
    public function __invoke(Request $request, AiProviderModelDiscovery $discovery): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
        ]);
        $provider = AiProvider::tryFrom((string) $validated['provider']);

        abort_unless($provider, 404);

        $credential = PlatformAiProviderCredential::query()
            ->where('provider', $provider->value)
            ->first();
        $secret = $credential?->apiKey();

        if (! $secret) {
            return response()->json([
                'models' => [],
                'configured' => false,
                'message' => __('app.ai_model_discovery_missing_secret'),
            ]);
        }

        try {
            return response()->json([
                'models' => $discovery->models($provider, $secret),
                'configured' => true,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'models' => [],
                'configured' => true,
                'message' => __('app.ai_model_discovery_failed'),
            ], 502);
        }
    }
}
