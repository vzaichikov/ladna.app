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
            <div class="crm-row lg:grid-cols-[1fr_140px_160px_120px_auto] lg:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $plan->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $plan->slug }}</p>
                </div>
                <div class="text-sm font-semibold text-slate-700">{{ __('app.subscription_plan_type_'.$plan->plan_type->value) }}</div>
                <div class="text-sm font-semibold text-slate-700">{{ \App\Support\MoneyFormatter::format($plan->price_cents, $plan->currency) }}</div>
                <span class="{{ $plan->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                    {{ $plan->is_active ? __('app.active') : __('app.inactive') }}
                </span>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.action-button :href="route('platform.subscription-plans.edit', $plan)" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('platform.subscription-plans.destroy', $plan) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_subscription_plans')" icon="platform" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
