<?php

namespace App\Support\CustomerNotifications;

use App\Models\ScheduledClass;
use Illuminate\Support\Carbon;

class CustomerNotificationSchedulePlanner
{
    private const QuietStartsAtHour = 21;

    private const QuietEndsAtHour = 9;

    private const PreviousDayFallbackHour = 20;

    private const MinimumLeadMinutes = 60;

    public function scheduledSendAt(ScheduledClass $scheduledClass, int $hoursBefore, ?Carbon $now = null): ?Carbon
    {
        $timezone = $scheduledClass->displayTimezone();
        $nowLocal = ($now ?? now())->copy()->timezone($timezone)->startOfMinute();
        $startsAtLocal = $scheduledClass->starts_at->copy()->timezone($timezone)->startOfMinute();

        if ($startsAtLocal->lessThanOrEqualTo($nowLocal)) {
            return null;
        }

        $latestLocal = $startsAtLocal->copy()->subMinutes(self::MinimumLeadMinutes);
        $targetLocal = $startsAtLocal->copy()->subHours(max(1, $hoursBefore));
        $scheduledLocal = $this->adjustForQuietHours($targetLocal, $latestLocal);

        if (! $scheduledLocal) {
            return null;
        }

        if ($scheduledLocal->lessThanOrEqualTo($nowLocal)) {
            $scheduledLocal = $this->sendableAtOrNext($nowLocal, $latestLocal);
        }

        if (! $scheduledLocal || $scheduledLocal->greaterThan($latestLocal)) {
            return null;
        }

        return $scheduledLocal->copy()->timezone(config('app.timezone'));
    }

    public function isAllowedSendTime(ScheduledClass $scheduledClass, ?Carbon $now = null): bool
    {
        return $this->isAllowedLocalTime(
            ($now ?? now())->copy()->timezone($scheduledClass->displayTimezone()),
        );
    }

    public function nextAllowedSendAt(ScheduledClass $scheduledClass, ?Carbon $now = null): ?Carbon
    {
        $timezone = $scheduledClass->displayTimezone();
        $nowLocal = ($now ?? now())->copy()->timezone($timezone)->startOfMinute();
        $startsAtLocal = $scheduledClass->starts_at->copy()->timezone($timezone)->startOfMinute();
        $latestLocal = $startsAtLocal->copy()->subMinutes(self::MinimumLeadMinutes);

        $scheduledLocal = $this->sendableAtOrNext($nowLocal, $latestLocal);

        return $scheduledLocal?->timezone(config('app.timezone'));
    }

    private function adjustForQuietHours(Carbon $targetLocal, Carbon $latestLocal): ?Carbon
    {
        if ($this->isAllowedLocalTime($targetLocal)) {
            return $targetLocal;
        }

        if ((int) $targetLocal->format('H') >= self::QuietStartsAtHour) {
            return $targetLocal->copy()->setTime(self::PreviousDayFallbackHour, 0);
        }

        $morningCandidate = $targetLocal->copy()->setTime(self::QuietEndsAtHour, 0);

        if ($morningCandidate->lessThanOrEqualTo($latestLocal)) {
            return $morningCandidate;
        }

        return $targetLocal->copy()->subDay()->setTime(self::PreviousDayFallbackHour, 0);
    }

    private function sendableAtOrNext(Carbon $referenceLocal, Carbon $latestLocal): ?Carbon
    {
        $candidate = $this->isAllowedLocalTime($referenceLocal)
            ? $referenceLocal
            : $this->nextAllowedLocalTime($referenceLocal);

        if ($candidate->greaterThan($latestLocal)) {
            return null;
        }

        return $candidate;
    }

    private function isAllowedLocalTime(Carbon $localTime): bool
    {
        $hour = (int) $localTime->format('H');

        return $hour >= self::QuietEndsAtHour && $hour < self::QuietStartsAtHour;
    }

    private function nextAllowedLocalTime(Carbon $localTime): Carbon
    {
        $hour = (int) $localTime->format('H');

        if ($hour < self::QuietEndsAtHour) {
            return $localTime->copy()->setTime(self::QuietEndsAtHour, 0);
        }

        return $localTime->copy()->addDay()->setTime(self::QuietEndsAtHour, 0);
    }
}
