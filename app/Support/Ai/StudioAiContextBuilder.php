<?php

namespace App\Support\Ai;

use App\Enums\AiConversationMessageRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\ScheduledClass;
use App\Models\TelegramChatAuthorization;
use App\Models\WebsiteLead;
use App\Support\StudioClassScheduleDetails;
use Illuminate\Support\Carbon;

class StudioAiContextBuilder
{
    private const ClassBookingDetailsDaysAhead = 7;

    public function __construct(private readonly StudioClassScheduleDetails $classScheduleDetails) {}

    /**
     * @return array<string, mixed>
     */
    public function studioContext(Account $account, bool $includeClassBookingDetails = true): array
    {
        $timezone = $account->timezone ?: config('app.timezone');
        $today = now($timezone)->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $nextSevenDaysEnd = $today->copy()->addDays(7)->endOfDay();

        $context = [
            'studio' => [
                'name' => $account->name,
                'timezone' => $timezone,
                'opening_hours' => $account->openingHours(),
            ],
            'metrics' => [
                'customers_total' => Customer::query()->whereBelongsTo($account)->count(),
                'locations_active' => $account->locations()->active()->count(),
                'active_class_passes' => CustomerClassPass::query()
                    ->whereBelongsTo($account)
                    ->where('status', CustomerClassPassStatus::Active->value)
                    ->where('is_active', true)
                    ->count(),
                'unpaid_active_class_passes' => CustomerClassPass::query()
                    ->whereBelongsTo($account)
                    ->where('status', CustomerClassPassStatus::Active->value)
                    ->where('is_active', true)
                    ->unpaid()
                    ->count(),
                'partial_active_class_passes' => CustomerClassPass::query()
                    ->whereBelongsTo($account)
                    ->where('status', CustomerClassPassStatus::Active->value)
                    ->where('is_active', true)
                    ->partiallyPaid()
                    ->count(),
                'open_website_leads' => WebsiteLead::query()
                    ->whereBelongsTo($account)
                    ->whereIn('status', [WebsiteLeadStatus::New->value, WebsiteLeadStatus::Callback->value])
                    ->count(),
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
                    'booked' => $this->bookingCount($account, $today),
                ],
                'tomorrow' => [
                    'date' => $tomorrow->toDateString(),
                    'scheduled' => $this->scheduledClassCount($account, $tomorrow),
                    'booked' => $this->bookingCount($account, $tomorrow),
                ],
                'next_7_days' => [
                    'from' => $today->toDateString(),
                    'to' => $nextSevenDaysEnd->toDateString(),
                    'scheduled' => $this->scheduledClassCountBetween($account, $today, $nextSevenDaysEnd),
                    'booked' => $this->bookingCountBetween($account, $today, $nextSevenDaysEnd),
                ],
            ],
        ];

        if ($includeClassBookingDetails) {
            $context['class_booking_details'] = [
                'available_from' => $today->toDateString(),
                'available_to' => $today->copy()->addDays(self::ClassBookingDetailsDaysAhead)->toDateString(),
                ...$this->classBookingDetailsForDays($account, $today, self::ClassBookingDetailsDaysAhead),
            ];
        }

        return $context;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function actorContext(?TelegramChatAuthorization $authorization): ?array
    {
        if (! $authorization) {
            return null;
        }

        $authorization->loadMissing(['user', 'trainer']);

        return [
            'channel' => 'telegram_owner',
            'user' => $authorization->user ? [
                'id' => $authorization->user->id,
                'name' => $authorization->user->name,
            ] : null,
            'trainer' => $authorization->trainer ? [
                'id' => $authorization->trainer->id,
                'name' => $authorization->trainer->name,
            ] : null,
            'authorized_phone_matches_trainer' => filled($authorization->phone) && $authorization->trainer !== null,
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
        return $this->scheduledClassCountBetween($account, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    private function scheduledClassCountBetween(Account $account, Carbon $from, Carbon $to): int
    {
        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereBetween('starts_at', [$from->copy()->timezone('UTC'), $to->copy()->timezone('UTC')])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->count();
    }

    private function bookingCount(Account $account, Carbon $day): int
    {
        return $this->bookingCountBetween($account, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    private function bookingCountBetween(Account $account, Carbon $from, Carbon $to): int
    {
        return ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereHas('scheduledClass', function ($query) use ($from, $to): void {
                $query
                    ->whereBetween('starts_at', [$from->copy()->timezone('UTC'), $to->copy()->timezone('UTC')])
                    ->where('status', ScheduledClassStatus::Scheduled->value);
            })
            ->where('status', ClassBookingStatus::Booked->value)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function classBookingDetailsForDays(Account $account, Carbon $today, int $daysAhead): array
    {
        $details = [];

        for ($offset = 0; $offset <= $daysAhead; $offset++) {
            $details[$this->relativeDayKey($offset)] = $this->classScheduleDetails->forDay(
                $account,
                $today->copy()->addDays($offset),
                classLimit: 20,
                bookingLimitPerClass: 20,
            );
        }

        return $details;
    }

    private function relativeDayKey(int $offset): string
    {
        return match ($offset) {
            0 => 'today',
            1 => 'tomorrow',
            2 => 'day_after_tomorrow',
            default => 'in_'.$offset.'_days',
        };
    }
}
