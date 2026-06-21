@extends('layouts.public')

@section('title', $copy['title'])

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto max-w-5xl px-5 py-10 sm:px-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>

                <nav class="flex gap-2 text-sm font-semibold" aria-label="{{ $copy['language_label'] }}">
                    <a href="{{ route('changelog.en') }}" class="rounded-full border px-3 py-2 transition {{ $locale === 'en' ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-500' }}">EN</a>
                    <a href="{{ route('changelog.ua') }}" class="rounded-full border px-3 py-2 transition {{ $locale === 'uk' ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-500' }}">UA</a>
                </nav>
            </div>

            <header class="mt-12 grid gap-5">
                <div class="w-fit rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-sm font-semibold text-brand-600">
                    {{ str_replace(':version', $currentVersion, $copy['current_version']) }}
                </div>
                <h1 class="max-w-3xl text-5xl font-semibold leading-none text-slate-950 sm:text-7xl">{{ $copy['heading'] }}</h1>
                @if (filled($copy['intro'] ?? null))
                    <p class="max-w-3xl text-lg leading-8 text-slate-500">{{ $copy['intro'] }}</p>
                @endif
            </header>

            <section class="mt-10 grid gap-4" aria-label="{{ $copy['history_label'] }}">
                @foreach ($releases as $release)
                    <article class="grid gap-4 rounded-2xl border border-stone-200 bg-white/85 p-5 shadow-crm sm:p-6">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <span class="inline-flex w-fit rounded-full bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white">{{ $release['version'] }}</span>
                            <time datetime="{{ $release['date'] }}" class="text-sm font-medium text-slate-500">{{ $release['display_date'] }}</time>
                        </div>

                        <div>
                            <h2 class="text-2xl font-semibold leading-tight text-slate-950">{{ $release['title'] }}</h2>
                            <ul class="mt-3 list-disc space-y-1 pl-5 text-slate-600">
                                @foreach ($release['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>

                        <div class="text-sm font-medium text-slate-500">{{ $release['meta'] }}</div>
                    </article>
                @endforeach
            </section>
        </section>
    </main>
@endsection
