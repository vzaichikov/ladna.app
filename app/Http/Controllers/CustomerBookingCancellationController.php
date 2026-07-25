<?php

namespace App\Http\Controllers;

use App\Actions\CancelClassBooking;
use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CustomerBookingCancellationController extends Controller
{
    public function __invoke(
        string $accountSlug,
        ClassBooking $classBooking,
        CancelClassBooking $cancelClassBooking,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $customer = Auth::guard('customer')->user();

        abort_unless($customer instanceof Customer, 403);
        abort_unless($customer->account_id === $account->id && $classBooking->account_id === $account->id && $classBooking->customer_id === $customer->id, 404);

        $classBooking->loadMissing('scheduledClass.classType');

        if (
            ! in_array($classBooking->status, [ClassBookingStatus::Booked, ClassBookingStatus::Cancelled], true)
            || $classBooking->scheduledClass?->starts_at?->lessThanOrEqualTo(now())
        ) {
            return back()->withErrors(['booking' => __('app.customer_booking_cancel_unavailable')]);
        }

        $cancelClassBooking->execute($classBooking, requireBookedUpcoming: true);

        return redirect()
            ->route('customer.dashboard', $account->slug)
            ->with('status', __('app.customer_booking_cancelled'));
    }
}
