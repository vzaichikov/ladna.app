@extends('layouts.app')

@section('title', __('app.event_festival_staff').' - '.$account->name)

@section('content')
    <x-ui.page-header :title="__('app.event_festival_staff')" :copy="__('app.event_festival_staff_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.event-festival-staff.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.add_event_festival_staff') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.event-festival-staff.index', $account)"
        :reset-href="route('dashboard.accounts.event-festival-staff.index', $account)"
        class="mt-6 sm:grid-cols-2"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input
                name="q"
                value="{{ $filters['q'] }}"
                maxlength="255"
                class="crm-field"
                placeholder="{{ __('app.search') }}"
            >
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($staffMemberships as $membership)
            <div class="crm-row sm:grid-cols-[1fr_auto] sm:items-center">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-950">{{ $membership->user->name }}</h2>
                    <p class="mt-1 break-all text-sm text-slate-500">{{ $membership->user->email }}</p>
                </div>
                <div class="flex flex-wrap gap-2 sm:justify-end">
                    <x-ui.action-button
                        :href="route('dashboard.accounts.event-festival-staff.edit', [$account, $membership])"
                        icon="edit"
                        :label="__('app.edit')"
                    />
                    <form method="POST" action="{{ route('dashboard.accounts.event-festival-staff.destroy', [$account, $membership]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state
                :title="$hasFilters ? __('app.no_data') : __('app.no_event_festival_staff')"
                icon="users"
                class="m-5"
            >
                @if ($hasFilters)
                    <x-ui.button
                        :href="route('dashboard.accounts.event-festival-staff.index', $account)"
                        variant="secondary"
                        class="mt-3"
                    >
                        {{ __('app.reset_filters') }}
                    </x-ui.button>
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div class="mt-6">{{ $staffMemberships->links() }}</div>
@endsection
