<?php

namespace App\Http\Requests;

use App\Support\Festivals\FestivalLandingRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFestivalLandingTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accessPlatform') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $registeredKeys = collect(app(FestivalLandingRegistry::class)->templates())
            ->keys()
            ->reject(fn (string $key): bool => $key === FestivalLandingRegistry::DEFAULT_TEMPLATE)
            ->values()
            ->all();

        return [
            'festival_landing_templates' => ['nullable', 'array'],
            'festival_landing_templates.*' => ['required', 'string', 'distinct', Rule::in($registeredKeys)],
        ];
    }

    /** @return list<string> */
    public function templateKeys(): array
    {
        return collect($this->validated('festival_landing_templates', []))
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();
    }
}
