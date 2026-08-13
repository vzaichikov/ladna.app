<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

class FestivalTimelineOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalSchedule', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
