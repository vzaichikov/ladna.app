@extends('layouts.public')

@section('title', __('app.api_documentation').' - '.__('app.app_name'))

@section('content')
    <main class="min-h-screen bg-canvas px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="crm-page-kicker">{{ __('app.api') }}</p>
                    <h1 class="crm-page-title">{{ __('app.api_documentation') }}</h1>
                    <p class="crm-page-copy max-w-3xl">{{ __('app.api_documentation_copy') }}</p>
                </div>
                <x-ui.button :href="$openApiUrl" variant="secondary">
                    <x-ui.icon name="external" class="h-4 w-4" />
                    {{ __('app.openapi_json') }}
                </x-ui.button>
            </div>

            <section class="mt-8 grid gap-4 lg:grid-cols-3">
                @foreach ($spec['paths'] as $path => $operations)
                    @foreach ($operations as $method => $operation)
                        <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                            <div class="flex items-center gap-2">
                                <span class="crm-status-scheduled uppercase">{{ $method }}</span>
                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $operation['tags'][0] ?? __('app.api') }}</span>
                            </div>
                            <h2 class="mt-4 text-base font-semibold text-slate-950">{{ $operation['summary'] }}</h2>
                            <p class="mt-3 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-slate-600">{{ $path }}</p>
                        </article>
                    @endforeach
                @endforeach
            </section>

            <section class="mt-8 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.authentication') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_docs_auth_copy') }}</p>
                <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>Authorization: Bearer ladna_your_token</code></pre>
            </section>

            <section class="mt-8 space-y-6">
                @foreach ($examples as $example)
                    <article class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-950">{{ $example['title'] }}</h2>
                                <p class="mt-2 font-mono text-xs text-slate-500">{{ $example['method'] }} {{ $example['path'] }}</p>
                            </div>
                        </div>
                        <div class="mt-5 grid gap-4 lg:grid-cols-3">
                            @foreach ($example['samples'] as $sample)
                                <div class="min-w-0 overflow-hidden rounded-lg border border-stone-200">
                                    <div class="border-b border-stone-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {{ $sample['label'] }}
                                    </div>
                                    <pre class="max-h-96 overflow-auto bg-slate-950 p-4 text-xs leading-5 text-slate-100"><code>{{ $sample['source'] }}</code></pre>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </section>
        </div>
    </main>
@endsection
