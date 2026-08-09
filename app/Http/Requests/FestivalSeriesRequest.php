<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FestivalSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'organizer_name' => ['nullable', 'string', 'max:255'],
            'organizer_email' => ['nullable', 'email', 'max:255'],
            'organizer_phone' => ['nullable', 'string', 'max:50'],
            'organizer_telegram_url' => ['nullable', 'url:http,https', 'max:2048'],
            'organizer_instagram_url' => ['nullable', 'url:http,https', 'max:2048'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
