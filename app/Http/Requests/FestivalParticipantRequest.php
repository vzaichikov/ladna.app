<?php

namespace App\Http\Requests;

use App\Enums\FestivalTeamMemberType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class FestivalParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('festival') !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'patronymic' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'member_type' => ['required', Rule::enum(FestivalTeamMemberType::class)],
            'photo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max('4mb')],
            'remove_photo' => ['sometimes', 'boolean'],
            'fragment_context' => ['nullable', Rule::in(['team', 'helper_selection', 'performer_selection'])],
        ];
    }
}
