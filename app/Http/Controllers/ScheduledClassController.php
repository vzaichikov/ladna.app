<?php

namespace App\Http\Controllers;

use App\Enums\ClassBookingStatus;
use App\Models\Account;
use Illuminate\View\View;

class ScheduledClassController extends Controller
{
    public function __invoke(Account $account): View
    {
        $this->authorize('view', $account);

        return view('scheduled-classes.index', [
            'account' => $account,
            'scheduledClasses' => $account->scheduledClasses()
                ->with(['location', 'room', 'classType.activityDirection', 'trainer', 'scheduleSeries', 'classBookings.customer'])
                ->orderBy('starts_at')
                ->limit(100)
                ->get(),
            'customers' => $account->customers()->orderBy('name')->get(),
            'bookingStatuses' => ClassBookingStatus::cases(),
        ]);
    }
}
