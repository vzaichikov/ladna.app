@extends('layouts.public')

@section('title', __('app.register').' - '.__('app.app_name'))

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-canvas px-5 py-10">
        <section class="w-full max-w-md rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-ui.app-logo />
            </a>

            <div class="mt-8">
                <div class="crm-page-kicker">{{ __('app.workspace') }}</div>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">{{ __('app.register') }}</h1>
            </div>

            <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
                @csrf
                <label class="block">
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name') }}" required autofocus class="crm-field">
                    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.email') }}</span>
                    <input name="email" type="email" value="{{ old('email') }}" required class="crm-field">
                    @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.password') }}</span>
                    <input name="password" type="password" required class="crm-field">
                    @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.confirm_password') }}</span>
                    <input name="password_confirmation" type="password" required class="crm-field">
                </label>
                <x-ui.button type="submit" class="w-full">{{ __('app.register') }}</x-ui.button>
            </form>
        </section>
    </main>
@endsection
