@extends('layouts.public')

@section('title', __('app.app_name'))

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto flex min-h-screen w-full max-w-6xl flex-col justify-center gap-12 px-5 py-10 sm:px-8 lg:grid lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
            <div class="space-y-7">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>

                <div class="space-y-5">
                    <h1 class="max-w-2xl text-4xl font-semibold leading-tight text-slate-950 sm:text-5xl">
                        {{ __('app.app_tagline') }}
                    </h1>
                    <p class="max-w-xl text-lg leading-8 text-slate-500">
                        {{ __('app.auth_intro') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    @auth
                        <x-ui.button :href="route('dashboard.index')" size="lg">{{ __('app.dashboard') }}</x-ui.button>
                    @else
                        <x-ui.button :href="route('login')" size="lg">{{ __('app.login') }}</x-ui.button>
                    @endauth
                </div>
            </div>

            <x-ui.panel class="grid gap-4 sm:grid-cols-2">
                @foreach (['Pole Beginner', 'Stretching', 'Exotic Flow', 'Strength'] as $index => $title)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                        <div class="text-sm font-medium text-slate-500">{{ now()->addDays($index + 1)->format('D, M j') }}</div>
                        <div class="mt-3 text-xl font-semibold text-slate-950">{{ $title }}</div>
                        <div class="mt-4 flex items-center justify-between text-sm text-slate-500">
                            <span>{{ 18 + $index }}:00</span>
                            <span>{{ 10 + $index }}/12</span>
                        </div>
                    </div>
                @endforeach
            </x-ui.panel>
        </section>
    </main>
@endsection
