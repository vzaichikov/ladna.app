@extends('layouts.app')

@section('title', __('app.generated_classes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.generated_classes') }}</h1>
            <p class="crm-page-copy">{{ __('app.generated_classes_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="button" data-class-record-mock-open>
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.add_class_record') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">{{ __('app.schedule_series') }}</x-ui.button>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.generated_classes') }}">
        @foreach ($tabs as $tab => $label)
            <a
                href="{{ route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => $tab, 'locations' => $selectedLocationIds, 'rooms' => $selectedRoomIds]) }}"
                class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $activeTab === $tab ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('dashboard.accounts.scheduled-classes.index', $account) }}" class="mt-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <div class="grid gap-4 lg:grid-cols-2">
            <fieldset>
                <legend class="crm-label">{{ __('app.filter_locations') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($filterLocations as $location)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                            'border-brand-200 bg-brand-50 text-brand-700' => in_array($location->id, $selectedLocationIds, true),
                            'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($location->id, $selectedLocationIds, true),
                        ])>
                            <input type="checkbox" name="locations[]" value="{{ $location->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($location->id, $selectedLocationIds, true))>
                            <span>{{ $location->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="crm-label">{{ __('app.filter_rooms') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($filterRooms as $room)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                            'border-brand-200 bg-brand-50 text-brand-700' => in_array($room->id, $selectedRoomIds, true),
                            'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($room->id, $selectedRoomIds, true),
                        ])>
                            <input type="checkbox" name="rooms[]" value="{{ $room->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($room->id, $selectedRoomIds, true))>
                            <span>{{ $room->location?->name }} · {{ $room->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => $activeTab])" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <section class="mt-6 space-y-8">
        @forelse ($scheduledClassDays as $date => $classes)
            @php
                $firstClass = $classes->first();
                $day = \Illuminate\Support\Carbon::parse($date, $firstClass?->displayTimezone() ?? config('app.timezone'));
                $timelineStartHour = max(0, $classes->map(fn ($scheduledClass) => $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->hour)->min() - 1);
                $timelineEndHour = min(24, $classes->map(function ($scheduledClass): int {
                    $endsAt = $scheduledClass->ends_at->copy()->timezone($scheduledClass->displayTimezone());

                    return $endsAt->minute > 0 || $endsAt->second > 0 ? $endsAt->hour + 1 : $endsAt->hour;
                })->max() + 1);

                if ($timelineEndHour <= $timelineStartHour) {
                    $timelineEndHour = min(24, $timelineStartHour + 2);
                }

                $timelineHours = range($timelineStartHour, $timelineEndHour);
                $timelineTotalMinutes = max(60, ($timelineEndHour - $timelineStartHour) * 60);
            @endphp

            <section>
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <h2 class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-800">
                        {{ $day->translatedFormat('l, j F') }}
                    </h2>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
                        {{ $classes->count() }}
                    </span>
                </div>

                <div class="mb-5 overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
                    <div class="min-w-[720px]">
                        <div class="flex justify-between text-[11px] font-semibold text-slate-500">
                            @foreach ($timelineHours as $hour)
                                <span>{{ sprintf('%02d:00', $hour) }}</span>
                            @endforeach
                        </div>
                        <div class="relative mt-2 h-24 rounded-lg border border-stone-200 bg-slate-50">
                            <div class="absolute left-0 right-0 top-1/2 h-px bg-stone-300"></div>
                            @foreach ($timelineHours as $hour)
                                @php
                                    $tickLeft = (($hour - $timelineStartHour) * 60 / $timelineTotalMinutes) * 100;
                                @endphp
                                <span class="absolute inset-y-0 w-px bg-stone-200" style="left: {{ number_format($tickLeft, 4, '.', '') }}%"></span>
                            @endforeach

                            @foreach ($classes as $scheduledClass)
                                @php
                                    $timelineTimezone = $scheduledClass->displayTimezone();
                                    $timelineStartsAt = \Illuminate\Support\Carbon::parse($date, $timelineTimezone)->setTime($timelineStartHour, 0);
                                    $startsAt = $scheduledClass->starts_at->copy()->timezone($timelineTimezone);
                                    $endsAt = $scheduledClass->ends_at->copy()->timezone($timelineTimezone);
                                    $offsetMinutes = max(0, (int) round(($startsAt->getTimestamp() - $timelineStartsAt->getTimestamp()) / 60));
                                    $durationMinutes = max(15, (int) $startsAt->diffInMinutes($endsAt));
                                    $leftPercent = min(100, max(0, ($offsetMinutes / $timelineTotalMinutes) * 100));
                                    $widthPercent = min(100 - $leftPercent, max(6, ($durationMinutes / $timelineTotalMinutes) * 100));
                                    $timelineTop = 14 + ($loop->index % 2) * 34;
                                @endphp
                                <a
                                    href="#scheduled-class-{{ $scheduledClass->id }}"
                                    class="absolute flex h-8 items-center gap-2 overflow-hidden rounded-lg bg-brand-600 px-2 text-xs font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                                    style="left: {{ number_format($leftPercent, 4, '.', '') }}%; width: {{ number_format($widthPercent, 4, '.', '') }}%; top: {{ $timelineTop }}px;"
                                    title="{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }} · {{ $scheduledClass->title }}"
                                >
                                    <span class="shrink-0">{{ $startsAt->format('H:i') }}</span>
                                    <span class="truncate">{{ $scheduledClass->title }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    @foreach ($classes as $scheduledClass)
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

                        <article id="scheduled-class-{{ $scheduledClass->id }}" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
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
                                <form method="POST" action="{{ route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]) }}" class="mt-4 space-y-3 rounded-lg bg-slate-50 p-3">
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
                                                    <form method="POST" action="{{ route('dashboard.accounts.bookings.update', [$account, $booking]) }}" class="flex grow gap-2">
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
                                                    <form method="POST" action="{{ route('dashboard.accounts.bookings.destroy', [$account, $booking]) }}" data-confirm-delete>
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
                    @endforeach
                </div>
            </section>
        @empty
            <x-ui.empty-state :title="__('app.no_public_classes')" icon="calendar" />
        @endforelse
    </section>

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="class-record-mock-title"
        data-class-record-mock-modal
    >
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                    <x-ui.icon name="calendar-plus" class="h-5 w-5" />
                </div>
                <div>
                    <h2 id="class-record-mock-title" class="text-lg font-semibold text-slate-950">{{ __('app.class_record_mock_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.class_record_mock_body') }}</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <x-ui.button type="button" variant="secondary" data-class-record-mock-close>{{ __('app.cancel') }}</x-ui.button>
            </div>
        </div>
    </div>
@endsection
