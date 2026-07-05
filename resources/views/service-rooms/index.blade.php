@extends('layouts.app')

@section('title', __('app.service_rooms').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.service_rooms') }}</h1>
            <p class="crm-page-copy">{{ __('app.service_rooms_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('dashboard.accounts.rooms.index', $account)" variant="secondary">
                <x-ui.icon name="rooms" class="h-4 w-4" />
                {{ __('app.rooms') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.service-rooms.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_service_room') }}
            </x-ui.button>
        </div>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($serviceRooms as $serviceRoom)
            <div class="crm-row lg:grid-cols-[1fr_150px_140px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                        <x-ui.icon name="video" class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $serviceRoom->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $serviceRoom->location->name }} · {{ $serviceRoom->slug }}</p>
                    </div>
                </div>
                <span class="{{ $serviceRoom->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $serviceRoom->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <span class="{{ $serviceRoom->hasEnabledRtspCamera() ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $serviceRoom->hasEnabledRtspCamera() ? __('app.camera_enabled') : __('app.camera_disabled') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.action-button :href="route('dashboard.accounts.service-rooms.edit', [$account, $serviceRoom])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.service-rooms.destroy', [$account, $serviceRoom]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_service_rooms')" icon="video" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
