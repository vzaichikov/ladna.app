<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Location;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartFinanceEpochRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $account->isOwnedBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'approval' => ['required', Rule::in(['approve'])],
            'cashboxes' => ['required', 'array', 'min:1'],
            'cashboxes.*.location_id' => [
                'required',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'cashboxes.*.actual_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'cashboxes.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approval.required' => __('app.finance_epoch_approval_required'),
            'approval.in' => __('app.finance_epoch_approval_required'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $account = $this->route('account');
                $keys = collect($this->input('cashboxes', []))
                    ->map(fn (array $cashbox): string => (string) ($cashbox['location_id'] ?? '').':'.strtoupper((string) ($cashbox['currency'] ?? $account?->default_currency ?? 'UAH')));

                if ($keys->duplicates()->isNotEmpty()) {
                    $validator->errors()->add('cashboxes', __('validation.distinct', ['attribute' => __('app.cashbox_reconciliation')]));
                }
            },
        ];
    }

    /**
     * @return array<int, array{location_id: int, actual_counted_cents: int, currency: string}>
     */
    public function cashboxes(): array
    {
        $account = $this->route('account');

        return collect($this->validated('cashboxes'))
            ->map(fn (array $cashbox): array => [
                'location_id' => (int) $cashbox['location_id'],
                'actual_counted_cents' => PaymentAmounts::decimalToCents($cashbox['actual_amount']) ?? 0,
                'currency' => strtoupper((string) ($cashbox['currency'] ?? $account?->default_currency ?? 'UAH')),
            ])
            ->all();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->input('idempotency_key') ?: (string) Str::uuid(),
        ]);
    }
}
