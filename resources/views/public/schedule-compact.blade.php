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
        $selectedManualActivityDirection = ($compact['manualActivityDirections'] ?? collect())->firstWhere('id', $compact['selectedManualActivityDirectionId'] ?? null);
        $selectedManualTrainer = ($compact['manualTrainers'] ?? $compact['trainers'])->firstWhere('id', $compact['selectedManualTrainerId']);
        $selectedManualRoom = $compact['rooms']->firstWhere('id', $compact['selectedManualRoomId']);
        $selectedGroupPanel = $compact['groupPanel'];
        $selectedManualPanel = $compact['manualPanel'];
        $usesTrainerPrivateTimeframes = (bool) ($compact['usesTrainerPrivateTimeframes'] ?? false);
        $routeName = $isEmbed ? 'public.schedule.embed' : 'public.schedule';
        $routeParams = ['accountSlug' => $account->slug, 'locationSlug' => $location->slug];
        $compactUrl = static fn (array $query): string => route($routeName, [...$routeParams, ...array_filter($query, fn ($value) => $value !== null && $value !== '')]);
        $withoutQuery = static fn (array $query, string $key): array => array_diff_key($query, [$key => true]);
        $manualDateLabel = $compact['selectedDate']->translatedFormat('D, j F');
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-4xl px-4 sm:px-6 {{ $isEmbed ? 'py-3' : 'py-4' }}">
            @include('public._compact-header')

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
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold transition {{ $manualAction['active'] ? 'border-brand-700 bg-brand-700 text-white shadow-sm shadow-brand-700/20 ring-2 ring-brand-100' : 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20 hover:bg-brand-700' }}"
                                >
                                    <x-ui.icon :name="$manualAction['icon']" class="h-4 w-4" />
                                    {{ $manualAction['label'] }}
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    @if ($selectedManualKind)
                        @php
                            $manualCloseUrl = $compactUrl($withoutQuery($selectedQuery, 'kind'));
                            $manualMainUrl = $compactUrl($manualQuery);
                            $manualBookLabel = $selectedManualKind === \App\Enums\ScheduleKind::RoomRental
                                ? __('app.book_this_room_rental')
                                : __('app.book_this_private_lesson');
                        @endphp
                        <section class="fixed inset-0 z-50 overflow-y-auto bg-ink-950/45 px-3 py-4 sm:px-6" role="dialog" aria-modal="true" aria-labelledby="manual-booking-title">
                            <div class="mx-auto min-h-full max-w-xl">
                                <div class="rounded-xl bg-white shadow-2xl shadow-ink-950/20">
                                    <header class="sticky top-0 z-10 rounded-t-xl border-b border-stone-200 bg-white px-4 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                @if ($selectedManualPanel)
                                                    <a href="{{ $manualMainUrl }}" data-public-schedule-link class="mb-2 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500">
                                                        <x-ui.icon name="arrow-left" class="h-4 w-4" />
                                                        {{ __('app.back_to_booking_options') }}
                                                    </a>
                                                @endif
                                                <h2 id="manual-booking-title" class="truncate text-lg font-semibold text-slate-950">
                                                    @if ($selectedManualPanel === 'service')
                                                        {{ __('app.choose_class_type') }}
                                                    @elseif ($selectedManualPanel === 'activity_direction')
                                                        {{ __('app.choose_activity_direction') }}
                                                    @elseif ($selectedManualPanel === 'date')
                                                        {{ __('app.choose_date_and_time') }}
                                                    @elseif ($selectedManualPanel === 'trainer')
                                                        {{ __('app.choose_trainer') }}
                                                    @elseif ($selectedManualPanel === 'room')
                                                        {{ __('app.choose_room') }}
                                                    @else
                                                        {{ __('app.public_booking_'.$selectedManualKind->value.'_cta') }}
                                                    @endif
                                                </h2>
                                                @unless ($selectedManualPanel)
                                                    <p class="mt-1 text-sm leading-5 text-slate-500">{{ __('app.public_booking_service_help') }}</p>
                                                @endunless
                                            </div>
                                            <a href="{{ $manualCloseUrl }}" data-public-schedule-link class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('app.close') }}">
                                                <x-ui.icon name="close" class="h-5 w-5" />
                                            </a>
                                        </div>
                                    </header>

                                    <div class="p-4">
                                        @if (! $selectedManualPanel)
                                            <div class="space-y-2">
                                                @if ($selectedManualKind === \App\Enums\ScheduleKind::PrivateLesson && ($compact['manualActivityDirectionOptions'] ?? []) !== [])
                                                    <a href="{{ $compactUrl([...$manualQuery, 'manual_panel' => 'activity_direction']) }}" data-public-schedule-link class="flex min-h-16 items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                                            <x-ui.icon name="directions" class="h-5 w-5" />
                                                        </span>
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block text-sm font-semibold text-slate-950">{{ __('app.choose_activity_direction') }}</span>
                                                            <span class="mt-0.5 block truncate text-sm text-slate-500">{{ __('app.direction') }}: {{ $selectedManualActivityDirection?->name ?? __('app.choose_activity_direction') }}</span>
                                                        </span>
                                                        <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-400" />
                                                    </a>
                                                @endif

                                                <a href="{{ $compactUrl([...$manualQuery, 'manual_panel' => 'service']) }}" data-public-schedule-link class="flex min-h-16 items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                                        <x-ui.icon name="class-pass-plans" class="h-5 w-5" />
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-sm font-semibold text-slate-950">{{ __('app.choose_class_type') }}</span>
                                                        <span class="mt-0.5 block truncate text-sm text-slate-500">{{ __('app.class_type') }}: {{ $selectedManualClassType?->name ?? __('app.any_option') }}</span>
                                                    </span>
                                                    <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-400" />
                                                </a>

                                                <a href="{{ $compactUrl([...$manualQuery, 'manual_panel' => 'date']) }}" data-public-schedule-link class="flex min-h-16 items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                                        <x-ui.icon name="calendar" class="h-5 w-5" />
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-sm font-semibold text-slate-950">{{ __('app.choose_date_and_time') }}</span>
                                                        <span class="mt-0.5 block truncate text-sm text-slate-500">{{ $manualDateLabel }}</span>
                                                    </span>
                                                    <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-400" />
                                                </a>

                                                @if ($selectedManualKind === \App\Enums\ScheduleKind::PrivateLesson)
                                                    <a href="{{ $compactUrl([...$manualQuery, 'manual_panel' => 'trainer']) }}" data-public-schedule-link class="flex min-h-16 items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                                            <x-ui.icon name="trainers" class="h-5 w-5" />
                                                        </span>
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block text-sm font-semibold text-slate-950">{{ __('app.choose_trainer') }}</span>
                                                            <span class="mt-0.5 block truncate text-sm text-slate-500">{{ $selectedManualTrainer?->name ?? __('app.choose_trainer') }}</span>
                                                        </span>
                                                        <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-400" />
                                                    </a>
                                                @endif

                                                @unless ($usesTrainerPrivateTimeframes && blank($compact['selectedManualStartsAt']))
                                                    <a href="{{ $compactUrl([...$manualQuery, 'manual_panel' => 'room']) }}" data-public-schedule-link class="flex min-h-16 items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                                            <x-ui.icon name="rooms" class="h-5 w-5" />
                                                        </span>
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block text-sm font-semibold text-slate-950">{{ __('app.choose_room') }}</span>
                                                            <span class="mt-0.5 block truncate text-sm text-slate-500">{{ $selectedManualRoom?->name ?? __('app.choose_room') }}</span>
                                                        </span>
                                                        <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-400" />
                                                    </a>
                                                @endunless
                                            </div>

                                            <div class="mt-4">
                                                @if ($compact['manualRequiredFilters'] !== [])
                                                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                                        {{ __('app.public_booking_choose_filters', ['filters' => implode(', ', $compact['manualRequiredFilters'])]) }}
                                                    </div>
                                                @elseif (($compact['manualAvailability']['closed'] ?? false) === true)
                                                    <div class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600">
                                                        {{ __('app.studio_closed_on_date') }}
                                                    </div>
                                                @else
                                                    <div class="space-y-2">
                                                        @forelse (($compact['manualAvailability']['slots'] ?? []) as $slot)
                                                            @php
                                                                if ($usesTrainerPrivateTimeframes) {
                                                                    $bookingUrl = $compactUrl([...$manualQuery, 'manual_panel' => 'room', 'starts_at' => $slot['starts_at'], 'room' => null]);
                                                                    $slotCta = __('app.choose_room');
                                                                } else {
                                                                    $bookingParams = array_filter([
                                                                        'accountSlug' => $account->slug,
                                                                        'locationSlug' => $location->slug,
                                                                        'schedule_kind' => $selectedManualKind->value,
                                                                        'date' => $compact['selectedDate']->toDateString(),
                                                                        'starts_at' => $slot['starts_at'],
                                                                        'class_type_id' => $selectedManualClassType?->id,
                                                                        'activity_direction_id' => $compact['selectedManualActivityDirectionId'] ?? null,
                                                                        'trainer_id' => $selectedManualTrainer?->id,
                                                                        'room_id' => $selectedManualRoom?->id,
                                                                    ], fn ($value) => $value !== null && $value !== '');
                                                                    $bookingUrl = route('public.booking.show', $bookingParams);
                                                                    $slotCta = $manualBookLabel;
                                                                }
                                                            @endphp
                                                            <a href="{{ $bookingUrl }}" @if ($usesTrainerPrivateTimeframes) data-public-schedule-link @endif class="flex min-h-14 items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                                                                <span>
                                                                    <span class="block text-base font-semibold text-slate-950">{{ $slot['time'] }}</span>
                                                                    <span class="mt-0.5 block text-xs font-semibold text-slate-500">{{ $slot['ends_time'] }} · {{ __('app.available_slots') }}</span>
                                                                </span>
                                                                <span class="inline-flex min-h-10 items-center justify-center rounded-lg bg-brand-600 px-3 text-sm font-semibold text-white shadow-sm shadow-brand-600/20">
                                                                    {{ $slotCta }}
                                                                </span>
                                                            </a>
                                                        @empty
                                                            <div class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600">
                                                                {{ __('app.no_available_manual_slots') }}
                                                            </div>
                                                        @endforelse
                                                    </div>
                                                @endif
                                            </div>
                                        @elseif ($selectedManualPanel === 'service')
                                            <div class="space-y-2">
                                                @foreach ($compact['manualClassTypeOptions'] as $option)
                                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                        <span>{{ $option['name'] }}</span>
                                                        @if ($option['active'])
                                                            <x-ui.icon name="check" class="h-4 w-4" />
                                                        @endif
                                                    </a>
                                                @endforeach
                                            </div>
                                        @elseif ($selectedManualPanel === 'activity_direction')
                                            <div class="space-y-2">
                                                @foreach ($compact['manualActivityDirectionOptions'] as $option)
                                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                        <span>{{ $option['name'] }}</span>
                                                        @if ($option['active'])
                                                            <x-ui.icon name="check" class="h-4 w-4" />
                                                        @endif
                                                    </a>
                                                @endforeach
                                            </div>
                                        @elseif ($selectedManualPanel === 'date')
                                            @if ($compact['manualMonthOptions'] !== [])
                                                <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2" aria-label="{{ __('app.schedule_months') }}">
                                                    @foreach ($compact['manualMonthOptions'] as $monthOption)
                                                        <a href="{{ $monthOption['url'] }}" data-public-schedule-link class="flex h-11 min-w-28 shrink-0 flex-col items-center justify-center rounded-lg border px-4 text-center transition {{ $monthOption['active'] ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100' }}">
                                                            <span class="text-sm font-semibold leading-none">{{ $monthOption['label'] }}</span>
                                                            <span class="mt-1 text-[11px] font-semibold leading-none {{ $monthOption['active'] ? 'text-white/80' : 'text-slate-500' }}">{{ $monthOption['year'] }}</span>
                                                        </a>
                                                    @endforeach
                                                </nav>
                                            @endif

                                            <div class="grid grid-cols-5 gap-2">
                                                @foreach ($compact['manualDateOptions'] as $dateOption)
                                                    <a href="{{ $dateOption['url'] }}" data-public-schedule-link class="flex h-14 flex-col items-center justify-center rounded-lg border text-center transition {{ $dateOption['active'] ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100' }}">
                                                        <span class="text-[11px] font-semibold leading-none {{ $dateOption['active'] ? 'text-white/80' : 'text-slate-500' }}">{{ $dateOption['label'] }}</span>
                                                        <span class="mt-1 text-lg font-semibold leading-none">{{ $dateOption['day'] }}</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @elseif ($selectedManualPanel === 'trainer')
                                            <div class="space-y-2">
                                                @foreach ($compact['manualTrainerOptions'] as $option)
                                                    @php
                                                        $optionTrainer = $compact['trainers']->firstWhere('id', $option['id']);
                                                    @endphp
                                                    <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                        @if ($optionTrainer?->photoUrl())
                                                            <img src="{{ $optionTrainer->photoUrl() }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                                        @else
                                                            <span class="flex h-9 w-9 items-center justify-center rounded-full {{ $option['active'] ? 'bg-white/15 text-white' : 'bg-violet-crm-100 text-violet-crm-700' }}">
                                                                <x-ui.icon name="trainers" class="h-4 w-4" />
                                                            </span>
                                                        @endif
                                                        <span class="min-w-0 flex-1 truncate">{{ $option['name'] }}</span>
                                                        @if ($optionTrainer?->trainerType)
                                                            <x-ui.trainer-type-badge :trainer-type="$optionTrainer->trainerType" class="{{ $option['active'] ? 'border-white/30 bg-white/15 text-white' : '' }}" />
                                                        @endif
                                                        @if ($option['active'])
                                                            <x-ui.icon name="check" class="h-4 w-4 shrink-0" />
                                                        @endif
                                                    </a>
                                                @endforeach
                                            </div>
                                        @elseif ($selectedManualPanel === 'room')
                                            @if ($usesTrainerPrivateTimeframes && blank($compact['selectedManualStartsAt']))
                                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
                                                    {{ __('app.public_booking_choose_time_first') }}
                                                </div>
                                            @else
                                            <div class="space-y-2">
                                                @forelse ($compact['manualRoomOptions'] as $option)
                                                    <a href="{{ $option['url'] }}" @unless ($option['booking'] ?? false) data-public-schedule-link @endunless class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                        <span>{{ $option['name'] }}</span>
                                                        @if ($option['active'])
                                                            <x-ui.icon name="check" class="h-4 w-4" />
                                                        @endif
                                                    </a>
                                                @empty
                                                    <div class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-600">
                                                        {{ __('app.no_available_manual_slots') }}
                                                    </div>
                                                @endforelse
                                            </div>
                                            @endif
                                        @endif
                                    </div>
                                </div>
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
                        <a href="{{ $compactUrl([...$selectedQuery, 'group_panel' => 'class_type']) }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                <x-ui.icon name="class-pass-plans" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_class_type') }}</span>
                                <span class="block text-sm font-semibold leading-snug text-slate-950">{{ __('app.class_type') }}: {{ $selectedClassType?->name ?? __('app.any_option') }}</span>
                            </span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400" />
                        </a>

                        <a href="{{ $compactUrl([...$selectedQuery, 'group_panel' => 'trainer']) }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                <x-ui.icon name="trainers" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_trainer') }}</span>
                                <span class="block text-sm font-semibold leading-snug text-slate-950">{{ __('app.trainer') }}: {{ $selectedTrainer?->name ?? __('app.any_option') }}</span>
                            </span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400" />
                        </a>

                        <a href="{{ $compactUrl([...$selectedQuery, 'group_panel' => 'room']) }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2.5 shadow-xs transition hover:border-brand-100 hover:bg-brand-50">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700">
                                <x-ui.icon name="rooms" class="h-4 w-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[11px] font-semibold uppercase text-slate-500">{{ __('app.choose_room') }}</span>
                                <span class="block text-sm font-semibold leading-snug text-slate-950">{{ __('app.room') }}: {{ $selectedRoom?->name ?? __('app.any_option') }}</span>
                            </span>
                            <x-ui.icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400" />
                        </a>
                    </section>

                    @if ($selectedGroupPanel)
                        @php
                            $groupPanelCloseUrl = $compactUrl($selectedQuery);
                        @endphp
                        <section class="fixed inset-0 z-50 overflow-y-auto bg-ink-950/45 px-3 py-4 sm:px-6" role="dialog" aria-modal="true" aria-labelledby="group-filter-title">
                            <div class="mx-auto min-h-full max-w-xl">
                                <div class="rounded-xl bg-white shadow-2xl shadow-ink-950/20">
                                    <header class="sticky top-0 z-10 rounded-t-xl border-b border-stone-200 bg-white px-4 py-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <h2 id="group-filter-title" class="truncate text-lg font-semibold text-slate-950">
                                                @if ($selectedGroupPanel === 'class_type')
                                                    {{ __('app.choose_class_type') }}
                                                @elseif ($selectedGroupPanel === 'trainer')
                                                    {{ __('app.choose_trainer') }}
                                                @else
                                                    {{ __('app.choose_room') }}
                                                @endif
                                            </h2>
                                            <a href="{{ $groupPanelCloseUrl }}" data-public-schedule-link class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700" aria-label="{{ __('app.close') }}">
                                                <x-ui.icon name="close" class="h-5 w-5" />
                                            </a>
                                        </div>
                                    </header>

                                    <div class="space-y-2 p-4">
                                        @if ($selectedGroupPanel === 'class_type')
                                            <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_class_type')) }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $compact['selectedClassTypeId'] ? 'bg-slate-50 text-slate-800 hover:bg-brand-50' : 'bg-brand-600 text-white' }}">
                                                <span>{{ __('app.any_option') }}</span>
                                                @unless ($compact['selectedClassTypeId'])
                                                    <x-ui.icon name="check" class="h-4 w-4" />
                                                @endunless
                                            </a>
                                            @foreach ($compact['classTypeOptions'] as $option)
                                                <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                    <span>{{ $option['name'] }}</span>
                                                    @if ($option['active'])
                                                        <x-ui.icon name="check" class="h-4 w-4" />
                                                    @endif
                                                </a>
                                            @endforeach
                                        @elseif ($selectedGroupPanel === 'trainer')
                                            <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_trainer')) }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $compact['selectedTrainerId'] ? 'bg-slate-50 text-slate-800 hover:bg-brand-50' : 'bg-brand-600 text-white' }}">
                                                <span class="flex h-9 w-9 items-center justify-center rounded-full {{ $compact['selectedTrainerId'] ? 'bg-violet-crm-100 text-violet-crm-700' : 'bg-white/15 text-white' }}">
                                                    <x-ui.icon name="trainers" class="h-4 w-4" />
                                                </span>
                                                <span class="min-w-0 flex-1 truncate">{{ __('app.any_option') }}</span>
                                                @unless ($compact['selectedTrainerId'])
                                                    <x-ui.icon name="check" class="h-4 w-4 shrink-0" />
                                                @endunless
                                            </a>
                                            @foreach ($compact['trainerOptions'] as $option)
                                                @php
                                                    $optionTrainer = $compact['trainers']->firstWhere('id', $option['id']);
                                                @endphp
                                                <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-14 items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                    @if ($optionTrainer?->photoUrl())
                                                        <img src="{{ $optionTrainer->photoUrl() }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                                    @else
                                                        <span class="flex h-9 w-9 items-center justify-center rounded-full {{ $option['active'] ? 'bg-white/15 text-white' : 'bg-violet-crm-100 text-violet-crm-700' }}">
                                                            <x-ui.icon name="trainers" class="h-4 w-4" />
                                                        </span>
                                                    @endif
                                                    <span class="min-w-0 flex-1 truncate">{{ $option['name'] }}</span>
                                                    @if ($optionTrainer?->trainerType)
                                                        <x-ui.trainer-type-badge :trainer-type="$optionTrainer->trainerType" class="{{ $option['active'] ? 'border-white/30 bg-white/15 text-white' : '' }}" />
                                                    @endif
                                                    @if ($option['active'])
                                                        <x-ui.icon name="check" class="h-4 w-4 shrink-0" />
                                                    @endif
                                                </a>
                                            @endforeach
                                        @else
                                            <a href="{{ $compactUrl($withoutQuery($selectedQuery, 'group_room')) }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $compact['selectedRoomId'] ? 'bg-slate-50 text-slate-800 hover:bg-brand-50' : 'bg-brand-600 text-white' }}">
                                                <span>{{ __('app.any_option') }}</span>
                                                @unless ($compact['selectedRoomId'])
                                                    <x-ui.icon name="check" class="h-4 w-4" />
                                                @endunless
                                            </a>
                                            @foreach ($compact['roomOptions'] as $option)
                                                <a href="{{ $option['url'] }}" data-public-schedule-link class="flex min-h-12 items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $option['active'] ? 'bg-brand-600 text-white' : 'bg-slate-50 text-slate-800 hover:bg-brand-50' }}">
                                                    <span>{{ $option['name'] }}</span>
                                                    @if ($option['active'])
                                                        <x-ui.icon name="check" class="h-4 w-4" />
                                                    @endif
                                                </a>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </section>
                    @endif

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
                                                    <x-ui.button :href="$bookingUrl" variant="primary" size="sm" class="w-full">
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
