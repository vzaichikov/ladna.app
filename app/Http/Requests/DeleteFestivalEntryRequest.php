<?php

namespace App\Http\Requests;

use App\Actions\Festivals\DeleteFestivalEntry;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteFestivalEntryRequest extends FormRequest
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
            'approval' => ['nullable', 'string', Rule::in([DeleteFestivalEntry::CONFIRMATION_PHRASE])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approval.in' => __('app.festival_application_delete_payment_history'),
        ];
    }

    public function paymentDeletionConfirmed(): bool
    {
        return $this->validated('approval') === DeleteFestivalEntry::CONFIRMATION_PHRASE;
    }
}
