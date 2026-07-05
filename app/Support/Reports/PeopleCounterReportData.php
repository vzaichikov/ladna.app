<?php

namespace App\Support\Reports;

use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Models\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PeopleCounterReportData
{
    public function forAccount(Account $account, int $perPage = 25): LengthAwarePaginator
    {
        return $account->scheduledClasses()
            ->with([
                'location:id,account_id,name,timezone',
                'room:id,account_id,location_id,name,rtsp_enabled,rtsp_url',
                'trainer:id,account_id,name',
                'classType:id,account_id,name',
                'peopleCount',
                'latestSuccessfulPeopleCounterSample',
            ])
            ->withCount([
                'classBookings as attended_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->where('status', ClassBookingStatus::Attended->value),
            ])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->where('ends_at', '<=', now())
            ->orderByDesc('starts_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
