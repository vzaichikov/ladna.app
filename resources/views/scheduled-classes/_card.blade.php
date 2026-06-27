@php
    $timezone = $scheduledClass->displayTimezone();
    $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
    $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
    $statusClass = $scheduledClass->displayStatusBadgeClass();
    $scheduleKind = $scheduledClass->classType?->schedule_kind;
    $displayTypeLabels = $scheduledClass->displayTypeLabels();
    $directionColor = $scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F';
    $formatColor = $account->scheduleKindColor($scheduleKind);
    $formatTextColor = $account->scheduleKindTextColor($scheduleKind);
    $cancellationWindow = app(\App\Support\ClassBookingCancellationWindow::class);
    $isCancelledClass = $scheduledClass->status === \App\Enums\ScheduledClassStatus::Cancelled;
    $activeCancellation = $scheduledClass->activeCancellation;
    $cancellationEffects = $activeCancellation?->effects ?? collect();
    $cancellationBookingsCount = $cancellationEffects->count();
    $releasedReservationsCount = $cancellationEffects->where('new_reservation_status', \App\Enums\CustomerClassPassReservationStatus::Released->value)->count();
    $addedSessionsCount = $cancellationEffects->sum('added_sessions_count');
    $addedDaysPassesCount = $cancellationEffects->where('added_validity_days', '>', 0)->count();
    $addedDaysCount = (int) ($cancellationEffects->max('added_validity_days') ?? 0);
    $readonly = $readonly ?? false;
    $canManageClassCancellation = auth()->user()?->can('manageSchedule', $account) && auth()->user()?->can('manageBookings', $account);
    $canCancelClass = $canManageClassCancellation && ! $isCancelledClass && $scheduledClass->isStudioCancellationOpen();
@endphp

<article id="scheduled-class-{{ $scheduledClass->id }}" data-scheduled-class-card data-scheduled-class-id="{{ $scheduledClass->id }}" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-4 shadow-xs" style="border-top-color: {{ $directionColor }}; border-top-width: 4px; border-right-color: {{ $formatColor }}; border-right-width: 4px;">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
            <h3 class="mt-2 text-lg font-semibold leading-tight text-slate-950">{{ $scheduledClass->title }}</h3>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($displayTypeLabels as $displayTypeLabel)
                    <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">{{ $displayTypeLabel }}</span>
                @endforeach
                @if ($scheduleKind)
                    <span class="rounded-md px-2 py-1 text-xs font-semibold" style="background-color: {{ $formatColor }}; color: {{ $formatTextColor }};">
                        {{ __('app.'.$scheduleKind->value) }}
                    </span>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span class="{{ $statusClass }}">{{ __($scheduledClass->displayStatusLabelKey()) }}</span>
            @if (! $readonly && $canManageClassCancellation)
                @if ($canCancelClass)
                    <form
                        method="POST"
                        action="{{ route('dashboard.accounts.scheduled-classes.cancel', [$account, $scheduledClass]) }}"
                        data-async-form
                        data-confirm-action
                        data-confirm-title="{{ __('app.confirm_cancel_scheduled_class_title') }}"
                        data-confirm-body="{{ __('app.confirm_cancel_scheduled_class_body') }}"
                        data-confirm-accept="{{ __('app.cancel_class') }}"
                    >
                        @csrf
                        @method('PATCH')
                        <x-ui.action-button type="submit" variant="danger" icon="ban" :label="__('app.cancel_class')" />
                    </form>
                @elseif ($isCancelledClass)
                    <form
                        method="POST"
                        action="{{ route('dashboard.accounts.scheduled-classes.restore', [$account, $scheduledClass]) }}"
                        data-async-form
                        data-confirm-action
                        data-confirm-title="{{ __('app.confirm_restore_scheduled_class_title') }}"
                        data-confirm-body="{{ __('app.confirm_restore_scheduled_class_body') }}"
                        data-confirm-accept="{{ __('app.restore_class') }}"
                    >
                        @csrf
                        @method('PATCH')
                        <x-ui.action-button type="submit" variant="secondary" icon="rotate-ccw" :label="__('app.restore_class')" />
                    </form>
                @endif
            @endif
        </div>
    </div>

    <dl class="mt-4 grid gap-3 text-sm">
        <div>
            <dt class="text-slate-500">{{ __('app.location') }}</dt>
            <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->location->name }} · {{ $scheduledClass->room?->name ?? __('app.room') }}</dd>
        </div>
        <div>
            <dt class="text-slate-500">{{ __('app.trainer') }}</dt>
            <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</dd>
        </div>
    </dl>

    @if ($activeCancellation)
        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
            <div class="font-semibold">{{ __('app.scheduled_class_cancelled_by_studio') }}</div>
            <div class="mt-1 flex flex-wrap gap-2 text-xs font-semibold">
                <span class="rounded-md bg-white/70 px-2 py-1">{{ trans_choice('app.cancelled_bookings_count', $cancellationBookingsCount, ['count' => $cancellationBookingsCount]) }}</span>
                @if ($releasedReservationsCount > 0)
                    <span class="rounded-md bg-white/70 px-2 py-1">{{ trans_choice('app.released_pass_reservations_count', $releasedReservationsCount, ['count' => $releasedReservationsCount]) }}</span>
                @endif
                @if ($addedSessionsCount > 0)
                    <span class="rounded-md bg-white/70 px-2 py-1">{{ trans_choice('app.added_sessions_count', $addedSessionsCount, ['count' => $addedSessionsCount]) }}</span>
                @endif
                @if ($addedDaysPassesCount > 0)
                    <span class="rounded-md bg-white/70 px-2 py-1">{{ trans_choice('app.extended_passes_days_count', $addedDaysPassesCount, ['passes' => $addedDaysPassesCount, 'days' => $addedDaysCount]) }}</span>
                @endif
            </div>
        </div>
    @endif

    @if (! $readonly)
        @can('manageBookings', $account)
        @unless ($isCancelledClass)
        <form method="POST" action="{{ route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]) }}" data-async-form class="mt-4 space-y-3 rounded-lg bg-slate-50 p-3">
            @csrf
            <div
                class="relative"
                data-customer-autocomplete
                data-search-url="{{ $customerSearchUrl }}"
                data-no-results="{{ __('app.no_customers_found') }}"
            >
                <label class="block">
                    <span class="crm-label">{{ __('app.search_customer') }}</span>
                    <input
                        type="text"
                        class="crm-field"
                        autocomplete="off"
                        placeholder="{{ __('app.customer_search_placeholder') }}"
                        data-customer-autocomplete-input
                    >
                </label>
                <input type="hidden" name="customer_id" data-customer-autocomplete-id>
                <div class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg" data-customer-autocomplete-results></div>
            </div>

            <input name="notes" class="crm-field" placeholder="{{ __('app.notes') }}">
            <x-ui.button type="submit" class="w-full">{{ __('app.add_booking') }}</x-ui.button>
        </form>
        @endunless
        @endcan
    @endif

    @if ($scheduledClass->classBookings->isNotEmpty())
        <div class="mt-4 space-y-2">
            @foreach ($scheduledClass->classBookings as $booking)
                @php
                    $bookingCancellationLocked = $cancellationWindow->isLockedForClass($scheduledClass);
                    $bookingStatusClass = match ($booking->status->value) {
                        'attended' => 'crm-status-active',
                        'cancelled', 'no_show' => 'crm-status-danger',
                        default => 'crm-status-scheduled',
                    };
                @endphp
                <div class="rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                            <div class="mt-1 text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                            @if ($booking->classPassReservation?->customerClassPass && ($booking->classPassReservation->status->value !== 'released' || $isCancelledClass))
                                @php
                                    $reservedPass = $booking->classPassReservation->customerClassPass;
                                @endphp
                                <div class="mt-2 inline-flex flex-wrap items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">
                                    <span>{{ $reservedPass->code }}</span>
                                    <span>{{ $reservedPass->remainingSessionsCount() }} {{ __('app.remaining_sessions_short') }}</span>
                                    <span>{{ __('app.'.$booking->classPassReservation->status->value) }}</span>
                                </div>
                            @elseif (! $isCancelledClass && in_array($booking->status->value, ['booked', 'attended', 'cancelled', 'no_show'], true))
                                <div class="mt-2 inline-flex rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                    {{ __('app.no_matching_class_pass_alert') }}
                                </div>
                            @endif
                            @if ($bookingCancellationLocked)
                                <div class="mt-2 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">
                                    {{ __('app.booking_cancellation_cutoff_marker') }}
                                </div>
                            @endif
                        </div>
                        <span class="{{ $bookingStatusClass }}">{{ __('app.'.$booking->status->value) }}</span>
                    </div>
                    @unless ($isCancelledClass || $readonly)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @can('markAttendance', $account)
                            <form method="POST" action="{{ route('dashboard.accounts.bookings.update', [$account, $booking]) }}" data-async-form class="flex grow gap-2">
                                @csrf
                                @method('PATCH')
                                <select name="status" class="crm-field mt-0 min-w-36">
                                    @foreach ($bookingStatuses as $status)
                                        @continue($bookingCancellationLocked && $status === \App\Enums\ClassBookingStatus::Cancelled && $booking->status !== $status)
                                        <option value="{{ $status->value }}" @selected($booking->status === $status)>{{ __('app.'.$status->value) }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.save') }}</x-ui.button>
                            </form>
                        @endcan
                        @can('manageBookings', $account)
                            @unless ($bookingCancellationLocked)
                            <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-async-form data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                            </form>
                            @endunless
                        @endcan
                    </div>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif
</article>
