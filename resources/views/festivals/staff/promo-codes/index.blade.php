@extends('layouts.app')

@section('title', __('app.promo_codes').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.promo_codes')" :copy="__('app.festival_promo_codes_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.promo-codes.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" /> {{ __('app.promo_code_add') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition])" class="sm:grid-cols-2">
        <label>
            <span class="crm-label">{{ __('app.search') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.promo_code_search_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    @error('promo_code')
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <div class="space-y-3">
        @forelse ($promoCodes as $promoCode)
            @php
                $usedCount = $promoCode->consumed_usage_count + $promoCode->reserved_usage_count;
                $currentlyAvailable = $promoCode->is_active && $promoCode->starts_at->isPast() && $promoCode->ends_at->isFuture();
            @endphp
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">{{ $promoCode->name }}</h2>
                            <code class="rounded-lg bg-slate-100 px-2.5 py-1 text-sm font-semibold text-slate-800">{{ $promoCode->code }}</code>
                            <span class="{{ $currentlyAvailable ? 'crm-status-active' : 'crm-status-muted' }}">{{ $currentlyAvailable ? __('app.active') : __('app.inactive') }}</span>
                        </div>
                        <p class="mt-2 text-sm font-semibold text-slate-800">
                            @if ($promoCode->discount_type === \App\Enums\PromoCodeDiscountType::Fixed)
                                {{ \App\Support\MoneyFormatter::format($promoCode->discount_value, $promoCode->currency) }}
                            @else
                                {{ $promoCode->discount_value }}%
                            @endif
                        </p>
                        <p class="mt-2 text-sm text-slate-600">{{ $promoCode->admissionTypes->pluck('name')->join(', ') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <form method="POST" action="{{ route('dashboard.accounts.festivals.promo-codes.toggle', [$account, $edition, $promoCode]) }}">
                            @csrf @method('PATCH')
                            <x-ui.action-button type="submit" :icon="$promoCode->is_active ? 'pause' : 'play'" :label="$promoCode->is_active ? __('app.deactivate') : __('app.activate')" />
                        </form>
                        <x-ui.action-button :href="route('dashboard.accounts.festivals.promo-codes.edit', [$account, $edition, $promoCode])" icon="edit" :label="__('app.edit')" />
                        @if ($promoCode->referenced_orders_count === 0)
                            <form method="POST" action="{{ route('dashboard.accounts.festivals.promo-codes.destroy', [$account, $edition, $promoCode]) }}" data-confirm-delete data-confirm-title="{{ __('app.promo_code_delete_title') }}" data-confirm-body="{{ __('app.promo_code_delete_copy') }}">
                                @csrf @method('DELETE')
                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                            </form>
                        @endif
                    </div>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">{{ __('app.promo_code_validity') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $promoCode->starts_at->timezone($edition->timezone)->format('d.m.Y H:i') }} – {{ $promoCode->ends_at->timezone($edition->timezone)->format('d.m.Y H:i') }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">{{ __('app.promo_code_total_usage') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $usedCount }} / {{ $promoCode->total_usage_limit ?? '∞' }}</dd>
                        @if ($promoCode->reserved_usage_count > 0)<span class="mt-1 block text-xs text-slate-500">{{ __('app.promo_code_reserved_usage', ['count' => $promoCode->reserved_usage_count]) }}</span>@endif
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">{{ __('app.promo_code_per_identity_usage') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $promoCode->per_identity_usage_limit ?? '∞' }}</dd>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3">
                        <dt class="text-xs text-slate-500">{{ __('app.promo_code_history') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $promoCode->referenced_orders_count }}</dd>
                    </div>
                </dl>
            </article>
        @empty
            <x-ui.empty-state icon="ticket">{{ $filters['q'] || $filters['status'] ? __('app.no_data') : __('app.promo_codes_empty') }}</x-ui.empty-state>
        @endforelse
    </div>

    <div>{{ $promoCodes->links() }}</div>
</x-festivals.staff.workspace>
@endsection
