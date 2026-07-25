<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\SalaryModel;
use App\Models\Trainer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainerSalaryAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account
            && ($this->user()?->can('manageStudioCashflow', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $accountId = $account instanceof Account ? $account->id : 0;

        return [
            'trainer_ids' => ['required', 'array', 'min:1', 'max:200'],
            'trainer_ids.*' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $accountId),
            ],
            'salary_model_id' => [
                'required',
                'integer',
                Rule::exists((new SalaryModel)->getTable(), 'id')
                    ->where(fn ($query) => $query->where('account_id', $accountId)->whereNull('archived_at')),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'trainer_ids' => collect($this->input('trainer_ids', []))
                ->filter(fn (mixed $trainerId): bool => filled($trainerId))
                ->map(fn (mixed $trainerId): int => (int) $trainerId)
                ->unique()
                ->values()
                ->all(),
        ]);
    }
}
