<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\ClassBooking;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClassBookingPaymentWaiverRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');
        $classBooking = $this->route('classBooking');

        return $account instanceof Account
            && $classBooking instanceof ClassBooking
            && $classBooking->account_id === $account->id
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
        return $this->safeReportUrl(
            (string) $this->input('return_to', ''),
            route('dashboard.accounts.reports.unpaid-class-payments', $account),
        );
    }

    private function safeReportUrl(string $returnTo, string $allowedUrl): ?string
    {
        if ($returnTo === '') {
            return null;
        }

        $returnPath = parse_url($returnTo, PHP_URL_PATH);
        $allowedPath = parse_url($allowedUrl, PHP_URL_PATH);

        if (! is_string($returnPath) || $returnPath !== $allowedPath) {
            return null;
        }

        $query = parse_url($returnTo, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $returnPath.'?'.$query : $returnPath;
    }
}
