<?php

namespace App\Http\Controllers;

use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\TelegramBotInstallation;
use App\Support\Telegram\CustomerTelegramBotConnector;
use App\Support\Telegram\TelegramWebhookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AccountCustomerTelegramWebhookController extends Controller
{
    public function show(Account $account, TelegramWebhookManager $webhooks): JsonResponse
    {
        $this->authorize('manageStudioSettings', $account);

        return response()->json($webhooks->status($this->installation($account)));
    }

    public function store(Account $account, CustomerTelegramBotConnector $connector): JsonResponse
    {
        $this->authorize('manageStudioSettings', $account);
        $this->installation($account);
        $result = $connector->registerWebhook($account);

        return response()->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function destroy(Account $account, CustomerTelegramBotConnector $connector): JsonResponse
    {
        $this->authorize('manageStudioSettings', $account);
        $this->installation($account);
        $result = $connector->deleteWebhook($account);

        return response()->json($result, $result['ok'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function installation(Account $account): TelegramBotInstallation
    {
        return $account->telegramBotInstallations()
            ->where('scope_type', 'account')
            ->where('scope_id', $account->id)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->firstOrFail();
    }
}
