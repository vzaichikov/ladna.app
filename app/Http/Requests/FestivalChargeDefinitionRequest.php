<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\FestivalChargeDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalChargeDefinitionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $fee = $this->route('festivalChargeDefinition');

        $this->merge([
            'pricing_mode' => $this->input('pricing_mode', $fee instanceof FestivalChargeDefinition ? $fee->pricing_mode->value : 'fixed'),
            'due_policy' => $this->input('due_policy', $fee instanceof FestivalChargeDefinition ? $fee->due_policy->value : 'fixed'),
        ]);
    }

    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    public function rules(): array
    {
        return [
            'festival_category_id' => ['nullable', 'integer'],
            'festival_workflow_step_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(['qualification', 'participation', 'late', 'custom'])],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'pricing_mode' => ['required', Rule::in(['fixed', 'roster'])],
            'included_members' => ['nullable', 'required_if:pricing_mode,roster', 'integer', 'min:1', 'max:100'],
            'additional_member_amount' => ['nullable', 'required_if:pricing_mode,roster', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'due_at' => ['nullable', 'date'],
            'due_policy' => ['required', Rule::in(['fixed', 'approval_relative'])],
            'due_days_after_approval' => ['nullable', 'required_if:due_policy,approval_relative', 'integer', 'min:0', 'max:365'],
            'due_hard_cap_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
