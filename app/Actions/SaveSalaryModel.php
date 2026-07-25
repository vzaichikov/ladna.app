<?php

namespace App\Actions;

use App\Enums\SalaryClassFormulaType;
use App\Enums\SalaryModelType;
use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\SalaryModelClassRule;
use App\Models\User;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Support\Facades\DB;

class SaveSalaryModel
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Account $account, array $validated, ?SalaryModel $salaryModel, ?User $actor): SalaryModel
    {
        return DB::transaction(function () use ($account, $validated, $salaryModel, $actor): SalaryModel {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($salaryModel) {
                abort_unless($salaryModel->account_id === $account->id && $salaryModel->archived_at === null, 404);
                abort_unless($salaryModel->type->value === $validated['type'], 422);
                $salaryModel->update(['name' => $validated['name']]);
            } else {
                $salaryModel = $account->salaryModels()->create([
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                ]);
            }

            $effectiveFrom = (string) $validated['effective_from'];
            $salaryModel->versions()
                ->whereDate('effective_from', $effectiveFrom)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $version = $salaryModel->versions()->create([
                'account_id' => $account->id,
                'created_by_user_id' => $actor?->id,
                'effective_from' => $effectiveFrom,
                'currency' => strtoupper((string) ($account->default_currency ?: 'UAH')),
                'period_unit' => $salaryModel->type === SalaryModelType::FixedPeriod
                    ? $validated['period_unit']
                    : null,
                'amount_cents' => $salaryModel->type === SalaryModelType::FixedPeriod
                    ? PaymentAmounts::decimalToCents($validated['amount'])
                    : null,
                'counted_booking_statuses' => $salaryModel->type === SalaryModelType::PerClass
                    ? array_values($validated['counted_booking_statuses'])
                    : null,
                'pay_empty_classes' => $salaryModel->type === SalaryModelType::PerClass
                    && (bool) ($validated['pay_empty_classes'] ?? false),
            ]);

            if ($salaryModel->type === SalaryModelType::PerClass) {
                foreach ($validated['rules'] as $ruleData) {
                    $classTypeId = filled($ruleData['class_type_id'] ?? null)
                        ? (int) $ruleData['class_type_id']
                        : null;
                    $classType = $classTypeId
                        ? $account->classTypes()->whereKey($classTypeId)->firstOrFail()
                        : null;

                    $rule = $version->classRules()->create([
                        'account_id' => $account->id,
                        'class_type_id' => $classType?->id,
                        'class_type_name' => $classType?->name,
                        'is_default' => (bool) ($ruleData['is_default'] ?? false),
                        'formula_type' => $ruleData['formula_type'],
                        ...$this->ruleAmounts($ruleData),
                        'minimum_people' => (int) ($ruleData['minimum_people'] ?? 0),
                        'included_people' => (int) ($ruleData['included_people'] ?? 0),
                    ]);

                    $this->createTiers($rule, $account, $ruleData);
                }
            }

            return $salaryModel->load([
                'versions' => fn ($query) => $query->latest('effective_from')->latest('id'),
                'versions.classRules.tiers',
            ]);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $ruleData
     * @return array<string, int|null>
     */
    private function ruleAmounts(array $ruleData): array
    {
        return [
            'flat_amount_cents' => $this->amount($ruleData, 'flat_amount'),
            'person_rate_cents' => $this->amount($ruleData, 'person_rate'),
            'base_amount_cents' => $this->amount($ruleData, 'base_amount'),
            'hourly_rate_cents' => $this->amount($ruleData, 'hourly_rate'),
            'extra_person_rate_cents' => $this->amount($ruleData, 'extra_person_rate'),
            'minimum_pay_cents' => $this->amount($ruleData, 'minimum_pay'),
            'maximum_pay_cents' => $this->amount($ruleData, 'maximum_pay'),
        ];
    }

    /**
     * @param  array<string, mixed>  $ruleData
     */
    private function amount(array $ruleData, string $key): ?int
    {
        return filled($ruleData[$key] ?? null)
            ? PaymentAmounts::decimalToCents($ruleData[$key])
            : null;
    }

    /**
     * @param  array<string, mixed>  $ruleData
     */
    private function createTiers(SalaryModelClassRule $rule, Account $account, array $ruleData): void
    {
        if ($rule->formula_type !== SalaryClassFormulaType::AttendanceTiers) {
            return;
        }

        collect($ruleData['tiers'] ?? [])
            ->sortBy(fn (array $tier): int => (int) $tier['minimum_people'])
            ->each(function (array $tier) use ($rule, $account): void {
                $rule->tiers()->create([
                    'account_id' => $account->id,
                    'minimum_people' => (int) $tier['minimum_people'],
                    'maximum_people' => filled($tier['maximum_people'] ?? null)
                        ? (int) $tier['maximum_people']
                        : null,
                    'amount_cents' => PaymentAmounts::decimalToCents($tier['amount']) ?? 0,
                ]);
            });
    }
}
