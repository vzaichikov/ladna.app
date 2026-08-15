@extends('layouts.app')

@section('title', __('app.checkbox_receipt_log').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.checkbox_receipt_log') }}</h1>
            <p class="crm-page-copy">{{ __('app.checkbox_receipt_log_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.integrations.show', [$account, \App\Enums\IntegrationCategory::Fiscalization])" variant="secondary">
            {{ __('app.open_fiscalization_settings') }}
        </x-ui.button>
    </div>

    <x-integration-category-navigation :account="$account" :active-category="\App\Enums\IntegrationCategory::Fiscalization" class="mt-6" />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.integrations.checkbox-logs.index', $account)"
        :reset-href="route('dashboard.accounts.integrations.checkbox-logs.index', $account)"
        class="mt-5 sm:grid-cols-[minmax(0,1fr)_14rem]"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $search }}" class="crm-field" placeholder="{{ __('app.checkbox_receipt_log_search_placeholder') }}">
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all_statuses') }}</option>
                @foreach ($statuses as $receiptStatus)
                    <option value="{{ $receiptStatus->value }}" @selected($status === $receiptStatus->value)>{{ __('app.fiscal_status_'.$receiptStatus->value) }}</option>
                @endforeach
            </select>
        </label>
    </x-ui.filter-bar>

    <div class="mt-6 grid gap-4">
        @forelse ($receipts as $entry)
            @php
                $statusClass = match ($entry['status']) {
                    \App\Enums\FiscalReceiptStatus::Fiscalized => 'crm-status-active',
                    \App\Enums\FiscalReceiptStatus::Failed => 'crm-status-danger',
                    default => 'crm-status-scheduled',
                };
                $updatedAt = $entry['fiscalized_at'] ?? $entry['failed_at'] ?? $entry['sent_at'] ?? $entry['updated_at'];
                $formatDate = fn ($date): string => \App\Support\DateTimePresenter::formatInTimezone(
                    $date,
                    \App\Support\DateTimePresenter::safeTimezone($account->timezone),
                    'd.m.Y H:i:s',
                ) ?? __('app.not_set');
            @endphp

            <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $statusClass }}">{{ __('app.fiscal_status_'.$entry['status']->value) }}</span>
                            <span class="crm-status-muted">{{ $entry['source_label'] }}</span>
                        </div>
                        <h2 class="mt-3 break-all font-mono text-sm font-semibold text-slate-950">{{ $entry['reference'] }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $entry['payment_provider'] }} · {{ \App\Support\MoneyFormatter::format($entry['amount_cents'], $entry['currency']) }}</p>
                    </div>
                    <dl class="grid shrink-0 gap-1 text-sm text-slate-600 lg:text-right">
                        <div><dt class="inline font-semibold text-slate-800">{{ __('app.attempts') }}:</dt> <dd class="inline">{{ $entry['attempts'] }}</dd></div>
                        <div><dt class="inline font-semibold text-slate-800">{{ __('app.updated_at') }}:</dt> <dd class="inline">{{ $formatDate($updatedAt) }}</dd></div>
                    </dl>
                </div>

                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg bg-stone-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.checkbox_request_id') }}</div>
                        <div class="mt-1 break-all font-mono text-xs text-slate-800">{{ $entry['request_summary']['id'] ?: __('app.not_set') }}</div>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.checkbox_provider_receipt_id') }}</div>
                        <div class="mt-1 break-all font-mono text-xs text-slate-800">{{ $entry['provider_receipt_id'] ?: __('app.not_set') }}</div>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.fiscal_receipt_number') }}</div>
                        <div class="mt-1 break-all font-mono text-xs text-slate-800">{{ $entry['fiscal_number'] ?: __('app.not_set') }}</div>
                    </div>
                    <div class="rounded-lg bg-stone-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('app.event_fiscal_provider_status') }}</div>
                        <div class="mt-1 break-all text-xs text-slate-800">{{ $entry['safe_provider_status'] ?: $entry['response_details']['status'] ?: __('app.not_set') }}</div>
                    </div>
                </div>

                @if ($entry['safe_error'] || $entry['response_details']['message'] || $entry['response_details']['validation'] !== [])
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        <strong>{{ $entry['safe_error'] ?: $entry['response_details']['message'] ?: __('app.fiscal_status_failed') }}</strong>
                        @foreach ($entry['response_details']['validation'] as $validation)
                            <div class="mt-2">
                                @if ($validation['location'] !== '')<span class="font-mono text-xs">{{ $validation['location'] }}</span>@endif
                                @if ($validation['message'] !== '')<span class="block">{{ $validation['message'] }}</span>@endif
                                @if ($validation['type'] !== '')<span class="block text-xs text-rose-700">{{ $validation['type'] }}</span>@endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <details class="mt-4 rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-900">{{ __('app.checkbox_request_summary') }}</summary>
                    <div class="mt-3 grid gap-4 text-sm text-slate-700 lg:grid-cols-2">
                        <div>
                            <h3 class="font-semibold text-slate-950">{{ __('app.items') }}</h3>
                            <div class="mt-2 grid gap-2">
                                @forelse ($entry['request_summary']['goods'] as $good)
                                    <div class="rounded-lg bg-white p-3">
                                        <div class="font-semibold">{{ $good['name'] ?: __('app.not_set') }}</div>
                                        <div class="mt-1 break-all font-mono text-xs text-slate-500">{{ $good['code'] ?: __('app.not_set') }}</div>
                                        @if ($good['price'] !== null)<div class="mt-1 text-xs">{{ \App\Support\MoneyFormatter::format((int) $good['price'], $entry['currency']) }}</div>@endif
                                    </div>
                                @empty
                                    <span class="text-slate-500">{{ __('app.not_set') }}</span>
                                @endforelse
                            </div>
                        </div>
                        <dl class="grid content-start gap-2">
                            <div><dt class="font-semibold text-slate-950">{{ __('app.total') }}</dt><dd>{{ $entry['request_summary']['total_sum'] !== null ? \App\Support\MoneyFormatter::format((int) $entry['request_summary']['total_sum'], $entry['currency']) : __('app.not_set') }}</dd></div>
                            <div><dt class="font-semibold text-slate-950">{{ __('app.delivery') }}</dt><dd>{{ $entry['request_summary']['delivery_channels'] !== [] ? collect($entry['request_summary']['delivery_channels'])->map(fn ($channel) => __('app.'.$channel))->implode(', ') : __('app.not_set') }}</dd></div>
                            <div><dt class="font-semibold text-slate-950">{{ __('app.sent_at') }}</dt><dd>{{ $formatDate($entry['sent_at']) }}</dd></div>
                        </dl>
                    </div>
                </details>
            </article>
        @empty
            <x-ui.empty-state :title="$search !== '' || $status !== '' ? __('app.checkbox_receipt_log_no_matches') : __('app.checkbox_receipt_log_empty')" icon="history" />
        @endforelse
    </div>

    @if ($receipts->hasPages())
        <div class="mt-5">{{ $receipts->links() }}</div>
    @endif
@endsection
