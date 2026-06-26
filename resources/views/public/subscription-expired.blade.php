@extends('layouts.public')

@section('title', ($title ?? __('app.subscription_expired_public_title')).' - '.$account->name)

@section('content')
    <main class="min-h-screen bg-[#FAF8F5] px-5 py-10 text-[#2B2B2F] sm:px-8 lg:px-10">
        <div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-5xl flex-col items-center justify-center gap-8 text-center">
            <img
                src="{{ asset('assets/brand/mascot/ladna-mascot-sad-expired-cutout.png') }}"
                alt=""
                class="h-80 w-auto max-w-full object-contain opacity-95 drop-shadow-[0_24px_44px_rgba(59,34,63,0.16)] sm:h-96"
            >
            <div class="max-w-2xl">
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-[#A78AB9]">{{ $account->name }}</p>
                <h1 class="mt-4 text-3xl font-semibold leading-tight text-[#2B1731] sm:text-5xl">
                    {{ $title ?? __('app.subscription_expired_public_title') }}
                </h1>
                <p class="mt-5 text-lg leading-8 text-[#4D3152]/75">
                    {{ $copy ?? __('app.subscription_expired_public_copy') }}
                </p>
                @if ($supportUrl)
                    <a href="{{ $supportUrl }}" class="mt-7 inline-flex h-12 items-center justify-center rounded-lg bg-[#3B223F] px-6 text-sm font-semibold text-white shadow-[0_18px_34px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731]">
                        {{ __('app.support') }}
                    </a>
                @endif
            </div>
        </div>
    </main>
@endsection
