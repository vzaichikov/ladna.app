@extends('layouts.app')

@section('title', __('app.platform').' - '.__('app.app_name'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.platform') }}</h1>
            <p class="crm-page-copy">{{ __('app.platform_admin') }}</p>
        </div>
        <a href="{{ route('platform.accounts.index') }}" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700">{{ __('app.accounts') }}</a>
    </div>

    <section class="mt-8 grid gap-4 md:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-crm">
            <div class="text-sm text-slate-500">{{ __('app.accounts') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ $accountsCount }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-crm">
            <div class="text-sm text-slate-500">{{ __('app.active') }}</div>
            <div class="mt-2 text-3xl font-semibold">{{ $activeAccountsCount }}</div>
        </div>
    </section>

    <section class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-crm">
        @foreach ($recentAccounts as $account)
            <a href="{{ route('platform.accounts.show', $account) }}" class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 last:border-b-0">
                <div>
                    <div class="font-semibold">{{ $account->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $account->subscription?->plan?->name ?? __('app.subscription_plan') }}</div>
                </div>
                <span class="text-sm font-semibold">{{ __('app.'.$account->status->value) }}</span>
            </a>
        @endforeach
    </section>
@endsection
