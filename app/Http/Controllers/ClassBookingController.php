<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Http\Requests\StoreClassBookingRequest;
use App\Http\Requests\UpdateClassBookingStatusRequest;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Http\RedirectResponse;

class ClassBookingController extends Controller
{
    public function store(StoreClassBookingRequest $request, Account $account, ScheduledClass $scheduledClass): RedirectResponse
    {
        $this->ensureClassBelongsToAccount($account, $scheduledClass);

        $customer = $account->customers()->whereKey($request->validated('customer_id'))->firstOrFail();

        $scheduledClass->classBookings()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'account_id' => $account->id,
                'booked_by_user_id' => $request->user()->id,
                'status' => ClassBookingStatus::Booked->value,
                'attended_at' => null,
                'notes' => $request->validated('notes'),
            ],
        );

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_created'));
    }

    public function update(UpdateClassBookingStatusRequest $request, Account $account, ClassBooking $classBooking): RedirectResponse
    {
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        $status = ClassBookingStatus::from($request->validated('status'));
        $classBooking->update([
            'status' => $status->value,
            'attended_at' => $status === ClassBookingStatus::Attended ? now() : null,
            'notes' => $request->validated('notes', $classBooking->notes),
        ]);

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_updated'));
    }

    public function destroy(Account $account, ClassBooking $classBooking): RedirectResponse
    {
        $this->authorize('manageBookings', $account);
        $this->ensureBookingBelongsToAccount($account, $classBooking);

        $classBooking->delete();

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.booking_deleted'));
    }

    private function ensureClassBelongsToAccount(Account $account, ScheduledClass $scheduledClass): void
    {
        abort_unless($scheduledClass->account_id === $account->id, 404);
    }

    private function ensureBookingBelongsToAccount(Account $account, ClassBooking $classBooking): void
    {
        abort_unless($classBooking->account_id === $account->id, 404);
    }
}
