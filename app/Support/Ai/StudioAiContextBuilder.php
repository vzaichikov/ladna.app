<?php

namespace App\Support\Ai;

use App\Enums\AiConversationMessageRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Models\WebsiteLead;
use App\Support\StudioClassScheduleDetails;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class StudioAiContextBuilder
{
    private const ClassBookingDetailsDaysAhead = 7;

    private const ConversationMessageLimit = 24;

    private const ConversationCharacterLimit = 20000;

    private const ConversationMessageCharacterLimit = 2000;

    private const TrainerContextLimit = 100;

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
            'trainers' => $this->activeTrainerContext($account),
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
    public function actorContext(?User $user, ?Trainer $trainer, string $channel): ?array
    {
        if (! $user && ! $trainer) {
            return null;
        }

        return [
            'channel' => $channel,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
            ] : null,
            'trainer' => $trainer ? [
                'id' => $trainer->id,
                'name' => $trainer->name,
            ] : null,
            'trainer_is_linked_to_user' => $user !== null
                && $trainer !== null
                && (int) $trainer->user_id === (int) $user->id,
        ];
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function recentConversationMessages(AiConversation $conversation, ?AiConversationMessage $excludeMessage = null): array
    {
        if ($excludeMessage
            && ((int) $excludeMessage->ai_conversation_id !== (int) $conversation->id
                || (int) $excludeMessage->account_id !== (int) $conversation->account_id)) {
            throw new InvalidArgumentException('The excluded message must belong to the supplied conversation.');
        }

        $messages = $conversation->messages()
            ->where('account_id', $conversation->account_id)
            ->whereIn('role', [
                AiConversationMessageRole::User->value,
                AiConversationMessageRole::Assistant->value,
                AiConversationMessageRole::RejectedIntent->value,
                AiConversationMessageRole::Tool->value,
            ])
            ->when($excludeMessage, fn ($query) => $query->where('id', '!=', $excludeMessage->id))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ConversationMessageLimit)
            ->get()
            ->map(fn (AiConversationMessage $message): ?array => $this->conversationMessage($message))
            ->filter()
            ->reverse()
            ->values();

        $turns = [];
        $turn = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                if ($this->isCompleteTurn($turn)) {
                    $turns[] = $turn;
                }

                $turn = [$message];

                continue;
            }

            if ($turn !== []) {
                $turn[] = $message;
            }
        }

        if ($this->isCompleteTurn($turn)) {
            $turns[] = $turn;
        }

        $selectedTurns = [];
        $messageCount = 0;
        $characterCount = 0;

        foreach (array_reverse($turns) as $completeTurn) {
            $turnMessageCount = count($completeTurn);
            $turnCharacterCount = array_sum(array_map(
                fn (array $message): int => mb_strlen($message['content']),
                $completeTurn,
            ));

            if ($messageCount + $turnMessageCount > self::ConversationMessageLimit
                || $characterCount + $turnCharacterCount > self::ConversationCharacterLimit) {
                break;
            }

            $selectedTurns[] = $completeTurn;
            $messageCount += $turnMessageCount;
            $characterCount += $turnCharacterCount;
        }

        return array_merge(...array_reverse($selectedTurns));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeBookingDialog(AiConversation $conversation): ?array
    {
        $message = $conversation->messages()
            ->where('account_id', $conversation->account_id)
            ->where('role', AiConversationMessageRole::Assistant->value)
            ->whereNotNull('metadata')
            ->latest('occurred_at')
            ->latest('id')
            ->first();

        $draft = data_get($message?->metadata, 'booking_dialog');
        $status = is_array($draft) ? (string) ($draft['status'] ?? '') : '';

        if (! in_array($status, ['awaiting_customer', 'awaiting_trainer', 'awaiting_date', 'awaiting_class'], true)) {
            return null;
        }

        return $draft;
    }

    /**
     * @return array{role: string, content: string}
     */
    private function conversationMessage(AiConversationMessage $message): ?array
    {
        $content = $message->content;

        if ($message->role === AiConversationMessageRole::Tool) {
            if (! is_array(data_get($message->metadata, 'result'))) {
                return null;
            }

            $content = '[Confirmed action result] '.$content;
        }

        return [
            'role' => $message->role === AiConversationMessageRole::User ? 'user' : 'assistant',
            'content' => mb_substr($content, 0, self::ConversationMessageCharacterLimit),
        ];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $turn
     */
    private function isCompleteTurn(array $turn): bool
    {
        return count($turn) >= 2
            && $turn[0]['role'] === 'user'
            && collect(array_slice($turn, 1))->every(
                fn (array $message): bool => $message['role'] === 'assistant',
            );
    }

    private function scheduledClassCount(Account $account, Carbon $day): int
    {
        return $this->scheduledClassCountBetween($account, $day->copy()->startOfDay(), $day->copy()->endOfDay());
    }

    /**
     * @return array{
     *     active_total: int,
     *     returned: int,
     *     truncated: bool,
     *     items: array<int, array{name: string}>
     * }
     */
    private function activeTrainerContext(Account $account): array
    {
        $activeTotal = $account->trainers()->active()->count();
        $trainers = $account->trainers()
            ->active()
            ->orderBy('name')
            ->limit(self::TrainerContextLimit)
            ->get(['id', 'account_id', 'name'])
            ->map(fn (Trainer $trainer): array => [
                'name' => $trainer->name,
            ])
            ->all();

        return [
            'active_total' => $activeTotal,
            'returned' => count($trainers),
            'truncated' => $activeTotal > count($trainers),
            'items' => $trainers,
        ];
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
