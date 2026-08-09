<?php

namespace App\Http\Requests;

use App\Enums\FestivalRegistrantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalPortalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('festival') !== null;
    }

    public function rules(): array
    {
        return [
            'registrant_type' => ['required', Rule::enum(FestivalRegistrantType::class)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:255'],
            'studio_name' => ['required', 'string', 'max:255'],
            'instagram_url' => ['nullable', 'url:http,https', 'max:2048'],
            'locale' => ['required', Rule::in(['en', 'uk'])],
        ];
    }
}
