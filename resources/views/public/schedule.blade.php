@extends('layouts.public')

@section('title', $account->name.' '.$location->name.' '.strtolower(__('app.schedule')))

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-6xl px-5 sm:px-8 {{ $isEmbed ? 'py-5' : 'py-10' }}">
            @php
                $showDateAnchors = count($dateAnchors) > 1 && in_array($selectedPeriod, ['week', 'month'], true);
                $routeName = $isEmbed ? 'public.schedule.embed' : 'public.schedule';
                $baseScheduleParams = [
                    'accountSlug' => $account->slug,
                    'locationSlug' => $location->slug,
                    'period' => $selectedPeriod,
                ];
                $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
                $formatDate = static fn ($date): string => \App\Support\DateTimePresenter::date($date, $account) ?? __('app.not_set');
            @endphp

            @unless ($isEmbed)
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-sm font-semibold text-slate-600 hover:text-slate-950">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>
            @endunless

            <header class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex gap-4">
                        <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-stone-200 bg-slate-50 shadow-xs">
                            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-12 max-w-12 object-contain">
                        </span>
                        <div>
                            <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                            <h1 class="mt-2 text-4xl font-semibold leading-tight text-slate-950">{{ $location->name }} {{ __('app.schedule') }}</h1>
                            @if ($location->address)
                                <p class="mt-3 max-w-2xl text-slate-500">{{ $location->address }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 lg:items-end">
                        <div class="flex flex-wrap gap-2 lg:justify-end">
                            @foreach ($manualCtaOptions as $manualCtaOption)
                                <x-ui.button type="button" variant="{{ $manualCtaOption['kind'] === \App\Enums\ScheduleKind::PrivateLesson ? 'brand' : 'secondary' }}" data-public-manual-booking-mock="{{ $manualCtaOption['kind']->value }}">
                                    <x-ui.icon :name="$manualCtaOption['kind'] === \App\Enums\ScheduleKind::PrivateLesson ? 'user' : 'rooms'" class="h-4 w-4" />
                                    {{ $manualCtaOption['label'] }}
                                </x-ui.button>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap items-center gap-3 lg:justify-end">
                            <form method="POST" action="{{ route('locale.update') }}">
                                @csrf
                                <select name="locale" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs">
                                    @foreach (config('ladna.locales') as $locale => $label)
                                        <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                                    @endforeach
                                </select>
                            </form>
                            <a href="{{ route('public.studio-rules', $account->slug) }}" class="text-sm font-semibold text-brand-700 transition hover:text-brand-600">
                                {{ __('app.studio_rules') }}
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            @if ($customer)
                <section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-xs">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-1 text-sm font-semibold text-emerald-800">
                                <x-ui.icon name="user" class="h-4 w-4" />
                                {{ __('app.public_schedule_logged_in_as', ['name' => $customerDisplayName ?? __('app.customer_section')]) }}
                            </div>
                            <h2 class="mt-4 text-lg font-semibold text-slate-950">{{ __('app.customer_class_passes') }}</h2>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button :href="route('customer.dashboard', $account->slug)" variant="secondary">
                                <x-ui.icon name="dashboard" class="h-4 w-4" />
                                {{ __('app.customer_portal') }}
                            </x-ui.button>
                            <x-ui.button :href="route('customer.profile.edit', $account->slug)" variant="ghost">
                                <x-ui.icon name="user" class="h-4 w-4" />
                                {{ __('app.profile') }}
                            </x-ui.button>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @forelse ($customerPasses as $pass)
                            <article class="rounded-xl border border-emerald-200 bg-white p-4 text-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $pass->plan_name }}</div>
                                        <div class="mt-1 text-slate-500">{{ $pass->code }}</div>
                                    </div>
                                    <span class="crm-status-active">{{ __('app.'.$pass->status->value) }}</span>
                                </div>
                                <dl class="mt-3 grid grid-cols-3 gap-2 text-slate-600">
                                    <div>
                                        <dt class="text-xs">{{ __('app.remaining_sessions') }}</dt>
                                        <dd class="font-semibold text-slate-950">{{ $pass->remainingSessionsCount() }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs">{{ __('app.reserved_sessions') }}</dt>
                                        <dd class="font-semibold text-slate-950">{{ $pass->reserved_sessions_count }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs">{{ __('app.used_sessions') }}</dt>
                                        <dd class="font-semibold text-slate-950">{{ $pass->used_sessions_count }}</dd>
                                    </div>
                                </dl>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                    <span>{{ __('app.expires_after_first_class') }}: {{ $formatDate($pass->expires_at) }}</span>
                                    <span>{{ __('app.usable_until_at') }}: {{ $formatDate($pass->usableUntilAt()) }}</span>
                                </div>
                            </article>
                        @empty
                            <x-ui.empty-state :title="__('app.no_customer_class_passes')" icon="class-pass-plans" class="md:col-span-2 bg-white" />
                        @endforelse
                    </div>
                </section>
            @endif

            @fragment('schedule-results')
                <div data-public-schedule-fragment data-public-schedule-loading="{{ __('app.loading') }}">
                    <nav class="mt-6 grid gap-2 sm:grid-cols-2 lg:grid-cols-4" aria-label="{{ __('app.schedule_periods') }}">
                        @foreach ($periodOptions as $periodOption)
                            <a
                                href="{{ $periodOption['url'] }}"
                                data-public-schedule-link
                                class="rounded-xl border p-4 transition {{ $periodOption['active'] ? 'border-violet-crm-600 bg-violet-crm-600 text-white shadow-sm shadow-violet-crm-600/20' : 'border-slate-200 bg-white text-slate-700 hover:border-violet-crm-200 hover:bg-violet-crm-50' }}"
                            >
                                <span class="block text-sm font-semibold">{{ $periodOption['label'] }}</span>
                                <span class="mt-1 block text-xs {{ $periodOption['active'] ? 'text-white/80' : 'text-slate-500' }}">{{ $periodOption['date'] }}</span>
                            </a>
                        @endforeach
                    </nav>

                    @if ($showDateAnchors)
                        <nav class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.schedule_dates') }}">
                            @foreach ($dateAnchors as $dateAnchor)
                                <a href="#{{ $dateAnchor['id'] }}" class="whitespace-nowrap rounded-full border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-100 hover:bg-brand-50">
                                    {{ $dateAnchor['label'] }}
                                    <span class="ml-1 text-xs text-slate-500">{{ $dateAnchor['count'] }}</span>
                                </a>
                            @endforeach
                        </nav>
                    @endif

                    @if ($rooms->count() > 1)
                        <nav class="mt-6 flex flex-wrap gap-2">
                            <a href="{{ route($routeName, $baseScheduleParams) }}" data-public-schedule-link class="rounded-full border px-4 py-2 text-sm font-semibold transition {{ $selectedRoomSlug ? 'border-slate-200 bg-white text-slate-700 hover:border-violet-crm-200' : 'border-violet-crm-600 bg-violet-crm-600 text-white' }}">{{ __('app.all_rooms') }}</a>
                            @foreach ($rooms as $room)
                                <a href="{{ route($routeName, [...$baseScheduleParams, 'room' => $room->slug]) }}" data-public-schedule-link class="rounded-full border px-4 py-2 text-sm font-semibold transition {{ $selectedRoomSlug === $room->slug ? 'border-violet-crm-600 bg-violet-crm-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-violet-crm-200' }}">{{ $room->name }}</a>
                            @endforeach
                        </nav>
                    @endif

                    <section class="mt-6 space-y-8">
                        @forelse ($classDays as $date => $classesForDay)
                            @include('public._schedule-day', [
                                'account' => $account,
                                'location' => $location,
                                'date' => $date,
                                'classes' => $classesForDay,
                                'customer' => $customer,
                            ])
                        @empty
                            <x-ui.empty-state icon="calendar">
                                {{ __('app.no_public_classes') }}
                            </x-ui.empty-state>
                        @endforelse

                        @if ($showDateAnchors)
                            <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.schedule_dates') }}">
                                @foreach ($dateAnchors as $dateAnchor)
                                    <a href="#{{ $dateAnchor['id'] }}" class="whitespace-nowrap rounded-full border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-100 hover:bg-brand-50">
                                        {{ $dateAnchor['label'] }}
                                        <span class="ml-1 text-xs text-slate-500">{{ $dateAnchor['count'] }}</span>
                                    </a>
                                @endforeach
                            </nav>
                        @endif
                    </section>
                </div>
            @endfragment
        </section>
    </main>
@endsection
