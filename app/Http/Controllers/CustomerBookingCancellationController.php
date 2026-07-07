<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileCustomerClassPassForBooking;
use App\Enums\ClassBookingStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerNotifications\ClassBookingNotificationCoordinator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class CustomerBookingCancellationController extends Controller
{
    public function __invoke(
        string $accountSlug,
        ClassBooking $classBooking,
        ClassBookingCancellationWindow $cancellationWindow,
        ReconcileCustomerClassPassForBooking $reconcileCustomerClassPassForBooking,
        ClassBookingNotificationCoordinator $notifications,
    ): RedirectResponse {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $customer = Auth::guard('customer')->user();

        abort_unless($customer instanceof Customer, 403);
        abort_unless($customer->account_id === $account->id && $classBooking->account_id === $account->id && $classBooking->customer_id === $customer->id, 404);

        $classBooking->loadMissing('scheduledClass.classType');

        if ($classBooking->status !== ClassBookingStatus::Booked || $classBooking->scheduledClass?->starts_at?->lessThanOrEqualTo(now())) {
            return back()->withErrors(['booking' => __('app.customer_booking_cancel_unavailable')]);
        }

        if ($cancellationWindow->isLockedForBooking($classBooking)) {
            return back()->withErrors(['booking' => __('app.booking_cancellation_cutoff_locked')]);
        }

        $classBooking->update([
            'status' => ClassBookingStatus::Cancelled->value,
            'attended_at' => null,
        ]);
        $reconcileCustomerClassPassForBooking->execute($classBooking);
        $notifications->bookingCancelled($classBooking);

        return redirect()
            ->route('customer.dashboard', $account->slug)
            ->with('status', __('app.customer_booking_cancelled'));
    }
}
