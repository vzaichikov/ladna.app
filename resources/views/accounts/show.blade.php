@extends('layouts.app')

@section('title', $account->name.' - '.__('app.app_name'))

@section('content')
    @php
        $firstLocation = $account->locations->first();
        $accountStatusClass = match ($account->status->value) {
            'active' => 'crm-status-active',
            'trialing' => 'crm-status-scheduled',
            default => 'crm-status-muted',
        };
    @endphp

    <x-ui.panel padding="lg">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                <div class="flex h-28 w-28 shrink-0 items-center justify-center rounded-2xl bg-brand-50 shadow-crm ring-1 ring-stone-200">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-20 max-w-20 object-contain">
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-3xl font-semibold text-slate-950">{{ $account->name }}</h1>
                        <span class="{{ $accountStatusClass }}">{{ __('app.'.$account->status->value) }}</span>
                    </div>
                    <dl class="mt-5 grid gap-x-10 gap-y-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="font-medium text-slate-500">{{ __('app.slug') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $account->slug }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-slate-500">{{ __('app.default_language') }}</dt>
                            <dd class="mt-1 font-semibold uppercase text-slate-950">{{ $account->default_language }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-slate-500">{{ __('app.currency') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $account->default_currency }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-slate-500">{{ __('app.timezone') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-950">{{ $account->timezone }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-slate-500">{{ __('app.brand_color') }}</dt>
                            <dd class="mt-1 flex items-center gap-2 font-semibold text-slate-950">
                                <span class="h-8 w-8 rounded-lg border border-stone-200" style="background-color: {{ $account->brand_color ?? '#3B223F' }}"></span>
                                {{ $account->brand_color ?? '#3B223F' }}
                            </dd>
                        </div>
                        @if ($firstLocation)
                            <div>
                                <dt class="font-medium text-slate-500">{{ __('app.public_schedule') }}</dt>
                                <dd class="mt-1">
                                    <a href="{{ route('public.schedule', [$account->slug, $firstLocation->slug]) }}" class="inline-flex items-center gap-1 font-semibold text-violet-crm-600 hover:text-violet-crm-700">
                                        {{ __('app.open') }}
                                        <x-ui.icon name="external" class="h-3.5 w-3.5" />
                                    </a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-ui.button :href="route('dashboard.accounts.brand.edit', $account)" variant="secondary">
                    <x-ui.icon name="edit" class="h-4 w-4" />
                    {{ __('app.edit') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.locations.create', $account)">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.create_location') }}
                </x-ui.button>
                <form method="POST" action="{{ route('dashboard.accounts.destroy', $account) }}" data-confirm-delete>
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash" class="h-4 w-4" />
                        {{ __('app.delete') }}
                    </x-ui.button>
                </form>
            </div>
        </div>
    </x-ui.panel>

    <section class="mt-6 grid gap-4 md:grid-cols-3 xl:grid-cols-7">
        <x-ui.metric :label="__('app.locations')" :value="$account->locations_count" icon="locations" :href="route('dashboard.accounts.locations.index', $account)" />
        <x-ui.metric :label="__('app.rooms')" :value="$account->rooms_count" icon="rooms" accent="brand" :href="route('dashboard.accounts.rooms.index', $account)" />
        <x-ui.metric :label="__('app.activity_directions')" :value="$account->activity_directions_count" icon="directions" :href="route('dashboard.accounts.activity-directions.index', $account)" />
        <x-ui.metric :label="__('app.class_types')" :value="$account->class_types_count" icon="class-types" accent="brand" :href="route('dashboard.accounts.class-types.index', $account)" />
        <x-ui.metric :label="__('app.trainers')" :value="$account->trainers_count" icon="trainers" :href="route('dashboard.accounts.trainers.index', $account)" />
        <x-ui.metric :label="__('app.customers')" :value="$account->customers_count" icon="accounts" :href="route('dashboard.accounts.customers.index', $account)" />
        <x-ui.metric :label="__('app.generated_classes')" :value="$account->scheduled_classes_count" icon="generated-classes" accent="emerald" :href="route('dashboard.accounts.scheduled-classes.index', $account)" />
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.35fr_0.9fr]">
        <x-ui.panel padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.locations') }}</h2>
                <x-ui.button :href="route('dashboard.accounts.locations.create', $account)" size="sm">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.create_location') }}
                </x-ui.button>
            </div>
            @forelse ($account->locations as $index => $location)
                <div class="crm-row md:grid-cols-[auto_1fr_auto] md:items-center">
                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-violet-crm-50 text-sm font-semibold text-violet-crm-700">
                        {{ $index + 1 }}
                    </span>
                    <div>
                        <h3 class="font-semibold text-slate-950">{{ $location->name }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $location->address ?: $location->slug }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button :href="route('public.schedule', [$account->slug, $location->slug])" variant="secondary" size="sm">
                            {{ __('app.schedule') }}
                            <x-ui.icon name="external" class="h-3.5 w-3.5" />
                        </x-ui.button>
                        <x-ui.button :href="route('dashboard.accounts.locations.edit', [$account, $location])" variant="secondary" size="sm">
                            {{ __('app.edit') }}
                        </x-ui.button>
                        <form method="POST" action="{{ route('dashboard.accounts.locations.destroy', [$account, $location]) }}" data-confirm-delete>
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            @empty
                <x-ui.empty-state :title="__('app.no_locations')" icon="locations" class="m-5" />
            @endforelse
            @if ($account->locations->isNotEmpty())
                <div class="border-t border-slate-100 px-5 py-4 text-center">
                    <a href="{{ route('dashboard.accounts.locations.index', $account) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-violet-crm-600 hover:text-violet-crm-700">
                        {{ __('app.view_all') }}
                        <x-ui.icon name="chevron-right" class="h-4 w-4" />
                    </a>
                </div>
            @endif
        </x-ui.panel>

        <x-ui.panel padding="none" class="overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.public_schedule') }}</h2>
                <x-ui.icon name="calendar" class="h-5 w-5 text-violet-crm-600" />
            </div>
            @forelse ($account->locations as $location)
                <div class="crm-row grid-cols-[auto_1fr_auto] items-center">
                    <x-ui.icon name="locations" class="h-5 w-5 text-brand-600" />
                    <div>
                        <div class="font-semibold text-slate-950">{{ $location->name }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ $location->timezone ?? $account->timezone }}</div>
                    </div>
                    <a href="{{ route('public.schedule', [$account->slug, $location->slug]) }}" class="text-violet-crm-600 hover:text-violet-crm-700">
                        <x-ui.icon name="external" class="h-4 w-4" />
                    </a>
                </div>
            @empty
                <x-ui.empty-state :title="__('app.no_public_classes')" icon="calendar" class="m-5" />
            @endforelse
        </x-ui.panel>
    </section>

    <x-ui.panel class="mt-6">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.quick_actions') }}</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <x-ui.button :href="route('dashboard.accounts.locations.create', $account)" variant="secondary">
                <x-ui.icon name="locations" class="h-4 w-4 text-brand-600" />
                {{ __('app.create_location') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.rooms.index', $account)" variant="secondary">
                <x-ui.icon name="rooms" class="h-4 w-4 text-brand-600" />
                {{ __('app.rooms') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.activity-directions.index', $account)" variant="secondary">
                <x-ui.icon name="directions" class="h-4 w-4 text-brand-600" />
                {{ __('app.activity_directions') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.trainers.index', $account)" variant="secondary">
                <x-ui.icon name="trainers" class="h-4 w-4 text-brand-600" />
                {{ __('app.trainers') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.customers.index', $account)" variant="secondary">
                <x-ui.icon name="accounts" class="h-4 w-4 text-brand-600" />
                {{ __('app.customers') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">
                <x-ui.icon name="schedule" class="h-4 w-4 text-brand-600" />
                {{ __('app.schedule_series') }}
            </x-ui.button>
            @if ($account->isOwnedBy(auth()->user()))
                <x-ui.button :href="route('dashboard.accounts.integrations.index', $account)" variant="secondary">
                    <x-ui.icon name="integrations" class="h-4 w-4 text-brand-600" />
                    {{ __('app.integrations') }}
                </x-ui.button>
            @endif
        </div>
    </x-ui.panel>
@endsection
