<?php

namespace App\Http\Requests;

use App\Models\Trainer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduledClassTrainerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageSchedule', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'readonly' => ['sometimes', 'boolean'],
            'trainer_id' => [
                'required',
                'integer',
                Rule::exists((new Trainer)->getTable(), 'id')
                    ->where('account_id', $this->route('account')?->id),
            ],
        ];
    }
}
