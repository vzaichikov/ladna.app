<?php

namespace App\Http\Requests;

use App\Enums\FestivalStreamProvider;
use App\Models\Account;
use App\Rules\FestivalYouTubeUrl;
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
            'provider' => ['required', Rule::enum(FestivalStreamProvider::class)],
            'youtube_url' => [
                Rule::requiredIf($this->string('provider')->toString() === FestivalStreamProvider::YouTube->value),
                'nullable',
                'bail',
                'string',
                'max:2048',
                new FestivalYouTubeUrl,
            ],
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
