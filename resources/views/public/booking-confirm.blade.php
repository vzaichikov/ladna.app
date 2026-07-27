@extends('layouts.public')

@section('title', __('app.booking_confirmation').' · '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-xl bg-canvas px-4 pb-6 sm:px-6" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-xl px-4 py-4 sm:px-6">
            <a href="{{ $selection['backUrl'] }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                {{ __('app.public_booking_back_to_schedule') }}
            </a>

            <header class="mt-3">
                <h1 class="text-2xl font-semibold leading-tight text-slate-950">{{ __('app.booking_confirmation') }}</h1>
            </header>

            <div class="mt-4 space-y-4">
                @include('public._booking-selection-card', ['selection' => $selection])
                @include('public._booking-form', [
                    'account' => $account,
                    'location' => $location,
                    'customer' => $customer,
                    'selection' => $selection,
                    'allowsGuestBooking' => $allowsGuestBooking,
                    'isModal' => false,
                    'returnUrl' => $selection['backUrl'],
                ])
            </div>
        </section>
    </main>
@endsection
