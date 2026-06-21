@extends('layouts.public')

@section('title', $copy['title'])

@section('content')
    @php
        $languageRoutes = [
            'en' => "{$page}.en",
            'uk' => "{$page}.ua",
        ];
        $routeLocale = $locale === 'uk' ? 'ua' : 'en';
        $relatedRoute = $page === 'terms' ? "privacy.$routeLocale" : "terms.$routeLocale";
    @endphp

    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto max-w-5xl px-5 py-10 sm:px-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>

                <nav class="flex gap-2 text-sm font-semibold" aria-label="{{ $copy['language_label'] }}">
                    <a href="{{ route($languageRoutes['en']) }}" class="rounded-full border px-3 py-2 transition {{ $locale === 'en' ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-500' }}">EN</a>
                    <a href="{{ route($languageRoutes['uk']) }}" class="rounded-full border px-3 py-2 transition {{ $locale === 'uk' ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-500' }}">UA</a>
                </nav>
            </div>

            <header class="mt-12 grid gap-6">
                <div class="flex flex-wrap items-center gap-3 text-sm font-semibold">
                    <span class="rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-brand-600">{{ $copy['kicker'] }}</span>
                    <span class="rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-slate-500">{{ $copy['updated_label'] }}: {{ $updatedAt }}</span>
                </div>

                <div class="grid gap-5">
                    <h1 class="max-w-3xl text-5xl font-semibold leading-none text-slate-950 sm:text-7xl">{{ $copy['heading'] }}</h1>
                    <p class="max-w-3xl text-lg leading-8 text-slate-500">{{ $copy['intro'] }}</p>
                </div>

                <dl class="grid gap-4 rounded-2xl border border-stone-200 bg-white/85 p-5 shadow-crm sm:grid-cols-3 sm:p-6">
                    @foreach ($copy['facts'] as $fact)
                        <div class="grid gap-1">
                            <dt class="text-sm font-medium text-slate-500">{{ $fact['label'] }}</dt>
                            <dd class="text-base font-semibold text-slate-900">
                                @if (filled($fact['url'] ?? null))
                                    <a href="{{ $fact['url'] }}" class="text-brand-600 transition hover:text-brand-700">{{ $fact['value'] }}</a>
                                @else
                                    {{ $fact['value'] }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <a href="{{ route($relatedRoute) }}" class="w-fit rounded-full border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-brand-500 hover:bg-white">
                    {{ $copy['related_link'] }}
                </a>
            </header>

            <section class="mt-10 grid gap-4" aria-label="{{ $copy['heading'] }}">
                @foreach ($copy['sections'] as $section)
                    <article class="grid gap-4 rounded-2xl border border-stone-200 bg-white/85 p-5 shadow-crm sm:p-6" aria-labelledby="legal-section-{{ $loop->iteration }}">
                        <h2 id="legal-section-{{ $loop->iteration }}" class="text-2xl font-semibold leading-tight text-slate-950">{{ $section['title'] }}</h2>

                        <div class="grid gap-4 text-base leading-8 text-slate-600">
                            @foreach ($section['body'] as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </section>
        </section>
    </main>
@endsection
