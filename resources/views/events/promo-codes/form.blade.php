@extends('layouts.app')

@php
    $editing = $promoCode->exists;
    $selectedIds = collect(old('ticket_type_ids', $selectedTicketTypeIds))->map(fn ($id) => (int) $id);
    $discountAmount = old('discount_amount');

    if ($discountAmount === null && $editing) {
        $discountAmount = $promoCode->discount_type === \App\Enums\PromoCodeDiscountType::Fixed
            ? \App\Support\Payments\PaymentAmounts::centsToDecimalString($promoCode->discount_value)
            : $promoCode->discount_value;
    }
@endphp

@section('title', ($editing ? __('app.promo_code_edit') : __('app.promo_code_add')).' - '.$event->title)

@section('content')
<div class="w-full min-w-0 space-y-6" data-event-admin-page>
    <x-ui.page-header
        :title="$editing ? __('app.promo_code_edit') : __('app.promo_code_add')"
        :copy="__('app.event_promo_code_form_help', ['event' => $event->title])"
    />

    <x-ui.event-navigation :account="$account" :event="$event" active="promo-codes" />

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $editing
            ? route('dashboard.accounts.events.promo-codes.update', [$account, $event, $promoCode])
            : route('dashboard.accounts.events.promo-codes.store', [$account, $event]) }}"
        class="space-y-6"
    >
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block">
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name', $promoCode->name) }}" maxlength="255" required class="crm-field">
                    <x-ui.field-error name="name" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.promo_code') }}</span>
                    <input name="code" value="{{ old('code', $promoCode->code) }}" minlength="3" maxlength="64" required class="crm-field font-mono uppercase">
                    <x-ui.field-error name="code" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.discount_type') }}</span>
                    <select name="discount_type" required class="crm-field">
                        <option value="fixed" @selected(old('discount_type', $promoCode->discount_type?->value) === 'fixed')>{{ __('app.promo_code_discount_fixed') }}</option>
                        <option value="percent" @selected(old('discount_type', $promoCode->discount_type?->value ?? 'percent') === 'percent')>{{ __('app.promo_code_discount_percent') }}</option>
                    </select>
                    <x-ui.field-error name="discount_type" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.discount') }}</span>
                    <input type="number" name="discount_amount" value="{{ $discountAmount }}" min="0.01" step="0.01" required inputmode="decimal" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_discount_help', ['currency' => $event->currency]) }}</span>
                    <x-ui.field-error name="discount_amount" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.starts_at') }}</span>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $promoCode->starts_at?->timezone($event->timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <x-ui.field-error name="starts_at" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.ends_at') }}</span>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $promoCode->ends_at?->timezone($event->timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <x-ui.field-error name="ends_at" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.promo_code_total_limit') }}</span>
                    <input type="number" name="max_total_uses" value="{{ old('max_total_uses', $promoCode->max_total_uses) }}" min="1" step="1" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_unlimited_when_empty') }}</span>
                    <x-ui.field-error name="max_total_uses" />
                </label>
                <label class="block">
                    <span class="crm-label">{{ __('app.promo_code_per_identity_limit') }}</span>
                    <input type="number" name="max_uses_per_identity" value="{{ old('max_uses_per_identity', $promoCode->max_uses_per_identity) }}" min="1" step="1" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_unlimited_when_empty') }}</span>
                    <x-ui.field-error name="max_uses_per_identity" />
                </label>
            </div>

            <fieldset class="mt-6">
                <legend class="crm-label">{{ __('app.event_ticket_types') }}</legend>
                <p class="crm-help">{{ __('app.event_promo_code_ticket_types_help') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($ticketTypes as $ticketType)
                        <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-slate-50 px-4 py-3 text-sm">
                            <input type="checkbox" name="ticket_type_ids[]" value="{{ $ticketType->id }}" @checked($selectedIds->contains($ticketType->id)) class="crm-checkbox mt-0.5">
                            <span class="min-w-0">
                                <span class="font-semibold text-slate-900">{{ $ticketType->name }}</span>
                                <span class="mt-1 block text-xs text-slate-500">{{ \App\Support\MoneyFormatter::format($ticketType->price_cents, $event->currency) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-ui.field-error name="ticket_type_ids" class="mt-2" />
            </fieldset>

            <label class="mt-6 flex items-center gap-3 text-sm font-semibold text-slate-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $promoCode->is_active)) class="crm-checkbox">
                {{ __('app.active') }}
            </label>
        </section>

        <div class="flex flex-wrap justify-end gap-2 rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <x-ui.button :href="route('dashboard.accounts.events.promo-codes.index', [$account, $event])" variant="secondary">
                {{ __('app.cancel') }}
            </x-ui.button>
            <x-ui.button type="submit">
                <x-ui.icon name="save" class="h-4 w-4" />
                {{ __('app.save') }}
            </x-ui.button>
        </div>
    </form>
</div>
@endsection
