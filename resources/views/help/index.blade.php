@extends('layouts.public')

@section('title', $copy['title'])

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <section class="mx-auto max-w-7xl px-5 py-10 sm:px-8">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                    <x-ui.app-logo mark-class="h-9 w-9" />
                </a>

                <span class="w-fit rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-sm font-semibold text-slate-500">
                    {{ $copy['updated_label'] }}: {{ $updatedAt }}
                </span>
            </div>

            <header class="mt-12 grid gap-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(320px,0.55fr)] lg:items-end">
                <div class="grid gap-5">
                    <div class="w-fit rounded-full border border-stone-200 bg-white/80 px-3 py-1.5 text-sm font-semibold text-brand-600">
                        {{ $copy['kicker'] }}
                    </div>
                    <h1 class="max-w-4xl text-5xl font-semibold leading-none text-slate-950 sm:text-7xl">{{ $copy['heading'] }}</h1>
                    <p class="max-w-3xl text-lg leading-8 text-slate-500">{{ $copy['intro'] }}</p>
                </div>

                <section class="rounded-xl border border-stone-200 bg-white/85 p-5 shadow-crm" aria-labelledby="help-connections-title">
                    <h2 id="help-connections-title" class="text-xl font-semibold text-slate-950">{{ $copy['overview_title'] }}</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">{{ $copy['overview_body'] }}</p>
                </section>
            </header>

            <section class="mt-10 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ $copy['overview_title'] }}">
                @foreach (config('help.connections') as $connection)
                    <article class="rounded-xl border border-stone-200 bg-white/85 p-5 shadow-xs">
                        <h3 class="text-base font-semibold text-slate-950">{{ $connection['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ $connection['body'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="mt-12" aria-labelledby="help-pages-title">
                <div class="flex items-center justify-between gap-4">
                    <h2 id="help-pages-title" class="text-2xl font-semibold text-slate-950">{{ $copy['choose_page'] }}</h2>
                    <x-ui.icon name="sparkles" class="hidden h-6 w-6 text-brand-600 sm:block" />
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    @foreach ($pages as $pageSlug => $page)
                        <a href="{{ route('help.show', $pageSlug) }}" class="group grid gap-4 rounded-xl border border-stone-200 bg-white/90 p-5 shadow-xs transition hover:border-brand-300 hover:bg-white hover:shadow-crm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                                        <x-ui.icon :name="$page['icon']" class="h-5 w-5" />
                                    </span>
                                    <h3 class="text-lg font-semibold leading-tight text-slate-950">{{ $page['title'] }}</h3>
                                </div>
                                <x-ui.icon name="chevron-right" class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:text-brand-600" />
                            </div>
                            <p class="text-sm leading-6 text-slate-500">{{ $page['summary'] }}</p>
                            <span class="text-sm font-semibold text-brand-600">{{ $copy['open_page'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        </section>
    </main>
@endsection
