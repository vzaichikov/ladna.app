@extends('layouts.app')

@section('title', __('app.preview').' - '.$plan->name)

@section('content')
    @php($formatMoney = fn (int $cents): string => \App\Support\MoneyFormatter::format($cents, $priceVersion->currency))
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ $plan->name }}</div>
            <h1 class="crm-page-title">{{ __('app.price_version_number', ['version' => $priceVersion->version]) }}</h1>
            <p class="crm-page-copy">{{ __('app.price_version_preview_copy') }}</p>
        </div>
        @if ($priceVersion->status === \App\Enums\SubscriptionPriceStatus::Draft)
            <x-ui.button :href="route('platform.subscription-plans.price-versions.edit', [$plan, $priceVersion])" variant="secondary">{{ __('app.edit') }}</x-ui.button>
        @endif
    </div>

    @error('price_version')
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900">{{ $message }}</div>
    @enderror

    <section class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-ui.metric :label="__('app.price_version_status')" :value="__('app.price_status_'.$priceVersion->status->value)" icon="bell" />
        <x-ui.metric :label="__('app.trial_days')" :value="$priceVersion->trial_days" icon="schedule" accent="emerald" />
        <x-ui.metric :label="__('app.annual_discount_percent')" :value="$priceVersion->annual_discount_percent.'%'" icon="payments" accent="amber" />
    </section>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="grid grid-cols-[100px_1fr_1fr] gap-4 border-b border-stone-100 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <div>{{ __('app.locations') }}</div><div>{{ __('app.monthly') }}</div><div>{{ __('app.annual') }}</div>
        </div>
        @foreach ($quotes as $locations => $quote)
            <div class="grid grid-cols-[100px_1fr_1fr] gap-4 border-b border-stone-100 px-5 py-4 text-sm last:border-0">
                <div class="font-semibold text-slate-950">{{ $locations }}</div>
                <div>{{ $formatMoney($quote['monthly']->finalAmountCents) }}</div>
                <div>{{ $formatMoney($quote['annual']->finalAmountCents) }} <span class="text-emerald-700">−{{ $formatMoney($quote['annual']->discountCents) }}</span></div>
            </div>
        @endforeach
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="border-b border-stone-100 p-5">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_tariff_packages') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_tariff_packages_preview_help') }}</p>
        </div>
        @forelse($plan->festivalTariffPackages as $package)
            <div class="crm-row lg:grid-cols-[120px_1fr_1fr_180px_auto] lg:items-center">
                <strong class="text-slate-950">{{ $package->name }}</strong>
                <span>{{ trans_choice('app.festival_participants_count', $package->max_participants, ['count' => $package->max_participants]) }}</span>
                <span>{{ trans_choice('app.festival_tickets_count', $package->max_tickets, ['count' => $package->max_tickets]) }}</span>
                <span class="font-semibold">{{ \App\Support\MoneyFormatter::format($package->price_cents, $package->currency) }}</span>
                <span class="{{ $package->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ $package->is_active ? __('app.active') : __('app.inactive') }}</span>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_festival_tariff_packages')" icon="trophy" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div class="mt-6 flex flex-wrap gap-3">
        @if ($priceVersion->status === \App\Enums\SubscriptionPriceStatus::Draft)
            <form method="POST" action="{{ route('platform.subscription-plans.price-versions.publish', [$plan, $priceVersion]) }}">
                @csrf
                <x-ui.button type="submit">{{ __('app.publish_now') }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('platform.subscription-plans.price-versions.schedule', [$plan, $priceVersion]) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label><span class="crm-label">{{ __('app.effective_at') }}</span><input type="datetime-local" name="effective_at" required class="crm-field"></label>
                <x-ui.button type="submit" variant="secondary">{{ __('app.schedule_publication') }}</x-ui.button>
            </form>
        @elseif ($priceVersion->status === \App\Enums\SubscriptionPriceStatus::Scheduled)
            <form method="POST" action="{{ route('platform.subscription-plans.price-versions.publish', [$plan, $priceVersion]) }}">
                @csrf
                <x-ui.button type="submit">{{ __('app.publish_scheduled_version') }}</x-ui.button>
            </form>
        @elseif ($priceVersion->status === \App\Enums\SubscriptionPriceStatus::Published)
            <form method="POST" action="{{ route('platform.subscription-plans.price-versions.retire', [$plan, $priceVersion]) }}" data-confirm-delete>
                @csrf
                <x-ui.button type="submit" variant="danger">{{ __('app.retire_price_version') }}</x-ui.button>
            </form>
        @endif
    </div>
@endsection
