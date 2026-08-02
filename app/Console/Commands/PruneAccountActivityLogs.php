<?php

namespace App\Console\Commands;

use App\Models\AccountActivityLog;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\AiProviderRequest;
use App\Models\CustomerNotification;
use App\Models\McpToolInvocation;
use App\Models\TelegramAlert;
use App\Models\TelegramAuthorizationSelection;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Support\AccountActivityLogSettings;
use App\Support\Ai\AiConversationImageCleaner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

#[Signature('account-activity-logs:prune')]
#[Description('Delete activity, Telegram, and Telegram assistant log entries older than the configured retention period.')]
class PruneAccountActivityLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AiConversationImageCleaner $imageCleaner): int
    {
        $retentionDays = AccountActivityLogSettings::retentionDays();
        $cutoff = now()->subDays($retentionDays);
        $deletedActivityLogs = AccountActivityLog::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->where('occurred_at', '<', $cutoff)
            ->delete();

        $deletedMcpToolInvocations = $this->deleteOldTelegramMcpToolInvocations($cutoff);
        $deletedAiProviderRequests = AiProviderRequest::query()
            ->where('started_at', '<', $cutoff)
            ->delete();
        $deletedAiPendingActions = $this->deleteOldTelegramAiPendingActions($cutoff);
        $deletedAiMessages = $this->deleteOldTelegramAiMessages($cutoff, $imageCleaner);
        $deletedAiConversations = $this->deleteOldTelegramAiConversations($cutoff);
        $deletedTelegramAlerts = $this->deleteOldTelegramAlerts($cutoff);
        $deletedCustomerTelegramNotifications = $this->deleteOldCustomerTelegramNotifications($cutoff);
        $deletedTelegramMessages = $this->deleteOldTelegramMessages($cutoff);
        $deletedTelegramUpdates = $this->deleteOldTelegramUpdates($cutoff);
        $deletedAuthorizationSelections = $this->deleteOldTelegramAuthorizationSelections($cutoff);

        $this->info(__('app.account_activity_logs_pruned', ['count' => $deletedActivityLogs]));
        $this->info(__('app.ai_provider_requests_pruned', ['count' => $deletedAiProviderRequests]));
        $this->info(__('app.telegram_logs_pruned', [
            'messages' => $deletedTelegramMessages,
            'updates' => $deletedTelegramUpdates,
            'alerts' => $deletedTelegramAlerts,
            'notifications' => $deletedCustomerTelegramNotifications,
            'conversations' => $deletedAiConversations,
            'conversation_messages' => $deletedAiMessages,
            'pending_actions' => $deletedAiPendingActions,
            'tool_invocations' => $deletedMcpToolInvocations,
            'authorization_selections' => $deletedAuthorizationSelections,
        ]));

        return self::SUCCESS;
    }

    private function deleteOldTelegramMcpToolInvocations(Carbon $cutoff): int
    {
        return McpToolInvocation::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'started_at', $cutoff))
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('conversation', fn (Builder $query): Builder => $this->whereTelegramOwnerConversation($query))
                    ->orWhereHas('conversationMessage.conversation', fn (Builder $query): Builder => $this->whereTelegramOwnerConversation($query));
            })
            ->delete();
    }

    private function deleteOldTelegramAiPendingActions(Carbon $cutoff): int
    {
        return AiPendingAction::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'created_at', $cutoff))
            ->whereHas('conversation', fn (Builder $query): Builder => $this->whereTelegramOwnerConversation($query))
            ->delete();
    }

    private function deleteOldTelegramAiMessages(
        Carbon $cutoff,
        AiConversationImageCleaner $imageCleaner,
    ): int {
        $query = AiConversationMessage::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'occurred_at', $cutoff))
            ->whereHas('conversation', fn (Builder $query): Builder => $this->whereTelegramOwnerConversation($query));
        $imageCleaner->deleteForMessageIds((clone $query)->pluck('id'));

        return $query->delete();
    }

    private function deleteOldTelegramAiConversations(Carbon $cutoff): int
    {
        return AiConversation::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'last_message_at', $cutoff))
            ->where(fn (Builder $query): Builder => $this->whereTelegramOwnerConversation($query))
            ->delete();
    }

    private function deleteOldTelegramAlerts(Carbon $cutoff): int
    {
        return TelegramAlert::query()
            ->where(fn (Builder $query): Builder => $this->wherePrunableTelegramAccount($query))
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'created_at', $cutoff))
            ->delete();
    }

    private function deleteOldCustomerTelegramNotifications(Carbon $cutoff): int
    {
        return CustomerNotification::query()
            ->whereHas('account', fn (Builder $query): Builder => $query->operational())
            ->telegramInteraction()
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'created_at', $cutoff))
            ->delete();
    }

    private function deleteOldTelegramMessages(Carbon $cutoff): int
    {
        return TelegramMessage::query()
            ->where(fn (Builder $query): Builder => $this->wherePrunableTelegramAccount($query))
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'sent_at', $cutoff))
            ->delete();
    }

    private function deleteOldTelegramUpdates(Carbon $cutoff): int
    {
        return TelegramUpdate::query()
            ->where(fn (Builder $query): Builder => $this->wherePrunableTelegramAccount($query))
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'received_at', $cutoff))
            ->delete();
    }

    private function deleteOldTelegramAuthorizationSelections(Carbon $cutoff): int
    {
        return TelegramAuthorizationSelection::query()
            ->where(fn (Builder $query): Builder => $this->whereOlderThan($query, 'expires_at', $cutoff))
            ->delete();
    }

    private function whereTelegramOwnerConversation(Builder $query): Builder
    {
        return $query
            ->where('channel', 'telegram_owner')
            ->whereNotNull('telegram_chat_authorization_id');
    }

    private function wherePrunableTelegramAccount(Builder $query): Builder
    {
        return $query
            ->whereNull('account_id')
            ->orWhereHas('account', fn (Builder $query): Builder => $query->operational());
    }

    private function whereOlderThan(Builder $query, string $dateColumn, Carbon $cutoff): Builder
    {
        return $query
            ->where($dateColumn, '<', $cutoff)
            ->orWhere(fn (Builder $query): Builder => $query
                ->whereNull($dateColumn)
                ->where('created_at', '<', $cutoff));
    }
}
