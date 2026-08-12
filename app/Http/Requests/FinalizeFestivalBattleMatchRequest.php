<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FinalizeFestivalBattleMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audience_votes_a' => ['required', 'integer', 'min:0'],
            'audience_votes_b' => ['required', 'integer', 'min:0'],
            'tie_winner_entry_id' => ['nullable', 'integer', 'min:1'],
            'tie_break_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
