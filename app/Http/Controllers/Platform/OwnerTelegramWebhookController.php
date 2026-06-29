<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Telegram\TelegramWebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OwnerTelegramWebhookController extends Controller
{
    public function show(TelegramWebhookManager $webhooks): JsonResponse
    {
        return response()->json($webhooks->status());
    }

    public function store(TelegramWebhookManager $webhooks): JsonResponse
    {
        $result = $webhooks->register();

        return response()->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function destroy(TelegramWebhookManager $webhooks): JsonResponse
    {
        $result = $webhooks->delete();

        return response()->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
