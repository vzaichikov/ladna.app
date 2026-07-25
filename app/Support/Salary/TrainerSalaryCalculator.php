<?php

namespace App\Support\Salary;

use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Enums\SalaryPeriodUnit;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\SalaryModelClassRule;
use App\Models\SalaryModelVersion;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerSalaryAssignment;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TrainerSalaryCalculator
{
    public function __construct(private SalaryModelResolver $resolver) {}

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array{
     *     trainers: Collection<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     incomplete: bool,
     *     fixed_ignores_location: bool
     * }
     */
    public function forAccount(Account $account, array $filters): array
    {
        $trainers = $account->trainers()->with('trainerType')->orderBy('name')->get();

        return $this->calculate($account, $trainers, $filters);
    }

    /**
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array<string, mixed>
     */
    public function forTrainer(Account $account, Trainer $trainer, array $filters): array
    {
        abort_unless($trainer->account_id === $account->id, 404);

        $result = $this->calculate($account, collect([$trainer]), $filters);

        return $result['trainers']->get($trainer->id, [
            'trainer' => $trainer,
            'amounts' => [],
            'entries' => collect(),
            'incomplete' => true,
            'current_model' => null,
            'model_names' => [],
        ]);
    }

    /**
     * @param  Collection<int, Trainer>  $trainers
     * @param  array{date_from: string, date_to: string, location_id: int|null}  $filters
     * @return array{
     *     trainers: Collection<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     incomplete: bool,
     *     fixed_ignores_location: bool
     * }
     */
    private function calculate(Account $account, Collection $trainers, array $filters): array
    {
        [$localFrom, $localTo, $databaseFrom, $databaseTo] = $this->ranges($account, $filters);
        $trainerIds = $trainers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $today = CarbonImmutable::now($account->timezone ?: config('app.timezone'))->startOfDay();
        $assignmentCutoff = $localTo->greaterThan($today) ? $localTo : $today;
        $assignments = $account->trainerSalaryAssignments()
            ->whereIn('trainer_id', $trainerIds)
            ->whereDate('effective_from', '<=', $assignmentCutoff->toDateString())
            ->whereNull('superseded_at')
            ->with('salaryModel')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        $salaryModelIds = $assignments->pluck('salary_model_id')->unique()->values();
        $versions = $account->salaryModelVersions()
            ->whereIn('salary_model_id', $salaryModelIds)
            ->whereDate('effective_from', '<=', $localTo->toDateString())
            ->whereNull('superseded_at')
            ->with(['salaryModel', 'classRules.tiers'])
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        $classes = $this->classes(
            $account,
            $trainerIds,
            $databaseFrom,
            $databaseTo,
            $filters['location_id'],
        );
        $trainerResults = $trainers->mapWithKeys(function (Trainer $trainer) use (
            $account,
            $assignments,
            $versions,
            $classes,
            $localFrom,
            $localTo,
            $today,
        ): array {
            $trainerAssignments = $assignments->where('trainer_id', $trainer->id)->values();
            $classEntries = $classes
                ->where('trainer_id', $trainer->id)
                ->map(fn (ScheduledClass $scheduledClass): array => $this->classEntry(
                    $account,
                    $scheduledClass,
                    $trainerAssignments,
                    $versions,
                ))
                ->reject(fn (array $entry): bool => $entry['skip']);
            $fixedEntries = $this->fixedEntries(
                $trainer,
                $trainerAssignments,
                $versions,
                $localFrom,
                $localTo,
            );
            $entries = $fixedEntries
                ->concat($classEntries)
                ->sortBy(fn (array $entry): string => $entry['sort_at'])
                ->values();
            $amounts = $entries
                ->filter(fn (array $entry): bool => $entry['amount_cents'] !== null)
                ->groupBy('currency')
                ->map(fn (Collection $currencyEntries): int => (int) $currencyEntries->sum('amount_cents'))
                ->sortKeys()
                ->all();
            $currentAssignment = $this->resolver->assignmentFor($trainerAssignments, $trainer->id, $today);
            $incomplete = ($trainer->is_active && ! $currentAssignment)
                || $entries->contains(fn (array $entry): bool => $entry['amount_cents'] === null);

            return [$trainer->id => [
                'trainer' => $trainer,
                'amounts' => $amounts,
                'entries' => $entries,
                'incomplete' => $incomplete,
                'current_model' => $currentAssignment?->salaryModel,
                'model_names' => $entries->pluck('model_name')->filter()->unique()->values()->all(),
            ]];
        });
        $totals = $trainerResults
            ->flatMap(fn (array $result): Collection => collect($result['amounts'])
                ->map(fn (int $amount, string $currency): array => compact('currency', 'amount')))
            ->groupBy('currency')
            ->map(fn (Collection $amounts): int => (int) $amounts->sum('amount'))
            ->sortKeys()
            ->all();

        return [
            'trainers' => $trainerResults,
            'totals' => $totals,
            'incomplete' => $trainerResults->contains(fn (array $result): bool => $result['incomplete']),
            'fixed_ignores_location' => $filters['location_id'] !== null
                && $trainerResults->contains(fn (array $result): bool => $result['entries']
                    ->contains(fn (array $entry): bool => $entry['kind'] === 'fixed')),
        ];
    }

    /**
     * @param  array<int, int>  $trainerIds
     * @return Collection<int, ScheduledClass>
     */
    private function classes(
        Account $account,
        array $trainerIds,
        CarbonImmutable $databaseFrom,
        CarbonImmutable $databaseTo,
        ?int $locationId,
    ): Collection {
        return ScheduledClass::query()
            ->whereBelongsTo($account)
            ->whereIn('trainer_id', $trainerIds)
            ->where('status', '!=', ScheduledClassStatus::Cancelled->value)
            ->where('ends_at', '<=', now())
            ->whereBetween('starts_at', [$databaseFrom, $databaseTo])
            ->whereHas('classType', fn (Builder $query) => $query
                ->whereIn('schedule_kind', ScheduleKindRegistry::trainerReportableValues()))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->with([
                'classType:id,account_id,name,schedule_kind',
                'location:id,account_id,name,timezone',
                'classBookings' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->orderBy('id')
                    ->select(['id', 'account_id', 'scheduled_class_id', 'status', 'corrected_removed_at']),
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, TrainerSalaryAssignment>  $assignments
     * @param  Collection<int, SalaryModelVersion>  $versions
     * @return array<string, mixed>
     */
    private function classEntry(
        Account $account,
        ScheduledClass $scheduledClass,
        Collection $assignments,
        Collection $versions,
    ): array {
        $localDate = $scheduledClass->starts_at
            ->copy()
            ->timezone($account->timezone ?: config('app.timezone'))
            ->toImmutable();
        $assignment = $this->resolver->assignmentFor(
            $assignments,
            (int) $scheduledClass->trainer_id,
            $localDate,
        );
        $baseEntry = $this->classBaseEntry($account, $scheduledClass, $localDate, $assignment);

        if (! $assignment) {
            return [...$baseEntry, 'amount_cents' => null, 'currency' => null, 'reason_key' => 'salary_reason_no_assignment'];
        }

        if ($assignment->salaryModel?->type !== SalaryModelType::PerClass) {
            return [...$baseEntry, 'skip' => true, 'amount_cents' => 0, 'currency' => null, 'reason_key' => 'salary_reason_fixed_model'];
        }

        $version = $this->resolver->versionFor($versions, $assignment->salary_model_id, $localDate);
        if (! $version) {
            return [...$baseEntry, 'amount_cents' => null, 'currency' => null, 'reason_key' => 'salary_reason_no_version'];
        }

        $countedPeople = $this->countedPeople($scheduledClass, $version);
        $rule = $version->classRules->firstWhere('class_type_id', $scheduledClass->class_type_id)
            ?? $version->classRules->firstWhere('is_default', true);
        if (! $rule) {
            return [
                ...$baseEntry,
                'version' => $version,
                'counted_people' => $countedPeople,
                'amount_cents' => null,
                'currency' => $version->currency,
                'reason_key' => 'salary_reason_no_rule',
            ];
        }

        if ($countedPeople === 0 && ! $version->pay_empty_classes) {
            return [
                ...$baseEntry,
                'version' => $version,
                'rule' => $rule,
                'counted_people' => 0,
                'amount_cents' => 0,
                'currency' => $version->currency,
                'formula' => '0',
                'reason_key' => 'salary_reason_empty_class',
            ];
        }

        $calculation = $this->calculateRule($rule, $countedPeople, $scheduledClass->durationMinutes());

        return [
            ...$baseEntry,
            'version' => $version,
            'rule' => $rule,
            'counted_people' => $countedPeople,
            'amount_cents' => $calculation['amount_cents'],
            'currency' => $version->currency,
            'formula' => $calculation['formula'],
            'reason_key' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classBaseEntry(
        Account $account,
        ScheduledClass $scheduledClass,
        CarbonImmutable $localDate,
        ?TrainerSalaryAssignment $assignment,
    ): array {
        $displayStartsAt = $scheduledClass->starts_at->copy()->timezone(
            $scheduledClass->location?->timezone
                ?? $account->timezone
                ?? config('app.timezone'),
        );
        $displayEndsAt = $scheduledClass->ends_at->copy()->timezone($displayStartsAt->timezone);

        return [
            'kind' => 'class',
            'sort_at' => $scheduledClass->starts_at->format('Y-m-d H:i:s').'-'.str_pad((string) $scheduledClass->id, 20, '0', STR_PAD_LEFT),
            'date' => $displayStartsAt->toDateString(),
            'time' => $displayStartsAt->format('H:i').'–'.$displayEndsAt->format('H:i'),
            'local_date' => $localDate->toDateString(),
            'scheduled_class' => $scheduledClass,
            'class_type' => $scheduledClass->classType?->name ?? $scheduledClass->title,
            'location' => $scheduledClass->location?->name,
            'duration_minutes' => $scheduledClass->durationMinutes(),
            'actual_bookings' => $scheduledClass->classBookings->count(),
            'counted_people' => 0,
            'model_name' => $assignment?->salaryModel?->name,
            'assignment' => $assignment,
            'formula' => null,
            'reason_key' => null,
            'skip' => false,
        ];
    }

    private function countedPeople(ScheduledClass $scheduledClass, SalaryModelVersion $version): int
    {
        $matchingBookings = $scheduledClass->classBookings
            ->filter(fn ($booking): bool => in_array(
                $booking->status->value,
                $version->countedBookingStatusValues(),
                true,
            ))
            ->count();

        return match ($scheduledClass->classType?->schedule_kind) {
            ScheduleKind::PrivateLesson, ScheduleKind::RoomRental => $matchingBookings > 0
                ? max(0, (int) $scheduledClass->capacity)
                : 0,
            default => $matchingBookings,
        };
    }

    /**
     * @return array{amount_cents: int, formula: string}
     */
    private function calculateRule(SalaryModelClassRule $rule, int $people, int $durationMinutes): array
    {
        [$amount, $formula] = match ($rule->formula_type) {
            SalaryClassFormulaType::Flat => [
                (int) $rule->flat_amount_cents,
                $this->decimalAmount((int) $rule->flat_amount_cents),
            ],
            SalaryClassFormulaType::PerPerson => $this->perPersonCalculation($rule, $people),
            SalaryClassFormulaType::BasePlusExtra => $this->basePlusExtraCalculation($rule, $people),
            SalaryClassFormulaType::HourlyPlusExtra => $this->hourlyPlusExtraCalculation($rule, $people, $durationMinutes),
            SalaryClassFormulaType::AttendanceTiers => $this->tierCalculation($rule, $people),
        };
        $clampedAmount = max((int) ($rule->minimum_pay_cents ?? 0), $amount);
        if ($rule->maximum_pay_cents !== null) {
            $clampedAmount = min($clampedAmount, (int) $rule->maximum_pay_cents);
        }

        if ($clampedAmount !== $amount) {
            $formula .= ' → '.$this->decimalAmount($clampedAmount);
        }

        return ['amount_cents' => $clampedAmount, 'formula' => $formula];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function perPersonCalculation(SalaryModelClassRule $rule, int $people): array
    {
        $billablePeople = max($people, (int) $rule->minimum_people);
        $amount = $billablePeople * (int) $rule->person_rate_cents;

        return [
            $amount,
            $billablePeople.' × '.$this->decimalAmount((int) $rule->person_rate_cents)
                .' = '.$this->decimalAmount($amount),
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function basePlusExtraCalculation(SalaryModelClassRule $rule, int $people): array
    {
        $extraPeople = max(0, $people - (int) $rule->included_people);
        $amount = (int) $rule->base_amount_cents + ($extraPeople * (int) $rule->extra_person_rate_cents);

        return [
            $amount,
            $this->decimalAmount((int) $rule->base_amount_cents)
                .' + '.$extraPeople.' × '.$this->decimalAmount((int) $rule->extra_person_rate_cents)
                .' = '.$this->decimalAmount($amount),
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function hourlyPlusExtraCalculation(SalaryModelClassRule $rule, int $people, int $durationMinutes): array
    {
        $hourlyAmount = intdiv(((int) $rule->hourly_rate_cents * $durationMinutes) + 30, 60);
        $extraPeople = max(0, $people - (int) $rule->included_people);
        $amount = $hourlyAmount + ($extraPeople * (int) $rule->extra_person_rate_cents);

        return [
            $amount,
            $durationMinutes.'m × '.$this->decimalAmount((int) $rule->hourly_rate_cents)
                .'/60 + '.$extraPeople.' × '.$this->decimalAmount((int) $rule->extra_person_rate_cents)
                .' = '.$this->decimalAmount($amount),
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function tierCalculation(SalaryModelClassRule $rule, int $people): array
    {
        $tier = $rule->tiers
            ->first(fn ($tier): bool => $tier->minimum_people <= $people
                && ($tier->maximum_people === null || $tier->maximum_people >= $people));
        $amount = (int) ($tier?->amount_cents ?? 0);
        $range = $tier
            ? $tier->minimum_people.'–'.($tier->maximum_people ?? '∞')
            : '—';

        return [$amount, $range.' = '.$this->decimalAmount($amount)];
    }

    /**
     * @param  Collection<int, TrainerSalaryAssignment>  $assignments
     * @param  Collection<int, SalaryModelVersion>  $versions
     * @return Collection<int, array<string, mixed>>
     */
    private function fixedEntries(
        Trainer $trainer,
        Collection $assignments,
        Collection $versions,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $entries = collect();

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $assignment = $this->resolver->assignmentFor($assignments, $trainer->id, $date);
            if (! $assignment || $assignment->salaryModel?->type !== SalaryModelType::FixedPeriod) {
                continue;
            }

            $version = $this->resolver->versionFor($versions, $assignment->salary_model_id, $date);
            if (! $version || ! $version->period_unit || $version->amount_cents === null) {
                $key = 'missing-'.$assignment->id.'-'.$date->toDateString();
                $entries->put($key, [
                    'kind' => 'fixed',
                    'sort_at' => $date->format('Y-m-d').' 00:00:00',
                    'date' => $date->toDateString(),
                    'period_start' => $date->toDateString(),
                    'period_end' => $date->toDateString(),
                    'covered_days' => 1,
                    'total_days' => 1,
                    'model_name' => $assignment->salaryModel?->name,
                    'assignment' => $assignment,
                    'version' => null,
                    'amount_cents' => null,
                    'currency' => null,
                    'formula' => null,
                    'reason_key' => 'salary_reason_no_version',
                ]);

                continue;
            }

            [$periodStart, $periodEnd, $dayOffset, $totalDays] = $this->fixedPeriod($date, $version->period_unit);
            $dailyAmount = $this->allocatedDayAmount((int) $version->amount_cents, $totalDays, $dayOffset);
            $key = implode('-', [$assignment->id, $version->id, $periodStart->toDateString()]);
            $entry = $entries->get($key, [
                'kind' => 'fixed',
                'sort_at' => $periodStart->format('Y-m-d').' 00:00:00',
                'date' => $periodStart->toDateString(),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'covered_from' => $date->toDateString(),
                'covered_to' => $date->toDateString(),
                'covered_days' => 0,
                'total_days' => $totalDays,
                'model_name' => $assignment->salaryModel?->name,
                'assignment' => $assignment,
                'version' => $version,
                'amount_cents' => 0,
                'currency' => $version->currency,
                'formula' => null,
                'reason_key' => null,
            ]);
            $entry['covered_days']++;
            $entry['covered_from'] = min($entry['covered_from'], $date->toDateString());
            $entry['covered_to'] = max($entry['covered_to'], $date->toDateString());
            $entry['amount_cents'] += $dailyAmount;
            $entry['formula'] = $entry['covered_days'].'/'.$totalDays.' · '
                .$this->decimalAmount((int) $version->amount_cents)
                .' = '.$this->decimalAmount((int) $entry['amount_cents']);
            $entries->put($key, $entry);
        }

        return $entries->values();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int, 3: int}
     */
    private function fixedPeriod(CarbonImmutable $date, SalaryPeriodUnit $unit): array
    {
        return match ($unit) {
            SalaryPeriodUnit::Month => [
                $date->startOfMonth(),
                $date->endOfMonth(),
                $date->day - 1,
                $date->daysInMonth,
            ],
            SalaryPeriodUnit::Week => [
                $date->startOfWeek(),
                $date->endOfWeek(),
                $date->dayOfWeekIso - 1,
                7,
            ],
            SalaryPeriodUnit::Day => [$date, $date, 0, 1],
        };
    }

    private function allocatedDayAmount(int $periodAmountCents, int $totalDays, int $dayOffset): int
    {
        $base = intdiv($periodAmountCents, $totalDays);
        $remainder = $periodAmountCents % $totalDays;

        return $base + ($dayOffset < $remainder ? 1 : 0);
    }

    private function decimalAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    /**
     * @param  array{date_from: string, date_to: string}  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable}
     */
    private function ranges(Account $account, array $filters): array
    {
        $timezone = $account->timezone ?: config('app.timezone');
        $localFrom = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone);
        $localTo = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone);

        return [
            $localFrom,
            $localTo,
            $localFrom->startOfDay()->timezone(config('app.timezone')),
            $localTo->endOfDay()->timezone(config('app.timezone')),
        ];
    }
}
