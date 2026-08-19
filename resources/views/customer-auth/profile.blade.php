@extends('layouts.public')

@section('title', __('app.profile').' - '.$account->name)

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
                    <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.profile') }}</h1>
                </div>
            </div>

            @unless ($customer->profileIsComplete())
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    {{ __('app.customer_profile_required') }}
                </div>
            @endunless

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            <x-customer-auth.profile-form :account="$account" :customer="$customer" :profile-phone-merge="$profilePhoneMerge" />
        </section>
    </main>
@endsection
