<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\TrainerType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrainerTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');
        $trainerType = $this->route('trainer_type');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique((new TrainerType)->getTable(), 'name')
                    ->where('account_id', $account instanceof Account ? $account->id : null)
                    ->ignore($trainerType),
            ],
            'icon' => ['required', Rule::in(array_keys(config('icons.trainer_types')))],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
