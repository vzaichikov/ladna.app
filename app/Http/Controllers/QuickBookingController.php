<?php

namespace App\Http\Controllers;

use App\Actions\CreateQuickBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Http\Requests\StoreQuickBookingRequest;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuickBookingController extends Controller
{
    public function store(StoreQuickBookingRequest $request, Account $account, CreateQuickBooking $createQuickBooking): RedirectResponse|JsonResponse
    {
        $classBooking = $createQuickBooking->execute($account, $request->user(), $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('app.quick_booking_created'),
                'booking_id' => $classBooking->id,
                'scheduled_class_id' => $classBooking->scheduled_class_id,
            ], 201);
        }

        return back()->with('status', __('app.quick_booking_created'));
    }

    public function groupAvailability(Request $request, Account $account): JsonResponse
    {
        $this->authorize('manageBookings', $account);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $timezone = $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $validated['date'].' 00:00:00', $timezone);
        $endsAt = $startsAt->endOfDay();
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        $classes = $account->scheduledClasses()
            ->with(['location', 'classType', 'trainer'])
            ->withCount([
                'classBookings as active_bookings_count' => fn ($query) => $query->whereIn('status', $activeStatuses),
            ])
            ->whereBetween('starts_at', [
                $startsAt->timezone(config('app.timezone')),
                $endsAt->timezone(config('app.timezone')),
            ])
            ->where('status', ScheduledClassStatus::Scheduled->value)
            ->whereHas('classType', fn ($query) => $query
                ->where('is_active', true)
                ->where('schedule_kind', ScheduleKind::GroupClass->value))
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get()
            ->filter(fn ($scheduledClass): bool => $this->availableSpots($scheduledClass) > 0)
            ->map(fn ($scheduledClass): array => [
                'id' => $scheduledClass->id,
                'time' => $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->format('H:i'),
                'title' => $scheduledClass->title,
                'class_type' => $scheduledClass->classType?->name,
                'trainer' => $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned'),
                'available_spots' => $this->availableSpots($scheduledClass),
                'capacity' => (int) ($scheduledClass->capacity ?? 0),
            ])
            ->values();

        return response()->json(['data' => $classes]);
    }

    private function availableSpots(mixed $scheduledClass): int
    {
        return max(0, (int) ($scheduledClass->capacity ?? 0) - (int) ($scheduledClass->active_bookings_count ?? 0));
    }
}
