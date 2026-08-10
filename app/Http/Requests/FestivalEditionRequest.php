<?php

namespace App\Http\Requests;

use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalRegistrationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalEditionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'festival_purchase_id' => [Rule::requiredIf($this->route('festivalEdition') === null), 'nullable', 'integer'],
            'festival_series_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
            'status' => ['required', Rule::enum(FestivalEditionStatus::class)],
            'registration_status' => ['required', Rule::enum(FestivalRegistrationStatus::class)],
            'summary' => ['nullable', 'string', 'max:500'],
            'description_html' => ['nullable', 'string', 'max:100000'],
            'rules_html' => ['nullable', 'string', 'max:100000'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string', 'max:500'],
            'venue_map_url' => ['nullable', 'url:http,https', 'max:2048'],
            'venue_directions' => ['nullable', 'string', 'max:5000'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'age_reference_date' => ['required', 'date'],
            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after:registration_opens_at'],
            'max_entries_per_participant' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
