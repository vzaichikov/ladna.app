@extends('layouts.app')

@section('title', __('app.email_deliveries').' - '.__('app.platform'))

@section('content')
    @php
        $formatDate = fn ($delivery, $date): string => \App\Support\DateTimePresenter::formatInTimezone(
            $date,
            \App\Support\DateTimePresenter::safeTimezone($delivery->account_timezone),
            'd.m.Y H:i',
        ) ?? __('app.not_set');
        $statusClass = fn ($deliveryStatus): string => match ($deliveryStatus?->value ?? $deliveryStatus) {
            \App\Enums\EmailDeliveryStatus::Sent->value => 'crm-status-active',
            \App\Enums\EmailDeliveryStatus::Failed->value => 'crm-status-danger',
            \App\Enums\EmailDeliveryStatus::Skipped->value => 'crm-status-muted',
            default => 'crm-status-scheduled',
        };
        $hasFilters = $search !== '' || $status !== '' || $scenario !== '' || $engine !== '';
    @endphp

    <div>
        <h1 class="crm-page-title">{{ __('app.email_deliveries') }}</h1>
        <p class="crm-page-copy">{{ __('app.email_deliveries_copy') }}</p>
    </div>

    <form method="GET" action="{{ route('platform.email-deliveries.index') }}" class="mt-6 grid gap-4 rounded-xl border border-stone-200 bg-white p-5 shadow-crm lg:grid-cols-[1fr_160px_220px_180px_auto] lg:items-end">
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="search" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.email_deliveries_search_placeholder') }}">
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $deliveryStatus)
                    <option value="{{ $deliveryStatus->value }}" @selected($status === $deliveryStatus->value)>{{ __($deliveryStatus->labelKey()) }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.email_scenario') }}</span>
            <select name="scenario" class="crm-field">
                <option value="">{{ __('app.all_email_scenarios') }}</option>
                @foreach ($scenarios as $emailScenario)
                    <option value="{{ $emailScenario->value }}" @selected($scenario === $emailScenario->value)>{{ __($emailScenario->labelKey()) }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.actual_transport') }}</span>
            <select name="engine" class="crm-field">
                <option value="">{{ __('app.all_transports') }}</option>
                @foreach ($engines as $mailEngine)
                    <option value="{{ $mailEngine->value }}" @selected($engine === $mailEngine->value)>{{ __($mailEngine->labelKey()) }}</option>
                @endforeach
            </select>
        </label>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" variant="secondary">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            @if ($hasFilters)
                <x-ui.button :href="route('platform.email-deliveries.index')" variant="ghost">{{ __('app.reset_filters') }}</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @if ($deliveries->isEmpty())
            <x-ui.empty-state :title="__('app.email_deliveries_empty')" icon="mail-check" class="m-5" />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1280px] text-left text-sm">
                    <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.account') }}</th>
                            <th class="px-5 py-3">{{ __('app.recipient') }}</th>
                            <th class="px-5 py-3">{{ __('app.email_scenario') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3">{{ __('app.delivery') }}</th>
                            <th class="px-5 py-3">{{ __('app.timestamps') }}</th>
                            <th class="px-5 py-3">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($deliveries as $delivery)
                            <tr class="align-top">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $delivery->account?->name ?? data_get($delivery->payload, 'account_name', __('app.not_set')) }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $delivery->account?->slug ?? __('app.deleted_record') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">#{{ $delivery->account_id ?? __('app.not_set') }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ $delivery->recipient_name ?: __('app.not_set') }}</div>
                                    <div class="mt-1 font-mono text-xs text-slate-700">{{ $delivery->recipient_email }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ __($delivery->recipient_kind->labelKey()) }} · {{ strtoupper($delivery->locale) }}</div>
                                </td>
                                <td class="max-w-md px-5 py-4">
                                    <div class="font-semibold text-slate-950">{{ __($delivery->scenario->labelKey()) }}</div>
                                    <div class="mt-1 text-sm leading-5 text-slate-700">{{ $delivery->subject ?: __('app.not_set') }}</div>
                                    @if ($delivery->last_error)
                                        <div class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs leading-5 text-rose-800">{{ $delivery->last_error }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $statusClass($delivery->status) }}">{{ __($delivery->status->labelKey()) }}</span>
                                    <div class="mt-2 text-xs text-slate-500">{{ __('app.attempts') }}: {{ $delivery->attempts }}</div>
                                    @if ($delivery->status_reason)
                                        <div class="mt-1 text-xs text-slate-500">{{ __('app.reason') }}: {{ __('app.email_delivery_reason_'.$delivery->status_reason) }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    <div>{{ __('app.configured_transport') }}: {{ $delivery->configured_engine ? __('app.mail_engine_'.$delivery->configured_engine) : __('app.not_set') }}</div>
                                    <div class="mt-1">{{ __('app.actual_transport') }}: {{ $delivery->actual_engine ? __('app.mail_engine_'.$delivery->actual_engine) : __('app.not_set') }}</div>
                                    @if ($delivery->fallback_used)
                                        <div class="mt-1 text-xs font-semibold text-amber-700">{{ __('app.fallback_used') }}</div>
                                    @endif
                                    @if ($delivery->provider_message_id)
                                        <div class="mt-2 max-w-52 break-all font-mono text-xs text-slate-500">{{ $delivery->provider_message_id }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-slate-700">
                                    <div>{{ __('app.queued_at') }}: {{ $formatDate($delivery, $delivery->queued_at) }}</div>
                                    <div class="mt-1">{{ __('app.sent_at') }}: {{ $formatDate($delivery, $delivery->sent_at) }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $delivery->account_timezone }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($delivery->html_body || $delivery->text_body)
                                        <x-ui.button
                                            :href="route('platform.email-deliveries.preview', $delivery)"
                                            variant="secondary"
                                            size="sm"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('app.view_email_snapshot') }}
                                        </x-ui.button>
                                    @else
                                        <span class="text-xs text-slate-500">{{ __('app.snapshot_not_available') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($deliveries->hasPages())
            <div class="border-t border-stone-100 px-5 py-4">{{ $deliveries->links() }}</div>
        @endif
    </x-ui.panel>
@endsection
