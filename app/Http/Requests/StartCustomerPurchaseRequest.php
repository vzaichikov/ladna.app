<?php

namespace App\Http\Requests;

use App\Enums\IntegrationProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCustomerPurchaseRequest extends FormRequest
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
            'provider' => ['required', Rule::in([
                IntegrationProvider::Monopay->value,
                IntegrationProvider::Liqpay->value,
                IntegrationProvider::Wayforpay->value,
            ])],
        ];
    }
}
