@php
    $timezone = $scheduledClass->displayTimezone();
    $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
    $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
    $displayTitle = $scheduledClass->displayTitle();
    $statusClass = $scheduledClass->displayStatusBadgeClass();
    $scheduleKind = $scheduledClass->classType?->schedule_kind;
    $isGroupClass = $scheduleKind === \App\Enums\ScheduleKind::GroupClass;
    $isRoomRental = $scheduleKind === \App\Enums\ScheduleKind::RoomRental;
    $additionalTrainers = $scheduledClass->relationLoaded('additionalTrainers')
        ? $scheduledClass->additionalTrainers
        : collect();
    $acceptsCustomerBookings = $scheduledClass->acceptsCustomerBookings();
    $activeBookingStatuses = [
        \App\Enums\ClassBookingStatus::Booked->value,
        \App\Enums\ClassBookingStatus::Attended->value,
    ];
    $activeBookings = $acceptsCustomerBookings
        ? $scheduledClass->classBookings->filter(
            fn ($booking): bool => ! $booking->isCorrectedRemoved() && in_array($booking->status->value, $activeBookingStatuses, true),
        )
        : collect();
    $capacity = max(0, (int) ($scheduledClass->capacity ?? 0));
    $loadPercent = $capacity > 0 ? (int) round(($activeBookings->count() / $capacity) * 100) : 0;
    $barWidth = min(100, max(0, $loadPercent));
    $displayTypeLabels = $scheduledClass->displayTypeLabels();
    $directionColor = $isRoomRental
        ? $scheduledClass->room?->colorAccent($scheduledClass->classType?->colorAccent('#3B223F') ?? '#3B223F')
        : ($scheduledClass->classType?->colorAccent($scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F') ?? '#3B223F');
    $formatColor = $account->scheduleKindColor($scheduleKind);
    $formatTextColor = $account->scheduleKindTextColor($scheduleKind);
    $cancellationWindow = app(\App\Support\ClassBookingCancellationWindow::class);
    $isCancelledClass = $scheduledClass->status === \App\Enums\ScheduledClassStatus::Cancelled;
    $activeCancellation = $scheduledClass->activeCancellation;
    $isClosedCorrectionCancellation = $activeCancellation?->isClosedCorrection() ?? false;
    $cancellationEffects = $activeCancellation?->effects ?? collect();
    $cancellationBookingsCount = $cancellationEffects->count();
    $releasedReservationsCount = $cancellationEffects->where('new_reservation_status', \App\Enums\CustomerClassPassReservationStatus::Released->value)->count();
    $addedSessionsCount = $cancellationEffects->sum('added_sessions_count');
    $addedDaysPassesCount = $cancellationEffects->where('added_validity_days', '>', 0)->count();
    $addedDaysCount = (int) ($cancellationEffects->max('added_validity_days') ?? 0);
    $readonly = $readonly ?? false;
    $trainerOptions = $trainerOptions ?? collect();
    $trainerChanges = $scheduledClass->relationLoaded('trainerChanges') ? $scheduledClass->trainerChanges : collect();
    $canEditScheduledClassTrainer = (auth()->user()?->can('manageSchedule', $account) ?? false)
        && $scheduledClass->canManuallyCorrectTrainer()
        && $trainerOptions->isNotEmpty();
    $trainerOptionAllowsInactive = $scheduledClass->ends_at->lessThanOrEqualTo(now());
    $canManageClassCancellation = auth()->user()?->can('manageSchedule', $account) && auth()->user()?->can('manageBookings', $account);
    $canCancelClass = $canManageClassCancellation && ! $isCancelledClass && $scheduledClass->isStudioCancellationOpen();
    $canRestoreClass = $canManageClassCancellation && $isCancelledClass && ! $isClosedCorrectionCancellation;
    $isClosedClass = ! $isCancelledClass && $scheduledClass->ends_at->lessThanOrEqualTo(now());
    $canCorrectClosedClass = $acceptsCustomerBookings
        && (auth()->user()?->can('correctClosedClasses', $account) ?? false)
        && $isClosedClass;
    $canOpenCustomerPage = auth()->user()?->can('manageClients', $account) ?? false;
    $canEditInternalClass = ! $readonly
        && $account->hasScheduleKindEnabled(\App\Enums\ScheduleKind::InternalClass)
        && (auth()->user()?->can('manageSchedule', $account) ?? false)
        && $scheduledClass->isFullyEditableOccurrence();
    $classBorderColor = $isCancelledClass ? '#94A3B8' : $directionColor;
@endphp

<article
    id="scheduled-class-{{ $scheduledClass->id }}"
    data-scheduled-class-card
    data-scheduled-class-id="{{ $scheduledClass->id }}"
    @class([
        'scroll-mt-24 rounded-xl border p-4 shadow-xs',
        'border-stone-200 bg-white' => ! $isCancelledClass,
        'border-slate-300 bg-slate-50' => $isCancelledClass,
    ])
    style="border-top-color: {{ $classBorderColor }}; border-top-width: 4px; border-right-color: {{ $formatColor }}; border-right-width: 4px;"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div @class(['text-sm font-semibold', 'text-brand-600' => ! $isCancelledClass, 'text-slate-500 line-through' => $isCancelledClass])>{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
            <h3 @class(['mt-2 text-lg font-semibold leading-tight', 'text-slate-950' => ! $isCancelledClass, 'text-slate-500 line-through' => $isCancelledClass])>{{ $displayTitle }}</h3>
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
            @if ($canEditInternalClass)
                <x-ui.action-button
                    type="button"
                    icon="edit"
                    :label="__('app.edit_internal_class')"
                    data-manual-class-open="internal-edit-{{ $scheduledClass->id }}"
                />
            @endif
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
                @elseif ($canRestoreClass)
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
            <dt class="text-slate-500">{{ $scheduleKind === \App\Enums\ScheduleKind::InternalClass ? __('app.main_trainer') : __('app.trainer') }}</dt>
            <dd class="mt-1 flex items-center gap-2 font-semibold text-slate-950">
                <span>{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</span>
                @if ($canEditScheduledClassTrainer)
                    <x-ui.action-button
                        type="button"
                        variant="ghost"
                        icon="pencil"
                        :label="__('app.edit_scheduled_class_trainer')"
                        data-scheduled-class-trainer-open="{{ $scheduledClass->id }}"
                    />
                @endif
            </dd>
            @if ($additionalTrainers->isNotEmpty())
                <dd class="mt-2 flex flex-wrap gap-2">
                    @foreach ($additionalTrainers as $additionalTrainer)
                        <span class="crm-status-muted">{{ $additionalTrainer->name }}</span>
                    @endforeach
                </dd>
            @endif
        </div>
    </dl>

    @if ($isGroupClass)
        <div class="mt-4">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-semibold text-slate-700">{{ __('app.booked_capacity') }}</span>
                <span class="font-semibold text-slate-950">{{ __('app.booked_of_capacity', ['booked' => $activeBookings->count(), 'capacity' => $capacity]) }}</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100">
                <div class="h-full rounded-full bg-brand-600" style="width: {{ $barWidth }}%"></div>
            </div>
        </div>
    @endif

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

    @if (! $readonly && $acceptsCustomerBookings)
        @can('manageBookings', $account)
        @unless ($isCancelledClass || $isClosedClass)
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

    @if ($canCorrectClosedClass)
        <details class="mt-4 text-sm">
            <summary class="inline-flex w-fit cursor-pointer items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-semibold text-rose-900 transition hover:bg-rose-100">
                {{ __('app.unlock_closed_class_corrections') }}
            </summary>
            <div class="mt-3 space-y-4 rounded-lg border border-rose-200 bg-rose-50 p-3">
                <div class="rounded-md bg-white/70 p-3 text-rose-900">{{ __('app.closed_class_correction_warning') }}</div>

                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.scheduled-classes.cancel-closed', [$account, $scheduledClass]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_cancel_closed_class_title') }}"
                    data-confirm-body="{{ __('app.confirm_cancel_closed_class_body') }}"
                    data-confirm-accept="{{ __('app.cancel_closed_class') }}"
                    data-confirm-icon="triangle-alert"
                    data-confirm-variant="danger"
                    class="space-y-3 rounded-lg border border-rose-100 bg-white p-3"
                >
                    @csrf
                    @method('PATCH')
                    <div>
                        <div class="font-semibold text-slate-950">{{ __('app.cancel_closed_class') }}</div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ __('app.cancel_closed_class_warning') }}</p>
                    </div>
                    <label class="block">
                        <span class="crm-label">{{ __('app.pass_effect') }}</span>
                        <select name="pass_effect" class="crm-field">
                            <option value="{{ \App\Models\ScheduledClassCancellation::PassEffectReturnSession }}">{{ __('app.pass_effect_return_session') }}</option>
                            <option value="{{ \App\Models\ScheduledClassCancellation::PassEffectKeepConsumed }}">{{ __('app.pass_effect_keep_consumed') }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.reason') }}</span>
                        <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.closed_class_cancellation_reason_placeholder') }}"></textarea>
                    </label>
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold leading-5 text-amber-900">{{ __('app.cancel_closed_class_cash_warning') }}</div>
                    <x-ui.button type="submit" variant="danger" size="sm" class="w-fit">{{ __('app.cancel_closed_class') }}</x-ui.button>
                </form>

                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.scheduled-classes.corrections.bookings.store', [$account, $scheduledClass]) }}"
                    data-async-form
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_closed_class_correction_title') }}"
                    data-confirm-body="{{ __('app.confirm_closed_class_add_body') }}"
                    data-confirm-accept="{{ __('app.apply_correction') }}"
                    data-confirm-icon="triangle-alert"
                    data-confirm-variant="danger"
                    data-class-pass-preview-url="{{ route('dashboard.accounts.scheduled-classes.corrections.pass-preview', [$account, $scheduledClass]) }}"
                    class="space-y-3 rounded-lg border border-rose-100 bg-white p-3"
                >
                    @csrf
                    <div class="font-semibold text-slate-950">{{ __('app.add_correct_customer') }}</div>
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
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600" data-class-pass-preview>
                        {{ __('app.closed_class_correction_pass_preview_empty') }}
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="crm-label">{{ __('app.booking_status') }}</span>
                            <select name="status" class="crm-field">
                                @foreach ($bookingStatuses as $status)
                                    <option value="{{ $status->value }}" @selected($status === \App\Enums\ClassBookingStatus::Attended)>{{ __('app.'.$status->value) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="crm-label">{{ __('app.notes') }}</span>
                            <input name="notes" class="crm-field" placeholder="{{ __('app.notes') }}">
                        </label>
                    </div>
                    <label class="block">
                        <span class="crm-label">{{ __('app.reason') }}</span>
                        <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.closed_class_correction_reason_placeholder') }}"></textarea>
                    </label>
                    <x-ui.button type="submit" variant="danger" size="sm" class="w-fit">{{ __('app.add_correct_customer') }}</x-ui.button>
                </form>

                @if ($scheduledClass->classBookings->isNotEmpty())
                    <div class="space-y-3">
                        <div class="font-semibold text-slate-950">{{ __('app.remove_wrong_customer') }}</div>
                        @foreach ($scheduledClass->classBookings as $booking)
                            <form
                                method="POST"
                                action="{{ route('dashboard.accounts.bookings.corrections.remove', [$account, $booking]) }}"
                                data-async-form
                                data-confirm-action
                                data-confirm-title="{{ __('app.confirm_closed_class_correction_title') }}"
                                data-confirm-body="{{ __('app.confirm_closed_class_remove_body') }}"
                                data-confirm-accept="{{ __('app.apply_correction') }}"
                                data-confirm-icon="triangle-alert"
                                data-confirm-variant="danger"
                                class="space-y-3 rounded-lg border border-rose-100 bg-white p-3"
                            >
                                @csrf
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                                    @if ($booking->manualCashPayment)
                                        <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">{{ __('app.linked_cash_payment_unchanged') }}</span>
                                    @endif
                                </div>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.pass_effect') }}</span>
                                    <select name="pass_effect" class="crm-field">
                                        <option value="{{ \App\Models\ClassBookingCorrection::PassEffectReturnSession }}">{{ __('app.pass_effect_return_session') }}</option>
                                        <option value="{{ \App\Models\ClassBookingCorrection::PassEffectKeepConsumed }}">{{ __('app.pass_effect_keep_consumed') }}</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.reason') }}</span>
                                    <textarea name="reason" rows="3" class="crm-field" required placeholder="{{ __('app.closed_class_correction_reason_placeholder') }}"></textarea>
                                </label>
                                <x-ui.button type="submit" variant="danger" size="sm" class="w-fit">{{ __('app.remove_wrong_customer') }}</x-ui.button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>
    @endif

    @if ($acceptsCustomerBookings && $scheduledClass->classBookings->isNotEmpty())
        <div class="mt-4 space-y-2">
            @foreach ($scheduledClass->classBookings as $booking)
                @php
                    $bookingCancellationLocked = $cancellationWindow->isLockedForClass($scheduledClass);
                    $canRemoveBooking = (bool) (request()->user()?->can(
                        $bookingCancellationLocked ? 'correctClosedClasses' : 'manageBookings',
                        $account,
                    ) ?? false);
                    $bookingStatusClass = match ($booking->status->value) {
                        'attended' => 'crm-status-active',
                        'cancelled', 'no_show' => 'crm-status-danger',
                        default => 'crm-status-scheduled',
                    };
                    $reservedPass = $booking->classPassReservation?->customerClassPass;
                    $hasActivePassReservation = $reservedPass
                        && in_array($booking->classPassReservation->status->value, ['reserved', 'used'], true);
                    $anyTimeAddonAmountCents = $hasActivePassReservation
                        ? $reservedPass->anyTimeAddonAmountCentsFor($scheduledClass)
                        : null;
                    $hasAnyTimeAddonPayment = $anyTimeAddonAmountCents !== null && $anyTimeAddonAmountCents > 0;
                    $manualCashPayment = $booking->manualCashPayment;
                    $hasUnpaidRequiredManualPayment = ! $manualCashPayment
                        && ! $isCancelledClass
                        && in_array($booking->status->value, ['booked', 'attended'], true)
                        && (
                            ($isRoomRental && ! $hasActivePassReservation)
                            || $hasAnyTimeAddonPayment
                        );
                @endphp
                <div class="rounded-lg border border-slate-200 p-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                                @if ($canOpenCustomerPage)
                                    <x-ui.action-button
                                        :href="route('dashboard.accounts.customers.edit', [$account, $booking->customer])"
                                        target="_blank"
                                        rel="noopener"
                                        variant="ghost"
                                        icon="external-link"
                                        :label="__('app.open_customer')"
                                    />
                                @endif
                            </div>
                            <div class="mt-1 text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                            @if ($reservedPass && ($booking->classPassReservation->status->value !== 'released' || $isCancelledClass))
                                <div class="mt-2 inline-flex flex-wrap items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">
                                    <span>{{ $reservedPass->code }}</span>
                                    <span>{{ $reservedPass->remainingSessionsCount() }} {{ __('app.remaining_sessions_short') }}</span>
                                    <span>{{ __('app.'.$booking->classPassReservation->status->value) }}</span>
                                    @if ($hasAnyTimeAddonPayment)
                                        <span>+ {{ \App\Support\MoneyFormatter::format($anyTimeAddonAmountCents, $reservedPass->currency) }} {{ __('app.any_time_addon_summary') }}</span>
                                    @endif
                                </div>
                            @elseif (! $isCancelledClass && in_array($booking->status->value, ['booked', 'attended', 'cancelled', 'no_show'], true))
                                @if (! $booking->skip_class_pass_reservation)
                                <div class="mt-2 inline-flex rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                    {{ __('app.no_matching_class_pass_alert') }}
                                </div>
                                @else
                                    <div class="mt-2 inline-flex rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-xs font-semibold text-sky-800">
                                        {{ __('app.direct_rental_payment') }}
                                    </div>
                                @endif
                            @endif
                            @if ($bookingCancellationLocked)
                                <div class="mt-2 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">
                                    {{ __('app.booking_cancellation_cutoff_marker') }}
                                </div>
                            @endif
                            @if ($hasUnpaidRequiredManualPayment)
                                <div class="mt-2 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">
                                    {{ __('app.unpaid_class_booking_payment_alert') }}
                                </div>
                            @endif
                        </div>
                        <span class="{{ $bookingStatusClass }}">{{ __('app.'.$booking->status->value) }}</span>
                    </div>
                    @php
                        $canRecordBookingPayment = ! $readonly
                            && ! $isCancelledClass
                            && ! $isClosedClass
                            && (
                                ($isRoomRental && ! $hasActivePassReservation)
                                || ($hasAnyTimeAddonPayment && ! $manualCashPayment)
                            )
                            && in_array($booking->status->value, ['booked', 'attended'], true);
                        $bookingPaymentValue = $manualCashPayment
                            ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) $manualCashPayment->amount_cents)
                            : ($hasAnyTimeAddonPayment ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) $anyTimeAddonAmountCents) : '');
                    @endphp
                    @if (($isRoomRental || $hasAnyTimeAddonPayment) && $manualCashPayment)
                        <div class="mt-3 inline-flex rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800">
                            {{ $hasAnyTimeAddonPayment ? __('app.any_time_addon_paid') : __('app.class_booking_payment') }}: {{ \App\Support\MoneyFormatter::format($manualCashPayment->amount_cents, $manualCashPayment->currency) }}
                        </div>
                    @elseif ($hasAnyTimeAddonPayment)
                        <div class="mt-3 inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">
                            {{ __('app.any_time_addon_due') }}: {{ \App\Support\MoneyFormatter::format($anyTimeAddonAmountCents, $reservedPass?->currency ?? $account->default_currency) }}
                        </div>
                    @endif
                    @if ($canRecordBookingPayment)
                        <form method="POST" action="{{ route('dashboard.accounts.bookings.payment.store', [$account, $booking]) }}" data-async-form class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-sky-100 bg-sky-50 p-3">
                            @csrf
                            <label class="min-w-40 grow">
                                <span class="crm-label">{{ $hasAnyTimeAddonPayment ? __('app.any_time_addon_price') : __('app.class_booking_payment_amount') }}</span>
                                <input
                                    name="amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputmode="decimal"
                                    value="{{ $bookingPaymentValue }}"
                                    class="crm-field"
                                    placeholder="0.00"
                                    @readonly($hasAnyTimeAddonPayment)
                                >
                            </label>
                            <x-ui.button type="submit" variant="secondary" size="sm">{{ $hasAnyTimeAddonPayment ? __('app.record_any_time_addon_payment') : ($manualCashPayment ? __('app.update_payment') : __('app.record_payment')) }}</x-ui.button>
                        </form>
                    @endif
                    @unless ($isCancelledClass || $readonly || $isClosedClass)
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
                        @if ($canRemoveBooking)
                            <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-async-form data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                            </form>
                        @endif
                    </div>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif
    @if ($canEditScheduledClassTrainer)
        <div
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4"
            data-scheduled-class-trainer-modal="{{ $scheduledClass->id }}"
            role="dialog"
            aria-modal="true"
            aria-labelledby="scheduled-class-trainer-title-{{ $scheduledClass->id }}"
        >
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="scheduled-class-trainer-title-{{ $scheduledClass->id }}" class="text-xl font-semibold text-slate-950">
                            {{ __('app.edit_scheduled_class_trainer') }}
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.edit_scheduled_class_trainer_copy') }}</p>
                    </div>
                    <x-ui.action-button
                        type="button"
                        variant="ghost"
                        icon="x"
                        :label="__('app.close')"
                        data-scheduled-class-trainer-close
                    />
                </div>

                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.scheduled-classes.trainer.update', [$account, $scheduledClass]) }}"
                    data-async-form
                    class="mt-5 space-y-4"
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="readonly" value="{{ $readonly ? 1 : 0 }}">
                    <div
                        class="hidden"
                        data-async-form-status
                        data-validation-message="{{ __('app.async_validation_failed') }}"
                        data-error-message="{{ __('app.async_request_failed') }}"
                    ></div>
                    <label class="block">
                        <span class="crm-label">{{ __('app.trainer') }}</span>
                        <select name="trainer_id" class="crm-field" required data-scheduled-class-trainer-select>
                            <option value="" disabled @selected($scheduledClass->trainer_id === null)>{{ __('app.choose_trainer') }}</option>
                            @foreach ($trainerOptions as $trainerOption)
                                @continue(! $trainerOptionAllowsInactive && ! $trainerOption->is_active)
                                <option value="{{ $trainerOption->id }}" @selected($scheduledClass->trainer_id === $trainerOption->id)>
                                    {{ $trainerOption->name }}@if (! $trainerOption->is_active) · {{ __('app.inactive') }}@endif
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" data-scheduled-class-trainer-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                    </div>
                </form>

                <section class="mt-6 border-t border-stone-200 pt-5">
                    <h3 class="text-sm font-semibold text-slate-950">{{ __('app.trainer_change_history') }}</h3>
                    @if ($trainerChanges->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">{{ __('app.no_trainer_change_history') }}</p>
                    @else
                        <ol class="mt-3 space-y-3">
                            @foreach ($trainerChanges as $trainerChange)
                                <li class="rounded-xl border border-stone-200 bg-slate-50 p-3 text-sm">
                                    <div class="font-semibold text-slate-950">
                                        {{ $trainerChange->previous_trainer_name ?? __('app.trainer_not_assigned') }}
                                        <span aria-hidden="true">→</span>
                                        {{ $trainerChange->new_trainer_name ?? __('app.trainer_not_assigned') }}
                                    </div>
                                    <div class="mt-1 text-xs leading-5 text-slate-500">
                                        <time datetime="{{ $trainerChange->created_at->toIso8601String() }}">
                                            {{ \App\Support\DateTimePresenter::format($trainerChange->created_at, $account, 'd.m.Y H:i') }}
                                        </time>
                                        · {{ __('app.changed_by') }} {{ $trainerChange->actor_name ?? $trainerChange->actor_email ?? __('app.system') }}
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>
        </div>
    @endif
</article>
