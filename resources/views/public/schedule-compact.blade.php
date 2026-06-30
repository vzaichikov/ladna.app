@extends('layouts.public')

@section('title', $account->name.' '.$location->name.' '.strtolower(__('app.schedule')))

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-4xl bg-canvas px-4 pb-6 sm:px-6" />
@endsection

@section('content')
    @php
        $compact = $compactSchedule;
        $selectedManualKind = $compact['selectedManualKind'];
        $selectedQuery = $compact['selectedQuery'];
        $manualQuery = $compact['manualQuery'];
        $selectedClassType = $compact['classTypes']->firstWhere('id', $compact['selectedClassTypeId']);
        $selectedTrainer = $compact['trainers']->firstWhere('id', $compact['selectedTrainerId']);
        $selectedRoom = $compact['rooms']->firstWhere('id', $compact['selectedRoomId']);
        $selectedManualClassType = $compact['manualClassTypes']->firstWhere('id', $compact['selectedManualClassTypeId']);
        $selectedManualTrainer = $compact['trainers']->firstWhere('id', $compact['selectedManualTrainerId']);
        $selectedManualRoom = $compact['rooms']->firstWhere('id', $compact['selectedManualRoomId']);
        $routeName = $isEmbed ? 'public.schedule.embed' : 'public.schedule';
        $routeParams = ['accountSlug' => $account->slug, 'locationSlug' => $location->slug];
        $compactUrl = static fn (array $query): string => route($routeName, [...$routeParams, ...array_filter($query, fn ($value) => $value !== null && $value !== '')]);
        $withoutQuery = static fn (array $query, string $key): array => array_diff_key($query, [$key => true]);
        $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-4xl px-4 sm:px-6 {{ $isEmbed ? 'py-3' : 'py-4' }}">
            <header class="border-b border-stone-200 pb-3">
                <div class="flex items-start gap-3">
                    @if ($account->logo_path)
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-white shadow-xs">
                            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-8 max-w-8 object-contain">
                        </span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <h1 class="text-xl font-semibold leading-tight text-slate-950 sm:text-2xl">{{ $location->name }}</h1>
                        @if ($location->address)
                            <p class="mt-1 text-sm leading-5 text-slate-500">{{ $location->address }}</p>
                        @endif
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if ($customer)
                        <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                            <x-ui.icon name="user" class="h-3.5 w-3.5" />
                            {{ __('app.public_schedule_logged_in_as', ['name' => $customerDisplayName ?? __('app.customer_section')]) }}
                        </span>
                        <a href="{{ route('customer.dashboard', $account->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                            <x-ui.icon name="layout-dashboard" class="h-3.5 w-3.5" />
                            {{ __('app.customer_portal') }}
                        </a>
                    @else
                        <a href="{{ route('customer.studio.login', $account->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                            <x-ui.icon name="log-in" class="h-3.5 w-3.5" />
                            {{ __('app.customer_login') }}
                        </a>
                    @endif

                    <form method="POST" action="{{ route('locale.update') }}">
                        @csrf
                        <select name="locale" onchange="this.form.submit()" class="rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                            @foreach (config('ladna.locales') as $locale => $label)
                                <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </header>

            @if (session('status'))
                <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @fragment('schedule-results')
                <div data-public-schedule-fragment data-public-schedule-loading="{{ __('app.loading') }}">
                    @if ($compact['manualActionOptions'] !== [])
                        <nav class="mt-3 grid gap-2 sm:grid-cols-2" aria-label="{{ __('app.public_booking_services') }}">
                            @foreach ($compact['manualActionOptions'] as $manualAction)
                                <a
                                    href="{{ $manualAction['url'] }}"
                                    data-public-schedule-link
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold transition {{ $manualAction['active'] ? 'border-violet-crm-600 bg-violet-crm-600 text-white shadow-sm shadow-violet-crm-600/20' : 'border-violet-crm-500 bg-violet-crm-500 text-white shadow-sm shadow-violet-crm-500/20 hover:bg-brand-600' }}"
                                >
                                    <x-ui.icon :name="$manualAction['icon']" class="h-4 w-4" />
                                    {{ $manualAction['label'] }}
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    @if ($selectedManualKind)
                        <section class="mt-3 rounded-xl border border-violet-crm-100 bg-white p-3 shadow-xs">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold text-slate-950">{{ __('app.public_booking_'.$selectedManualKind->value.'_cta') }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ __('app.public_booking_service_help') }}</p>
                                </div>
                                <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'kind')) }}" data-public-schedule-link class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('app.close') }}">
                                    <x-ui.icon name="close" class="h-4 w-4" />
                                </a>
                            </div>

                            <div class="mt-3 grid gap-2 md:grid-cols-3">
                                <details class="group rounded-lg border border-stone-200 bg-slate-50">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                        <span class="min-w-0">
                                            <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_class_type') }}</span>
                                            <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.class_type') }}: {{ $selectedManualClassType?->name ?? __('app.any_option') }}</span>
                                        </span>
                                        <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                                    </summary>
                                    <div class="space-y-2 border-t border-stone-200 p-2">
                                        <a href="{{ $compactUrl($withoutQuery($manualQuery, 'class_type')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedManualClassTypeId'] ? 'text-slate-700 hover:bg-white' : 'bg-violet-crm-600 text-white' }}">
                                            {{ __('app.any_option') }}
                                        </a>
                                        @foreach ($compact['manualClassTypeOptions'] as $option)
                                            <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-white' }}">
                                                {{ $option['name'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </details>

                                @if ($selectedManualKind === \App\Enums\ScheduleKind::PrivateLesson)
                                    <details class="group rounded-lg border border-stone-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                            <span class="min-w-0">
                                                <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_trainer') }}</span>
                                                <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.trainer') }}: {{ $selectedManualTrainer?->name ?? __('app.any_option') }}</span>
                                            </span>
                                            <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                                        </summary>
                                        <div class="space-y-2 border-t border-stone-200 p-2">
                                            <a href="{{ $compactUrl($withoutQuery($manualQuery, 'trainer')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedManualTrainerId'] ? 'text-slate-700 hover:bg-white' : 'bg-violet-crm-600 text-white' }}">
                                                {{ __('app.any_option') }}
                                            </a>
                                            @foreach ($compact['manualTrainerOptions'] as $option)
                                                @php
                                                    $optionTrainer = $compact['trainers']->firstWhere('id', $option['id']);
                                                @endphp
                                                <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-white' }}">
                                                    @if ($optionTrainer?->photoUrl())
                                                        <img src="{{ $optionTrainer->photoUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                                    @else
                                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-violet-crm-100 text-violet-crm-700">
                                                            <x-ui.icon name="trainers" class="h-4 w-4" />
                                                        </span>
                                                    @endif
                                                    <span class="min-w-0 flex-1 truncate">{{ $option['name'] }}</span>
                                                    @if ($optionTrainer?->trainerType)
                                                        <x-ui.trainer-type-badge :trainer-type="$optionTrainer->trainerType" class="{{ $option['active'] ? 'border-white/30 bg-white/15 text-white' : '' }}" />
                                                    @endif
                                                </a>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                <details class="group rounded-lg border border-stone-200 bg-slate-50">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                        <span class="min-w-0">
                                            <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_room') }}</span>
                                            <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.room') }}: {{ $selectedManualRoom?->name ?? __('app.any_option') }}</span>
                                        </span>
                                        <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                                    </summary>
                                    <div class="space-y-2 border-t border-stone-200 p-2">
                                        <a href="{{ $compactUrl($withoutQuery($manualQuery, 'room')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedManualRoomId'] ? 'text-slate-700 hover:bg-white' : 'bg-violet-crm-600 text-white' }}">
                                            {{ __('app.any_option') }}
                                        </a>
                                        @foreach ($compact['manualRoomOptions'] as $option)
                                            <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-white' }}">
                                                {{ $option['name'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </details>
                            </div>

                            <div class="mt-3">
                                @if ($compact['manualRequiredFilters'] !== [])
                                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                        {{ __('app.public_booking_choose_filters', ['filters' => implode(', ', $compact['manualRequiredFilters'])]) }}
                                    </div>
                                @elseif (($compact['manualAvailability']['closed'] ?? false) === true)
                                    <div class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600">
                                        {{ __('app.studio_closed_on_date') }}
                                    </div>
                                @else
                                    <div class="flex flex-wrap gap-2">
                                        @forelse (($compact['manualAvailability']['slots'] ?? []) as $slot)
                                            @php
                                                $bookingParams = array_filter([
                                                    'accountSlug' => $account->slug,
                                                    'locationSlug' => $location->slug,
                                                    'schedule_kind' => $selectedManualKind->value,
                                                    'date' => $compact['selectedDate']->toDateString(),
                                                    'starts_at' => $slot['starts_at'],
                                                    'class_type_id' => $selectedManualClassType?->id,
                                                    'trainer_id' => $selectedManualTrainer?->id,
                                                    'room_id' => $selectedManualRoom?->id,
                                                ], fn ($value) => $value !== null && $value !== '');
                                                $bookingUrl = route('public.booking.show', $bookingParams);
                                            @endphp
                                            <a href="{{ $bookingUrl }}" class="inline-flex min-h-12 items-center gap-3 rounded-lg border border-violet-crm-100 bg-violet-crm-50 px-3 py-2 text-sm font-semibold text-violet-crm-800 transition hover:border-violet-crm-300 hover:bg-violet-crm-100">
                                                <span class="text-base text-slate-950">{{ $slot['time'] }}</span>
                                                <span class="text-xs text-slate-500">{{ $slot['ends_time'] }} · {{ __('app.available_slots') }}</span>
                                            </a>
                                        @empty
                                            <div class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600">
                                                {{ __('app.no_available_manual_slots') }}
                                            </div>
                                        @endforelse
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endif

                    @if ($compact['monthOptions'] !== [])
                        <nav class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0" aria-label="{{ __('app.schedule_months') }}">
                            @foreach ($compact['monthOptions'] as $monthOption)
                                <a
                                    href="{{ $monthOption['url'] }}"
                                    data-public-schedule-link
                                    class="flex h-11 min-w-28 shrink-0 flex-col items-center justify-center rounded-lg border px-4 text-center transition {{ $monthOption['active'] ? 'border-violet-crm-600 bg-violet-crm-600 text-white shadow-sm shadow-violet-crm-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-violet-crm-200' }}"
                                >
                                    <span class="text-sm font-semibold leading-none">{{ $monthOption['label'] }}</span>
                                    <span class="mt-1 text-[11px] font-semibold leading-none {{ $monthOption['active'] ? 'text-white/80' : 'text-slate-500' }}">{{ $monthOption['year'] }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    <nav class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0" aria-label="{{ __('app.schedule_dates') }}">
                        @foreach ($compact['dateOptions'] as $dateOption)
                            <a
                                href="{{ $dateOption['url'] }}"
                                data-public-schedule-link
                                class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-lg border text-center transition {{ $dateOption['active'] ? 'border-violet-crm-600 bg-violet-crm-600 text-white shadow-sm shadow-violet-crm-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-violet-crm-200' }}"
                            >
                                <span class="text-[11px] font-semibold leading-none {{ $dateOption['active'] ? 'text-white/80' : 'text-slate-500' }}">{{ $dateOption['label'] }}</span>
                                <span class="mt-1 text-lg font-semibold leading-none">{{ $dateOption['day'] }}</span>
                            </a>
                        @endforeach
                    </nav>

                    <section class="mt-3 grid gap-2 md:grid-cols-3">
                        <details class="group rounded-lg border border-stone-200 bg-white shadow-xs">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_class_type') }}</span>
                                    <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.class_type') }}: {{ $selectedClassType?->name ?? __('app.any_option') }}</span>
                                </span>
                                <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                            </summary>
                            <div class="space-y-2 border-t border-stone-100 p-2">
                                <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_class_type')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedClassTypeId'] ? 'text-slate-700 hover:bg-slate-50' : 'bg-violet-crm-600 text-white' }}">
                                    {{ __('app.any_option') }}
                                </a>
                                @foreach ($compact['classTypeOptions'] as $option)
                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
                                        {{ $option['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        </details>

                        <details class="group rounded-lg border border-stone-200 bg-white shadow-xs">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_trainer') }}</span>
                                    <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.trainer') }}: {{ $selectedTrainer?->name ?? __('app.any_option') }}</span>
                                </span>
                                <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                            </summary>
                            <div class="space-y-2 border-t border-stone-100 p-2">
                                <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_trainer')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedTrainerId'] ? 'text-slate-700 hover:bg-slate-50' : 'bg-violet-crm-600 text-white' }}">
                                    {{ __('app.any_option') }}
                                </a>
                                @foreach ($compact['trainerOptions'] as $option)
                                    @php
                                        $optionTrainer = $compact['trainers']->firstWhere('id', $option['id']);
                                    @endphp
                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
                                        @if ($optionTrainer?->photoUrl())
                                            <img src="{{ $optionTrainer->photoUrl() }}" alt="" class="h-8 w-8 rounded-full object-cover">
                                        @else
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-violet-crm-100 text-violet-crm-700">
                                                <x-ui.icon name="trainers" class="h-4 w-4" />
                                            </span>
                                        @endif
                                        <span class="min-w-0 flex-1 truncate">{{ $option['name'] }}</span>
                                        @if ($optionTrainer?->trainerType)
                                            <x-ui.trainer-type-badge :trainer-type="$optionTrainer->trainerType" class="{{ $option['active'] ? 'border-white/30 bg-white/15 text-white' : '' }}" />
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </details>

                        <details class="group rounded-lg border border-stone-200 bg-white shadow-xs">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_room') }}</span>
                                    <span class="block truncate text-sm font-semibold text-slate-950">{{ __('app.room') }}: {{ $selectedRoom?->name ?? __('app.any_option') }}</span>
                                </span>
                                <x-ui.icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-400" />
                            </summary>
                            <div class="space-y-2 border-t border-stone-100 p-2">
                                <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_room')) }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $compact['selectedRoomId'] ? 'text-slate-700 hover:bg-slate-50' : 'bg-violet-crm-600 text-white' }}">
                                    {{ __('app.any_option') }}
                                </a>
                                @foreach ($compact['roomOptions'] as $option)
                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold {{ $option['active'] ? 'bg-violet-crm-600 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
                                        {{ $option['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    </section>

                    <section class="mt-3 space-y-3">
                        @forelse ($compact['classes'] as $scheduledClass)
                            @php
                                $cardTimezone = $scheduledClass->displayTimezone();
                                $startsAt = $scheduledClass->starts_at->copy()->timezone($cardTimezone);
                                $endsAt = $scheduledClass->ends_at->copy()->timezone($cardTimezone);
                                $capacity = (int) ($scheduledClass->capacity ?? 0);
                                $activeBookingsCount = (int) ($scheduledClass->active_bookings_count ?? 0);
                                $availableSpots = max(0, $capacity - $activeBookingsCount);
                                $isFull = $capacity <= 0 || $availableSpots < 1;
                                $canBook = $scheduledClass->isBookingOpen() && ! $isFull;
                                $bookingUrl = route('public.booking.show', [
                                    'accountSlug' => $account->slug,
                                    'locationSlug' => $location->slug,
                                    'schedule_kind' => \App\Enums\ScheduleKind::GroupClass->value,
                                    'scheduled_class_id' => $scheduledClass->id,
                                ]);
                            @endphp
                            <article class="rounded-xl border border-stone-200 bg-white p-3 shadow-xs">
                                <div class="flex gap-3">
                                    <div class="flex h-14 w-16 shrink-0 flex-col items-center justify-center rounded-lg bg-ink-950 text-white">
                                        <span class="text-lg font-semibold leading-none">{{ $startsAt->format('H:i') }}</span>
                                        <span class="mt-1 text-[11px] text-slate-300">{{ $endsAt->format('H:i') }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <h2 class="truncate text-base font-semibold leading-snug text-slate-950">{{ $scheduledClass->title }}</h2>
                                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium text-slate-500">
                                                    @if ($scheduledClass->trainer?->photoUrl())
                                                        <img src="{{ $scheduledClass->trainer->photoUrl() }}" alt="" class="h-6 w-6 rounded-full object-cover">
                                                    @endif
                                                    <span>{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</span>
                                                    @if ($scheduledClass->trainer?->trainerType)
                                                        <x-ui.trainer-type-badge :trainer-type="$scheduledClass->trainer->trainerType" class="h-6 rounded-full px-2 text-[11px]" />
                                                    @endif
                                                    <span>{{ $scheduledClass->room?->name ?? $location->name }}</span>
                                                    <span>{{ $scheduledClass->durationMinutes() }} {{ __('app.minutes') }}</span>
                                                </div>
                                            </div>
                                            <div class="flex w-28 shrink-0 flex-col gap-2">
                                                <span class="inline-flex min-h-8 w-full items-center justify-center rounded-lg px-3 py-1.5 text-center text-xs font-semibold leading-tight {{ $isFull ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                                                    {{ $capacity > 0 ? __('app.available_slots_short', ['count' => $availableSpots]) : __('app.capacity_not_set') }}
                                                </span>
                                                @if ($canBook)
                                                    <x-ui.button :href="$bookingUrl" variant="brand" size="sm" class="w-full">
                                                        {{ __('app.book_this_class') }}
                                                    </x-ui.button>
                                                @else
                                                    <x-ui.button type="button" variant="secondary" size="sm" class="w-full" disabled>
                                                        {{ $isFull ? __('app.booking_full') : __('app.booking_closed') }}
                                                    </x-ui.button>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <x-ui.empty-state icon="calendar" class="bg-white">
                                {{ __('app.no_public_booking_slots') }}
                            </x-ui.empty-state>
                        @endforelse
                    </section>
                </div>
            @endfragment

            @unless ($isEmbed)
                <x-ui.public-contact-links :account="$account" class="mt-6" />
            @endunless
        </section>
    </main>
@endsection
