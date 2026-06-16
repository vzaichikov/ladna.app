@extends('layouts.app')

@section('title', __('app.activity_directions').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.activity_directions') }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.activity-directions.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_activity_direction') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($activityDirections as $activityDirection)
            <div class="crm-row lg:grid-cols-[1fr_150px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    <span class="h-11 w-11 rounded-lg border border-slate-200" style="background-color: {{ $activityDirection->color ?? '#f5f5f5' }}"></span>
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $activityDirection->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $activityDirection->slug }}</p>
                    </div>
                </div>
                <span class="{{ $activityDirection->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $activityDirection->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.activity-directions.edit', [$account, $activityDirection])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('dashboard.accounts.activity-directions.destroy', [$account, $activityDirection]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_activity_directions')" icon="directions" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
