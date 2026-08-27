<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FestivalTelegramMiniAppRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'init_data' => ['required', 'string', 'max:10000'],
            'action' => ['nullable', 'string', 'in:dashboard,profile,entries,entry,create_entry,ticket_checkout,ticket_order'],
            'target_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
