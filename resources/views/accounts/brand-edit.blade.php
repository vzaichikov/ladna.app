@extends('layouts.app')

@section('title', __('app.my_brand').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.my_brand') }}</h1>
            <p class="crm-page-copy">{{ __('app.business_details_copy') }}</p>
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.my_brand') }}">
        <a
            href="{{ route('dashboard.accounts.brand.edit', $account) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'business' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.business_details') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.brand.edit', [$account, 'tab' => 'qr']) }}"
            class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeTab === 'qr' ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.login_qr_codes') }}
        </a>
    </nav>

    @if ($activeTab === 'qr')
        <section class="mt-6 max-w-3xl rounded-xl border border-stone-200 bg-white p-6 shadow-crm" data-print-section>
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.login_qr_codes') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.login_qr_codes_copy') }}</p>
                </div>
                <x-ui.button type="button" variant="secondary" data-print-button>
                    <x-ui.icon name="printer" class="h-4 w-4" />
                    {{ __('app.print') }}
                </x-ui.button>
            </div>

            <div class="mt-6 grid gap-6 sm:grid-cols-[260px_1fr] sm:items-center">
                <div class="flex aspect-square items-center justify-center rounded-xl border border-stone-200 bg-white p-4">
                    {!! $customerLoginQrSvg !!}
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <img src="{{ $account->logoUrl() }}" alt="" class="h-12 w-12 rounded-lg object-contain ring-1 ring-stone-200">
                        <div>
                            <div class="text-base font-semibold text-slate-950">{{ $account->name }}</div>
                            <div class="text-sm text-slate-500">{{ __('app.customer_login') }}</div>
                        </div>
                    </div>
                    <label class="mt-5 block">
                        <span class="crm-label">{{ __('app.login_url') }}</span>
                        <input value="{{ $customerLoginUrl }}" readonly class="crm-field font-mono text-xs">
                    </label>
                </div>
            </div>
        </section>
    @else
        <form method="POST" action="{{ route('dashboard.accounts.update', $account) }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')

            @include('accounts.form-fields')

            <x-ui.button type="submit">
                <x-ui.icon name="edit" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </form>
    @endif
@endsection
