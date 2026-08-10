<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalChargeDefinitionRequest extends FormRequest
{
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
            'amount_cents' => ['required', 'integer', 'min:0'],
            'due_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
