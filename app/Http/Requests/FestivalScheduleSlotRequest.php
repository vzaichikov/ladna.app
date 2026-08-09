<?php

namespace App\Http\Requests;

use App\Enums\FestivalScheduleSlotType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FestivalScheduleSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'festival_stage_id' => ['required', 'integer'],
            'festival_entry_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(FestivalScheduleSlotType::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'reschedule_reason' => ['nullable', 'string', 'max:3000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
