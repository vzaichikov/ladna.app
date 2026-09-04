@extends('layouts.app')

@section('title', __('app.promo_codes').' - '.$event->title)

@section('content')
<div class="w-full min-w-0 space-y-6" data-event-admin-page>
    <x-ui.page-header :title="__('app.promo_codes')" :copy="__('app.event_promo_codes_help')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.events.promo-codes.create', [$account, $event])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.promo_code_add') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.event-navigation :account="$account" :event="$event" active="promo-codes" />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.events.promo-codes.index', [$account, $event])"
        :reset-href="route('dashboard.accounts.events.promo-codes.index', [$account, $event])"
        class="sm:grid-cols-[minmax(0,1fr)_14rem]"
    >
        <label class="block">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.promo_code_search_placeholder') }}">
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    @if ($promoCodes->isEmpty())
        <x-ui.empty-state :title="$hasFilters ? __('app.no_matching_results') : __('app.promo_codes_empty')" icon="ticket">
            <p>{{ $hasFilters ? __('app.change_or_reset_filters') : __('app.event_promo_codes_empty_help') }}</p>
            <div class="mt-4">
                <x-ui.button
                    :href="$hasFilters
                        ? route('dashboard.accounts.events.promo-codes.index', [$account, $event])
                        : route('dashboard.accounts.events.promo-codes.create', [$account, $event])"
                    variant="secondary"
                >
                    {{ $hasFilters ? __('app.reset_filters') : __('app.promo_code_add') }}
                </x-ui.button>
            </div>
        </x-ui.empty-state>
    @else
        <div class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-crm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.promo_code') }}</th>
                            <th class="px-5 py-3">{{ __('app.discount') }}</th>
                            <th class="px-5 py-3">{{ __('app.validity_period') }}</th>
                            <th class="px-5 py-3">{{ __('app.promo_code_uses') }}</th>
                            <th class="px-5 py-3">{{ __('app.status') }}</th>
                            <th class="px-5 py-3 text-right">{{ __('app.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($promoCodes as $promoCode)
                            <tr>
                                <td class="px-5 py-4">
                                    <strong class="text-slate-950">{{ $promoCode->name }}</strong>
                                    <p class="mt-1 font-mono text-xs font-semibold text-brand-700">{{ $promoCode->code }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ __('app.promo_code_ticket_type_count', ['count' => $promoCode->ticket_types_count]) }}</p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-950">
                                    {{ $promoCode->discount_type === \App\Enums\PromoCodeDiscountType::Percent
                                        ? $promoCode->discount_value.'%'
                                        : \App\Support\MoneyFormatter::format($promoCode->discount_value, $promoCode->currency) }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600">
                                    <p>{{ $promoCode->starts_at->timezone($event->timezone)->format('d.m.Y H:i') }}</p>
                                    <p>{{ $promoCode->ends_at->timezone($event->timezone)->format('d.m.Y H:i') }}</p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4">
                                    <span class="font-semibold text-slate-950">{{ $promoCode->uses_count }}</span>
                                    <span class="text-slate-500">/ {{ $promoCode->max_total_uses ?? '∞' }}</span>
                                    <p class="mt-1 text-xs text-slate-500">{{ __('app.promo_code_per_identity_limit') }}: {{ $promoCode->max_uses_per_identity ?? '∞' }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="{{ $promoCode->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                                        {{ $promoCode->is_active ? __('app.active') : __('app.inactive') }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        <x-ui.button :href="route('dashboard.accounts.events.promo-codes.edit', [$account, $event, $promoCode])" variant="secondary" size="sm">
                                            {{ __('app.edit') }}
                                        </x-ui.button>
                                        @if ($promoCode->history_count === 0)
                                            <form method="POST" action="{{ route('dashboard.accounts.events.promo-codes.destroy', [$account, $event, $promoCode]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                    @if ($promoCode->history_count > 0)
                                        <p class="mt-2 text-right text-xs text-slate-500">{{ __('app.promo_code_used_help') }}</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{ $promoCodes->links() }}
    @endif
</div>
@endsection
