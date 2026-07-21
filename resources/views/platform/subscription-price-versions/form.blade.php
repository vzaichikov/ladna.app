@php
    $tierRows = collect(old('tiers', $tiers->map(fn ($tier) => [
        'starts_at_location' => data_get($tier, 'starts_at_location'),
        'ends_at_location' => data_get($tier, 'ends_at_location'),
        'unit_price_uah' => number_format((int) data_get($tier, 'unit_price_cents') / 100, 2, '.', ''),
    ])->all()));
@endphp

<div class="grid gap-4 sm:grid-cols-3">
    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="currency" class="crm-field">
            @foreach (config('ladna.currencies') as $currency)
                <option value="{{ $currency }}" @selected(old('currency', $priceVersion->currency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.trial_days') }}</span>
        <input name="trial_days" type="number" min="1" max="90" value="{{ old('trial_days', $priceVersion->trial_days ?? 30) }}" required class="crm-field">
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.annual_discount_percent') }}</span>
        <input name="annual_discount_percent" type="number" min="0" max="100" value="{{ old('annual_discount_percent', $priceVersion->annual_discount_percent ?? 10) }}" required class="crm-field">
    </label>
</div>

<div class="mt-6" data-tier-editor>
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.graduated_location_tiers') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('app.graduated_location_tiers_help') }}</p>
        </div>
        <x-ui.button type="button" variant="secondary" data-add-tier>{{ __('app.add_tier') }}</x-ui.button>
    </div>

    @error('tiers') <div class="mt-3 text-sm font-semibold text-rose-700">{{ $message }}</div> @enderror

    <div class="mt-4 space-y-3" data-tier-rows>
        @foreach ($tierRows as $index => $tier)
            <div class="grid gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_1.4fr_auto]" data-tier-row>
                <label>
                    <span class="crm-label">{{ __('app.tier_start_location') }}</span>
                    <input name="tiers[{{ $index }}][starts_at_location]" type="number" min="1" value="{{ data_get($tier, 'starts_at_location') }}" required class="crm-field" data-tier-field="starts_at_location">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.tier_end_location') }}</span>
                    <input name="tiers[{{ $index }}][ends_at_location]" type="number" min="1" value="{{ data_get($tier, 'ends_at_location') }}" class="crm-field" data-tier-field="ends_at_location">
                </label>
                <label>
                    <span class="crm-label">{{ __('app.unit_price_uah') }}</span>
                    <input name="tiers[{{ $index }}][unit_price_uah]" type="number" min="0.01" step="0.01" value="{{ data_get($tier, 'unit_price_uah') }}" required class="crm-field" data-tier-field="unit_price_uah">
                </label>
                <button type="button" class="self-end rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" data-remove-tier>{{ __('app.remove') }}</button>
            </div>
        @endforeach
    </div>
</div>

<template data-tier-template>
    <div class="grid gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:grid-cols-[1fr_1fr_1.4fr_auto]" data-tier-row>
        <label><span class="crm-label">{{ __('app.tier_start_location') }}</span><input type="number" min="1" required class="crm-field" data-tier-field="starts_at_location"></label>
        <label><span class="crm-label">{{ __('app.tier_end_location') }}</span><input type="number" min="1" class="crm-field" data-tier-field="ends_at_location"></label>
        <label><span class="crm-label">{{ __('app.unit_price_uah') }}</span><input type="number" min="0.01" step="0.01" required class="crm-field" data-tier-field="unit_price_uah"></label>
        <button type="button" class="self-end rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" data-remove-tier>{{ __('app.remove') }}</button>
    </div>
</template>

<script>
        document.querySelectorAll('[data-tier-editor]').forEach((editor) => {
            const rows = editor.querySelector('[data-tier-rows]');
            const template = editor.parentElement.querySelector('[data-tier-template]');
            const rename = () => rows.querySelectorAll('[data-tier-row]').forEach((row, index) => {
                row.querySelectorAll('[data-tier-field]').forEach((field) => {
                    field.name = `tiers[${index}][${field.dataset.tierField}]`;
                });
            });

            editor.querySelector('[data-add-tier]').addEventListener('click', () => {
                rows.append(template.content.cloneNode(true));
                rename();
            });
            rows.addEventListener('click', (event) => {
                if (event.target.closest('[data-remove-tier]') && rows.querySelectorAll('[data-tier-row]').length > 1) {
                    event.target.closest('[data-tier-row]').remove();
                    rename();
                }
            });
            rename();
        });
</script>
