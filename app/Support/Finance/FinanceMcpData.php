<?php

namespace App\Support\Finance;

use App\Models\Account;
use App\Models\CashboxReconciliation;
use App\Models\FinanceEpoch;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceMcpData
{
    private const MaximumPeriodDays = 366;

    private const DefaultLimit = 20;

    private const MaximumLimit = 50;

    public function __construct(
        private readonly FinanceReportData $financeReportData,
        private readonly EarningsReportData $earningsReportData,
        private readonly RentalReportData $rentalReportData,
        private readonly CashboxBalanceService $cashboxBalanceService,
        private readonly PayrollPeriodResolver $payrollPeriodResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function financialReport(Account $account, array $arguments): array
    {
        $context = $this->period($account, $arguments);
        $report = $this->financeReportData->forAccount(
            $account,
            $context['filters'],
            $context['starts_at'],
            $context['ends_at'],
            $context['epoch'],
        );
        $limit = $this->limit($arguments);
        $sections = collect($report['sections'])
            ->map(function (Collection $rows, string $section) use ($account, $limit): array {
                $items = $rows
                    ->take($limit)
                    ->map(fn (array $row): array => [
                        'occurred_at' => $this->occurredAt($account, $row['occurred_at']),
                        'label' => $row['label'],
                        'location_name' => $row['location'],
                        'amount' => $this->money((int) $row['amount_cents'], (string) $row['currency']),
                    ])
                    ->values()
                    ->all();

                return [
                    'kind' => $section,
                    'total_rows' => $rows->count(),
                    'returned' => count($items),
                    'truncated' => $rows->count() > $limit,
                    'items' => $items,
                ];
            })
            ->values()
            ->all();

        return [
            'status' => 'ok',
            ...$this->periodPayload($account, $context),
            'location' => $this->locationPayload($context['location']),
            'totals' => collect($report['totals'])
                ->map(fn (array $totals): array => $this->moneyTotals($totals))
                ->all(),
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function cashboxOverview(Account $account, array $arguments): array
    {
        $location = $this->location($account, $arguments['location_id'] ?? null);
        $currency = filled($arguments['currency'] ?? null)
            ? Str::upper((string) $arguments['currency'])
            : null;
        $epoch = $account->activeFinanceEpoch();
        $snapshots = $location
            ? collect([$this->cashboxBalanceService->snapshotFor($account, $location, $currency, $epoch)])
            : $this->cashboxBalanceService->forAccount($account, $epoch)
                ->when($currency, fn (Collection $rows): Collection => $rows->where('currency', $currency))
                ->values();

        return [
            'status' => 'ok',
            'timezone' => DateTimePresenter::accountTimezone($account),
            'active_epoch' => $this->epochPayload($account, $epoch),
            'cashboxes' => $snapshots
                ->map(fn (array $snapshot): array => $this->cashboxPayload($account, $snapshot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function earningsReport(Account $account, array $arguments): array
    {
        $context = $this->period($account, $arguments);
        $report = $this->earningsReportData->forAccount(
            $account,
            $context['filters'],
            $context['starts_at'],
            $context['ends_at'],
        );
        $limit = $this->limit($arguments);
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $report['rows'];

        return [
            'status' => 'ok',
            ...$this->periodPayload($account, $context),
            'location' => $this->locationPayload($context['location']),
            'salary_incomplete' => (bool) $report['salary_incomplete'],
            'totals' => collect($report['totals'])
                ->map(fn (array $totals): array => $this->moneyTotals($totals))
                ->all(),
            'total_rows' => $rows->count(),
            'returned' => min($rows->count(), $limit),
            'truncated' => $rows->count() > $limit,
            'items' => $rows
                ->take($limit)
                ->map(fn (array $row): array => [
                    'scheduled_class_id' => $row['scheduled_class']->id,
                    'title' => $row['scheduled_class']->title,
                    'kind' => $row['kind'],
                    'starts_at' => $this->occurredAt($account, $row['starts_at']),
                    'location_name' => $row['location']?->name,
                    'room_name' => $row['room']?->name,
                    'bookings_count' => (int) $row['bookings_count'],
                    'value' => $this->moneyTotals($row['value_by_currency']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function rentalReport(Account $account, array $arguments): array
    {
        $context = $this->period($account, $arguments);
        $report = $this->rentalReportData->forAccount(
            $account,
            $context['filters'],
            $context['starts_at'],
            $context['ends_at'],
        );
        $limit = $this->limit($arguments);
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $report['rows'];

        return [
            'status' => 'ok',
            ...$this->periodPayload($account, $context),
            'location' => $this->locationPayload($context['location']),
            'totals' => collect($report['totals'])
                ->map(fn (array $totals): array => $this->moneyTotals($totals))
                ->all(),
            'total_rows' => $rows->count(),
            'returned' => min($rows->count(), $limit),
            'truncated' => $rows->count() > $limit,
            'items' => $rows
                ->take($limit)
                ->map(fn (array $row): array => [
                    'scheduled_class_id' => $row['scheduled_class']->id,
                    'booking_id' => $row['booking']->id,
                    'starts_at' => $this->occurredAt($account, $row['starts_at']),
                    'location_name' => $row['location']?->name,
                    'room_name' => $row['room']?->name,
                    'customer' => $row['customer'] ? [
                        'customer_id' => $row['customer']->id,
                        'name' => $row['customer']->name,
                    ] : null,
                    'duration_minutes' => (int) $row['duration_minutes'],
                    'accrued' => $this->moneyTotals($row['accrued_by_currency']),
                    'paid' => $this->moneyTotals($row['paid_by_currency']),
                    'refunded' => $this->moneyTotals($row['refunded_by_currency']),
                    'debt' => $this->moneyTotals($row['debt_by_currency']),
                    'rental_status' => $row['status'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function payrollOverview(Account $account, array $arguments): array
    {
        $limit = $this->limit($arguments);
        $runs = $account->payrollRuns()
            ->with(['lines.trainer'])
            ->orderByDesc('period_ends_on')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $suggestedPeriod = $this->payrollPeriodResolver->latestCompleted($account);

        return [
            'status' => 'ok',
            'timezone' => DateTimePresenter::accountTimezone($account),
            'active_epoch' => $this->epochPayload($account, $account->activeFinanceEpoch()),
            'cadence' => [
                'value' => $account->payroll_cadence->value,
                'anchor_date' => $account->payroll_anchor_date?->toDateString(),
                'latest_completed_period' => [
                    'starts_on' => $suggestedPeriod['starts_on']->toDateString(),
                    'ends_on' => $suggestedPeriod['ends_on']->toDateString(),
                ],
            ],
            'returned' => min($runs->count(), $limit),
            'truncated' => $runs->count() > $limit,
            'runs' => $runs
                ->take($limit)
                ->map(fn (PayrollRun $run): array => [
                    'payroll_run_id' => $run->id,
                    'status' => $run->status,
                    'immutable_snapshot' => true,
                    'period_starts_on' => $run->period_starts_on->toDateString(),
                    'period_ends_on' => $run->period_ends_on->toDateString(),
                    'cadence' => $run->cadence->value,
                    'totals' => $this->moneyTotals((array) $run->totals),
                    'incomplete' => (bool) $run->incomplete,
                    'closed_at' => $this->occurredAt($account, $run->closed_at),
                    'voided_at' => $this->occurredAt($account, $run->voided_at),
                    'supersedes_payroll_run_id' => $run->supersedes_payroll_run_id,
                    'lines' => $run->lines
                        ->map(fn ($line): array => [
                            'trainer_id' => $line->trainer_id,
                            'trainer_name' => $line->trainer?->name,
                            'amounts' => $this->moneyTotals((array) $line->amounts),
                            'model_names' => array_values((array) $line->model_names),
                            'incomplete' => (bool) $line->incomplete,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *     filters: array{date_from: string, date_to: string, location_id: int|null},
     *     starts_at: CarbonImmutable,
     *     ends_at: CarbonImmutable,
     *     epoch: FinanceEpoch|null,
     *     location: Location|null
     * }
     */
    private function period(Account $account, array $arguments): array
    {
        $timezone = DateTimePresenter::accountTimezone($account);
        $today = CarbonImmutable::now($timezone);
        $fromDate = (string) ($arguments['date_from'] ?? $today->startOfMonth()->toDateString());
        $toDate = (string) ($arguments['date_to'] ?? $today->toDateString());
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate, $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate, $timezone);

        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MaximumPeriodDays) {
            throw ValidationException::withMessages([
                'date_to' => 'The finance period must end on or after date_from and may not exceed 366 days.',
            ]);
        }

        $location = $this->location($account, $arguments['location_id'] ?? null);
        $epoch = $account->activeFinanceEpoch();
        $startsAt = $from->startOfDay()->timezone((string) config('app.timezone'));
        $endsAt = $to->endOfDay()->timezone((string) config('app.timezone'));

        if ($epoch?->starts_at && $startsAt->lessThan($epoch->starts_at)) {
            $startsAt = $epoch->starts_at->toImmutable();
            $fromDate = $epoch->starts_at->copy()->timezone($timezone)->toDateString();
        }

        if ($startsAt->greaterThan($endsAt)) {
            throw ValidationException::withMessages([
                'date_to' => 'The selected finance period ends before the active finance epoch.',
            ]);
        }

        return [
            'filters' => [
                'date_from' => $fromDate,
                'date_to' => $toDate,
                'location_id' => $location?->id,
            ],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'epoch' => $epoch,
            'location' => $location,
        ];
    }

    private function location(Account $account, mixed $locationId): ?Location
    {
        if (blank($locationId)) {
            return null;
        }

        $location = $account->locations()
            ->select(['id', 'account_id', 'name'])
            ->whereKey((int) $locationId)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location does not belong to this studio.',
            ]);
        }

        return $location;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function limit(array $arguments): int
    {
        return min(max((int) ($arguments['limit'] ?? self::DefaultLimit), 1), self::MaximumLimit);
    }

    /**
     * @param  array{filters: array{date_from: string, date_to: string, location_id: int|null}, starts_at: CarbonImmutable, ends_at: CarbonImmutable, epoch: FinanceEpoch|null, location: Location|null}  $context
     * @return array<string, mixed>
     */
    private function periodPayload(Account $account, array $context): array
    {
        return [
            'timezone' => DateTimePresenter::accountTimezone($account),
            'period' => [
                'date_from' => $context['filters']['date_from'],
                'date_to' => $context['filters']['date_to'],
                'effective_starts_at' => $this->occurredAt($account, $context['starts_at']),
                'effective_ends_at' => $this->occurredAt($account, $context['ends_at']),
            ],
            'active_epoch' => $this->epochPayload($account, $context['epoch']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function epochPayload(Account $account, ?FinanceEpoch $epoch): ?array
    {
        if (! $epoch) {
            return null;
        }

        return [
            'finance_epoch_id' => $epoch->id,
            'starts_at' => $this->occurredAt($account, $epoch->starts_at),
            'legacy' => (bool) $epoch->is_legacy,
        ];
    }

    /**
     * @return array{location_id: int, name: string}|null
     */
    private function locationPayload(?Location $location): ?array
    {
        return $location ? [
            'location_id' => $location->id,
            'name' => $location->name,
        ] : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function cashboxPayload(Account $account, array $snapshot): array
    {
        $location = $account->locations()->select(['id', 'name'])->findOrFail($snapshot['location_id']);
        $reconciliation = filled($snapshot['reconciliation_id'])
            ? CashboxReconciliation::query()
                ->whereBelongsTo($account)
                ->whereKey($snapshot['reconciliation_id'])
                ->first()
            : null;

        return [
            'location' => [
                'location_id' => $location->id,
                'name' => $location->name,
            ],
            'currency' => $snapshot['currency'],
            'balance' => $this->money((int) $snapshot['balance_cents'], (string) $snapshot['currency']),
            'baseline_actual' => $this->money((int) $snapshot['base_actual_cents'], (string) $snapshot['currency']),
            'movements_after_baseline' => $this->money((int) $snapshot['movements_cents'], (string) $snapshot['currency']),
            'latest_reconciliation' => $reconciliation ? [
                'cashbox_reconciliation_id' => $reconciliation->id,
                'kind' => $reconciliation->kind,
                'occurred_at' => $this->occurredAt($account, $reconciliation->occurred_at),
                'expected_before' => $this->money((int) $reconciliation->expected_before_cents, (string) $reconciliation->currency),
                'actual_counted' => $this->money((int) $reconciliation->actual_counted_cents, (string) $reconciliation->currency),
                'variance' => $this->money((int) $reconciliation->variance_cents, (string) $reconciliation->currency),
            ] : null,
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<int, array{currency: string, amount_cents: int, amount: string}>
     */
    private function moneyTotals(array $totals): array
    {
        return collect($totals)
            ->map(fn (int $amount, string $currency): array => $this->money($amount, $currency))
            ->values()
            ->all();
    }

    /**
     * @return array{currency: string, amount_cents: int, amount: string}
     */
    private function money(int $amountCents, string $currency): array
    {
        return [
            'currency' => Str::upper($currency),
            'amount_cents' => $amountCents,
            'amount' => number_format($amountCents / 100, 2, '.', ''),
        ];
    }

    private function occurredAt(Account $account, ?CarbonInterface $occurredAt): ?string
    {
        return $occurredAt?->copy()
            ->timezone(DateTimePresenter::accountTimezone($account))
            ->toIso8601String();
    }
}
