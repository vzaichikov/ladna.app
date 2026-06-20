<?php

namespace App\Http\Requests;

use App\Enums\ClassBookingStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateClassBookingStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return ($this->user()?->can('markAttendance', $account) ?? false)
            || ($this->user()?->can('manageBookings', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ClassBookingStatus::class)],
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
