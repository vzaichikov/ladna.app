@extends('layouts.public')

@section('title', __('app.mcp_guide_page_title', ['studio' => $guide['studio_name']]))

@push('head')
    <meta name="robots" content="noindex,nofollow">
@endpush

@section('content')
    <main class="min-h-screen bg-canvas text-slate-950">
        <div class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
            <header class="flex flex-col gap-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <a href="{{ route('home') }}" class="inline-flex w-fit items-center gap-3 rounded-xl border border-stone-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs">
                        <x-ui.app-logo mark-class="h-9 w-9" />
                    </a>
                    <span class="inline-flex w-fit rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700">{{ __('app.mcp_guide_read_only_badge') }}</span>
                </div>

                <div class="max-w-3xl">
                    <p class="text-sm font-semibold text-brand-700">{{ $guide['studio_name'] }}</p>
                    <h1 class="mt-3 text-4xl font-semibold leading-tight text-slate-950 sm:text-6xl">{{ __('app.mcp_guide_heading') }}</h1>
                    <p class="mt-4 text-lg leading-8 text-slate-600">{{ __('app.mcp_guide_intro') }}</p>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    @foreach ($guide['facts'] as $fact)
                        <article class="rounded-xl border border-stone-200 bg-slate-50 p-4">
                            <x-ui.icon :name="$fact['icon']" class="h-5 w-5 text-brand-700" />
                            <h2 class="mt-3 font-semibold text-slate-950">{{ $fact['title'] }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $fact['body'] }}</p>
                        </article>
                    @endforeach
                </div>
            </header>

            <section class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm sm:p-8" aria-labelledby="connection-address-title">
                <h2 id="connection-address-title" class="text-2xl font-semibold text-slate-950">{{ __('app.mcp_connection_link_title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.mcp_connection_link_copy') }}</p>

                <div class="mt-5" data-copy-container>
                    <label class="block">
                        <span class="crm-label">{{ __('app.mcp_connection_link_label') }}</span>
                        <input value="{{ $guide['connection_url'] }}" readonly class="crm-field font-mono text-xs" data-copy-source>
                    </label>
                    <x-ui.button type="button" variant="secondary" class="mt-3" data-copy-button data-copy-success-label="{{ __('app.copied') }}">
                        <x-ui.icon name="copy" class="h-4 w-4" />
                        <span data-copy-label>{{ __('app.copy') }}</span>
                    </x-ui.button>
                </div>
            </section>

            <section class="mt-6" aria-labelledby="guide-apps-title">
                <h2 id="guide-apps-title" class="text-2xl font-semibold text-slate-950">{{ __('app.mcp_guide_apps_title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.mcp_guide_apps_copy') }}</p>

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    @foreach ($guide['clients'] as $client)
                        <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                            <h3 class="text-lg font-semibold text-slate-950">{{ $client['name'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ $client['copy'] }}</p>
                            <ol class="mt-4 grid gap-3">
                                @foreach ($client['steps'] as $step)
                                    <li class="flex items-start gap-3 text-sm leading-6 text-slate-700">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">{{ $loop->iteration }}</span>
                                        <span>{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>

                            @if ($client['help_url'])
                                <a href="{{ $client['help_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-900">
                                    {{ __('app.mcp_guide_official_help') }}
                                    <x-ui.icon name="external" class="h-4 w-4" />
                                </a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-violet-200 bg-violet-50 p-6 shadow-crm sm:p-8" aria-labelledby="guide-helper-title">
                <h2 id="guide-helper-title" class="text-2xl font-semibold text-violet-950">{{ __('app.mcp_guide_helper_title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-violet-800">{{ __('app.mcp_guide_helper_copy') }}</p>
                <div class="mt-4" data-copy-container>
                    <textarea readonly rows="5" class="crm-field resize-none bg-white text-sm leading-6" data-copy-source>{{ $guide['setup_prompt'] }}</textarea>
                    <x-ui.button type="button" variant="secondary" class="mt-3" data-copy-button data-copy-success-label="{{ __('app.copied') }}">
                        <x-ui.icon name="copy" class="h-4 w-4" />
                        <span data-copy-label>{{ __('app.mcp_guide_copy_prompt') }}</span>
                    </x-ui.button>
                </div>
            </section>

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm" aria-labelledby="guide-examples-title">
                    <h2 id="guide-examples-title" class="text-2xl font-semibold text-slate-950">{{ __('app.mcp_guide_examples_title') }}</h2>
                    <div class="mt-4 grid gap-3">
                        @foreach ($guide['examples'] as $example)
                            <blockquote class="rounded-lg bg-slate-50 p-4 text-sm font-medium leading-6 text-slate-800">
                                “{{ $example['prompt'] }}”
                                @if ($example['permission_note'])
                                    <span class="mt-2 block text-xs font-normal text-slate-500">{{ $example['permission_note'] }}</span>
                                @endif
                            </blockquote>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl border border-stone-200 bg-white p-6 shadow-crm" aria-labelledby="guide-trouble-title">
                    <h2 id="guide-trouble-title" class="text-2xl font-semibold text-slate-950">{{ __('app.mcp_guide_troubleshooting_title') }}</h2>
                    <div class="mt-4 grid gap-4">
                        @foreach ($guide['troubleshooting'] as $item)
                            <article>
                                <h3 class="font-semibold text-slate-950">{{ $item['title'] }}</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $item['body'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>

            <section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-950 shadow-crm sm:p-8" aria-labelledby="guide-safety-title">
                <h2 id="guide-safety-title" class="text-2xl font-semibold">{{ __('app.mcp_guide_safety_title') }}</h2>
                <p class="mt-2 max-w-4xl text-sm leading-6 text-emerald-900">{{ __('app.mcp_guide_safety_copy') }}</p>
            </section>
        </div>
    </main>
@endsection
