@extends('layouts.public')

@section('title', __('app.events').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
<main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
    <section class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
        <x-ui.public-studio-header :account="$account" />

        <header class="mt-8">
            <p class="text-sm font-semibold text-brand-700">{{ $account->name }}</p>
            <h1 class="mt-1 text-4xl font-semibold leading-tight sm:text-5xl">{{ __('app.events') }}</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-slate-500">{{ __('app.events_public_intro', ['studio' => $account->name]) }}</p>
        </header>

        <nav class="mt-6 flex gap-1 overflow-x-auto rounded-lg bg-stone-100 p-1" aria-label="{{ __('app.events') }}">
            @foreach (['upcoming', 'past'] as $value)
                <a
                    href="{{ route('public.events.index', ['accountSlug' => $account->slug, 'tab' => $value === 'past' ? 'past' : null]) }}"
                    class="crm-tab whitespace-nowrap"
                    aria-current="{{ $tab === $value ? 'page' : 'false' }}"
                >
                    {{ __('app.events_'.$value.'_public') }}
                </a>
            @endforeach
        </nav>

        <div class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3" data-public-events-list>
            @forelse ($events as $event)
                <x-ui.public-event-card :account="$account" :event="$event" />
            @empty
                <div class="md:col-span-2 lg:col-span-3">
                    <x-ui.empty-state icon="calendar-days">{{ __('app.events_public_empty') }}</x-ui.empty-state>
                </div>
            @endforelse
        </div>

        @if ($events->hasPages())
            <div class="mt-8">{{ $events->links() }}</div>
        @endif
    </section>
</main>
@endsection
