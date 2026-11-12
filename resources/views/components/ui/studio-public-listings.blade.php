@props(['account', 'events', 'festivals'])

@if ($events->isNotEmpty())
    <section class="mt-10">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-brand-700">{{ __('app.event_one_time_activities') }}</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.events_upcoming_public') }}</h2>
            </div>
            <x-ui.button :href="route('public.events.index', $account->slug)" variant="secondary">
                {{ __('app.events_view_all_public') }}
                <x-ui.icon name="arrow-right" class="h-4 w-4" />
            </x-ui.button>
        </div>

        <div
            class="mt-5 grid snap-x snap-mandatory auto-cols-[86%] grid-flow-col gap-4 overflow-x-auto pb-3 scrollbar-thin sm:auto-cols-[22rem] lg:auto-cols-[24rem]"
            aria-label="{{ __('app.events_upcoming_public') }}"
            data-public-events-rail
        >
            @foreach ($events as $event)
                <x-ui.public-event-card :account="$account" :event="$event" class="snap-start" />
            @endforeach
        </div>
    </section>
@endif

@if ($festivals->isNotEmpty())
    <section class="mt-10">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-brand-700">{{ __('app.festivals') }}</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.festivals_upcoming_public') }}</h2>
            </div>
            <x-ui.button :href="route('public.festivals.index', $account->slug)" variant="secondary">
                {{ __('app.festivals_view_all_public') }}
                <x-ui.icon name="arrow-right" class="h-4 w-4" />
            </x-ui.button>
        </div>

        <div
            class="mt-5 grid snap-x snap-mandatory auto-cols-[86%] grid-flow-col gap-4 overflow-x-auto pb-3 scrollbar-thin sm:auto-cols-[22rem] lg:auto-cols-[24rem]"
            aria-label="{{ __('app.festivals_upcoming_public') }}"
            data-public-festivals-rail
        >
            @foreach ($festivals as $festival)
                <x-ui.public-festival-card :account="$account" :edition="$festival" class="snap-start" />
            @endforeach
        </div>
    </section>
@endif
