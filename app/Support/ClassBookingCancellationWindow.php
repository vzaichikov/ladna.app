<?php

namespace App\Support;

use App\Models\ClassBooking;
use App\Models\ScheduledClass;
use Illuminate\Support\Carbon;

class ClassBookingCancellationWindow
{
    public function isLockedForBooking(ClassBooking $classBooking): bool
    {
        $classBooking->loadMissing('scheduledClass.classType');

        return $classBooking->scheduledClass instanceof ScheduledClass
            && $this->isLockedForClass($classBooking->scheduledClass);
    }

    public function isLockedForClass(ScheduledClass $scheduledClass): bool
    {
        $closesAt = $this->closesAt($scheduledClass);

        return $closesAt !== null && now()->greaterThanOrEqualTo($closesAt);
    }

    public function closesAt(ScheduledClass $scheduledClass): ?Carbon
    {
        $scheduledClass->loadMissing('classType');
        $cutoffMinutes = $scheduledClass->effectiveCancellationCutoffMinutes();

        if ($cutoffMinutes === null) {
            return null;
        }

        return $scheduledClass->starts_at->copy()->subMinutes((int) $cutoffMinutes);
    }
}
