<?php

namespace App\Http\Requests;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class StoreClassBookingPaymentRequest extends FormRequest
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
            && (int) $classBooking->account_id === (int) $account->id
            && ($this->user()?->can('manageBookings', $account) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $classBooking = $this->route('classBooking');

                if (! $classBooking instanceof ClassBooking) {
                    return;
                }

                $classBooking->loadMissing('scheduledClass.classType');
                $anyTimeAddonAmountCents = $classBooking->anyTimeAddonAmountCents();
                $isAnyTimeAddonPayment = $anyTimeAddonAmountCents !== null && $anyTimeAddonAmountCents > 0;

                if (! $isAnyTimeAddonPayment && $classBooking->scheduledClass?->classType?->schedule_kind !== ScheduleKind::RoomRental) {
                    $validator->errors()->add('amount', __('app.class_booking_payment_rental_only'));
                }

                if ($classBooking->activeClassPassReservation() && ! $isAnyTimeAddonPayment) {
                    $validator->errors()->add('amount', __('app.class_booking_payment_class_pass_reserved'));
                }

                if ($isAnyTimeAddonPayment && $this->amountCents() !== $anyTimeAddonAmountCents) {
                    $validator->errors()->add('amount', __('app.any_time_addon_payment_amount_mismatch'));
                }
            },
        ];
    }

    protected function failedValidation(ValidationContract $validator): void
    {
        if ($this->expectsJsonValidationResponse()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first() ?: __('app.async_validation_failed'),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->input('amount')) ?? 0;
    }

    public function safeReturnUrl(Account $account): ?string
    {
        $returnTo = (string) $this->input('return_to', '');

        if ($returnTo === '') {
            return null;
        }

        $returnPath = parse_url($returnTo, PHP_URL_PATH);
        $allowedPath = parse_url(route('dashboard.accounts.reports.unpaid-class-payments', $account), PHP_URL_PATH);

        if (! is_string($returnPath) || $returnPath !== $allowedPath) {
            return null;
        }

        $query = parse_url($returnTo, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $returnPath.'?'.$query : $returnPath;
    }

    private function expectsJsonValidationResponse(): bool
    {
        return $this->expectsJson()
            || $this->ajax()
            || str_contains((string) $this->header('Accept'), 'json');
    }
}
