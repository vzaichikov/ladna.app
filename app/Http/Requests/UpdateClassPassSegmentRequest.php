<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ActivityDirection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassPassSegmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'activity_direction_ids' => $this->input('activity_direction_ids', []),
        ]);
    }

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
            'schedule_kind' => ['required', Rule::in($account instanceof Account ? $account->enabledScheduleKindValues() : [])],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
            'activity_direction_ids' => ['nullable', 'array'],
            'activity_direction_ids.*' => [
                'integer',
                Rule::exists((new ActivityDirection)->getTable(), 'id')->where('account_id', $account?->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
