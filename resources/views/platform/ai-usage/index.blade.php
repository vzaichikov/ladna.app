@extends('layouts.app')

@section('title', __('app.ai_limits_usage').' - '.__('app.app_name'))

@section('content')
    @php
        $settingsTabs = [
            ['label' => __('app.system_settings_tab_appearance'), 'url' => route('platform.settings.edit', ['tab' => 'appearance'])],
            ['label' => __('app.system_settings_tab_support'), 'url' => route('platform.settings.edit', ['tab' => 'support'])],
            ['label' => __('app.system_settings_tab_activity_log'), 'url' => route('platform.settings.edit', ['tab' => 'activity-log'])],
            ['label' => __('app.system_settings_tab_ai_owner'), 'url' => route('platform.settings.edit', ['tab' => 'ai-owner'])],
        ];
        $turnFields = [
            ['name' => 'firewall_user_turns_per_minute', 'label' => __('app.ai_limit_user_turns_minute')],
            ['name' => 'firewall_user_turns_per_hour', 'label' => __('app.ai_limit_user_turns_hour')],
            ['name' => 'firewall_user_turns_per_day', 'label' => __('app.ai_limit_user_turns_day')],
            ['name' => 'firewall_admin_turns_per_minute', 'label' => __('app.ai_limit_admin_turns_minute')],
            ['name' => 'firewall_admin_turns_per_hour', 'label' => __('app.ai_limit_admin_turns_hour')],
            ['name' => 'firewall_admin_turns_per_day', 'label' => __('app.ai_limit_admin_turns_day')],
            ['name' => 'firewall_account_turns_per_day', 'label' => __('app.ai_limit_account_turns_day')],
        ];
        $providerFields = [
            ['name' => 'firewall_user_provider_calls_per_hour', 'label' => __('app.ai_limit_user_provider_hour')],
            ['name' => 'firewall_user_provider_calls_per_day', 'label' => __('app.ai_limit_user_provider_day')],
            ['name' => 'firewall_admin_provider_calls_per_hour', 'label' => __('app.ai_limit_admin_provider_hour')],
            ['name' => 'firewall_admin_provider_calls_per_day', 'label' => __('app.ai_limit_admin_provider_day')],
            ['name' => 'firewall_account_provider_calls_per_day', 'label' => __('app.ai_limit_account_provider_day')],
        ];
        $restrictionFields = [
            ['name' => 'firewall_user_out_of_scope_streak', 'label' => __('app.ai_limit_user_off_topic')],
            ['name' => 'firewall_admin_out_of_scope_streak', 'label' => __('app.ai_limit_admin_off_topic')],
            ['name' => 'firewall_cooldown_first_minutes', 'label' => __('app.ai_limit_cooldown_first')],
            ['name' => 'firewall_cooldown_second_minutes', 'label' => __('app.ai_limit_cooldown_second')],
            ['name' => 'firewall_cooldown_third_minutes', 'label' => __('app.ai_limit_cooldown_third')],
            ['name' => 'firewall_escalation_reset_days', 'label' => __('app.ai_limit_escalation_reset')],
        ];
        $summaryCards = [
            ['label' => __('app.ai_usage_turns'), 'value' => $usage['summary']['turns']],
            ['label' => __('app.ai_usage_provider_requests'), 'value' => $usage['summary']['provider_requests']],
            ['label' => __('app.ai_usage_total_tokens'), 'value' => $usage['summary']['total_tokens']],
            ['label' => __('app.ai_usage_out_of_scope'), 'value' => $usage['summary']['out_of_scope'].' ('.$usage['summary']['out_of_scope_percentage'].'%)'],
            ['label' => __('app.ai_usage_rate_limited'), 'value' => $usage['summary']['rate_limited_attempts']],
            ['label' => __('app.ai_usage_active_cooldowns'), 'value' => $usage['summary']['active_cooldowns']],
        ];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.ai_limits_usage') }}</h1>
            <p class="crm-page-copy">{{ __('app.ai_limits_usage_copy') }}</p>
        </div>
        <x-ui.button type="submit" form="ai-firewall-settings-form" class="self-start">
            {{ __('app.save') }}
        </x-ui.button>
    </div>

    <x-ui.panel class="mt-6">
        <nav class="border-b border-stone-100 pb-4" aria-label="{{ __('app.system_settings') }}">
            <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col">
                @foreach ($settingsTabs as $tab)
                    <a href="{{ $tab['url'] }}" class="crm-tab justify-start sm:justify-center">{{ $tab['label'] }}</a>
                @endforeach
                <span class="crm-tab justify-start bg-white text-slate-950 shadow-sm sm:justify-center" aria-current="page">
                    {{ __('app.system_settings_tab_ai_usage') }}
                </span>
            </div>
        </nav>

        <form id="ai-firewall-settings-form" method="POST" action="{{ route('platform.ai-usage.update') }}" class="mt-6 space-y-6">
            @csrf
            @method('PUT')

            <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-4">
                <input type="hidden" name="firewall_enabled" value="0">
                <input
                    type="checkbox"
                    name="firewall_enabled"
                    value="1"
                    class="crm-checkbox mt-1"
                    @checked((bool) old('firewall_enabled', $platformAiSetting->firewall_enabled))
                >
                <span>
                    <span class="block text-sm font-semibold text-slate-950">{{ __('app.ai_firewall_enabled') }}</span>
                    <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __('app.ai_firewall_enabled_copy') }}</span>
                </span>
            </label>

            <div class="grid gap-6 xl:grid-cols-3">
                @foreach ([
                    ['title' => __('app.ai_turn_limits'), 'copy' => __('app.ai_turn_limits_copy'), 'fields' => $turnFields],
                    ['title' => __('app.ai_provider_call_limits'), 'copy' => __('app.ai_provider_call_limits_copy'), 'fields' => $providerFields],
                    ['title' => __('app.ai_restriction_limits'), 'copy' => __('app.ai_restriction_limits_copy'), 'fields' => $restrictionFields],
                ] as $group)
                    <section class="rounded-xl border border-stone-200 bg-white p-4">
                        <h2 class="font-semibold text-slate-950">{{ $group['title'] }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">{{ $group['copy'] }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                            @foreach ($group['fields'] as $field)
                                <label class="block">
                                    <span class="crm-label">{{ $field['label'] }}</span>
                                    <input
                                        type="number"
                                        name="{{ $field['name'] }}"
                                        value="{{ old($field['name'], $platformAiSetting->{$field['name']}) }}"
                                        min="1"
                                        class="crm-field"
                                        required
                                    >
                                    @error($field['name'])
                                        <span class="crm-help">{{ $message }}</span>
                                    @enderror
                                </label>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel class="mt-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.ai_usage_statistics') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">
                {{ __('app.ai_usage_statistics_copy', ['from' => $usage['period']['from'], 'to' => $usage['period']['to']]) }}
            </p>
        </div>

        <form method="GET" action="{{ route('platform.ai-usage.index') }}" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <label class="block">
                <span class="crm-label">{{ __('app.period') }}</span>
                <select name="period" class="crm-field">
                    @foreach (['today' => __('app.today'), '7' => __('app.last_7_days'), '30' => __('app.last_30_days'), 'custom' => __('app.custom_period')] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['period'] ?? '30') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="crm-field">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="crm-field">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.studio') }}</span>
                <select name="account_id" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($accountOptions as $account)
                        <option value="{{ $account->id }}" @selected((string) ($filters['account_id'] ?? '') === (string) $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.user') }}</span>
                <select name="user_id" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($userOptions as $user)
                        <option value="{{ $user->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.channel') }}</span>
                <select name="channel" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($channelOptions as $channel)
                        <option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ $channel }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.provider') }}</span>
                <select name="provider" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($providerOptions as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.model') }}</span>
                <select name="model" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($modelOptions as $model)
                        <option value="{{ $model }}" @selected(($filters['model'] ?? '') === $model)>{{ $model }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    <option value="succeeded" @selected(($filters['status'] ?? '') === 'succeeded')>{{ __('app.succeeded') }}</option>
                    <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>{{ __('app.failed') }}</option>
                </select>
            </label>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary">{{ __('app.apply_filters') }}</x-ui.button>
                <x-ui.button :href="route('platform.ai-usage.index')" variant="ghost">{{ __('app.reset_filters') }}</x-ui.button>
            </div>
        </form>

        <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ($summaryCards as $card)
                <div class="rounded-xl border border-stone-200 bg-white p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold text-slate-950">
                        {{ is_numeric($card['value']) ? \Illuminate\Support\Number::format($card['value']) : $card['value'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                __('app.ai_usage_input_tokens') => $usage['summary']['input_tokens'],
                __('app.ai_usage_cached_input_tokens') => $usage['summary']['cached_input_tokens'],
                __('app.ai_usage_output_tokens') => $usage['summary']['output_tokens'],
                __('app.ai_usage_reasoning_tokens') => $usage['summary']['reasoning_tokens'],
                __('app.ai_usage_total_tokens') => $usage['summary']['total_tokens'],
            ] as $label => $value)
                <div class="rounded-xl bg-stone-50 p-4">
                    <p class="text-sm text-slate-500">{{ $label }}</p>
                    <p class="mt-1 text-lg font-semibold text-slate-950">{{ \Illuminate\Support\Number::format($value) }}</p>
                </div>
            @endforeach
        </div>
    </x-ui.panel>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        @foreach ([
            ['title' => __('app.ai_usage_by_provider_model'), 'rows' => $usage['provider_breakdown'], 'label' => fn ($row) => $row->provider.' / '.$row->model],
            ['title' => __('app.ai_usage_by_channel'), 'rows' => $usage['channel_breakdown'], 'label' => fn ($row) => $row->channel],
        ] as $breakdown)
            <x-ui.panel>
                <h2 class="text-lg font-semibold text-slate-950">{{ $breakdown['title'] }}</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-400">
                            <tr><th class="pb-3 pr-4">{{ __('app.name') }}</th><th class="pb-3 pr-4">{{ __('app.requests') }}</th><th class="pb-3">{{ __('app.tokens') }}</th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($breakdown['rows'] as $row)
                                <tr><td class="py-3 pr-4 font-medium text-slate-900">{{ $breakdown['label']($row) }}</td><td class="py-3 pr-4">{{ \Illuminate\Support\Number::format($row->requests) }}</td><td class="py-3">{{ \Illuminate\Support\Number::format($row->tokens ?? 0) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-center text-slate-500">{{ __('app.no_data') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <x-ui.panel>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.ai_usage_top_studios') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400"><tr><th class="pb-3 pr-4">{{ __('app.studio') }}</th><th class="pb-3 pr-4">{{ __('app.requests') }}</th><th class="pb-3 pr-4">{{ __('app.tokens') }}</th><th class="pb-3"></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($usage['top_accounts'] as $row)
                            <tr>
                                <td class="py-3 pr-4 font-medium text-slate-900">{{ $row->account?->name ?? '#'.$row->account_id }}</td>
                                <td class="py-3 pr-4">{{ \Illuminate\Support\Number::format($row->requests) }}</td>
                                <td class="py-3 pr-4">{{ \Illuminate\Support\Number::format($row->tokens ?? 0) }}</td>
                                <td class="py-3 text-right">
                                    @if ($row->account)
                                        <form method="POST" action="{{ route('platform.ai-usage.accounts.reset', $row->account) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('app.ai_reset_daily_counters') }}</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('app.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.panel>

        <x-ui.panel>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.ai_usage_top_users') }}</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-slate-400"><tr><th class="pb-3 pr-4">{{ __('app.user') }}</th><th class="pb-3 pr-4">{{ __('app.requests') }}</th><th class="pb-3 pr-4">{{ __('app.tokens') }}</th><th class="pb-3"></th></tr></thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($usage['top_users'] as $row)
                            <tr>
                                <td class="py-3 pr-4 font-medium text-slate-900">{{ $row->user?->name ?? '#'.$row->user_id }}</td>
                                <td class="py-3 pr-4">{{ \Illuminate\Support\Number::format($row->requests) }}</td>
                                <td class="py-3 pr-4">{{ \Illuminate\Support\Number::format($row->tokens ?? 0) }}</td>
                                <td class="py-3 text-right">
                                    @if ($row->user)
                                        <form method="POST" action="{{ route('platform.ai-usage.users.reset', $row->user) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm">{{ __('app.ai_reset_user_limits') }}</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-slate-500">{{ __('app.no_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.panel>
    </div>

    <x-ui.panel class="mt-6">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.ai_active_restrictions') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.ai_active_restrictions_copy') }}</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-400">
                    <tr><th class="pb-3 pr-4">{{ __('app.user') }}</th><th class="pb-3 pr-4">{{ __('app.studio') }}</th><th class="pb-3 pr-4">{{ __('app.reason') }}</th><th class="pb-3 pr-4">{{ __('app.strikes') }}</th><th class="pb-3 pr-4">{{ __('app.cooldown_level') }}</th><th class="pb-3 pr-4">{{ __('app.automatic_unblock') }}</th><th class="pb-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($usage['active_restrictions'] as $restriction)
                        <tr>
                            <td class="py-3 pr-4 font-medium text-slate-900">{{ $restriction->user?->name ?? '#'.$restriction->user_id }}</td>
                            <td class="py-3 pr-4">{{ $restriction->lastAccount?->name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ $restriction->blocked_reason }}</td>
                            <td class="py-3 pr-4">{{ $restriction->consecutive_out_of_scope_count }}</td>
                            <td class="py-3 pr-4">{{ $restriction->cooldown_level }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ $restriction->blocked_until?->format('Y-m-d H:i') }}</td>
                            <td class="py-3 text-right">
                                @if ($restriction->user)
                                    <form method="POST" action="{{ route('platform.ai-usage.users.reset', $restriction->user) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.reset') }}</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-slate-500">{{ __('app.ai_no_active_restrictions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>

    <x-ui.panel class="mt-6">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.ai_recent_provider_requests') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.ai_recent_provider_requests_copy') }}</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-400">
                    <tr><th class="pb-3 pr-4">{{ __('app.time') }}</th><th class="pb-3 pr-4">{{ __('app.studio') }}</th><th class="pb-3 pr-4">{{ __('app.user') }}</th><th class="pb-3 pr-4">{{ __('app.channel') }}</th><th class="pb-3 pr-4">{{ __('app.provider_model') }}</th><th class="pb-3 pr-4">{{ __('app.request_type') }}</th><th class="pb-3 pr-4">{{ __('app.status') }}</th><th class="pb-3 pr-4">{{ __('app.tokens') }}</th><th class="pb-3">{{ __('app.duration') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($usage['recent_requests'] as $providerRequest)
                        <tr>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ $providerRequest->started_at?->format('Y-m-d H:i:s') }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->account?->name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->user?->name ?? '—' }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->channel }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->provider }} / {{ $providerRequest->model }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->request_type }}{{ $providerRequest->provider_round ? ' #'.$providerRequest->provider_round : '' }}</td>
                            <td class="py-3 pr-4">{{ $providerRequest->status }}@if($providerRequest->error_code) · {{ $providerRequest->error_code }}@endif</td>
                            <td class="py-3 pr-4">{{ $providerRequest->total_tokens === null ? '—' : \Illuminate\Support\Number::format($providerRequest->total_tokens) }}</td>
                            <td class="py-3">{{ $providerRequest->duration_ms === null ? '—' : $providerRequest->duration_ms.' ms' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-6 text-center text-slate-500">{{ __('app.no_data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>
@endsection
