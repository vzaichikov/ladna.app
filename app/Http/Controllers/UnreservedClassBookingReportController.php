<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\Trainer;
use App\Support\ScheduledClassFocus;
use App\Support\UnreservedClassPassBookingIssues;
use App\Support\WorkingLocationContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnreservedClassBookingReportController extends Controller
{
    public function __invoke(
        Request $request,
        Account $account,
        UnreservedClassPassBookingIssues $issues,
        WorkingLocationContext $workingLocationContext,
        ScheduledClassFocus $scheduledClassFocus,
    ): View {
        $this->authorize('viewReports', $account);

        $selectedLocationId = $request->query->has('location_id')
            ? $workingLocationContext->filterLocationId($account, includeInactive: true, request: $request)
            : null;
        $locations = $account->locations()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'is_active']);
        $bookings = $issues
            ->paginateForAccount($account, $selectedLocationId)
            ->through(function (ClassBooking $booking) use ($account, $scheduledClassFocus): array {
                $scheduledClass = $booking->scheduledClass;
                $trainers = collect([$scheduledClass->trainer])
                    ->filter(fn (?Trainer $trainer): bool => $trainer !== null)
                    ->concat($scheduledClass->additionalTrainers)
                    ->unique('id')
                    ->pluck('name')
                    ->values();

                return [
                    'booking' => $booking,
                    'scheduled_class_url' => $scheduledClassFocus->url($account, $scheduledClass),
                    'trainer_names' => $trainers,
                ];
            });

        return view('reports.unreserved-class-bookings', [
            'account' => $account,
            'bookings' => $bookings,
            'locations' => $locations,
            'selectedLocationId' => $selectedLocationId,
        ]);
    }
}
