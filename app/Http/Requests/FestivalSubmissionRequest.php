<?php

namespace App\Http\Requests;

use App\Models\FestivalRequirementDefinition;
use Illuminate\Foundation\Http\FormRequest;

class FestivalSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('festival') !== null;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.FestivalRequirementDefinition::MaxFileSizeKb]];
    }
}
