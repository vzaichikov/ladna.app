<?php

namespace App\Http\Controllers;

use App\Actions\CreateQuickBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Http\Requests\StoreQuickBookingRequest;
use App\Models\Account;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Trainer;
use App\Support\ManualQuickBookingAvailability;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuickBookingController extends Controller
{
    public function store(StoreQuickBookingRequest $request, Account $account, CreateQuickBooking $createQuickBooking): RedirectResponse|JsonResponse
    {
        try {
            $classBooking = $createQuickBooking->execute($account, $request->user(), $request->validated());
        } catch (ValidationException $exception) {
            if ($this->expectsJsonValidationResponse($request)) {
                return response()->json([
                    'message' => collect($exception->errors())->flatten()->first() ?: __('app.async_validation_failed'),
                    'errors' => $exception->errors(),
                ], 422);
            }

            throw $exception;
        }

        if ($request->expectsJson()) {
            $request->session()->flash('status', __('app.quick_booking_created'));

            return response()->json([
                'message' => __('app.quick_booking_created'),
                'booking_id' => $classBooking->id,
                'scheduled_class_id' => $classBooking->scheduled_class_id,
                'reload' => true,
            ], 201);
        }

        return back()->with('status', __('app.quick_booking_created'));
    }

    private function expectsJsonValidationResponse(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || str_contains((string) $request->header('Accept'), 'json');
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
                'classBookings as active_bookings_count' => fn ($query) => $query
                    ->notCorrectedRemoved()
                    ->whereIn('status', $activeStatuses),
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

    public function manualAvailability(Request $request, Account $account, ManualQuickBookingAvailability $availability): JsonResponse
    {
        $this->authorize('manageBookings', $account);

        $manualKinds = collect(ScheduleKindRegistry::manualKinds())
            ->map(fn (ScheduleKind $scheduleKind): string => $scheduleKind->value)
            ->all();
        $validated = $request->validate([
            'schedule_kind' => ['required', Rule::in($manualKinds)],
            'date' => ['required', 'date_format:Y-m-d'],
            'location_id' => ['required', Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account->id)],
            'room_id' => ['required', Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account->id)],
            'class_type_id' => ['required', Rule::exists((new ClassType)->getTable(), 'id')->where('account_id', $account->id)],
            'trainer_id' => ['nullable', Rule::exists((new Trainer)->getTable(), 'id')->where('account_id', $account->id)],
        ]);
        $scheduleKind = ScheduleKind::from($validated['schedule_kind']);

        abort_unless($account->hasScheduleKindEnabled($scheduleKind), 404);

        $result = $availability->for($account, $scheduleKind, [
            'date' => $validated['date'],
            'location_id' => (int) $validated['location_id'],
            'room_id' => (int) $validated['room_id'],
            'class_type_id' => (int) $validated['class_type_id'],
            'trainer_id' => filled($validated['trainer_id'] ?? null) ? (int) $validated['trainer_id'] : null,
            'allow_past' => in_array($scheduleKind, [ScheduleKind::PrivateLesson, ScheduleKind::RoomRental], true),
        ]);

        return response()->json([
            'data' => $result['slots'],
            'closed' => $result['closed'],
            'timezone' => $result['timezone'],
            'date' => $result['date'],
        ]);
    }

    private function availableSpots(mixed $scheduledClass): int
    {
        return max(0, (int) ($scheduledClass->capacity ?? 0) - (int) ($scheduledClass->active_bookings_count ?? 0));
    }
}
