<?php

namespace App\Http\Controllers;

use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Http\Requests\StoreManualScheduledClassRequest;
use App\Models\Account;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class ManualScheduledClassController extends Controller
{
    public function store(StoreManualScheduledClassRequest $request, Account $account, string $scheduleKind): RedirectResponse
    {
        $scheduleKind = ScheduleKind::tryFrom($scheduleKind);
        abort_unless($scheduleKind && in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true), 404);
        abort_unless($account->hasScheduleKindEnabled($scheduleKind), 404);

        $validated = $request->validated();
        $location = $account->locations()->whereKey($validated['location_id'])->firstOrFail();
        $room = $account->rooms()->whereKey($validated['room_id'])->firstOrFail();
        $classType = $account->classTypes()
            ->whereKey($validated['class_type_id'])
            ->where('schedule_kind', $scheduleKind->value)
            ->firstOrFail();
        $trainer = filled($validated['trainer_id'] ?? null)
            ? $account->trainers()->whereKey($validated['trainer_id'])->firstOrFail()
            : null;
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $timezone);
        $durationMinutes = (int) (($validated['duration_minutes'] ?? null) ?: $classType->default_duration_minutes);
        $endsAt = $startsAt->addMinutes($durationMinutes);

        $account->scheduledClasses()->create([
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
            'schedule_series_id' => null,
            'title' => ($validated['title'] ?? null) ?: $classType->name,
            'description' => ($validated['description'] ?? null) ?: $classType->description,
            'starts_at' => $startsAt->timezone(config('app.timezone')),
            'ends_at' => $endsAt->timezone(config('app.timezone')),
            'capacity' => ($validated['capacity'] ?? null) ?? $classType->default_capacity ?? $room->capacity,
            'booking_cutoff_minutes' => ($validated['booking_cutoff_minutes'] ?? null) ?? $classType->booking_cutoff_minutes,
            'is_generated' => false,
            'is_manually_modified' => false,
            'metadata' => [
                'source' => 'manual',
                'schedule_kind' => $scheduleKind->value,
            ],
            'is_public' => (bool) ScheduleKindRegistry::get($scheduleKind)['default_is_public'],
            'status' => ScheduledClassStatus::Scheduled->value,
        ]);

        return redirect()->route('dashboard.accounts.scheduled-classes.index', $account)
            ->with('status', __('app.manual_class_created'));
    }
}
