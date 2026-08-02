<?php

namespace App\Support\Telegram;

use App\Enums\CustomerNotificationStatus;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramCustomerSessionState;
use App\Models\CustomerNotification;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramCustomerSession;
use Illuminate\Support\Facades\DB;

class CustomerTelegramConnectionManager
{
    public function reset(TelegramChatAuthorization $authorization): void
    {
        TelegramCustomerSession::query()
            ->where('telegram_chat_authorization_id', $authorization->id)
            ->update([
                'state' => TelegramCustomerSessionState::Idle->value,
                'encrypted_context' => null,
                'expires_at' => now()->addMinutes(30),
                'updated_at' => now(),
            ]);
    }

    public function revoke(TelegramChatAuthorization $authorization): void
    {
        DB::transaction(function () use ($authorization): void {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();

            TelegramCustomerSession::query()
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->update([
                    'telegram_chat_authorization_id' => null,
                    'state' => TelegramCustomerSessionState::AwaitingContact->value,
                    'encrypted_context' => null,
                    'expires_at' => now()->addMinutes(30),
                    'updated_at' => now(),
                ]);

            CustomerNotification::query()
                ->where('telegram_chat_authorization_id', $authorization->id)
                ->whereIn('status', [
                    CustomerNotificationStatus::Pending->value,
                    CustomerNotificationStatus::Processing->value,
                ])
                ->update([
                    'telegram_chat_authorization_id' => null,
                    'resolved_channel' => null,
                    'updated_at' => now(),
                ]);
        });
    }
}
