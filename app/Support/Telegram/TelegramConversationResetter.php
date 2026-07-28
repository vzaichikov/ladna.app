<?php

namespace App\Support\Telegram;

use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\TelegramChatAuthorization;
use App\Support\Ai\AiConversationImageCleaner;
use Illuminate\Support\Facades\DB;

class TelegramConversationResetter
{
    public function __construct(private readonly AiConversationImageCleaner $conversationImageCleaner) {}

    public function reset(TelegramChatAuthorization $authorization): void
    {
        $conversationIds = $authorization->conversations()
            ->where('channel', 'telegram_owner')
            ->where('status', AiConversation::StatusActive)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return;
        }

        $this->conversationImageCleaner->deleteForConversationIds($conversationIds);

        DB::transaction(function () use ($conversationIds): void {
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
        $this->reset($authorization);

        DB::transaction(function () use ($authorization): void {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();
        });
    }
}
