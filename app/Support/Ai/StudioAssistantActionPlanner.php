<?php

namespace App\Support\Ai;

use App\Enums\AiConversationMessageRole;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StudioAssistantActionPlanner
{
    public function startGroupBookingDialog(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation): StudioAssistantActionPlan
    {
        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, [
            'status' => 'collecting',
        ]);
    }

    public function plan(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $text, bool $allowNewBookingDialog = true): StudioAssistantActionPlan
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        if ($arguments = $this->cancelBookingArguments($normalized)) {
            return StudioAssistantActionPlan::pending(
                $this->createPendingAction($account, $user, $trainer, $conversation, 'cancel-booking', $arguments, $this->cancelBookingPreview($account, $arguments)),
            );
        }

        if ($allowNewBookingDialog && $arguments = $this->groupBookingArguments($normalized)) {
            return StudioAssistantActionPlan::pending(
                $this->createPendingAction($account, $user, $trainer, $conversation, 'create-booking', $arguments, $this->createBookingPreview($account, $arguments)),
            );
        }

        if ($bookingPlan = $this->conversationalGroupBookingPlan($account, $user, $trainer, $conversation, $text, $normalized, $allowNewBookingDialog)) {
            return $bookingPlan;
        }

        return StudioAssistantActionPlan::none();
    }

    /**
     * @return array{booking_id: int}|null
     */
    private function cancelBookingArguments(string $text): ?array
    {
        $hasCancelIntent = str_contains($text, 'cancel')
            || str_contains($text, 'скас')
            || str_contains($text, 'отмен');

        if (! $hasCancelIntent || preg_match('/(?:booking|запис|брон)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) !== 1) {
            return null;
        }

        return ['booking_id' => (int) $matches[1]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function groupBookingArguments(string $text): ?array
    {
        if (! $this->hasBookingIntent($text)) {
            return null;
        }

        $customerId = null;
        $scheduledClassId = null;

        if (preg_match('/(?:customer|client|клієнт|клиент)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) === 1) {
            $customerId = (int) $matches[1];
        }

        if (preg_match('/(?:class|занят|тренув)[^\d#]*(?:#\s*)?(\d+)/u', $text, $matches) === 1) {
            $scheduledClassId = (int) $matches[1];
        }

        if (! $customerId || ! $scheduledClassId) {
            return null;
        }

        return [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'customer_id' => $customerId,
            'scheduled_class_id' => $scheduledClassId,
        ];
    }

    private function conversationalGroupBookingPlan(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $text, string $normalized, bool $allowNewBookingDialog): ?StudioAssistantActionPlan
    {
        $draft = $this->activeBookingDraft($conversation);

        if ($draft) {
            if ($this->isDialogCancel($normalized)) {
                return $this->bookingDialogMessage(
                    __('app.assistant_booking_dialog_cancelled'),
                    [...$draft, 'status' => 'cancelled'],
                );
            }

            return $this->continueBookingDraft($account, $user, $trainer, $conversation, $text, $draft);
        }

        if ($this->isDialogCancel($normalized)) {
            return StudioAssistantActionPlan::message(__('app.assistant_booking_dialog_no_active'), [
                'booking_dialog' => ['status' => 'none'],
            ]);
        }

        if (! $allowNewBookingDialog) {
            return null;
        }

        if (! $this->hasBookingIntent($normalized)) {
            return null;
        }

        $draft = [
            'status' => 'collecting',
            'customer_query' => $this->extractCustomerQuery($text),
            'trainer_query' => $this->extractTrainerQuery($text),
            'date' => $this->extractDate($text, $account),
        ];

        if ($trainer && $this->mentionsAuthorizedTrainer($normalized)) {
            $draft['trainer_id'] = $trainer->id;
            $draft['trainer_name'] = $trainer->name;
            unset($draft['trainer_query']);
        }

        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function continueBookingDraft(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $text, array $draft): StudioAssistantActionPlan
    {
        $status = (string) ($draft['status'] ?? '');

        if ($status === 'awaiting_customer') {
            $selected = $this->selectStoredOption($text, $draft['customer_candidates'] ?? []);

            if ($selected) {
                $draft['customer_id'] = $selected['id'];
                $draft['customer_name'] = $selected['name'];
            } else {
                $draft['customer_query'] = trim($text);
                unset($draft['customer_id'], $draft['customer_name']);
            }

            unset($draft['customer_candidates']);
        } elseif ($status === 'awaiting_trainer') {
            $selected = $this->selectStoredOption($text, $draft['trainer_candidates'] ?? []);

            if ($selected) {
                $draft['trainer_id'] = $selected['id'];
                $draft['trainer_name'] = $selected['name'];
            } else {
                $draft['trainer_query'] = trim($text);
                unset($draft['trainer_id'], $draft['trainer_name']);
            }

            unset($draft['trainer_candidates']);
        } elseif ($status === 'awaiting_date') {
            $date = $this->extractDate($text, $account);

            if (! $date) {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_date_not_understood'), [
                    ...$draft,
                    'status' => 'awaiting_date',
                ]);
            }

            $draft['date'] = $date;
        } elseif ($status === 'awaiting_class') {
            $selected = $this->selectClassOption($text, $draft['class_options'] ?? []);

            if (! $selected) {
                return $this->bookingDialogMessage(
                    __('app.assistant_booking_dialog_class_choice_invalid', [
                        'options' => $this->numberedOptions($draft['class_options'] ?? []),
                    ]),
                    [
                        ...$draft,
                        'status' => 'awaiting_class',
                    ],
                    $this->optionFollowUps($draft['class_options'] ?? []),
                );
            }

            $draft['scheduled_class_id'] = $selected['id'];
            unset($draft['class_options']);
        }

        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function resolveBookingDraft(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, array $draft): StudioAssistantActionPlan
    {
        if (blank($draft['customer_id'] ?? null)) {
            $customerQuery = trim((string) ($draft['customer_query'] ?? ''));

            if ($customerQuery === '') {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_customer_missing'), [
                    ...$draft,
                    'status' => 'awaiting_customer',
                ]);
            }

            $customerMatch = $this->matchCustomer($account, $customerQuery);

            if ($customerMatch['status'] === 'none') {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_customer_not_found', ['query' => $customerQuery]), [
                    ...$draft,
                    'status' => 'awaiting_customer',
                ]);
            }

            if ($customerMatch['status'] === 'multiple') {
                $candidates = $customerMatch['candidates'];

                return $this->bookingDialogMessage(
                    __('app.assistant_booking_dialog_customer_choose', [
                        'query' => $customerQuery,
                        'options' => $this->numberedOptions($candidates),
                    ]),
                    [
                        ...$draft,
                        'status' => 'awaiting_customer',
                        'customer_candidates' => $candidates,
                    ],
                    $this->optionFollowUps($candidates),
                );
            }

            $customer = $customerMatch['candidates'][0];
            $draft['customer_id'] = $customer['id'];
            $draft['customer_name'] = $customer['name'];
        }

        if (blank($draft['date'] ?? null)) {
            return $this->bookingDialogMessage(__('app.assistant_booking_dialog_date_missing'), [
                ...$draft,
                'status' => 'awaiting_date',
            ]);
        }

        if (blank($draft['trainer_id'] ?? null)) {
            $trainerQuery = trim((string) ($draft['trainer_query'] ?? ''));

            if ($trainerQuery === '') {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_trainer_missing'), [
                    ...$draft,
                    'status' => 'awaiting_trainer',
                ]);
            }

            $trainerMatch = $this->matchTrainer($account, $trainerQuery);

            if ($trainerMatch['status'] === 'none') {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_trainer_not_found', ['query' => $trainerQuery]), [
                    ...$draft,
                    'status' => 'awaiting_trainer',
                ]);
            }

            if ($trainerMatch['status'] === 'multiple') {
                $candidates = $trainerMatch['candidates'];

                return $this->bookingDialogMessage(
                    __('app.assistant_booking_dialog_trainer_choose', [
                        'query' => $trainerQuery,
                        'options' => $this->numberedOptions($candidates),
                    ]),
                    [
                        ...$draft,
                        'status' => 'awaiting_trainer',
                        'trainer_candidates' => $candidates,
                    ],
                    $this->optionFollowUps($candidates),
                );
            }

            $matchedTrainer = $trainerMatch['candidates'][0];
            $draft['trainer_id'] = $matchedTrainer['id'];
            $draft['trainer_name'] = $matchedTrainer['name'];
        }

        if (filled($draft['scheduled_class_id'] ?? null)) {
            return $this->pendingBookingFromDraft($account, $user, $trainer, $conversation, $draft);
        }

        $classOptions = $this->classOptions($account, $draft);

        if ($classOptions->isEmpty()) {
            return $this->bookingDialogMessage(
                __('app.assistant_booking_dialog_class_not_found', [
                    'date' => $this->formatDate((string) $draft['date']),
                    'trainer' => $draft['trainer_name'] ?? __('app.not_set'),
                ]),
                [
                    ...$draft,
                    'status' => 'awaiting_trainer',
                ],
            );
        }

        if ($classOptions->count() === 1) {
            $draft['scheduled_class_id'] = $classOptions->first()['id'];

            return $this->pendingBookingFromDraft($account, $user, $trainer, $conversation, $draft);
        }

        $options = $classOptions->take(6)->values()->all();

        return $this->bookingDialogMessage(
            __('app.assistant_booking_dialog_class_choose', [
                'customer' => $draft['customer_name'],
                'trainer' => $draft['trainer_name'],
                'date' => $this->formatDate((string) $draft['date']),
                'options' => $this->numberedOptions($options),
            ]),
            [
                ...$draft,
                'status' => 'awaiting_class',
                'class_options' => $options,
            ],
            $this->optionFollowUps($options),
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function pendingBookingFromDraft(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, array $draft): StudioAssistantActionPlan
    {
        $arguments = [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'customer_id' => (int) $draft['customer_id'],
            'scheduled_class_id' => (int) $draft['scheduled_class_id'],
        ];

        return StudioAssistantActionPlan::pending(
            $this->createPendingAction($account, $user, $trainer, $conversation, 'create-booking', $arguments, $this->createBookingPreview($account, $arguments)),
            __('app.assistant_pending_action_created'),
            [
                'booking_dialog' => [
                    ...$draft,
                    'status' => 'pending_action_created',
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<int, string>  $followUps
     */
    private function bookingDialogMessage(string $message, array $draft, array $followUps = []): StudioAssistantActionPlan
    {
        return StudioAssistantActionPlan::message($message, [
            'booking_dialog' => $draft,
            'follow_up_actions' => $followUps,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeBookingDraft(AiConversation $conversation): ?array
    {
        $message = $conversation->messages()
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
     * @return array{status: string, candidates: array<int, array<string, mixed>>}
     */
    private function matchCustomer(Account $account, string $query): array
    {
        $stems = $this->searchStems($query);

        if ($stems === []) {
            return ['status' => 'none', 'candidates' => []];
        }

        $candidates = $account->customers()
            ->select(['id', 'name', 'phone'])
            ->where(function ($builder) use ($stems): void {
                foreach ($stems as $stem) {
                    $builder->orWhere('name', 'like', '%'.$this->escapeLike($stem).'%');
                }
            })
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'label' => filled($customer->phone) ? "{$customer->name}, {$customer->phone}" : $customer->name,
                'score' => $this->nameScore($query, $customer->name),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 35)
            ->sortByDesc('score')
            ->values()
            ->all();

        return $this->matchResult($candidates);
    }

    /**
     * @return array{status: string, candidates: array<int, array<string, mixed>>}
     */
    private function matchTrainer(Account $account, string $query): array
    {
        $stems = $this->searchStems($query);

        if ($stems === []) {
            return ['status' => 'none', 'candidates' => []];
        }

        $candidates = $account->trainers()
            ->select(['id', 'name'])
            ->where(function ($builder) use ($stems): void {
                foreach ($stems as $stem) {
                    $builder->orWhere('name', 'like', '%'.$this->escapeLike($stem).'%');
                }
            })
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn (Trainer $trainer): array => [
                'id' => $trainer->id,
                'name' => $trainer->name,
                'label' => $trainer->name,
                'score' => $this->nameScore($query, $trainer->name),
            ])
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 35)
            ->sortByDesc('score')
            ->values()
            ->all();

        return $this->matchResult($candidates);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{status: string, candidates: array<int, array<string, mixed>>}
     */
    private function matchResult(array $candidates): array
    {
        if ($candidates === []) {
            return ['status' => 'none', 'candidates' => []];
        }

        $topScore = (int) $candidates[0]['score'];
        $nextScore = (int) ($candidates[1]['score'] ?? 0);

        if ($topScore >= 70 && ($nextScore === 0 || $topScore - $nextScore >= 12 || $topScore >= 92)) {
            return ['status' => 'single', 'candidates' => [$candidates[0]]];
        }

        return ['status' => 'multiple', 'candidates' => array_slice($candidates, 0, 6)];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return Collection<int, array<string, mixed>>
     */
    private function classOptions(Account $account, array $draft): Collection
    {
        $timezone = $account->timezone ?: config('app.timezone');
        $day = Carbon::createFromFormat('Y-m-d', (string) $draft['date'], $timezone)->startOfDay();
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->where('trainer_id', (int) $draft['trainer_id'])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('starts_at', '>=', now())
            ->whereBetween('starts_at', [$day->copy()->timezone('UTC'), $day->copy()->endOfDay()->timezone('UTC')])
            ->whereHas('classType', fn ($query) => $query
                ->where('is_active', true)
                ->where('schedule_kind', ScheduleKind::GroupClass->value))
            ->with(['location:id,account_id,name,timezone', 'room:id,account_id,location_id,name', 'classType:id,account_id,name,schedule_kind', 'trainer:id,account_id,name'])
            ->withCount([
                'classBookings as active_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', $activeStatuses),
            ])
            ->orderBy('starts_at')
            ->limit(12)
            ->get()
            ->map(fn (ScheduledClass $scheduledClass): array => $this->classOption($scheduledClass))
            ->filter(fn (array $option): bool => (int) ($option['available_spots'] ?? 0) > 0)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function classOption(ScheduledClass $scheduledClass): array
    {
        $startsAt = $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone());
        $endsAt = $scheduledClass->ends_at->copy()->timezone($scheduledClass->displayTimezone());
        $capacity = (int) ($scheduledClass->capacity ?? 0);
        $activeBookingsCount = (int) ($scheduledClass->active_bookings_count ?? 0);
        $availableSpots = max(0, $capacity - $activeBookingsCount);
        $timeRange = $startsAt->format('H:i').'-'.$endsAt->format('H:i');
        $location = $scheduledClass->location?->name ?? __('app.not_set');
        $title = $scheduledClass->title ?: ($scheduledClass->classType?->name ?? __('app.not_set'));

        return [
            'id' => $scheduledClass->id,
            'time' => $startsAt->format('H:i'),
            'time_range' => $timeRange,
            'title' => $title,
            'location' => $location,
            'room' => $scheduledClass->room?->name,
            'trainer' => $scheduledClass->trainer?->name,
            'available_spots' => $availableSpots,
            'capacity' => $capacity,
            'label' => "{$timeRange} · {$title} · {$location} · {$availableSpots}/{$capacity}",
            'search_text' => "{$timeRange} {$title} {$location}",
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function selectStoredOption(string $text, array $options): ?array
    {
        $index = $this->selectedOptionIndex($text);

        return $index !== null ? ($options[$index] ?? null) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function selectClassOption(string $text, array $options): ?array
    {
        if ($selected = $this->selectStoredOption($text, $options)) {
            return $selected;
        }

        return collect($options)
            ->first(fn (array $option): bool => $this->classChoiceMatches($text, (string) ($option['search_text'] ?? '')));
    }

    private function selectedOptionIndex(string $text): ?int
    {
        $normalized = $this->normalizeName($text);

        if (preg_match('/^\D*(\d{1,2})\D*$/u', $normalized, $matches) === 1) {
            return max(0, (int) $matches[1] - 1);
        }

        return match ($normalized) {
            'first', 'one', 'перший', 'перше', 'першу', 'первый', 'первое', 'первую' => 0,
            'second', 'two', 'другий', 'друге', 'другу', 'второй', 'второе', 'вторую' => 1,
            'third', 'three', 'третій', 'третє', 'третю', 'третий', 'третье', 'третью' => 2,
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, string>
     */
    private function optionFollowUps(array $options): array
    {
        return collect($options)
            ->take(3)
            ->keys()
            ->map(fn (int $index): string => (string) ($index + 1))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function numberedOptions(array $options): string
    {
        return collect($options)
            ->values()
            ->map(fn (array $option, int $index): string => ($index + 1).'. '.$option['label'])
            ->implode("\n");
    }

    private function hasBookingIntent(string $text): bool
    {
        if (preg_match('/^\/book(?:@\w+)?(?:\s|$)/u', $text) === 1) {
            return true;
        }

        if ($this->asksAboutBookingWorkflow($text)) {
            return false;
        }

        return preg_match('/\bbook\s+(?:customer|client)[^\d#]*(?:#\s*)?\d+.+\bclass[^\d#]*(?:#\s*)?\d+/u', $text) === 1
            || preg_match('/(?:^|\s)(?:запиши|запишіть|запиши-но|запиши\s+будь\s+ласка)(?:\s|$)/u', $text) === 1
            || preg_match('/(?:^|\s)(?:додай|добавь|створи|создай)\s+запис(?:\s|$)/u', $text) === 1
            || preg_match('/(?:^|\s)(?:можеш|можете|можемо|можна|давай|будь\s+ласка|пожалуйста|can|could)\b.{0,120}\b(?:записати|записать|book)\b/u', $text) === 1;
    }

    private function asksAboutBookingWorkflow(string $text): bool
    {
        return preg_match('/(?:що|шо|что|what)\s+робити/u', $text) === 1
            || preg_match('/(?:як|как|how)\s+.{0,80}(?:записати|записать|запис|book)/u', $text) === 1
            || preg_match('/(?:забула|забув|забыл|забыла|forgot)\s+.{0,80}(?:записати|записать|book)/u', $text) === 1;
    }

    private function isDialogCancel(string $text): bool
    {
        if (preg_match('/^\/(?:cancel_booking|cancel)(?:@\w+)?(?:\s|$)/u', $text) === 1) {
            return true;
        }

        if (in_array($text, ['cancel', 'cancel booking', 'exit booking', 'stop booking', 'never mind', 'скасувати', 'відміна', 'отмена', 'отменить', 'не треба'], true)) {
            return true;
        }

        return str_contains($text, 'передумала')
            || str_contains($text, 'передумав')
            || str_contains($text, 'завершимо запис')
            || str_contains($text, 'завершити запис')
            || str_contains($text, 'закінчити запис')
            || str_contains($text, 'вийти з запису')
            || str_contains($text, 'выйти из записи');
    }

    private function mentionsAuthorizedTrainer(string $text): bool
    {
        return preg_match('/(?:^|\s)(?:до|к|у)\s+(?:мене|мені|мне|меня)(?:\s|$)/u', $text) === 1
            || str_contains($text, 'with me');
    }

    private function extractCustomerQuery(string $text): ?string
    {
        if (preg_match('/(?:запиши|запишіть|записати|записать|book)\s+(.+?)(?=\s+(?:на|к|до|у|to|for|with)\b|$)/iu', $text, $matches) !== 1) {
            if (preg_match('/([\p{L}\'’ʼ -]{2,120})\s+(?:записати|записать)\b/iu', $text, $matches) !== 1) {
                return null;
            }
        }

        $query = $this->cleanCustomerQuery($matches[1]);

        return $query !== '' ? $query : null;
    }

    private function cleanCustomerQuery(string $query): string
    {
        return Str::of($query)
            ->replaceMatches('/\b(?:можемо|можна|будь\s+ласка|пожалуйста|please|can|could|можешь|можеш)\b/iu', ' ')
            ->replaceMatches('/\b(?:сьогодні|сегодня|today|завтра|tomorrow)\b/iu', ' ')
            ->replaceMatches('/(?:^|\s)(?:на|до|к|у|with|to)\s+(?:мене|мені|мне|меня|me)(?=\s|$)/iu', ' ')
            ->replaceMatches('/\b(?:на|до|к|у|with|to|for)\b/iu', ' ')
            ->squish()
            ->trim(" \t\n\r\0\x0B.,!?")
            ->toString();
    }

    private function extractTrainerQuery(string $text): ?string
    {
        foreach ([
            '/(?:^|\s)(?:к|до|у)\s+([\p{L}\'’ʼ -]{2,80}?)(?=\s+(?:на|о|об|в|at|today|tomorrow|\d)|[?.!,]|$)/iu',
            '/(?:trainer|тренер[а-яіїєґ]*|тренер[а-яё]*)\s+([\p{L}\'’ʼ -]{2,80}?)(?=\s+(?:на|о|об|в|at|today|tomorrow|\d)|[?.!,]|$)/iu',
            '/(?:with)\s+([\p{L}\'’ʼ -]{2,80}?)(?=\s+(?:on|at|today|tomorrow|\d)|[?.!,]|$)/iu',
        ] as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $query = trim($matches[1], " \t\n\r\0\x0B.,!?");

                return $query !== '' ? $query : null;
            }
        }

        return null;
    }

    private function extractDate(string $text, Account $account): ?string
    {
        $normalized = $this->normalizeName($text);
        $timezone = $account->timezone ?: config('app.timezone');

        if (str_contains($normalized, 'tomorrow') || str_contains($normalized, 'завтра')) {
            return Carbon::now($timezone)->addDay()->toDateString();
        }

        if (str_contains($normalized, 'today') || str_contains($normalized, 'сьогодні') || str_contains($normalized, 'сегодня')) {
            return Carbon::now($timezone)->toDateString();
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text, $matches) === 1) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3], $timezone)->toDateString();
        }

        if (preg_match('/\b(\d{1,2})[.\/-](\d{1,2})(?:[.\/-](\d{2,4}))?\b/u', $text, $matches) === 1) {
            $year = filled($matches[3] ?? null) ? (int) $matches[3] : Carbon::now($timezone)->year;
            $year = $year < 100 ? 2000 + $year : $year;

            return Carbon::createFromDate($year, (int) $matches[2], (int) $matches[1], $timezone)->toDateString();
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function searchStems(string $value): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', $this->normalizeName($value)) ?: [])
            ->map(fn (string $token): string => $this->stemNameToken($token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    private function nameScore(string $query, string $candidate): int
    {
        $queryStems = $this->searchStems($query);
        $candidateStems = $this->searchStems($candidate);

        if ($queryStems === [] || $candidateStems === []) {
            return 0;
        }

        $score = 0;

        foreach ($queryStems as $queryStem) {
            $best = 0;

            foreach ($candidateStems as $candidateStem) {
                if ($queryStem === $candidateStem) {
                    $best = max($best, 40);
                } elseif (str_starts_with($candidateStem, $queryStem) || str_starts_with($queryStem, $candidateStem)) {
                    $best = max($best, 35);
                } elseif (str_contains($candidateStem, $queryStem) || str_contains($queryStem, $candidateStem)) {
                    $best = max($best, 25);
                }
            }

            $score += $best;
        }

        return min(100, (int) round($score / (count($queryStems) * 40) * 100));
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replace(['ё', 'ї', 'є', 'ґ'], ['е', 'і', 'е', 'г'])
            ->replaceMatches('/[^\p{L}\p{N}\s:.-]+/u', ' ')
            ->squish()
            ->toString();
    }

    private function classChoiceMatches(string $query, string $searchText): bool
    {
        $query = $this->normalizeName($query);
        $searchText = $this->normalizeName($searchText);

        if ($query === '') {
            return false;
        }

        if (str_contains($searchText, $query)) {
            return true;
        }

        $queryAscii = Str::ascii($query);
        $searchAscii = Str::ascii($searchText);
        $queryAsciiVariants = $this->latinClassSearchVariants($queryAscii);
        $searchAsciiVariants = $this->latinClassSearchVariants($searchAscii);

        foreach ($queryAsciiVariants as $queryAsciiVariant) {
            foreach ($searchAsciiVariants as $searchAsciiVariant) {
                if ($queryAsciiVariant !== '' && str_contains($searchAsciiVariant, $queryAsciiVariant)) {
                    return true;
                }
            }
        }

        $queryTokens = $queryAsciiVariants
            ->flatMap(fn (string $variant): array => preg_split('/\s+/u', $variant) ?: [])
            ->unique()
            ->all();
        $searchTokens = $searchAsciiVariants
            ->flatMap(fn (string $variant): array => preg_split('/\s+/u', $variant) ?: [])
            ->unique()
            ->all();

        foreach ($queryTokens as $queryToken) {
            if (mb_strlen($queryToken) < 3) {
                continue;
            }

            foreach ($searchTokens as $searchToken) {
                if (mb_strlen($searchToken) < 3) {
                    continue;
                }

                if (levenshtein($queryToken, $searchToken) <= max(1, (int) floor(mb_strlen($queryToken) * 0.25))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return Collection<int, string>
     */
    private function latinClassSearchVariants(string $value): Collection
    {
        return collect([
            $value,
            str_replace(['kz', 'ks'], 'x', $value),
        ])
            ->map(fn (string $variant): string => trim($variant))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->unique()
            ->values();
    }

    private function stemNameToken(string $token): string
    {
        if (mb_strlen($token) <= 3) {
            return $token;
        }

        return preg_replace('/(ові|еві|ами|ями|ого|ему|ому|ою|ею|ая|яя|ую|юю|ий|ій|ый|ой|ей|ом|ем|ам|ям|ах|ях|а|я|у|ю|е|і|и|ы)$/u', '', $token) ?: $token;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\%_');
    }

    private function formatDate(string $date): string
    {
        return Carbon::createFromFormat('Y-m-d', $date)->format('d.m.Y');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $preview
     */
    private function createPendingAction(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation, string $actionName, array $arguments, array $preview): AiPendingAction
    {
        return AiPendingAction::create([
            'account_id' => $account->id,
            'ai_conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'trainer_id' => $trainer?->id,
            'action_name' => $actionName,
            'arguments' => $arguments,
            'preview' => $preview,
            'status' => AiPendingAction::StatusPending,
            'expires_at' => now()->addMinutes(20),
        ]);
    }

    /**
     * @param  array{booking_id: int}  $arguments
     * @return array<string, mixed>
     */
    private function cancelBookingPreview(Account $account, array $arguments): array
    {
        $booking = ClassBooking::query()
            ->whereBelongsTo($account)
            ->with(['customer', 'scheduledClass.location', 'scheduledClass.classType'])
            ->find($arguments['booking_id']);

        if (! $booking) {
            return [
                'summary' => __('app.assistant_cancel_booking_preview_missing', ['id' => $arguments['booking_id']]),
                'warnings' => [__('app.assistant_booking_not_found')],
            ];
        }

        return [
            'summary' => __('app.assistant_cancel_booking_preview', [
                'id' => $booking->id,
                'customer' => $booking->customer?->name ?? __('app.not_set'),
                'class' => $booking->scheduledClass?->title ?? __('app.not_set'),
                'time' => $this->classTime($booking->scheduledClass),
                'location' => $booking->scheduledClass?->location?->name ?? __('app.not_set'),
            ]),
            'booking_id' => $booking->id,
            'customer' => $booking->customer?->name,
            'class' => $booking->scheduledClass?->title,
            'time' => $this->classTime($booking->scheduledClass),
            'location' => $booking->scheduledClass?->location?->name,
            'status' => $booking->status instanceof ClassBookingStatus ? $booking->status->value : $booking->status,
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function createBookingPreview(Account $account, array $arguments): array
    {
        $customer = filled($arguments['customer_id'] ?? null)
            ? $account->customers()->whereKey((int) $arguments['customer_id'])->first()
            : null;
        $scheduledClass = filled($arguments['scheduled_class_id'] ?? null)
            ? ScheduledClass::query()
                ->whereBelongsTo($account)
                ->with(['location', 'classType'])
                ->whereKey((int) $arguments['scheduled_class_id'])
                ->first()
            : null;

        $warnings = [];

        if (! $customer) {
            $warnings[] = __('app.assistant_customer_not_found');
        }

        if (! $scheduledClass) {
            $warnings[] = __('app.assistant_class_not_found');
        }

        return [
            'summary' => __('app.assistant_create_booking_preview', [
                'customer' => $customer?->name ?? '#'.($arguments['customer_id'] ?? '?'),
                'class' => $scheduledClass?->title ?? '#'.($arguments['scheduled_class_id'] ?? '?'),
                'time' => $this->classTime($scheduledClass),
                'location' => $scheduledClass?->location?->name ?? __('app.not_set'),
            ]),
            'customer' => $customer?->name,
            'scheduled_class_id' => $scheduledClass?->id,
            'class' => $scheduledClass?->title,
            'time' => $this->classTime($scheduledClass),
            'location' => $scheduledClass?->location?->name,
            'warnings' => $warnings,
        ];
    }

    private function classTime(?ScheduledClass $scheduledClass): string
    {
        if (! $scheduledClass) {
            return __('app.not_set');
        }

        return $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->format('Y-m-d H:i');
    }
}
