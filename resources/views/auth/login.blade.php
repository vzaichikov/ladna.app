@extends('layouts.public')

@section('title', __('app.login').' - '.__('app.app_name'))

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10">
        <section class="grid w-full max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-crm lg:grid-cols-[0.95fr_1.05fr]">
            <div class="hidden bg-ink-950 p-8 text-white lg:flex lg:flex-col lg:justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/5 ring-1 ring-white/10">
                        <img src="{{ asset('brand/charmpole-icon.svg') }}" alt="" class="h-9 w-9">
                    </span>
                    <span class="text-lg font-semibold"><span class="text-brand-500">Charm</span> CRM</span>
                </a>
                <div>
                    <h1 class="text-3xl font-semibold leading-tight">{{ __('app.login') }}</h1>
                    <p class="mt-3 text-sm leading-6 text-slate-400">Studio operations, schedules, and customer access in one tenant-safe workspace.</p>
                </div>
            </div>

            <div class="p-6 sm:p-10">
                <a href="{{ route('home') }}" class="flex items-center gap-3 lg:hidden">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-ink-950">
                        <img src="{{ asset('brand/charmpole-icon.svg') }}" alt="" class="h-8 w-8">
                    </span>
                    <span class="text-lg font-semibold text-slate-950">{{ __('app.app_name') }}</span>
                </a>
                <div class="mt-8 lg:mt-0">
                    <div class="crm-page-kicker">{{ __('app.app_name') }}</div>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ __('app.login') }}</h1>
                </div>

                <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                    @csrf
                    <label class="block">
                        <span class="crm-label">{{ __('app.email') }}</span>
                        <input name="email" type="email" value="{{ old('email') }}" required autofocus class="crm-field">
                        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.password') }}</span>
                        <input name="password" type="password" required class="crm-field">
                        @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input name="remember" type="checkbox" value="1" class="crm-checkbox">
                        {{ __('app.remember_me') }}
                    </label>
                    <x-ui.button type="submit" class="w-full">{{ __('app.login') }}</x-ui.button>
                </form>

            </div>
        </section>
    </main>
@endsection
