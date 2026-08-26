<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassBookingPaymentWaiver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UnwaiveClassBookingPaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $waiver = $this->route('classBookingPaymentWaiver');

        return $account instanceof Account
            && $waiver instanceof ClassBookingPaymentWaiver
            && $waiver->account_id === $account->id
            && $account->isOwnedBy($this->user());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function safeReturnUrl(Account $account): ?string
    {
        $returnTo = (string) $this->input('return_to', '');

        if ($returnTo === '') {
            return null;
        }

        $returnPath = parse_url($returnTo, PHP_URL_PATH);
        $allowedPath = parse_url(route('dashboard.accounts.reports.unpaid-class-payments.waived', $account), PHP_URL_PATH);

        if (! is_string($returnPath) || $returnPath !== $allowedPath) {
            return null;
        }

        $query = parse_url($returnTo, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $returnPath.'?'.$query : $returnPath;
    }
}
