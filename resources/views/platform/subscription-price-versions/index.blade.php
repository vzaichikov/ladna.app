@extends('layouts.app')

@section('title', __('app.price_versions').' - '.$plan->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.subscription_plans') }}</div>
            <h1 class="crm-page-title">{{ __('app.price_versions') }} · {{ $plan->name }}</h1>
            <p class="crm-page-copy">{{ __('app.price_versions_admin_copy') }}</p>
        </div>
        <x-ui.button :href="route('platform.subscription-plans.price-versions.create', $plan)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_price_version') }}
        </x-ui.button>
    </div>

    @error('price_version')
        <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900">{{ $message }}</div>
    @enderror

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($priceVersions as $priceVersion)
            @php
                $statusClass = match ($priceVersion->status) {
                    \App\Enums\SubscriptionPriceStatus::Published => 'crm-status-active',
                    \App\Enums\SubscriptionPriceStatus::Scheduled => 'crm-status-scheduled',
                    \App\Enums\SubscriptionPriceStatus::Retired => 'crm-status-muted',
                    default => 'crm-status-warning',
                };
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_150px_180px_150px_auto] lg:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ __('app.price_version_number', ['version' => $priceVersion->version]) }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ trans_choice('app.price_tiers_count', $priceVersion->tiers_count, ['count' => $priceVersion->tiers_count]) }}</div>
                </div>
                <span class="{{ $statusClass }}">{{ __('app.price_status_'.$priceVersion->status->value) }}</span>
                <div class="text-sm text-slate-600">
                    {{ $priceVersion->effective_at?->format('d.m.Y H:i') ?? __('app.not_set') }}
                </div>
                <div class="text-sm text-slate-600">
                    {{ __('app.used_by_subscriptions_and_payments', ['subscriptions' => $priceVersion->subscriptions_count, 'payments' => $priceVersion->payments_count]) }}
                </div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.action-button :href="route('platform.subscription-plans.price-versions.preview', [$plan, $priceVersion])" icon="eye" :label="__('app.preview')" />
                    @if ($priceVersion->status === \App\Enums\SubscriptionPriceStatus::Draft)
                        <x-ui.action-button :href="route('platform.subscription-plans.price-versions.edit', [$plan, $priceVersion])" icon="edit" :label="__('app.edit')" />
                        <form method="POST" action="{{ route('platform.subscription-plans.price-versions.destroy', [$plan, $priceVersion]) }}" data-confirm-delete>
                            @csrf
                            @method('DELETE')
                            <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_price_versions')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
