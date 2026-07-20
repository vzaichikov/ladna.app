<?php

namespace App\Http\Requests;

use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\StudioExpense;
use App\Support\DateTimePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountPaymentFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('view', $account) ?? false)
            && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', Rule::in(CustomerPurchase::paymentMethods())],
            'status' => ['nullable', Rule::enum(CustomerPurchaseStatus::class)],
            'provider' => ['nullable', 'string', Rule::in($this->providerValues($account))],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0),
            ],
            'expense_category_id' => [
                'nullable',
                'integer',
                Rule::exists((new ExpenseCategory)->getTable(), 'id')
                    ->where('account_id', $account instanceof Account ? $account->id : 0),
            ],
            'expense_payment_method' => ['nullable', Rule::in(StudioExpense::paymentMethods())],
            'expense_status' => ['nullable', Rule::in(StudioExpense::statuses())],
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, search: string|null, payment_method: string|null, status: string|null, provider: string|null, location_id: int|null, expense_category_id: int|null, expense_payment_method: string|null, expense_status: string|null}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'date_from' => (string) $validated['date_from'],
            'date_to' => (string) $validated['date_to'],
            'search' => filled($validated['search'] ?? null) ? trim((string) $validated['search']) : null,
            'payment_method' => filled($validated['payment_method'] ?? null) ? (string) $validated['payment_method'] : null,
            'status' => filled($validated['status'] ?? null) ? (string) $validated['status'] : null,
            'provider' => filled($validated['provider'] ?? null) ? (string) $validated['provider'] : null,
            'location_id' => filled($validated['location_id'] ?? null) ? (int) $validated['location_id'] : null,
            'expense_category_id' => filled($validated['expense_category_id'] ?? null) ? (int) $validated['expense_category_id'] : null,
            'expense_payment_method' => filled($validated['expense_payment_method'] ?? null) ? (string) $validated['expense_payment_method'] : null,
            'expense_status' => filled($validated['expense_status'] ?? null) ? (string) $validated['expense_status'] : null,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function databaseRange(): array
    {
        $filters = $this->filters();
        $account = $this->route('account');
        $timezone = DateTimePresenter::accountTimezone($account instanceof Account ? $account : null);

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], $timezone)
                ->startOfDay()
                ->timezone((string) config('app.timezone')),
            CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], $timezone)
                ->endOfDay()
                ->timezone((string) config('app.timezone')),
        ];
    }

    protected function prepareForValidation(): void
    {
        $account = $this->route('account');
        $timezone = DateTimePresenter::accountTimezone($account instanceof Account ? $account : null);
        $today = CarbonImmutable::now($timezone);

        $this->merge([
            'date_from' => $this->input('date_from') ?: $today->toDateString(),
            'date_to' => $this->input('date_to') ?: $today->toDateString(),
            'search' => blank($this->input('search')) ? null : trim((string) $this->input('search')),
            'payment_method' => blank($this->input('payment_method')) ? null : $this->input('payment_method'),
            'status' => blank($this->input('status')) ? null : $this->input('status'),
            'provider' => blank($this->input('provider')) ? null : $this->input('provider'),
            'location_id' => blank($this->input('location_id')) ? null : $this->input('location_id'),
            'expense_category_id' => blank($this->input('expense_category_id')) ? null : $this->input('expense_category_id'),
            'expense_payment_method' => blank($this->input('expense_payment_method')) ? null : $this->input('expense_payment_method'),
            'expense_status' => blank($this->input('expense_status')) ? null : $this->input('expense_status'),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function providerValues(mixed $account): array
    {
        $configuredProviders = array_keys(config('integrations.providers', []));

        if (! $account instanceof Account) {
            return $configuredProviders;
        }

        return $account->customerPurchases()
            ->select('provider')
            ->distinct()
            ->pluck('provider')
            ->merge($configuredProviders)
            ->unique()
            ->values()
            ->all();
    }
}
