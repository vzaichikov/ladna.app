@extends('layouts.app')

@section('title', __('app.promo_codes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.promo_codes') }}</h1>
            <p class="crm-page-copy">{{ __('app.studio_promo_codes_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.promo-codes.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_promo_code') }}
        </x-ui.button>
    </div>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.promo-codes.index', $account)"
        :reset-href="route('dashboard.accounts.promo-codes.index', $account)"
        class="mt-6 sm:grid-cols-3"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $query }}" class="crm-field" placeholder="{{ __('app.promo_code_search_placeholder') }}">
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($status === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($status === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.discount_type') }}</span>
            <select name="discount_type" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="fixed" @selected($discountType === 'fixed')>{{ __('app.discount_type_fixed') }}</option>
                <option value="percent" @selected($discountType === 'percent')>{{ __('app.discount_type_percent') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($promoCodes as $promoCode)
            @php
                $discount = $promoCode->discount_type === \App\Enums\PromoCodeDiscountType::Fixed
                    ? \App\Support\MoneyFormatter::format($promoCode->discount_value, $promoCode->currency)
                    : $promoCode->discount_value.'%';
                $startsAt = $promoCode->starts_at->copy()->timezone($account->timezone);
                $endsAt = $promoCode->ends_at->copy()->timezone($account->timezone);
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_0.8fr_1.1fr_0.7fr_auto] lg:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $promoCode->name }}</h2>
                    <div class="mt-1 font-mono text-sm font-semibold text-brand-700">{{ $promoCode->code }}</div>
                </div>
                <div>
                    <div class="font-semibold text-slate-950">{{ $discount }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ trans_choice('app.promo_code_selected_plans', $promoCode->class_pass_plans_count, ['count' => $promoCode->class_pass_plans_count]) }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ $startsAt->format('d.m.Y H:i') }}</div>
                    <div>{{ $endsAt->format('d.m.Y H:i') }}</div>
                </div>
                <div class="text-sm text-slate-600">
                    <div>{{ __('app.promo_code_uses') }}: {{ $promoCode->uses_count }} / {{ $promoCode->max_total_uses ?? '∞' }}</div>
                    <div>{{ __('app.promo_code_per_customer') }}: {{ $promoCode->max_uses_per_identity ?? '∞' }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <span class="{{ $promoCode->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                        {{ $promoCode->is_active ? __('app.active') : __('app.inactive') }}
                    </span>
                    <x-ui.action-button :href="route('dashboard.accounts.promo-codes.edit', [$account, $promoCode])" icon="edit" :label="__('app.edit')" />
                    @if ($promoCode->customer_purchases_count === 0)
                        <form method="POST" action="{{ route('dashboard.accounts.promo-codes.destroy', [$account, $promoCode]) }}" data-confirm-delete>
                            @csrf
                            @method('DELETE')
                            <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                        </form>
                    @else
                        <span class="max-w-52 text-right text-xs text-slate-500">{{ __('app.promo_code_used_help') }}</span>
                    @endif
                </div>
            </div>
        @empty
            <x-ui.empty-state
                :title="$query !== '' || $status !== '' || $discountType !== '' ? __('app.no_promo_codes_match') : __('app.no_promo_codes')"
                icon="ticket"
                class="m-5"
            />
        @endforelse
    </x-ui.panel>

    @if ($promoCodes->hasPages())
        <div class="mt-6">{{ $promoCodes->links() }}</div>
    @endif
@endsection
