@extends('layouts.app')

@section('title', __('app.dashboard').' - '.__('app.app_name'))

@section('content')
    <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.workspace') }}</div>
            <h1 class="crm-page-title">{{ __('app.dashboard') }}</h1>
            <p class="crm-page-copy">{{ __('app.accounts_copy') }}</p>
        </div>
        @can('create', \App\Models\Account::class)
            <x-ui.button :href="route('dashboard.accounts.create')">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_account') }}
            </x-ui.button>
        @endcan
    </div>

    <section class="mt-6 grid gap-4 md:grid-cols-3">
        <x-ui.metric :label="__('app.accounts')" :value="$accounts->count()" icon="accounts" accent="violet" />
        <x-ui.metric :label="__('app.locations')" :value="$accounts->sum('locations_count')" icon="locations" accent="brand" />
        <x-ui.metric :label="__('app.default_language')" :value="strtoupper(app()->getLocale())" icon="globe" accent="emerald" />
    </section>

    <section class="mt-6 grid gap-4 xl:grid-cols-2">
        @forelse ($accounts as $account)
            <a href="{{ route('dashboard.accounts.show', $account) }}" class="group rounded-xl border border-stone-200 bg-white p-5 shadow-crm transition hover:-translate-y-0.5 hover:border-violet-crm-100 hover:shadow-lg">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <span class="flex h-14 w-14 items-center justify-center rounded-xl bg-brand-50 ring-1 ring-stone-200">
                            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-9 max-w-9 object-contain">
                        </span>
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950">{{ $account->name }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $account->slug }}</p>
                        </div>
                    </div>
                    <x-ui.icon name="chevron-right" class="mt-4 h-4 w-4 text-slate-300 transition group-hover:text-brand-500" />
                </div>
                <dl class="mt-6 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.default_language') }}</dt>
                        <dd class="mt-1 font-semibold uppercase text-slate-950">{{ $account->default_language }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.locations') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $account->locations_count }}</dd>
                    </div>
                </dl>
            </a>
        @empty
            <x-ui.empty-state :title="__('app.no_accounts')" icon="accounts" class="xl:col-span-2">
                @can('create', \App\Models\Account::class)
                    <x-ui.button :href="route('dashboard.accounts.create')" class="mt-5">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('app.create_account') }}
                    </x-ui.button>
                @endcan
            </x-ui.empty-state>
        @endforelse
    </section>
@endsection
