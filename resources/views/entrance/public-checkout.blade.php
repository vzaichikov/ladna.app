@extends('layouts.public')

@section('title', __('app.entrance_buy_ticket').' - '.$occasion->title)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-3xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
@php
    $ticketTypes = collect($ticketTypes);
    $paymentProviders = collect($paymentProviders);
@endphp
<main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
    <section class="mx-auto max-w-3xl px-5 py-8 sm:px-8 sm:py-12">
        <x-ui.public-studio-header :account="$account" class="mb-6" />

        <header class="rounded-3xl bg-slate-950 p-6 text-white shadow-crm sm:p-9">
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-200">{{ __('app.entrance_ticket_at_door') }}</p>
            <h1 class="mt-2 text-3xl font-semibold leading-tight sm:text-5xl">{{ $occasion->title }}</h1>
            <p class="mt-4 max-w-2xl text-sm leading-6 text-slate-300">{{ __('app.entrance_public_checkout_help') }}</p>
        </header>

        @if ($ticketTypes->isEmpty())
            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold leading-6 text-amber-950 shadow-crm sm:p-7" role="status">
                {{ __('app.entrance_no_ticket_types_available') }}
            </div>
        @else
        <form method="POST" action="{{ $storeUrl }}" class="mt-6 rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

            @if ($errors->any())
                <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.entrance_ticket_type') }}</span>
                    <select name="ticket_type_id" required class="crm-field min-h-12 text-base">
                        @foreach ($ticketTypes as $ticketType)
                            <option value="{{ data_get($ticketType, 'id') }}" @selected(old('ticket_type_id') == data_get($ticketType, 'id'))>
                                {{ data_get($ticketType, 'name') }}@if(data_get($ticketType, 'price_label')) · {{ data_get($ticketType, 'price_label') }}@endif
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.person_name') }}</span>
                    <input name="guest_name" value="{{ old('guest_name') }}" required maxlength="160" autocomplete="name" class="crm-field min-h-12 text-base">
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.email') }} <span class="font-normal text-slate-400">({{ __('app.optional') }})</span></span>
                    <input type="email" name="guest_email" value="{{ old('guest_email') }}" maxlength="255" autocomplete="email" class="crm-field min-h-12 text-base">
                </label>
            </div>

            @if ($termsLabel ?? null)
                <label class="mt-5 flex items-start gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                    <input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted', '1')) class="crm-checkbox mt-1">
                    <span>{!! $termsLabel !!}</span>
                </label>
            @else
                <input type="hidden" name="terms_accepted" value="1">
            @endif

            <div class="mt-6 border-t border-stone-100 pt-6">
                <h2 class="text-lg font-semibold">{{ __('app.payment_method') }}</h2>
                <div class="mt-3 grid gap-3">
                    @forelse ($paymentProviders as $provider)
                        @php
                            $providerValue = data_get($provider, 'value', data_get($provider, 'provider', $provider instanceof \BackedEnum ? $provider->value : $provider));
                            $providerLabel = data_get($provider, 'label', config('integrations.providers.'.$providerValue.'.label', $providerValue));
                        @endphp
                        <x-ui.button type="submit" name="provider" :value="$providerValue" variant="success" size="lg" class="w-full min-h-14">
                            <x-ui.payment-brand :provider="$providerValue" :label="$providerLabel" presentation="card" class="w-full" />
                        </x-ui.button>
                    @empty
                        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{{ __('app.no_payment_methods_available') }}</p>
                    @endforelse
                </div>
                <x-ui.accepted-card-brands class="mt-5" />
            </div>
        </form>
        @endif
    </section>
</main>
@endsection
