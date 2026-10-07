<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PayCustomerCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['studio_rules_accepted' => ['accepted']];
    }
}
