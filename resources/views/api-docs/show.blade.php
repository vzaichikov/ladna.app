@extends('layouts.public')

@section('title', __('app.api_documentation').' - '.__('app.app_name'))

@section('content')
    @php
        $tabs = [
            'public' => __('app.api_docs_tab_public'),
            'restricted' => __('app.api_docs_tab_restricted'),
            'mcp' => __('app.api_docs_tab_mcp'),
            'connect' => __('app.api_docs_tab_connect'),
        ];
    @endphp

    <main class="min-h-screen bg-canvas px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="crm-page-kicker">{{ $activeTab === 'connect' ? __('app.connections_title') : __('app.api') }}</p>
                    <h1 class="crm-page-title">{{ $activeTab === 'connect' ? __('app.api_docs_connect_title') : __('app.api_documentation') }}</h1>
                    <p class="crm-page-copy max-w-3xl">{{ $activeTab === 'connect' ? __('app.api_docs_connect_copy') : __('app.api_documentation_copy') }}</p>
                </div>
                @unless ($activeTab === 'connect')
                    <x-ui.button :href="$openApiUrl" variant="secondary">
                        <x-ui.icon name="external" class="h-4 w-4" />
                        {{ __('app.openapi_json') }}
                    </x-ui.button>
                @endunless
            </div>

            <nav class="mt-8" aria-label="{{ __('app.api_docs_sections') }}">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1 sm:flex">
                    @foreach ($tabs as $tabKey => $tabLabel)
                        <a
                            href="{{ route('api-docs.show', ['tab' => $tabKey]) }}"
                            class="crm-tab text-center sm:whitespace-nowrap"
                            data-api-docs-tab="{{ $tabKey }}"
                            @if ($activeTab === $tabKey) aria-current="page" @endif
                        >
                            {{ $tabLabel }}
                        </a>
                    @endforeach
                </div>
            </nav>

            @unless ($activeTab === 'connect')
                <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                    <h2 class="text-xl font-semibold text-slate-950">{{ __('app.api_docs_'.$activeTab.'_title') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ __('app.api_docs_'.$activeTab.'_copy') }}</p>
                </section>
            @endunless

            @if ($activeTab === 'connect')
                <section class="mt-6 space-y-6">
                    <article class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 shadow-crm">
                        <p class="text-sm font-semibold text-emerald-950">{{ __('app.api_docs_connect_chatgpt_title') }}</p>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-emerald-800">{{ __('app.api_docs_connect_chatgpt_copy') }}</p>
                        <a
                            href="https://help.openai.com/en/articles/12584461-developer-mode-and-full-mcp-connectors-in-chatgpt"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-emerald-900 shadow-xs ring-1 ring-emerald-200 transition hover:bg-emerald-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2"
                        >
                            {{ __('app.api_docs_connect_chatgpt_link') }}
                            <x-ui.icon name="external" class="h-4 w-4" />
                        </a>
                    </article>

                    <section>
                        <h2 class="text-xl font-semibold text-slate-950">{{ __('app.api_docs_connect_other_apps_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ __('app.api_docs_connect_other_apps_copy') }}</p>

                        <ol class="mt-5 grid gap-4 md:grid-cols-2">
                            @foreach ([1, 2, 3, 4] as $step)
                                <li class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                                    <div class="flex items-start gap-4">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-bold text-brand-700">{{ $step }}</span>
                                        <div class="min-w-0">
                                            <h3 class="font-semibold text-slate-950">{{ __('app.api_docs_connect_step_'.$step.'_title') }}</h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_docs_connect_step_'.$step.'_copy') }}</p>

                                            @if ($step === 2)
                                                <div class="mt-4" data-copy-container>
                                                    <label class="block">
                                                        <span class="crm-label">{{ __('app.api_docs_connect_ladna_address') }}</span>
                                                        <input value="{{ $mcpUrl }}" readonly class="crm-field font-mono text-xs" data-copy-source>
                                                    </label>
                                                    <x-ui.button type="button" variant="secondary" size="sm" class="mt-3" data-copy-button data-copy-success-label="{{ __('app.copied') }}">
                                                        <x-ui.icon name="copy" class="h-4 w-4" />
                                                        <span data-copy-label>{{ __('app.copy') }}</span>
                                                    </x-ui.button>
                                                </div>
                                            @elseif ($step === 4)
                                                <blockquote class="mt-4 rounded-lg bg-brand-50 px-4 py-3 text-sm font-medium leading-6 text-brand-900">
                                                    {{ __('app.api_docs_connect_test_prompt') }}
                                                </blockquote>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>

                    <article class="rounded-xl border border-rose-200 bg-rose-50 p-6 shadow-crm">
                        <h2 class="font-semibold text-rose-950">{{ __('app.api_docs_connect_safety_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-rose-800">{{ __('app.api_docs_connect_safety_copy') }}</p>
                    </article>
                </section>
            @else
                @if ($activeTab === 'mcp')
                    <section class="mt-6 grid gap-4 md:grid-cols-3">
                        @foreach ([
                            ['title' => __('app.api_docs_mcp_fact_transport_title'), 'body' => __('app.api_docs_mcp_fact_transport_body')],
                            ['title' => __('app.api_docs_mcp_fact_sign_in_title'), 'body' => __('app.api_docs_mcp_fact_sign_in_body')],
                            ['title' => __('app.api_docs_mcp_fact_read_only_title'), 'body' => __('app.api_docs_mcp_fact_read_only_body')],
                        ] as $fact)
                            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                                <h2 class="font-semibold text-slate-950">{{ $fact['title'] }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-500">{{ $fact['body'] }}</p>
                            </article>
                        @endforeach
                    </section>

                    <section class="mt-6 rounded-xl border border-violet-200 bg-violet-50 p-6 shadow-crm" aria-labelledby="mcp-oauth-title">
                        <h2 id="mcp-oauth-title" class="text-xl font-semibold text-violet-950">{{ __('app.api_docs_mcp_oauth_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-violet-800">{{ __('app.api_docs_mcp_oauth_copy') }}</p>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>{{ $mcpUrl }}</code></pre>
                    </section>

                    <section class="mt-6" aria-labelledby="mcp-tools-title">
                        <h2 id="mcp-tools-title" class="text-xl font-semibold text-slate-950">{{ __('app.api_docs_mcp_catalog_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ __('app.api_docs_mcp_catalog_copy', ['count' => collect($mcpToolGroups)->sum(fn (array $group): int => count($group['tools']))]) }}</p>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @foreach ($mcpToolGroups as $group)
                                <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                                    <h3 class="font-semibold text-slate-950">{{ $group['title'] }}</h3>
                                    <div class="mt-3 grid gap-3">
                                        @foreach ($group['tools'] as $tool)
                                            <div class="rounded-lg bg-slate-50 p-3">
                                                <code class="text-xs font-semibold text-brand-700">{{ $tool['name'] }}</code>
                                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ $tool['description'] }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="mt-6 grid gap-4 lg:grid-cols-3">
                    @foreach ($paths as $path => $operations)
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

                @if ($activeTab === 'restricted')
                    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.authentication') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_docs_'.$activeTab.'_auth_copy') }}</p>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>Authorization: Bearer ladna_your_token</code></pre>
                    </section>
                @elseif ($activeTab === 'mcp')
                    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.api_docs_mcp_service_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">{{ __('app.api_docs_mcp_service_copy') }}</p>
                        <pre class="mt-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-100"><code>{{ $mcpServiceUrl }}
Authorization: Bearer ladna_your_token</code></pre>
                    </section>

                    <section class="mt-6 grid gap-4 md:grid-cols-2">
                        <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                            <h2 class="font-semibold text-slate-950">{{ __('app.api_docs_mcp_scenarios_title') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_docs_mcp_scenarios_copy') }}</p>
                        </article>
                        <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                            <h2 class="font-semibold text-slate-950">{{ __('app.api_docs_mcp_errors_title') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.api_docs_mcp_errors_copy') }}</p>
                        </article>
                    </section>
                @endif

                @if ($examples !== [])
                    <section class="mt-6 space-y-6">
                        @foreach ($examples as $example)
                            <article class="rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                                <h2 class="text-lg font-semibold text-slate-950">{{ $example['title'] }}</h2>
                                <p class="mt-2 font-mono text-xs text-slate-500">{{ $example['method'] }} {{ $example['path'] }}</p>
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
                @endif
            @endif
        </div>
    </main>
@endsection
