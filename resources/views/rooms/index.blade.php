@extends('layouts.app')

@section('title', __('app.rooms').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.rooms') }}</h1>
            <p class="crm-page-copy">{{ __('app.rooms_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.rooms.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_room') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($rooms as $room)
            <div class="crm-row lg:grid-cols-[1fr_150px_140px_140px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                        <x-ui.icon name="rooms" class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $room->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $room->location->name }} · {{ $room->slug }}</p>
                    </div>
                </div>
                <span class="{{ $room->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $room->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="text-sm font-medium text-slate-500">{{ __('app.capacity') }}: {{ $room->capacity ?? __('app.capacity_not_set') }}</div>
                @if ($account->allowsRtspCameras())
                    <span class="{{ $room->hasEnabledRtspCamera() ? 'crm-status-active' : 'crm-status-muted' }}">
                        {{ $room->hasEnabledRtspCamera() ? __('app.camera_enabled') : __('app.camera_disabled') }}
                    </span>
                @else
                    <span class="hidden lg:block"></span>
                @endif
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.action-button :href="route('dashboard.accounts.rooms.edit', [$account, $room])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.rooms.destroy', [$account, $room]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_rooms')" icon="rooms" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
