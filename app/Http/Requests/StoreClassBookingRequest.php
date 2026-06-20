<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreClassBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageBookings', $this->route('account')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'customer_id' => ['required', Rule::exists((new Customer)->getTable(), 'id')->where('account_id', $account?->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJsonValidationResponse()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first() ?: __('app.async_validation_failed'),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    private function expectsJsonValidationResponse(): bool
    {
        return $this->expectsJson()
            || $this->ajax()
            || str_contains((string) $this->header('Accept'), 'json');
    }
}
