@extends('layouts.public')

@section('title', __('app.redirecting_to_payment').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="flex min-h-[calc(100vh-8rem)] items-center justify-center bg-canvas px-5 py-8 text-slate-950">
        <section class="w-full max-w-md rounded-xl border border-stone-200 bg-white p-6 text-center shadow-crm">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
                <x-ui.icon name="credit-card" class="h-6 w-6" />
            </span>
            <h1 class="mt-4 text-xl font-semibold text-slate-950">{{ __('app.redirecting_to_payment') }}</h1>
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.redirecting_to_payment_copy') }}</p>

            <form id="payment-redirect-form" method="{{ $checkout->method }}" action="{{ $checkout->url }}" class="mt-5">
                @foreach ($checkout->fields as $name => $value)
                    @if (is_array($value))
                        @foreach ($value as $item)
                            <input type="hidden" name="{{ $name }}" value="{{ $item }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <x-ui.button type="submit" class="w-full">
                    {{ __('app.continue_to_payment') }}
                </x-ui.button>
            </form>
        </section>
    </main>

    <script>
        document.getElementById('payment-redirect-form')?.submit();
    </script>
@endsection
