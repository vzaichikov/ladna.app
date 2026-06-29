<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TelegramUpdateStatus;
use App\Http\Controllers\Controller;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramUpdate;
use App\Support\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function Illuminate\Support\defer;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $webhookKey, TelegramUpdateProcessor $processor): Response|JsonResponse
    {
        $installation = TelegramBotInstallation::query()
            ->with('account')
            ->where('webhook_key_hash', TelegramBotInstallation::hashWebhookSecret($webhookKey))
            ->where('is_enabled', true)
            ->first();

        if (! $installation) {
            return response()->noContent(Response::HTTP_NOT_FOUND);
        }

        if ($installation->webhook_secret_token_hash) {
            $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

            if (! hash_equals($installation->webhook_secret_token_hash, TelegramBotInstallation::hashWebhookSecret($secret))) {
                return response()->json(['message' => __('app.telegram_webhook_forbidden')], Response::HTTP_FORBIDDEN);
            }
        }

        $updateId = $request->integer('update_id');

        if (! $updateId) {
            return response()->json(['message' => __('app.telegram_update_id_required')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $telegramUpdate = TelegramUpdate::firstOrCreate(
            [
                'telegram_bot_installation_id' => $installation->id,
                'update_id' => $updateId,
            ],
            [
                'account_id' => $installation->account_id,
                'profile' => $installation->profile->value,
                'payload' => $request->all(),
                'status' => TelegramUpdateStatus::Pending->value,
                'received_at' => now(),
            ],
        );

        if ($telegramUpdate->wasRecentlyCreated) {
            defer(function () use ($processor, $telegramUpdate): void {
                $processor->process($telegramUpdate->id);
            })->always();
        }

        return response()->noContent();
    }
}
