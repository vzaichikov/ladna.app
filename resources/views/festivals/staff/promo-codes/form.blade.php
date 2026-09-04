@extends('layouts.app')

@php
    $editing = $promoCode->exists;
    $selectedAdmissionTypeIds = collect(old('admission_type_ids', $editing ? $promoCode->admissionTypes->modelKeys() : []))->map(fn ($id) => (int) $id);
    $discountType = old('discount_type', $promoCode->discount_type?->value ?? \App\Enums\PromoCodeDiscountType::Percent->value);
    $storedDiscountValue = $promoCode->discount_type === \App\Enums\PromoCodeDiscountType::Fixed
        ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) $promoCode->discount_value)
        : $promoCode->discount_value;
@endphp

@section('title', ($editing ? __('app.promo_code_edit') : __('app.promo_code_add')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$editing ? __('app.promo_code_edit') : __('app.promo_code_add')" :copy="__('app.festival_promo_code_form_copy')" />

    <x-ui.panel>
        <form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.promo-codes.update', [$account, $edition, $promoCode]) : route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="grid gap-4 md:grid-cols-2">
                <label>
                    <span class="crm-label">{{ __('app.name') }}</span>
                    <input name="name" value="{{ old('name', $promoCode->name) }}" required maxlength="255" class="crm-field">
                    <x-ui.field-error name="name" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code') }}</span>
                    <input name="code" value="{{ old('code', $promoCode->code) }}" required minlength="3" maxlength="64" autocomplete="off" class="crm-field font-mono uppercase">
                    <span class="crm-help">{{ __('app.promo_code_code_help') }}</span>
                    <x-ui.field-error name="code" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_discount_type') }}</span>
                    <select name="discount_type" required class="crm-field">
                        @foreach (\App\Enums\PromoCodeDiscountType::cases() as $type)
                            <option value="{{ $type->value }}" @selected($discountType === $type->value)>{{ __('app.promo_code_discount_type_'.$type->value) }}</option>
                        @endforeach
                    </select>
                    <x-ui.field-error name="discount_type" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_discount_value') }}</span>
                    <input name="discount_value" value="{{ old('discount_value', $storedDiscountValue) }}" required inputmode="decimal" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_discount_value_help', ['currency' => strtoupper($promoCode->currency ?: $account->default_currency)]) }}</span>
                    <x-ui.field-error name="discount_value" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_starts_at') }}</span>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $promoCode->starts_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <x-ui.field-error name="starts_at" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_ends_at') }}</span>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $promoCode->ends_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" required class="crm-field">
                    <x-ui.field-error name="ends_at" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_total_usage_limit') }}</span>
                    <input type="number" name="total_usage_limit" value="{{ old('total_usage_limit', $promoCode->total_usage_limit) }}" min="1" max="100000000" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_unlimited_if_empty') }}</span>
                    <x-ui.field-error name="total_usage_limit" />
                </label>
                <label>
                    <span class="crm-label">{{ __('app.promo_code_per_identity_usage_limit') }}</span>
                    <input type="number" name="per_identity_usage_limit" value="{{ old('per_identity_usage_limit', $promoCode->per_identity_usage_limit) }}" min="1" max="1000000" class="crm-field">
                    <span class="crm-help">{{ __('app.promo_code_identity_help') }}</span>
                    <x-ui.field-error name="per_identity_usage_limit" />
                </label>
            </div>

            <fieldset>
                <legend class="crm-label">{{ __('app.promo_code_admission_types') }}</legend>
                <p class="crm-help">{{ __('app.promo_code_admission_types_help') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($admissionTypes as $admissionType)
                        <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-slate-50 p-4 text-sm">
                            <input type="checkbox" name="admission_type_ids[]" value="{{ $admissionType->id }}" class="crm-checkbox mt-0.5" @checked($selectedAdmissionTypeIds->contains($admissionType->id))>
                            <span><strong class="block text-slate-900">{{ $admissionType->name }}</strong><span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_delivery_mode_'.$admissionType->delivery_mode->value) }} · {{ $admissionType->is_active ? __('app.active') : __('app.inactive') }}</span></span>
                        </label>
                    @endforeach
                </div>
                <x-ui.field-error name="admission_type_ids" class="mt-2" />
                <x-ui.field-error name="admission_type_ids.*" class="mt-2" />
            </fieldset>

            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked((bool) old('is_active', $promoCode->is_active ?? true))>
                {{ __('app.active') }}
            </label>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" /> {{ __('app.save') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
