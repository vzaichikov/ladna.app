@php
    $timezone = $scheduledClass->displayTimezone();
    $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
    $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
    $statusClass = match ($scheduledClass->status->value) {
        'cancelled' => 'crm-status-danger',
        'draft' => 'crm-status-muted',
        default => 'crm-status-scheduled',
    };
@endphp

<article id="scheduled-class-{{ $scheduledClass->id }}" data-scheduled-class-card data-scheduled-class-id="{{ $scheduledClass->id }}" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
            <h3 class="mt-2 text-lg font-semibold leading-tight text-slate-950">{{ $scheduledClass->title }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ $scheduledClass->classType?->name ?? __('app.class_type') }}</p>
        </div>
        <span class="{{ $statusClass }}">{{ __('app.'.$scheduledClass->status->value) }}</span>
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

    @can('manageBookings', $account)
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
    @endcan

    @if ($scheduledClass->classBookings->isNotEmpty())
        <div class="mt-4 space-y-2">
            @foreach ($scheduledClass->classBookings as $booking)
                @php
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
                        </div>
                        <span class="{{ $bookingStatusClass }}">{{ __('app.'.$booking->status->value) }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @can('markAttendance', $account)
                            <form method="POST" action="{{ route('dashboard.accounts.bookings.update', [$account, $booking]) }}" data-async-form class="flex grow gap-2">
                                @csrf
                                @method('PATCH')
                                <select name="status" class="crm-field mt-0 min-w-36">
                                    @foreach ($bookingStatuses as $status)
                                        <option value="{{ $status->value }}" @selected($booking->status === $status)>{{ __('app.'.$status->value) }}</option>
                                    @endforeach
                                </select>
                                <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.save') }}</x-ui.button>
                            </form>
                        @endcan
                        @can('manageBookings', $account)
                            <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-async-form data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</article>
