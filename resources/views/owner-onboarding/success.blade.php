@extends('layouts.public')

@section('title', __('app.onboarding.success_title').' - '.__('app.app_name'))

@section('content')
    @php
        $stage = $onboarding->stepAnswers(1)['studio_stage'] ?? 'operating';
        $checklist = $stage === 'operating'
            ? __('app.onboarding.success_checklist_operating')
            : __('app.onboarding.success_checklist_preparing');
    @endphp

    <main class="min-h-screen bg-canvas px-4 py-6 text-slate-900 sm:px-6 sm:py-10">
        <div class="mx-auto max-w-6xl">
            <header class="flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-brand-700">
                    <x-ui.app-logo mark-class="h-10 w-10" text-class="text-brand-700" />
                </a>
                <a href="{{ route('dashboard.accounts.show', $account) }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-brand-100 bg-white px-4 text-sm font-semibold text-brand-700 shadow-xs transition hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                    {{ __('app.dashboard') }}
                </a>
            </header>

            <section class="relative mt-8 overflow-hidden rounded-3xl bg-brand-700 px-5 py-8 text-white shadow-[0_28px_80px_rgba(43,23,49,0.22)] sm:px-9 sm:py-10">
                <div class="absolute inset-0" aria-hidden="true">
                    <div class="absolute -left-20 -top-24 h-72 w-72 rounded-full bg-brand-500/30 blur-3xl"></div>
                    <div class="absolute -bottom-24 right-24 h-72 w-72 rounded-full bg-brand-100/20 blur-3xl"></div>
                </div>
                <div class="relative grid gap-6 sm:grid-cols-[1fr_180px] sm:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-brand-100">{{ __('app.onboarding.success_kicker') }}</p>
                        <h1 class="mt-3 max-w-3xl text-3xl font-semibold leading-tight sm:text-5xl">{{ __('app.onboarding.success_title') }}</h1>
                        <p class="mt-4 max-w-2xl text-base leading-7 text-white/75 sm:text-lg">{{ __('app.onboarding.success_copy') }}</p>
                    </div>
                    <img src="{{ asset('assets/brand/mascot/ladna-mascot-sporty-cutout.png') }}" alt="" class="mx-auto h-40 w-auto object-contain sm:h-44" aria-hidden="true">
                </div>
            </section>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                <section class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
                    <div class="border-b border-stone-100 p-5 sm:p-6">
                        <p class="text-sm font-semibold text-slate-500">{{ $account->name }} · {{ $location->name }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-brand-700">{{ __('app.onboarding.public_preview_title') }}</h2>
                        @if ($scheduledClass)
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ $scheduledClass->classType?->name ?? $scheduledClass->title }} ·
                                {{ $scheduledClass->starts_at->timezone('Europe/Kyiv')->translatedFormat('l, j F · H:i') }}
                            </p>
                        @endif
                    </div>
                    <iframe src="{{ $scheduleEmbedUrl }}" title="{{ __('app.onboarding.public_preview_title') }}" class="h-[520px] w-full bg-white" loading="lazy"></iframe>
                </section>

                <div class="space-y-6">
                    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                        <h2 class="text-lg font-semibold text-brand-700">{{ __('app.onboarding.share_title') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.onboarding.share_copy') }}</p>
                        <input value="{{ $scheduleUrl }}" readonly class="crm-field font-mono text-xs" data-copy-source>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            <x-ui.button
                                type="button"
                                data-copy-button
                                data-copy-value="{{ $scheduleUrl }}"
                                data-copy-success-label="{{ __('app.copied') }}"
                                data-onboarding-share
                                data-onboarding-share-track-url="{{ route('onboarding.share') }}"
                                data-onboarding-share-csrf="{{ csrf_token() }}"
                            >
                                <x-ui.icon name="copy" class="h-4 w-4" />
                                <span data-copy-label>{{ __('app.copy_link') }}</span>
                            </x-ui.button>
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                data-native-share
                                data-share-url="{{ $scheduleUrl }}"
                                data-share-title="{{ $account->name }}"
                                data-share-text="{{ __('app.onboarding.share_message', ['studio' => $account->name]) }}"
                                data-onboarding-share
                                data-onboarding-share-track-url="{{ route('onboarding.share') }}"
                                data-onboarding-share-csrf="{{ csrf_token() }}"
                            >
                                <x-ui.icon name="share-2" class="h-4 w-4" />
                                {{ __('app.onboarding.share') }}
                            </x-ui.button>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                        <h2 class="text-lg font-semibold text-brand-700">{{ __('app.onboarding.next_steps_title') }}</h2>
                        <ul class="mt-4 space-y-3">
                            @foreach ($checklist as $item)
                                <li class="flex items-start gap-3 text-sm leading-6 text-slate-600">
                                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-crm-100 text-brand-700">
                                        <x-ui.icon name="check" class="h-3.5 w-3.5" />
                                    </span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('dashboard.accounts.show', $account) }}" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-600">
                            {{ __('app.onboarding.open_dashboard') }}
                            <x-ui.icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    </section>
                </div>
            </div>
        </div>
    </main>
@endsection
