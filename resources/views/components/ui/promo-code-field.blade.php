@props([
    'id' => 'promo-code',
    'festival' => false,
])

@php
    $savedPromoCode = old('promo_code');
@endphp

<div
    {{ $attributes->class([
        'rounded-xl border p-4',
        'festival-border festival-page' => $festival,
        'border-stone-200 bg-slate-50' => ! $festival,
    ]) }}
    data-promo-code
    data-promo-code-success="{{ __('app.promo_code_applied') }}"
    data-promo-code-required="{{ __('app.promo_code_required') }}"
    data-promo-code-generic-error="{{ __('app.promo_code_apply_failed') }}"
>
    <label for="{{ $id }}" class="crm-label">{{ __('app.have_promo_code') }}</label>
    <div class="mt-2 flex flex-col gap-2 sm:flex-row">
        <input
            id="{{ $id }}"
            value="{{ $savedPromoCode }}"
            maxlength="64"
            autocomplete="off"
            class="crm-field font-mono uppercase"
            data-promo-code-input
        >
        <input type="hidden" name="promo_code" value="{{ $savedPromoCode }}" data-promo-code-hidden>
        <x-ui.button type="button" variant="secondary" data-promo-code-apply>{{ __('app.apply') }}</x-ui.button>
        <x-ui.button type="button" variant="secondary" class="!hidden" data-promo-code-remove>{{ __('app.remove') }}</x-ui.button>
    </div>
    <p class="mt-2 hidden text-sm font-semibold text-emerald-700" data-promo-code-result aria-live="polite"></p>
    <p class="mt-2 hidden text-sm font-semibold text-rose-700" data-promo-code-error aria-live="polite"></p>
    <x-ui.field-error name="promo_code" class="mt-2" />
</div>
