<?php

namespace App\Support\Telegram;

use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\TelegramChatAuthorization;
use Illuminate\Support\Facades\DB;

class TelegramConversationResetter
{
    public function reset(TelegramChatAuthorization $authorization): void
    {
        DB::transaction(function () use ($authorization): void {
            $conversationIds = $authorization->conversations()
                ->where('channel', 'telegram_owner')
                ->where('status', AiConversation::StatusActive)
                ->pluck('id');

            if ($conversationIds->isEmpty()) {
                return;
            }

            AiPendingAction::query()
                ->whereIn('ai_conversation_id', $conversationIds)
                ->where('status', AiPendingAction::StatusPending)
                ->update([
                    'status' => AiPendingAction::StatusCancelled,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            AiConversation::query()
                ->whereKey($conversationIds)
                ->update([
                    'status' => AiConversation::StatusCleared,
                    'last_message_at' => now(),
                    'updated_at' => now(),
                ]);
        });
    }

    public function revoke(TelegramChatAuthorization $authorization): void
    {
        DB::transaction(function () use ($authorization): void {
            $this->reset($authorization);

            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();
        });
    }
}
