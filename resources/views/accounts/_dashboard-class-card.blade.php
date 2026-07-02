@php
    $activeBookingStatuses = $activeBookingStatuses ?? [
        \App\Enums\ClassBookingStatus::Booked->value,
        \App\Enums\ClassBookingStatus::Attended->value,
    ];
    $timezone = $scheduledClass->displayTimezone();
    $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
    $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
    $activeBookings = $scheduledClass->classBookings->filter(
        fn ($booking): bool => in_array($booking->status->value, $activeBookingStatuses, true),
    );
    $capacity = max(0, (int) ($scheduledClass->capacity ?? 0));
    $loadPercent = $capacity > 0 ? (int) round(($activeBookings->count() / $capacity) * 100) : 0;
    $barWidth = min(100, max(0, $loadPercent));
    $statusClass = match ($scheduledClass->status->value) {
        'cancelled' => 'crm-status-danger',
        'draft' => 'crm-status-muted',
        default => 'crm-status-scheduled',
    };
    $scheduleKind = $scheduledClass->classType?->schedule_kind;
    $isRoomRental = $scheduleKind === \App\Enums\ScheduleKind::RoomRental;
    $isCancelledClass = $scheduledClass->status === \App\Enums\ScheduledClassStatus::Cancelled;
    $directionColor = $isRoomRental
        ? $scheduledClass->room?->colorAccent($scheduledClass->classType?->colorAccent('#3B223F') ?? '#3B223F')
        : ($scheduledClass->classType?->colorAccent($scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F') ?? '#3B223F');
    $formatColor = $account->scheduleKindColor($scheduleKind);
    $formatTextColor = $account->scheduleKindTextColor($scheduleKind);
    $showRoster = $showRoster ?? false;
    $classBorderColor = $isCancelledClass ? '#94A3B8' : $directionColor;
@endphp

<article
    @class([
        'rounded-xl border p-4 shadow-xs',
        'border-stone-200 bg-white' => ! $isCancelledClass,
        'border-slate-300 bg-slate-50' => $isCancelledClass,
    ])
    style="border-top-color: {{ $classBorderColor }}; border-top-width: 4px; border-right-color: {{ $formatColor }}; border-right-width: 4px;"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div @class(['text-sm font-semibold', 'text-brand-600' => ! $isCancelledClass, 'text-slate-500 line-through' => $isCancelledClass])>{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
            <h3 @class(['mt-1 text-lg font-semibold leading-tight', 'text-slate-950' => ! $isCancelledClass, 'text-slate-500 line-through' => $isCancelledClass])>{{ $scheduledClass->title }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ $scheduledClass->location?->name }} · {{ $scheduledClass->room?->name ?? __('app.room') }}</p>
        </div>
        <span class="{{ $statusClass }}">{{ __('app.'.$scheduledClass->status->value) }}</span>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
        @if ($scheduleKind)
            <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold" style="background-color: {{ $formatColor }}; color: {{ $formatTextColor }};">
                {{ __('app.'.$scheduleKind->value) }}
            </span>
        @endif
        <span class="crm-status-muted">{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</span>
    </div>

    <div class="mt-4">
        <div class="flex items-center justify-between gap-3 text-sm">
            <span class="font-semibold text-slate-700">{{ __('app.booked_capacity') }}</span>
            <span class="font-semibold text-slate-950">{{ __('app.booked_of_capacity', ['booked' => $activeBookings->count(), 'capacity' => $capacity]) }}</span>
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100">
            <div class="h-full rounded-full bg-brand-600" style="width: {{ $barWidth }}%"></div>
        </div>
    </div>

    @if ($showRoster)
        <div class="mt-4 border-t border-stone-100 pt-4">
            <h4 class="text-sm font-semibold text-slate-950">{{ __('app.class_roster') }}</h4>
            @if ($scheduledClass->classBookings->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach ($scheduledClass->classBookings as $booking)
                        @php
                            $bookingStatusClass = match ($booking->status->value) {
                                'attended' => 'crm-status-active',
                                'cancelled', 'no_show' => 'crm-status-danger',
                                default => 'crm-status-scheduled',
                            };
                        @endphp
                        <div class="rounded-lg border border-stone-200 bg-slate-50 p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                                </div>
                                <span class="{{ $bookingStatusClass }}">{{ __('app.'.$booking->status->value) }}</span>
                            </div>

                            @can('markAttendance', $account)
                                <form method="POST" action="{{ route('dashboard.accounts.bookings.update', [$account, $booking]) }}" data-async-form class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="crm-field mt-0 sm:min-w-40">
                                        @foreach ($bookingStatuses as $status)
                                            <option value="{{ $status->value }}" @selected($booking->status === $status)>{{ __('app.'.$status->value) }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.save') }}</x-ui.button>
                                </form>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-3 rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">{{ __('app.no_bookings_yet') }}</p>
            @endif
        </div>
    @endif
</article>
