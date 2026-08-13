<?php

namespace App\Http\Requests;

use App\Enums\FestivalStreamOverride;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalOnlineStreamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivalFinance', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'opens_at' => [Rule::requiredIf($this->boolean('is_enabled')), 'nullable', 'date_format:Y-m-d\TH:i'],
            'closes_at' => [Rule::requiredIf($this->boolean('is_enabled')), 'nullable', 'date_format:Y-m-d\TH:i', 'after:opens_at'],
            'playback_override' => ['required', Rule::enum(FestivalStreamOverride::class)],
            'rotate_publisher_token' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled'),
            'rotate_publisher_token' => $this->boolean('rotate_publisher_token'),
        ]);
    }
}
