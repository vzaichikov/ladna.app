<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishFestivalResultsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageFestivals', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tie_breaks' => ['sometimes', 'array', 'list'],
            'tie_breaks.*.total' => ['required', 'numeric'],
            'tie_breaks.*.orders' => ['required', 'array', 'min:2'],
            'tie_breaks.*.orders.*' => ['required', 'integer', 'min:1'],
            'tie_breaks.*.reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
