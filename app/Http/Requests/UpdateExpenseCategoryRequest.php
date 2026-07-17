<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ExpenseCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $expenseCategory = $this->route('expenseCategory');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique((new ExpenseCategory)->getTable(), 'name')
                    ->where('account_id', $account?->id)
                    ->ignore($expenseCategory instanceof ExpenseCategory ? $expenseCategory->id : null),
            ],
        ];
    }
}
