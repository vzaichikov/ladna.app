@extends('layouts.app')

@section('title', __('app.edit').' '.$account->name)

@section('content')
    @php
        $activeTab = $activeTab ?? 'account';
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.edit') }} {{ $account->name }}</h1>
            <p class="crm-page-copy">{{ __('app.account_edit_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.account_edit_tabs') }}">
        <a
            href="{{ route('dashboard.accounts.edit', [$account, 'tab' => 'account']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'account' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.my_account') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.edit', [$account, 'tab' => 'business']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'business' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.my_business') }}
        </a>
    </nav>

    @if ($activeTab === 'account')
        <form method="POST" action="{{ route('dashboard.accounts.owner-profile.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('profile.form-fields')
        </form>
    @else
        <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            <div>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.business_details') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.business_details_copy') }}</p>
            </div>

            @include('accounts.form-fields')
            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @endif
@endsection
