<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Finance\PayrollPeriodResolver;
use App\Support\Salary\TrainerSalaryCalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosePayrollRun
{
    public function __construct(
        private readonly PayrollPeriodResolver $periodResolver,
        private readonly TrainerSalaryCalculator $salaryCalculator,
    ) {}

    public function execute(
        Account $account,
        User $actor,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        string $idempotencyKey,
        ?PayrollRun $supersedes = null,
    ): PayrollRun {
        return DB::transaction(function () use ($account, $actor, $startsOn, $endsOn, $idempotencyKey, $supersedes): PayrollRun {
            $lockedAccount = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $start = CarbonImmutable::instance($startsOn)->startOfDay();
            $end = CarbonImmutable::instance($endsOn)->startOfDay();
            $existingRun = PayrollRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRun) {
                $sameRequest = $existingRun->account_id === $lockedAccount->id
                    && $existingRun->period_starts_on->isSameDay($start)
                    && $existingRun->period_ends_on->isSameDay($end)
                    && $existingRun->supersedes_payroll_run_id === $supersedes?->id;

                if (! $sameRequest) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('app.payroll_duplicate_request'),
                    ]);
                }

                return $existingRun->load(['lines.trainer', 'supersedes']);
            }

            if (! $this->periodResolver->matches($lockedAccount, $start, $end)) {
                throw ValidationException::withMessages([
                    'period_starts_on' => __('app.payroll_period_invalid'),
                ]);
            }

            if (! $this->periodResolver->isCompleted($lockedAccount, $end)) {
                throw ValidationException::withMessages([
                    'period_ends_on' => __('app.payroll_period_not_completed'),
                ]);
            }

            $lockedSupersedes = $this->lockedSupersededRun($lockedAccount, $supersedes);
            $this->ensurePeriodIsAvailable($lockedAccount, $start, $end, $lockedSupersedes);

            $calculation = $this->salaryCalculator->forAccount($lockedAccount, [
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'location_id' => null,
            ]);

            if ($calculation['incomplete']) {
                throw ValidationException::withMessages([
                    'period_starts_on' => __('app.payroll_calculation_incomplete'),
                ]);
            }

            $payrollRun = PayrollRun::query()->create([
                'account_id' => $lockedAccount->id,
                'finance_epoch_id' => $lockedAccount->activeFinanceEpoch()?->id,
                'supersedes_payroll_run_id' => $lockedSupersedes?->id,
                'cadence' => $lockedAccount->payroll_cadence,
                'period_starts_on' => $start->toDateString(),
                'period_ends_on' => $end->toDateString(),
                'status' => PayrollRun::StatusClosed,
                'totals' => $this->moneySnapshot($calculation['totals']),
                'incomplete' => false,
                'idempotency_key' => $idempotencyKey,
                'closed_by_user_id' => $actor->id,
                'closed_at' => now(),
            ]);

            foreach ($calculation['trainers'] as $trainerResult) {
                /** @var Trainer $trainer */
                $trainer = $trainerResult['trainer'];

                $payrollRun->lines()->create([
                    'account_id' => $lockedAccount->id,
                    'trainer_id' => $trainer->id,
                    'amounts' => $this->moneySnapshot($trainerResult['amounts']),
                    'model_names' => collect($trainerResult['model_names'])
                        ->map(fn (mixed $name): string => (string) $name)
                        ->values()
                        ->all(),
                    'entries' => collect($trainerResult['entries'])
                        ->map(fn (array $entry): array => $this->entrySnapshot($entry))
                        ->values()
                        ->all(),
                    'incomplete' => false,
                ]);
            }

            return $payrollRun->load(['lines.trainer', 'supersedes']);
        }, attempts: 5);
    }

    private function lockedSupersededRun(Account $account, ?PayrollRun $supersedes): ?PayrollRun
    {
        if (! $supersedes) {
            return null;
        }

        $lockedRun = PayrollRun::query()
            ->whereBelongsTo($account)
            ->whereKey($supersedes->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $lockedRun->isVoided()) {
            throw ValidationException::withMessages([
                'supersedes_payroll_run_id' => __('app.payroll_replacement_requires_voided_run'),
            ]);
        }

        if ($lockedRun->replacements()->where('status', PayrollRun::StatusClosed)->exists()) {
            throw ValidationException::withMessages([
                'supersedes_payroll_run_id' => __('app.payroll_replacement_already_exists'),
            ]);
        }

        return $lockedRun;
    }

    private function ensurePeriodIsAvailable(
        Account $account,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?PayrollRun $supersedes,
    ): void {
        if ($supersedes
            && (! $supersedes->period_starts_on->isSameDay($startsOn)
                || ! $supersedes->period_ends_on->isSameDay($endsOn))) {
            throw ValidationException::withMessages([
                'supersedes_payroll_run_id' => __('app.payroll_replacement_period_mismatch'),
            ]);
        }

        $overlapExists = PayrollRun::query()
            ->whereBelongsTo($account)
            ->where('status', PayrollRun::StatusClosed)
            ->whereDate('period_starts_on', '<=', $endsOn->toDateString())
            ->whereDate('period_ends_on', '>=', $startsOn->toDateString())
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'period_starts_on' => __('app.payroll_period_overlap'),
            ]);
        }
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int>
     */
    private function moneySnapshot(array $amounts): array
    {
        return collect($amounts)
            ->mapWithKeys(fn (mixed $amount, mixed $currency): array => [
                strtoupper((string) $currency) => (int) $amount,
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, bool|int|string|null>
     */
    private function entrySnapshot(array $entry): array
    {
        $snapshot = collect($entry)->only([
            'kind',
            'sort_at',
            'date',
            'time',
            'local_date',
            'class_type',
            'location',
            'duration_minutes',
            'actual_bookings',
            'counted_people',
            'class_value_cents',
            'class_value_pass_cents',
            'class_value_direct_cents',
            'class_value_bookings_count',
            'class_value_percentage_basis_points',
            'class_value_percentage',
            'model_name',
            'formula',
            'reason_key',
            'period_start',
            'period_end',
            'covered_from',
            'covered_to',
            'covered_days',
            'total_days',
            'amount_cents',
            'currency',
        ])->map(fn (mixed $value): bool|int|string|null => match (true) {
            is_bool($value) => $value,
            is_int($value) => $value,
            is_float($value) => (string) $value,
            is_string($value), $value === null => $value,
            default => (string) $value,
        })->all();

        if (isset($entry['scheduled_class']) && $entry['scheduled_class'] instanceof Model) {
            $snapshot['scheduled_class_id'] = (int) $entry['scheduled_class']->getKey();
        }

        return $snapshot;
    }
}
