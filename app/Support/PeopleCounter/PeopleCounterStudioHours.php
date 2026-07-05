<?php

namespace App\Support\PeopleCounter;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PeopleCounterStudioHours
{
    /**
     * @return array<int, int>
     */
    public function openAccountIds(Carbon $moment, bool $requireRtspCameras = false): array
    {
        return Account::query()
            ->active()
            ->where('enable_people_counter', true)
            ->when(
                $requireRtspCameras,
                fn (Builder $query): Builder => $query->where('allow_rtsp_cameras', true),
            )
            ->get(['id', 'timezone', 'opening_hours'])
            ->filter(fn (Account $account): bool => $this->isOpen($account, $moment))
            ->modelKeys();
    }

    public function isOpen(Account $account, ?Carbon $moment = null): bool
    {
        $moment ??= now();
        $timezone = $account->timezone ?: config('app.timezone');
        $localMoment = $moment->copy()->timezone($timezone);
        $openingHours = $account->openingHoursForIsoWeekday($localMoment->isoWeekday());

        if (! $openingHours) {
            return false;
        }

        $opensAt = Carbon::createFromFormat('Y-m-d H:i', $localMoment->format('Y-m-d').' '.$openingHours['opens_at'], $timezone);
        $closesAt = Carbon::createFromFormat('Y-m-d H:i', $localMoment->format('Y-m-d').' '.$openingHours['closes_at'], $timezone);

        if (! $opensAt || ! $closesAt || $closesAt->lessThanOrEqualTo($opensAt)) {
            return false;
        }

        return $localMoment->greaterThanOrEqualTo($opensAt)
            && $localMoment->lessThan($closesAt);
    }
}
