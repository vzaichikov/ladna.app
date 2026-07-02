<?php

namespace App\Http\Requests;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Support\Payments\PaymentAmounts;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
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

                if ($classBooking->scheduledClass?->classType?->schedule_kind !== ScheduleKind::RoomRental) {
                    $validator->errors()->add('amount', __('app.class_booking_payment_rental_only'));
                }

                $hasActivePassReservation = $classBooking->classPassReservation()
                    ->whereIn('status', [
                        CustomerClassPassReservationStatus::Reserved->value,
                        CustomerClassPassReservationStatus::Used->value,
                    ])
                    ->exists();

                if ($hasActivePassReservation) {
                    $validator->errors()->add('amount', __('app.class_booking_payment_class_pass_reserved'));
                }
            },
        ];
    }

    public function amountCents(): int
    {
        return PaymentAmounts::decimalToCents($this->input('amount')) ?? 0;
    }
}
