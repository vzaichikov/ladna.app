@extends('layouts.public')

@section('title', __('app.customer_google_phone_title').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
        <section class="mx-auto max-w-2xl">
            <div class="flex items-center gap-4">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                </span>
                <div>
                    <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                    <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.customer_google_phone_title') }}</h1>
                </div>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                <x-customer-auth.google-phone-panel :account="$account" :phone="$phone" />
            </div>
        </section>
    </main>
@endsection
