<?php

namespace App\Support\Ai;

use App\Enums\AiConversationMessageRole;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\ScheduledClass;
use App\Models\TelegramChatAuthorization;
use Illuminate\Support\Carbon;

class StudioAiContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function studioContext(Account $account): array
    {
        $timezone = $account->timezone ?: config('app.timezone');
        $today = now($timezone)->startOfDay();
        $tomorrow = $today->copy()->addDay();

        return [
            'studio' => [
                'name' => $account->name,
                'timezone' => $timezone,
                'opening_hours' => $account->openingHours(),
            ],
            'locations' => $account->locations()
                ->active()
                ->orderBy('name')
                ->get(['id', 'account_id', 'name', 'address', 'phone', 'email', 'timezone'])
                ->map(fn ($location): array => [
                    'name' => $location->name,
                    'address' => $location->address,
                    'phone' => $location->phone,
                    'email' => $location->email,
                    'timezone' => $location->timezone ?: $timezone,
                ])
                ->all(),
            'class_counts' => [
                'today' => [
                    'date' => $today->toDateString(),
                    'scheduled' => $this->scheduledClassCount($account, $today),
                ],
                'tomorrow' => [
                    'date' => $tomorrow->toDateString(),
                    'scheduled' => $this->scheduledClassCount($account, $tomorrow),
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function recentMessages(?TelegramChatAuthorization $authorization, int $limit = 8): array
    {
        if (! $authorization) {
            return [];
        }

        $conversation = $authorization->account
            ->aiConversations()
            ->where('telegram_chat_authorization_id', $authorization->id)
            ->where('channel', 'telegram_owner')
            ->where('status', 'active')
            ->latest('last_message_at')
            ->first();

        if (! $conversation) {
            return [];
        }

        return $conversation->messages()
            ->whereIn('role', [
                AiConversationMessageRole::User->value,
                AiConversationMessageRole::Assistant->value,
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($message): array => [
                'role' => $message->role === AiConversationMessageRole::Assistant ? 'assistant' : 'user',
                'content' => mb_substr($message->content, 0, 1200),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function recentConversationMessages(AiConversation $conversation, int $limit = 8): array
    {
        return $conversation->messages()
            ->whereIn('role', [
                AiConversationMessageRole::User->value,
                AiConversationMessageRole::Assistant->value,
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn ($message): array => [
                'role' => $message->role === AiConversationMessageRole::Assistant ? 'assistant' : 'user',
                'content' => mb_substr($message->content, 0, 1200),
            ])
            ->values()
            ->all();
    }

    private function scheduledClassCount(Account $account, Carbon $day): int
    {
        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBetween('starts_at', [$day->copy()->timezone('UTC'), $day->copy()->endOfDay()->timezone('UTC')])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->count();
    }
}
