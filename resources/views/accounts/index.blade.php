@extends('layouts.app')

@section('title', __('app.app_name').' - '.__('app.accounts'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.workspace') }}</div>
            <h1 class="crm-page-title">{{ __('app.accounts') }}</h1>
        </div>
        @can('create', \App\Models\Account::class)
            <x-ui.button :href="route('dashboard.accounts.create')">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_account') }}
            </x-ui.button>
        @endcan
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($accounts as $account)
            <a href="{{ route('dashboard.accounts.show', $account) }}" class="crm-row transition hover:bg-violet-crm-50/50 sm:grid-cols-[1fr_180px_auto] sm:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-ink-950">
                        <img src="{{ asset('brand/charmpole-icon.svg') }}" alt="" class="h-7 w-7">
                    </span>
                    <div>
                        <div class="font-semibold text-slate-950">{{ $account->name }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ $account->slug }}</div>
                    </div>
                </div>
                <div class="text-sm font-medium text-slate-500">{{ $account->locations_count }} {{ __('app.locations') }}</div>
                <x-ui.icon name="chevron-right" class="h-4 w-4 text-slate-300" />
            </a>
        @empty
            <x-ui.empty-state :title="__('app.no_accounts')" icon="accounts" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
