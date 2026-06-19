@extends('layouts.app')

@section('title', __('app.trainers').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.trainers') }}</h1>
            <p class="crm-page-copy">{{ __('app.trainers_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.trainers.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_trainer') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($trainers as $trainer)
            <div class="crm-row lg:grid-cols-[1fr_180px_150px_auto] lg:items-center">
                <div class="flex items-center gap-4">
                    @if ($trainer->photoUrl())
                        <img src="{{ $trainer->photoUrl() }}" alt="" class="h-11 w-11 rounded-full object-cover">
                    @else
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-violet-crm-100 text-violet-crm-700">
                            <x-ui.icon name="trainers" class="h-5 w-5" />
                        </span>
                    @endif
                    <div>
                        <h2 class="font-semibold text-slate-950">{{ $trainer->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $trainer->email ?? $trainer->phone ?? $trainer->slug }}</p>
                    </div>
                </div>
                <x-ui.trainer-type-badge :trainer-type="$trainer->trainerType" />
                <span class="{{ $trainer->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $trainer->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.trainers.edit', [$account, $trainer])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('dashboard.accounts.trainers.destroy', [$account, $trainer]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_trainers')" icon="trainers" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
