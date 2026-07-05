@extends('layouts.app')

@section('title', $account->name.' - '.__('app.app_name'))

@section('content')
    @php
        $activeBookingStatuses = [
            \App\Enums\ClassBookingStatus::Booked->value,
            \App\Enums\ClassBookingStatus::Attended->value,
        ];
        $isTrainerDashboard = $mode === 'trainer';
        $pageTitle = $isTrainerDashboard ? __('app.trainer_dashboard_title') : __('app.studio_dashboard_title');
        $pageCopy = $isTrainerDashboard ? __('app.trainer_dashboard_copy') : __('app.studio_dashboard_copy');
        $now = $isTrainerDashboard ? $trainerDashboard['now'] : $ownerDashboard['now'];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ $account->name }}</div>
            <h1 class="crm-page-title">{{ $pageTitle }}</h1>
            <p class="crm-page-copy">{{ $pageCopy }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white px-4 py-3 text-sm shadow-xs">
            <div class="font-semibold text-slate-950">{{ $now->translatedFormat('l, j F') }}</div>
            <div class="mt-1 text-slate-500">{{ $now->format('H:i') }} · {{ $timezone }}</div>
        </div>
    </div>

    @if ($isTrainerDashboard)
        @if (! $trainer)
            <x-ui.empty-state :title="__('app.trainer_profile_missing_title')" icon="trainers" class="mt-6">
                <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500">{{ __('app.trainer_profile_missing_copy') }}</p>
            </x-ui.empty-state>
        @else
            <section class="mt-6 grid gap-6 xl:grid-cols-2">
                <x-ui.panel padding="none" class="overflow-hidden">
                    <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.today') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.trainer_agenda_for', ['trainer' => $trainer->name]) }}</p>
                        </div>
                        <span class="crm-status-active">{{ $trainerDashboard['todayClasses']->count() }}</span>
                    </div>
                    <div class="grid gap-4 p-5">
                        @forelse ($trainerDashboard['todayClasses'] as $scheduledClass)
                            @include('accounts._dashboard-class-card', [
                                'account' => $account,
                                'scheduledClass' => $scheduledClass,
                                'showRoster' => true,
                                'bookingStatuses' => $trainerDashboard['bookingStatuses'],
                                'activeBookingStatuses' => $activeBookingStatuses,
                            ])
                        @empty
                            <p class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_assigned_classes_today') }}</p>
                        @endforelse
                    </div>
                </x-ui.panel>

                <x-ui.panel padding="none" class="overflow-hidden">
                    <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.tomorrow') }}</h2>
                        <span class="crm-status-scheduled">{{ $trainerDashboard['tomorrowClasses']->count() }}</span>
                    </div>
                    <div class="grid gap-4 p-5">
                        @forelse ($trainerDashboard['tomorrowClasses'] as $scheduledClass)
                            @include('accounts._dashboard-class-card', [
                                'account' => $account,
                                'scheduledClass' => $scheduledClass,
                                'showRoster' => true,
                                'bookingStatuses' => $trainerDashboard['bookingStatuses'],
                                'activeBookingStatuses' => $activeBookingStatuses,
                            ])
                        @empty
                            <p class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_assigned_classes_tomorrow') }}</p>
                        @endforelse
                    </div>
                </x-ui.panel>
            </section>

            <x-ui.panel padding="none" class="mt-6 overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.rest_of_week') }}</h2>
                    <x-ui.icon name="calendar" class="h-5 w-5 text-brand-600" />
                </div>
                @if ($trainerDashboard['weekDays']->isNotEmpty())
                    <div class="grid gap-4 p-5 lg:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($trainerDashboard['weekDays'] as $day)
                            <div class="rounded-xl border border-stone-200 bg-slate-50 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="font-semibold text-slate-950">{{ $day['date']->translatedFormat('l, j F') }}</h3>
                                    <span class="crm-status-muted">{{ $day['classes']->count() }}</span>
                                </div>
                                <div class="mt-4 space-y-3">
                                    @forelse ($day['classes'] as $scheduledClass)
                                        @php
                                            $startsAt = $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone());
                                            $endsAt = $scheduledClass->ends_at->copy()->timezone($scheduledClass->displayTimezone());
                                            $bookingCount = $scheduledClass->classBookings
                                                ->filter(fn ($booking): bool => in_array($booking->status->value, $activeBookingStatuses, true))
                                                ->count();
                                        @endphp
                                        <div class="rounded-lg border border-stone-200 bg-white p-3">
                                            <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
                                            <div class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->title }}</div>
                                            <div class="mt-1 text-sm text-slate-500">{{ $scheduledClass->location?->name }} · {{ $scheduledClass->room?->name ?? __('app.room') }}</div>
                                            <div class="mt-2 text-xs font-semibold text-slate-500">{{ __('app.booked_of_capacity', ['booked' => $bookingCount, 'capacity' => max(0, (int) ($scheduledClass->capacity ?? 0))]) }}</div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-slate-500">{{ __('app.no_assigned_classes_week') }}</p>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="m-5 rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_assigned_classes_week') }}</p>
                @endif
            </x-ui.panel>
        @endif
    @else
        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-ui.metric :label="__('app.active_customer_passes')" :value="$ownerDashboard['metrics']['activePasses']" icon="class-pass-plans" accent="brand" />
            <x-ui.metric :label="__('app.customers')" :value="$ownerDashboard['metrics']['customers']" :meta="__('app.new_customers_7_days', ['count' => $ownerDashboard['metrics']['newCustomers']])" icon="accounts" />
            <x-ui.metric :label="__('app.open_website_leads')" :value="$ownerDashboard['metrics']['openLeads']" :meta="__('app.new_leads_today', ['count' => $ownerDashboard['metrics']['todayNewLeads']])" icon="website-leads" accent="emerald" />
            <x-ui.metric :label="__('app.today_load')" :value="$ownerDashboard['metrics']['todayLoad']['percent'].'%'" :meta="__('app.booked_of_capacity', ['booked' => $ownerDashboard['metrics']['todayLoad']['bookings'], 'capacity' => $ownerDashboard['metrics']['todayLoad']['capacity']])" icon="generated-classes" accent="slate" />
        </section>

        @php
            $peopleCounterRooms = $ownerDashboard['peopleCounterRooms'] ?? collect();
            $peopleCounterTotal = $peopleCounterRooms->sum(fn (array $row): int => (int) ($row['detected_count'] ?? 0));
        @endphp
        @if ($peopleCounterRooms->isNotEmpty())
            <x-ui.panel padding="none" class="mt-6 overflow-hidden" data-people-counter-live-card>
                <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.people_counter_live_title') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('app.people_counter_live_copy') }}</p>
                    </div>
                    <span class="crm-status-active">{{ $peopleCounterTotal }}</span>
                </div>
                <div class="grid gap-4 p-5 lg:grid-cols-2">
                    @foreach ($peopleCounterRooms as $counterRoom)
                        @php
                            $sample = $counterRoom['sample'];
                            $detectedCount = $counterRoom['detected_count'];
                            $sampleStatus = $sample?->status;
                            $statusClass = match ($sampleStatus) {
                                \App\Models\PeopleCounterSample::StatusSucceeded => 'crm-status-active',
                                \App\Models\PeopleCounterSample::StatusCaptureFailed,
                                \App\Models\PeopleCounterSample::StatusDetectionFailed => 'crm-status-danger',
                                default => 'crm-status-muted',
                            };
                            $statusLabel = $sampleStatus
                                ? __('app.people_counter_live_status_'.$sampleStatus)
                                : __('app.people_counter_live_no_capture');
                            $imageGallery = $sample && $counterRoom['image_url'] ? [[
                                'url' => $counterRoom['image_url'],
                                'thumbnail_url' => $counterRoom['image_url'],
                                'title' => $counterRoom['room']->name,
                                'meta' => $counterRoom['captured_at']
                                    ? __('app.people_counter_live_last_updated_at', ['time' => $counterRoom['captured_at']->format('H:i')]).' · '.$counterRoom['timezone']
                                    : '',
                                'alt' => $counterRoom['room']->name,
                            ]] : [];
                        @endphp
                        <article
                            class="grid gap-4 rounded-lg border border-stone-200 bg-white p-4 sm:grid-cols-[7rem_1fr_auto] sm:items-center"
                            data-people-counter-live-room="{{ $counterRoom['room']->id }}:{{ $detectedCount ?? 'none' }}:{{ $sampleStatus ?? 'none' }}"
                        >
                            @if ($counterRoom['image_url'])
                                <x-people-counter.screenshot-trigger
                                    :gallery="$imageGallery"
                                    :thumbnail-url="$counterRoom['image_url']"
                                    :label="__('app.open_screenshot_gallery')"
                                    thumbnail-image-class="h-20 w-full object-cover sm:w-28"
                                />
                            @else
                                <div class="flex h-20 w-full items-center justify-center rounded-lg border border-stone-200 bg-slate-50 text-slate-400 sm:w-28">
                                    <x-ui.icon name="video" class="h-6 w-6" />
                                </div>
                            @endif

                            <div class="min-w-0">
                                <div class="font-semibold text-slate-950">{{ $counterRoom['room']->name }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $counterRoom['location_name'] ?? __('app.not_set') }}</div>
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                                    <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                                    @if ($counterRoom['captured_at'])
                                        <span>{{ __('app.people_counter_live_last_updated_at', ['time' => $counterRoom['captured_at']->format('H:i')]) }}</span>
                                        <span>{{ $counterRoom['timezone'] }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="text-left sm:text-right">
                                <div class="text-3xl font-semibold text-slate-950">{{ $detectedCount ?? '—' }}</div>
                                <div class="mt-1 text-xs font-semibold uppercase text-slate-500">{{ __('app.people_counter_live_count_label') }}</div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </x-ui.panel>
        @endif

        @php
            $problemItems = collect($ownerDashboard['problems'] ?? []);
            $problemAccentClasses = [
                'danger' => 'border-rose-200 bg-rose-50 text-rose-900',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
                'scheduled' => 'border-indigo-200 bg-indigo-50 text-indigo-950',
            ];
        @endphp
        @if ($problemItems->isNotEmpty())
            <x-ui.panel padding="none" class="mt-6 overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.studio_problem_moments') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('app.studio_problem_moments_copy') }}</p>
                    </div>
                    <span class="crm-status-warning">{{ $problemItems->sum('count') }}</span>
                </div>
                <div class="grid gap-3 p-5 md:grid-cols-3 xl:grid-cols-5">
                    @foreach ($problemItems as $problem)
                        <a href="{{ $problem['url'] }}" class="rounded-lg border px-4 py-3 transition hover:-translate-y-0.5 hover:shadow-xs {{ $problemAccentClasses[$problem['accent']] ?? 'border-stone-200 bg-slate-50 text-slate-950' }}">
                            <div class="text-2xl font-semibold">{{ $problem['count'] }}</div>
                            <div class="mt-1 text-sm font-medium">{{ $problem['label'] }}</div>
                        </a>
                    @endforeach
                </div>
            </x-ui.panel>
        @endif

        @if ($ownerDashboard['activeTrainerSubstitutions']->isNotEmpty())
            <x-ui.panel padding="none" class="mt-6 overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.active_trainer_substitutions') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ __('app.active_trainer_substitutions_copy') }}</p>
                    </div>
                    <x-ui.icon name="calendar-range" class="h-5 w-5 text-brand-600" />
                </div>
                <div class="divide-y divide-stone-100">
                    @foreach ($ownerDashboard['activeTrainerSubstitutions'] as $substitution)
                        <div class="grid gap-3 px-5 py-4 md:grid-cols-[1fr_1fr_1fr] md:items-center">
                            <div>
                                <div class="text-sm font-semibold text-slate-950">{{ $substitution->replacedTrainer?->name ?? $substitution->replaced_trainer_name }}</div>
                                <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.replaced_trainer') }}</div>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-950">{{ $substitution->substituteTrainer?->name ?? $substitution->substitute_trainer_name }}</div>
                                <div class="mt-1 text-xs font-medium text-slate-500">{{ __('app.substitute_trainer') }}</div>
                            </div>
                            <div class="text-sm text-slate-600 md:text-right">
                                <div class="font-semibold text-slate-950">
                                    {{ $substitution->date_from->toDateString() }}@if (! $substitution->date_from->isSameDay($substitution->date_to)) - {{ $substitution->date_to->toDateString() }}@endif
                                </div>
                                <div class="mt-1 text-xs font-medium text-slate-500">{{ $substitution->location?->name ?? $substitution->location_name }} · {{ $substitution->room?->name ?? $substitution->room_name }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endif

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.live_today') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('app.live_today_copy') }}</p>
                </div>
                <div class="grid gap-5 p-5">
                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold uppercase text-slate-500">{{ __('app.live_now') }}</h3>
                            <span class="crm-status-active">{{ $ownerDashboard['liveClasses']->count() }}</span>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            @forelse ($ownerDashboard['liveClasses'] as $scheduledClass)
                                @include('accounts._dashboard-class-card', [
                                    'account' => $account,
                                    'scheduledClass' => $scheduledClass,
                                    'showRoster' => false,
                                    'activeBookingStatuses' => $activeBookingStatuses,
                                ])
                            @empty
                                <p class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_live_classes') }}</p>
                            @endforelse
                        </div>
                    </div>

                    <div>
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold uppercase text-slate-500">{{ __('app.next_today') }}</h3>
                            <span class="crm-status-scheduled">{{ $ownerDashboard['nextClasses']->count() }}</span>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            @forelse ($ownerDashboard['nextClasses'] as $scheduledClass)
                                @include('accounts._dashboard-class-card', [
                                    'account' => $account,
                                    'scheduledClass' => $scheduledClass,
                                    'showRoster' => false,
                                    'activeBookingStatuses' => $activeBookingStatuses,
                                ])
                            @empty
                                <p class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ __('app.no_next_classes_today') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-ui.panel>

            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.studio_7_day_outlook') }}</h2>
                    <x-ui.icon name="calendar" class="h-5 w-5 text-brand-600" />
                </div>
                <div class="space-y-4 p-5">
                    @foreach ($ownerDashboard['outlookDays'] as $day)
                        @php
                            $barWidth = min(100, max(0, $day['percent']));
                        @endphp
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $day['date']->translatedFormat('D, j M') }}</div>
                                    <div class="text-xs font-semibold text-slate-500">{{ __('app.classes_bookings_capacity_short', ['classes' => $day['classes'], 'bookings' => $day['bookings'], 'capacity' => $day['capacity']]) }}</div>
                                </div>
                                <span class="crm-status-muted">{{ $day['percent'] }}%</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-stone-100">
                                <div class="h-full rounded-full bg-brand-600" style="width: {{ $barWidth }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-2">
            @foreach ([
                ['title' => __('app.load_by_location'), 'items' => $ownerDashboard['locationLoad'], 'empty' => __('app.no_location_load')],
                ['title' => __('app.load_by_room'), 'items' => $ownerDashboard['roomLoad'], 'empty' => __('app.no_room_load')],
            ] as $loadSection)
                <x-ui.panel padding="none" class="overflow-hidden">
                    <div class="flex items-center justify-between gap-4 border-b border-stone-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ $loadSection['title'] }}</h2>
                        <x-ui.icon name="generated-classes" class="h-5 w-5 text-brand-600" />
                    </div>
                    <div class="space-y-4 p-5">
                        @forelse ($loadSection['items'] as $load)
                            @php
                                $barWidth = min(100, max(0, $load['percent']));
                            @endphp
                            <div class="rounded-xl border border-stone-200 bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $load['name'] ?? __('app.unassigned') }}</div>
                                        @if ($load['secondary'])
                                            <div class="mt-1 text-sm text-slate-500">{{ $load['secondary'] }}</div>
                                        @endif
                                    </div>
                                    <span class="crm-status-muted">{{ $load['percent'] }}%</span>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-stone-100">
                                    <div class="h-full rounded-full bg-brand-600" style="width: {{ $barWidth }}%"></div>
                                </div>
                                <div class="mt-3 text-xs font-semibold text-slate-500">{{ __('app.classes_bookings_capacity_short', ['classes' => $load['classes'], 'bookings' => $load['bookings'], 'capacity' => $load['capacity']]) }}</div>
                            </div>
                        @empty
                            <p class="rounded-lg border border-stone-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ $loadSection['empty'] }}</p>
                        @endforelse
                    </div>
                </x-ui.panel>
            @endforeach
        </section>
    @endif
@endsection
