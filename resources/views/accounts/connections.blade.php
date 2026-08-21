@extends('layouts.app')

@section('title', __('app.integrations').' - '.$account->name)

@section('content')
    <div class="max-w-6xl">
        <h1 class="crm-page-title">{{ __('app.integrations') }}</h1>
        <p class="crm-page-copy">{{ __('app.studio_owner_integrations_copy') }}</p>

        <x-integration-category-navigation
            :account="$account"
            :active-tab="$activeTab"
            :can-manage-api-keys="$canManageApiKeys"
            :show-provider-categories="$canManageProviderIntegrations"
            class="mt-6"
        />

        @if ($activeTab === 'api')
            <div class="mt-6">
                @include('accounts.api-tokens', ['apiTokens' => $apiTokens])
            </div>
        @else
            <section class="mt-6 grid gap-6">
                <x-ui.panel>
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-2xl">
                            <span class="inline-flex rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">{{ __('app.mcp_guide_read_only_badge') }}</span>
                            <h2 class="mt-3 text-xl font-semibold text-slate-950">{{ __('app.mcp_connection_link_title') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('app.mcp_connection_link_copy') }}</p>
                        </div>
                        <x-ui.button :href="$guide['public_guide_url']" variant="ghost" target="_blank">
                            {{ __('app.mcp_connection_open_instructions') }}
                            <x-ui.icon name="external" class="h-4 w-4" />
                        </x-ui.button>
                    </div>

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

                    <div class="mt-5 rounded-lg border border-violet-100 bg-violet-50 p-4 text-sm leading-6 text-violet-950">
                        {{ __('app.mcp_connection_permission_note') }}
                    </div>
                </x-ui.panel>

                <x-ui.panel>
                    <div class="grid gap-2">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.mcp_guide_helper_title') }}</h2>
                        <p class="max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.mcp_guide_helper_copy') }}</p>
                    </div>

                    <div class="mt-4" data-copy-container>
                        <textarea readonly rows="5" class="crm-field resize-none text-sm leading-6" data-copy-source>{{ $guide['setup_prompt'] }}</textarea>
                        <x-ui.button type="button" variant="secondary" class="mt-3" data-copy-button data-copy-success-label="{{ __('app.copied') }}">
                            <x-ui.icon name="copy" class="h-4 w-4" />
                            <span data-copy-label>{{ __('app.mcp_guide_copy_prompt') }}</span>
                        </x-ui.button>
                    </div>
                </x-ui.panel>

                <section aria-labelledby="connections-apps-title">
                    <div>
                        <h2 id="connections-apps-title" class="text-xl font-semibold text-slate-950">{{ __('app.mcp_guide_apps_title') }}</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ __('app.mcp_guide_apps_copy') }}</p>
                    </div>

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

                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" aria-labelledby="connections-examples-title">
                    <h2 id="connections-examples-title" class="text-xl font-semibold text-slate-950">{{ __('app.mcp_guide_examples_title') }}</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
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

                <x-ui.panel padding="none" class="overflow-hidden">
                    <div class="border-b border-stone-100 p-5">
                        <h2 class="text-lg font-semibold text-slate-950">{{ $canManageTeamConnections ? __('app.mcp_team_connections') : __('app.mcp_my_connections') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">{{ $canManageTeamConnections ? __('app.mcp_team_connections_copy') : __('app.mcp_my_connections_copy') }}</p>
                    </div>

                    @if ($connections->isEmpty())
                        <x-ui.empty-state :title="__('app.mcp_no_connections')" icon="sparkles" class="m-5" />
                    @else
                        <div class="divide-y divide-stone-100">
                            @foreach ($connections as $connection)
                                <article class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-950">{{ $connection->client_name }}</div>
                                        <div class="mt-1 text-sm text-slate-600">{{ $connection->user->name }} · {{ $connection->user->email }}</div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ __('app.last_used') }}: {{ \App\Support\DateTimePresenter::format($connection->last_used_at, $account) ?? __('app.never') }}
                                        </div>
                                    </div>

                                    @if ($connection->user_id === $currentUser->id || $canManageTeamConnections)
                                        <form method="POST" action="{{ route('dashboard.accounts.integrations.mcp-connections.destroy', [$account, $connection]) }}" data-confirm-delete data-confirm-title="{{ __('app.mcp_disconnect_title') }}" data-confirm-body="{{ __('app.mcp_disconnect_copy') }}" data-confirm-accept="{{ __('app.mcp_disconnect') }}">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">
                                                {{ __('app.mcp_disconnect') }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-ui.panel>
            </section>
        @endif
    </div>
@endsection
