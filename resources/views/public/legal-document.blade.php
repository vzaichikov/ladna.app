@extends('layouts.public')

@section('title', $documentTitle.' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-4xl px-5 py-8 sm:px-8 sm:py-12">
            <a href="{{ $returnUrl }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 transition hover:text-slate-950">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                {{ __('app.back') }}
            </a>

            <header class="mt-6 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <span class="flex h-16 w-16 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                        <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                    </span>
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="mt-1 text-3xl font-semibold text-slate-950">{{ $documentTitle }}</h1>
                    </div>
                </div>

                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <select name="locale" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-xs">
                        @foreach (config('ladna.locales') as $locale => $label)
                            <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                        @endforeach
                    </select>
                </form>
            </header>

            <article class="mt-8 rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-8">
                @if ($documentHtml)
                    <div class="studio-rules-content">
                        {!! $documentHtml !!}
                    </div>
                @else
                    <x-ui.empty-state icon="file-text">
                        {{ $emptyMessage }}
                    </x-ui.empty-state>
                @endif
            </article>
        </section>
    </main>
@endsection
