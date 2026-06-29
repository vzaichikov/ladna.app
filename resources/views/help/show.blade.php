@extends('layouts.public')

@section('title', $page['title'].' - Ladna')

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto max-w-7xl px-5 py-10 sm:px-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>

                <a href="{{ route('help.index') }}" class="inline-flex w-fit items-center gap-2 rounded-full border border-stone-200 bg-white/80 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-brand-400">
                    <x-ui.icon name="arrow-left" class="h-4 w-4" />
                    {{ $copy['back_to_help'] }}
                </a>
            </div>

            <div class="mt-10 grid gap-8 lg:grid-cols-[280px_minmax(0,1fr)] lg:items-start">
                <aside class="lg:sticky lg:top-8">
                    <nav class="grid gap-2 rounded-xl border border-stone-200 bg-white/85 p-3 shadow-xs" aria-label="{{ $copy['choose_page'] }}">
                        @foreach ($pages as $pageSlug => $navPage)
                            <a
                                href="{{ route('help.show', $pageSlug) }}"
                                class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition {{ $slug === $pageSlug ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950' }}"
                            >
                                <x-ui.icon :name="$navPage['icon']" class="h-4 w-4 shrink-0" />
                                <span>{{ $navPage['title'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </aside>

                <article>
                    <header class="grid gap-5">
                        <div class="flex flex-wrap items-center gap-3 text-sm font-semibold">
                            <span class="rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-brand-600">{{ $copy['kicker'] }}</span>
                            <span class="rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-slate-500">{{ $copy['updated_label'] }}: {{ $updatedAt }}</span>
                        </div>

                        <div class="grid gap-4">
                            <h1 class="max-w-4xl text-4xl font-semibold leading-tight text-slate-950 sm:text-6xl">{{ $page['title'] }}</h1>
                            <p class="max-w-3xl text-lg leading-8 text-slate-500">{{ $page['summary'] }}</p>
                        </div>
                    </header>

                    <section class="mt-8 rounded-xl border border-stone-200 bg-white/90 p-5 shadow-xs sm:p-6" aria-labelledby="help-actions-title">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 id="help-actions-title" class="text-2xl font-semibold text-slate-950">{{ $copy['action_topics_title'] }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ $copy['action_topics_body'] }}</p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            @foreach ($page['sections'] as $sectionIndex => $section)
                                @if (! empty($section['steps']))
                                    <a href="#help-section-{{ $slug }}-{{ $sectionIndex + 1 }}" class="group flex items-start justify-between gap-4 rounded-lg border border-stone-100 bg-slate-50 px-4 py-3 transition hover:border-brand-200 hover:bg-brand-50">
                                        <span>
                                            <span class="block text-sm font-semibold leading-5 text-slate-950">{{ $section['title'] }}</span>
                                            <span class="mt-1 block text-xs font-semibold text-brand-600">{{ $copy['open_action'] }}</span>
                                        </span>
                                        <x-ui.icon name="chevron-right" class="mt-0.5 h-4 w-4 shrink-0 text-slate-300 transition group-hover:text-brand-600" />
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </section>

                    <section class="mt-8 grid gap-5" aria-label="{{ $copy['screenshot_title'] }}">
                        <h2 class="text-2xl font-semibold text-slate-950">{{ $copy['screenshot_title'] }}</h2>

                        @forelse ($page['screenshots'] ?? [] as $screenshot)
                            <figure class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-crm">
                                <img src="{{ asset($screenshot['path']) }}" alt="{{ $screenshot['alt'] }}" class="w-full bg-white object-contain">
                                <figcaption class="border-t border-stone-100 px-5 py-4 text-sm leading-6 text-slate-600">{{ $screenshot['caption'] }}</figcaption>
                            </figure>
                        @empty
                            <p class="rounded-xl border border-dashed border-stone-200 bg-white/75 p-5 text-sm leading-6 text-slate-500">{{ $copy['no_screenshot'] }}</p>
                        @endforelse
                    </section>

                    <section class="mt-8 grid gap-5" aria-label="{{ $page['title'] }}">
                        @foreach ($page['sections'] as $section)
                            <section class="rounded-xl border border-stone-200 bg-white/90 p-5 shadow-xs sm:p-6" aria-labelledby="help-section-{{ $slug }}-{{ $loop->iteration }}">
                                <h2 id="help-section-{{ $slug }}-{{ $loop->iteration }}" class="text-2xl font-semibold leading-tight text-slate-950">{{ $section['title'] }}</h2>

                                <div class="mt-4 grid gap-4 text-base leading-8 text-slate-600">
                                    @foreach ($section['body'] as $paragraph)
                                        <p>{{ $paragraph }}</p>
                                    @endforeach
                                </div>

                                @if (! empty($section['steps']))
                                    <ol class="mt-5 grid gap-3">
                                        @foreach ($section['steps'] as $step)
                                            <li class="flex gap-3 rounded-lg border border-stone-100 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">
                                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ $loop->iteration }}</span>
                                                <span>{{ $step }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif

                                @if (! empty($section['business_logic']))
                                    <div class="mt-5 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3">
                                        <h3 class="text-sm font-semibold text-brand-800">{{ $copy['business_logic_title'] }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-700">{{ $section['business_logic'] }}</p>
                                    </div>
                                @endif

                                @if (! empty($section['screenshots']))
                                    <div class="mt-5 grid gap-4">
                                        @foreach ($section['screenshots'] as $screenshotPath)
                                            @php($sectionScreenshot = collect($page['screenshots'] ?? [])->firstWhere('path', $screenshotPath))
                                            <figure class="overflow-hidden rounded-lg border border-stone-200 bg-white">
                                                <img src="{{ asset($screenshotPath) }}" alt="{{ $sectionScreenshot['alt'] ?? $section['title'] }}" class="w-full bg-white object-contain">
                                                <figcaption class="border-t border-stone-100 px-4 py-3 text-sm leading-6 text-slate-600">{{ $sectionScreenshot['caption'] ?? $section['title'] }}</figcaption>
                                            </figure>
                                        @endforeach
                                    </div>
                                @endif
                            </section>
                        @endforeach
                    </section>

                    @if (! empty($page['related']))
                        <section class="mt-8 rounded-xl border border-stone-200 bg-white/90 p-5 shadow-xs sm:p-6" aria-labelledby="help-related-title">
                            <h2 id="help-related-title" class="text-2xl font-semibold text-slate-950">{{ $copy['related_title'] }}</h2>
                            <div class="mt-4 flex flex-wrap gap-3">
                                @foreach ($page['related'] as $relatedSlug)
                                    @php($relatedPage = $pages[$relatedSlug] ?? null)

                                    @if ($relatedPage)
                                        <a href="{{ route('help.show', $relatedSlug) }}" class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:border-brand-500 hover:bg-white">
                                            <x-ui.icon :name="$relatedPage['icon']" class="h-4 w-4" />
                                            {{ $relatedPage['title'] }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endif
                </article>
            </div>
        </section>
    </main>
@endsection
