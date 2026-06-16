@extends('layouts.app')

@section('title', __('app.locations').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.locations') }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.locations.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_location') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($locations as $location)
            <div class="crm-row lg:grid-cols-[1fr_150px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-violet-crm-50 text-violet-crm-700">
                        <x-ui.icon name="locations" class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $location->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $location->address ?: $location->slug }}</p>
                    </div>
                </div>
                <span class="{{ $location->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $location->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.locations.edit', [$account, $location])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <x-ui.button :href="route('public.schedule', [$account->slug, $location->slug])" variant="secondary" size="sm">{{ __('app.schedule') }}</x-ui.button>
                    <x-ui.button :href="route('public.schedule.embed', [$account->slug, $location->slug])" variant="secondary" size="sm">{{ __('app.embed') }}</x-ui.button>
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
    </x-ui.panel>
@endsection
