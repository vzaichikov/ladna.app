@extends('layouts.public')

@section('title', __('app.customer_login').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@push('head')
    @if ($methods->google)
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@500&amp;display=swap">
    @endif

    @if ($methods->otp && $mode !== 'otp_code')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endpush

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8 sm:py-12">
        <section class="mx-auto w-full max-w-5xl">
            <div class="grid gap-8 lg:grid-cols-[0.9fr_1fr] lg:items-center">
                <div class="space-y-5">
                    <div class="flex items-center gap-4">
                        <span class="flex h-16 w-16 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                            <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                        </span>
                        <div>
                            <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                            <h1 class="mt-1 text-3xl font-semibold text-slate-950">{{ __('app.customer_login') }}</h1>
                        </div>
                    </div>
                    <p class="max-w-xl text-base leading-7 text-slate-600">{{ __('app.customer_login_copy') }}</p>
                    <a href="{{ route('public.studio-rules', $account->slug) }}" target="_blank" rel="noopener" class="inline-flex text-sm font-semibold text-brand-700 transition hover:text-brand-600">
                        {{ __('app.studio_rules') }}
                    </a>
                </div>

                <div class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-7">
                    @if (session('status'))
                        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <x-customer-auth.login-panel :account="$account" :methods="$methods" :mode="$mode" :phone="$phone" />
                </div>
            </div>
        </section>
    </main>
@endsection
