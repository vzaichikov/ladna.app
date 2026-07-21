@extends('layouts.app')

@section('title', __('app.subscription_plans').' - '.__('app.platform'))

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="crm-page-kicker">{{ __('app.platform') }}</div>
            <h1 class="crm-page-title">{{ __('app.subscription_plans') }}</h1>
        </div>
        <x-ui.button :href="route('platform.subscription-plans.create')">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_subscription_plan') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($plans as $plan)
            @php($currentPriceVersion = $currentPriceVersions->get($plan->id))
            <div class="crm-row lg:grid-cols-[1fr_140px_160px_120px_auto] lg:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $plan->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ $plan->slug }} · {{ trans_choice('app.price_versions_count', $plan->price_versions_count, ['count' => $plan->price_versions_count]) }} · {{ $plan->public_signup_enabled ? __('app.public_tariff') : __('app.private_tariff') }}
                    </p>
                </div>
                <div class="text-sm font-semibold text-slate-700">{{ __('app.subscription_plan_type_'.$plan->plan_type->value) }}</div>
                <div class="text-sm font-semibold text-slate-700">
                    @if ($currentPriceVersion)
                        {{ __('app.from_price_per_location', ['price' => \App\Support\MoneyFormatter::format($currentPriceVersion->tiers->first()?->unit_price_cents, $currentPriceVersion->currency)]) }}
                    @else
                        {{ \App\Support\MoneyFormatter::format($plan->price_cents, $plan->currency) }}
                    @endif
                </div>
                <span class="{{ $plan->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $plan->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.action-button :href="route('platform.subscription-plans.price-versions.index', $plan)" icon="payments" :label="__('app.price_versions')" />
                    <x-ui.action-button :href="route('platform.subscription-plans.edit', $plan)" icon="edit" :label="__('app.edit')" />
                    @if ($plan->subscriptions_count === 0 && $plan->subscription_payments_count === 0 && $plan->price_versions_count === 0)
                        <form method="POST" action="{{ route('platform.subscription-plans.destroy', $plan) }}" data-confirm-delete>
                            @csrf
                            @method('DELETE')
                            <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_subscription_plans')" icon="platform" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
