@extends('layouts.public')

@section('title', $account->name.' '.$location->name.' '.strtolower(__('app.schedule')))

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto max-w-6xl px-5 sm:px-8 {{ $isEmbed ? 'py-5' : 'py-10' }}">
            @unless ($isEmbed)
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-sm font-semibold text-slate-600 hover:text-slate-950">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>
            @endunless

            <header class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="mt-2 text-4xl font-semibold leading-tight text-slate-950">{{ $location->name }} {{ __('app.schedule') }}</h1>
                        @if ($location->address)
                            <p class="mt-3 max-w-2xl text-slate-500">{{ $location->address }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-3 sm:items-end">
                        <form method="POST" action="{{ route('locale.update') }}">
                            @csrf
                            <select name="locale" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs">
                                @foreach (config('charm.locales') as $locale => $label)
                                    <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                                @endforeach
                            </select>
                        </form>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">
                            {{ $location->timezone ?? $account->timezone ?? config('app.timezone') }}
                        </div>
                    </div>
                </div>
            </header>

            @if ($rooms->count() > 1)
                <nav class="mt-6 flex flex-wrap gap-2">
                    <a href="{{ route($isEmbed ? 'public.schedule.embed' : 'public.schedule', [$account->slug, $location->slug]) }}" class="rounded-full border px-4 py-2 text-sm font-semibold transition {{ $selectedRoomSlug ? 'border-slate-200 bg-white text-slate-700 hover:border-violet-crm-200' : 'border-violet-crm-600 bg-violet-crm-600 text-white' }}">{{ __('app.all_rooms') }}</a>
                    @foreach ($rooms as $room)
                        <a href="{{ route($isEmbed ? 'public.schedule.embed' : 'public.schedule', [$account->slug, $location->slug, 'room' => $room->slug]) }}" class="rounded-full border px-4 py-2 text-sm font-semibold transition {{ $selectedRoomSlug === $room->slug ? 'border-violet-crm-600 bg-violet-crm-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-violet-crm-200' }}">{{ $room->name }}</a>
                    @endforeach
                </nav>
            @endif

            <section class="mt-6 grid gap-4 lg:grid-cols-2">
                @forelse ($classes as $scheduledClass)
                    @php
                        $timezone = $scheduledClass->displayTimezone();
                        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
                        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
                    @endphp

                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-crm">
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-brand-600">{{ $startsAt->translatedFormat('D, j M') }}</div>
                                <h2 class="mt-2 text-2xl font-semibold text-slate-950">{{ $scheduledClass->title }}</h2>
                                @if ($scheduledClass->description)
                                    <p class="mt-3 text-sm leading-6 text-slate-500">{{ $scheduledClass->description }}</p>
                                @endif
                            </div>
                            <div class="rounded-xl bg-ink-950 px-4 py-3 text-center text-white">
                                <div class="text-xl font-semibold">{{ $startsAt->format('H:i') }}</div>
                                <div class="text-xs text-slate-300">{{ $endsAt->format('H:i') }}</div>
                            </div>
                        </div>

                        <dl class="mt-5 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div class="rounded-lg bg-slate-50 p-3">
                                <dt class="text-slate-500">{{ __('app.trainer') }}</dt>
                                <dd class="mt-1 flex items-center gap-2 font-semibold text-slate-950">
                                    @if ($scheduledClass->trainer?->photoUrl())
                                        <img src="{{ $scheduledClass->trainer->photoUrl() }}" alt="" class="h-7 w-7 rounded-full object-cover">
                                    @endif
                                    <span>{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</span>
                                </dd>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3">
                                <dt class="text-slate-500">{{ __('app.room') }}</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->room?->name ?? $location->name }}</dd>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3">
                                <dt class="text-slate-500">{{ __('app.duration') }}</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->durationMinutes() }} {{ __('app.minutes') }}</dd>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3">
                                <dt class="text-slate-500">{{ __('app.capacity') }}</dt>
                                <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->capacity ?? __('app.capacity_not_set') }}</dd>
                            </div>
                        </dl>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-slate-500">{{ __('app.book_stub') }}</p>
                            <x-ui.button :href="route('customer.studio.login', $account->slug)" variant="brand">
                                {{ __('app.book') }}
                            </x-ui.button>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state icon="calendar" class="lg:col-span-2">
                        {{ __('app.no_public_classes') }}
                    </x-ui.empty-state>
                @endforelse
            </section>
        </section>
    </main>
@endsection
