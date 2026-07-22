<?php

namespace App\Http\Requests;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationScope;
use App\Support\IntegrationCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCentralSmsProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'central_sms_provider' => [
                'required',
                'string',
                Rule::in(array_keys(IntegrationCatalog::providersForCategory(
                    IntegrationCategory::Messaging,
                    IntegrationScope::Platform,
                ))),
            ],
        ];
    }

    public function provider(): string
    {
        return (string) $this->validated('central_sms_provider');
    }
}
