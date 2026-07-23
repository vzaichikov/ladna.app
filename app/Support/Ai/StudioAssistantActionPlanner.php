<?php

namespace App\Support\Ai;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioAiDisposition;
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
use LogicException;

class StudioAssistantActionPlanner
{
    public function __construct(private readonly StudioAiContextBuilder $contextBuilder) {}

    public function startGroupBookingDialog(Account $account, User $user, ?Trainer $trainer, AiConversation $conversation): StudioAssistantActionPlan
    {
        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, [
            'status' => 'collecting',
        ]);
    }

    public function plan(
        Account $account,
        User $user,
        ?Trainer $trainer,
        AiConversation $conversation,
        StudioAiDisposition $disposition,
        StudioAiActionInput $input,
    ): StudioAssistantActionPlan {
        return match ($disposition) {
            StudioAiDisposition::StartBooking => $this->startBookingFromInput($account, $user, $trainer, $conversation, $input),
            StudioAiDisposition::ContinueBooking => $this->continueBookingFromInput($account, $user, $trainer, $conversation, $input),
            StudioAiDisposition::CancelBooking => $this->cancelBookingFromInput($account, $user, $trainer, $conversation, $input),
            StudioAiDisposition::CancelDialog => $this->cancelBookingDialog($conversation),
            default => StudioAssistantActionPlan::none(),
        };
    }

    public function planExactDialogOption(
        Account $account,
        User $user,
        ?Trainer $trainer,
        AiConversation $conversation,
        string $text,
    ): ?StudioAssistantActionPlan {
        if (preg_match('/^\s*(\d{1,2})\s*$/', $text, $matches) !== 1) {
            return null;
        }

        $draft = $this->contextBuilder->activeBookingDialog($conversation);

        if (! $draft || ! in_array($draft['status'] ?? null, ['awaiting_customer', 'awaiting_trainer', 'awaiting_class'], true)) {
            return null;
        }

        return $this->continueBookingFromInput(
            $account,
            $user,
            $trainer,
            $conversation,
            new StudioAiActionInput(optionNumber: (int) $matches[1]),
        );
    }

    private function startBookingFromInput(
        Account $account,
        User $user,
        ?Trainer $trainer,
        AiConversation $conversation,
        StudioAiActionInput $input,
    ): StudioAssistantActionPlan {
        $draft = [
            'status' => 'collecting',
            'customer_query' => $input->customerQuery,
            'trainer_query' => $input->trainerQuery,
            'date' => $input->date,
        ];

        if ($input->customerId !== null) {
            $customer = $account->customers()->whereKey($input->customerId)->first();

            if (! $customer) {
                return StudioAssistantActionPlan::message(__('app.assistant_customer_not_found'));
            }

            $draft['customer_id'] = $customer->id;
            $draft['customer_name'] = $customer->name;
            unset($draft['customer_query']);
        }

        if ($input->scheduledClassId !== null) {
            $scheduledClass = ScheduledClass::query()
                ->whereBelongsTo($account)
                ->whereKey($input->scheduledClassId)
                ->first();

            if (! $scheduledClass) {
                return StudioAssistantActionPlan::message(__('app.assistant_class_not_found'));
            }

            $draft['scheduled_class_id'] = $scheduledClass->id;
        }

        if ($input->useActorTrainer && $trainer) {
            $draft['trainer_id'] = $trainer->id;
            $draft['trainer_name'] = $trainer->name;
            unset($draft['trainer_query']);
        }

        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, $draft);
    }

    private function continueBookingFromInput(
        Account $account,
        User $user,
        ?Trainer $trainer,
        AiConversation $conversation,
        StudioAiActionInput $input,
    ): StudioAssistantActionPlan {
        $draft = $this->contextBuilder->activeBookingDialog($conversation);

        if (! $draft) {
            return StudioAssistantActionPlan::message(__('app.assistant_booking_dialog_no_active'), [
                'booking_dialog' => ['status' => 'none'],
            ]);
        }

        $status = (string) ($draft['status'] ?? '');

        if ($status === 'awaiting_customer') {
            $selected = $this->selectModelOption($input, $draft['customer_candidates'] ?? []);

            if ($selected) {
                $draft['customer_id'] = $selected['id'];
                $draft['customer_name'] = $selected['name'];
            } elseif ($input->customerQuery !== null) {
                $draft['customer_query'] = $input->customerQuery;
                unset($draft['customer_id'], $draft['customer_name']);
            } else {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_customer_missing'), $draft);
            }

            unset($draft['customer_candidates']);
        } elseif ($status === 'awaiting_trainer') {
            $selected = $this->selectModelOption($input, $draft['trainer_candidates'] ?? []);

            if ($input->useActorTrainer && $trainer) {
                $draft['trainer_id'] = $trainer->id;
                $draft['trainer_name'] = $trainer->name;
            } elseif ($selected) {
                $draft['trainer_id'] = $selected['id'];
                $draft['trainer_name'] = $selected['name'];
            } elseif ($input->trainerQuery !== null) {
                $draft['trainer_query'] = $input->trainerQuery;
                unset($draft['trainer_id'], $draft['trainer_name']);
            } else {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_trainer_missing'), $draft);
            }

            unset($draft['trainer_candidates']);
        } elseif ($status === 'awaiting_date') {
            if ($input->date === null) {
                return $this->bookingDialogMessage(__('app.assistant_booking_dialog_date_not_understood'), $draft);
            }

            $draft['date'] = $input->date;
        } elseif ($status === 'awaiting_class') {
            $selected = $this->selectModelOption($input, $draft['class_options'] ?? []);

            if (! $selected) {
                return $this->bookingDialogMessage(
                    __('app.assistant_booking_dialog_class_choice_invalid', [
                        'options' => $this->numberedOptions($draft['class_options'] ?? []),
                    ]),
                    $draft,
                    $this->optionFollowUps($draft['class_options'] ?? []),
                );
            }

            $draft['scheduled_class_id'] = $selected['id'];
            unset($draft['class_options']);
        }

        return $this->resolveBookingDraft($account, $user, $trainer, $conversation, $draft);
    }

    private function cancelBookingFromInput(
        Account $account,
        User $user,
        ?Trainer $trainer,
        AiConversation $conversation,
        StudioAiActionInput $input,
    ): StudioAssistantActionPlan {
        if ($input->bookingId === null) {
            return StudioAssistantActionPlan::none();
        }

        $booking = ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereKey($input->bookingId)
            ->first();

        if (! $booking) {
            return StudioAssistantActionPlan::message(__('app.assistant_booking_not_found'));
        }

        $arguments = ['booking_id' => $booking->id];

        return StudioAssistantActionPlan::pending(
            $this->createPendingAction(
                $account,
                $user,
                $trainer,
                $conversation,
                'cancel-booking',
                $arguments,
                $this->cancelBookingPreview($account, $arguments),
            ),
        );
    }

    private function cancelBookingDialog(AiConversation $conversation): StudioAssistantActionPlan
    {
        $draft = $this->contextBuilder->activeBookingDialog($conversation);

        if (! $draft) {
            return StudioAssistantActionPlan::message(__('app.assistant_booking_dialog_no_active'), [
                'booking_dialog' => ['status' => 'none'],
            ]);
        }

        return $this->bookingDialogMessage(
            __('app.assistant_booking_dialog_cancelled'),
            [...$draft, 'status' => 'cancelled'],
        );
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

        if (filled($draft['scheduled_class_id'] ?? null)) {
            return $this->pendingBookingFromDraft($account, $user, $trainer, $conversation, $draft);
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
        $preview = $this->createBookingPreview($account, $arguments);

        if (($preview['warnings'] ?? []) !== []) {
            return StudioAssistantActionPlan::message(implode(' ', $preview['warnings']));
        }

        return StudioAssistantActionPlan::pending(
            $this->createPendingAction($account, $user, $trainer, $conversation, 'create-booking', $arguments, $preview),
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
    private function selectModelOption(StudioAiActionInput $input, array $options): ?array
    {
        if ($input->optionNumber !== null) {
            return $options[$input->optionNumber - 1] ?? null;
        }

        if ($input->optionLabel === null) {
            return null;
        }

        $label = $this->normalizeName($input->optionLabel);

        return collect($options)->first(function (array $option) use ($label): bool {
            return collect([
                $option['label'] ?? null,
                $option['name'] ?? null,
                $option['search_text'] ?? null,
            ])
                ->filter(fn (mixed $candidate): bool => is_string($candidate))
                ->contains(fn (string $candidate): bool => $this->normalizeName($candidate) === $label);
        });
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
        if ((int) $conversation->account_id !== (int) $account->id
            || ($conversation->user_id !== null && (int) $conversation->user_id !== (int) $user->id)
            || ($trainer !== null && (int) $trainer->account_id !== (int) $account->id)) {
            throw new LogicException('Assistant action context does not belong to the supplied account and actor.');
        }

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
