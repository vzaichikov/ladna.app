@extends('layouts.app')

@section('title', __('app.customer_class_passes').' - '.$account->name)

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return \App\Support\MoneyFormatter::format($priceCents, $currency);
        };
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.customer_class_passes') }}</h1>
            <p class="crm-page-copy">{{ __('app.customer_class_passes_copy') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.customer-class-passes.index', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="grid gap-4 lg:grid-cols-[1fr_180px_220px_auto_auto] lg:items-end">
            <label class="block">
                <span class="crm-label">{{ __('app.search') }}</span>
                <input name="q" value="{{ request('q') }}" class="crm-field" placeholder="{{ __('app.class_pass_search_placeholder') }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="state" class="crm-field">
                    <option value="active" @selected($state === 'active')>{{ __('app.active') }}</option>
                    <option value="inactive" @selected($state === 'inactive')>{{ __('app.inactive') }}</option>
                    <option value="all" @selected($state === 'all')>{{ __('app.all_statuses') }}</option>
                </select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.schedule_kind') }}</span>
                <select name="schedule_kind" class="crm-field">
                    <option value="">{{ __('app.all_formats') }}</option>
                    @foreach ($enabledScheduleKinds as $kind)
                        <option value="{{ $kind }}" @selected($scheduleKind === $kind)>{{ __('app.'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
            <x-ui.button type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.customer-class-passes.index', $account)" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($customerClassPasses as $customerClassPass)
            <div class="crm-row xl:grid-cols-[1fr_1fr_0.8fr_0.9fr_0.9fr_auto] xl:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $customerClassPass->code }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->plan_name }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $customerClassPass->customer?->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->customer?->phone ?? $customerClassPass->customer?->email ?? __('app.no_contact') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ $formatMoney($customerClassPass->price_cents, $customerClassPass->currency) }}</div>
                    <div class="mt-1">{{ $customerClassPass->remainingSessionsCount() }} / {{ $customerClassPass->sessions_count }} {{ __('app.classes_count') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ __('app.purchased_at') }}: {{ $customerClassPass->purchased_at?->format('Y-m-d') }}</div>
                    <div class="mt-1">{{ __('app.opened_at') }}: {{ $customerClassPass->opened_at?->format('Y-m-d') ?? __('app.not_set') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ __('app.expires_after_first_class') }}: {{ $customerClassPass->expires_at?->format('Y-m-d') ?? __('app.not_set') }}</div>
                    <div class="mt-1 whitespace-nowrap">{{ __('app.usable_until_at') }}: {{ $customerClassPass->usableUntilAt()?->format('Y-m-d') ?? __('app.not_set') }}</div>
                    <div class="mt-1">{{ __('app.reserved_sessions') }}: {{ $customerClassPass->reserved_sessions_count }}</div>
                </div>
                <div class="flex flex-wrap gap-2 xl:justify-end">
                    <span class="{{ $customerClassPass->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.'.$customerClassPass->status->value) }}</span>
                    <x-ui.action-button :href="route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])" icon="edit" :label="__('app.edit')" />
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_customer_class_passes')" icon="class-pass-plans" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div class="mt-6">
        {{ $customerClassPasses->links() }}
    </div>
@endsection
