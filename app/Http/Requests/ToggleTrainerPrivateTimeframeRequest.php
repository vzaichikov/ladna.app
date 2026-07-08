<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleTrainerPrivateTimeframeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');
        $trainer = $this->route('trainer');
        $user = $this->user();

        if (! $account || ! $trainer || ! $user || $trainer->account_id !== $account->id) {
            return false;
        }

        return $user->can('manageTrainers', $account)
            || (int) $trainer->user_id === (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'selected' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'selected' => filter_var($this->input('selected'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);
    }
}
