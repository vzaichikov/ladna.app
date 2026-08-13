@extends('layouts.app')

@section('title', ($admissionType->exists ? __('app.festival_edit_admission_type') : __('app.festival_add_admission_type')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$admissionType->exists ? __('app.festival_edit_admission_type') : __('app.festival_add_admission_type')"
        :copy="__('app.festival_admission_type_form_copy')"
    />

    @if ($isLocked)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <div class="flex items-start gap-3">
                <x-ui.icon name="lock" class="mt-0.5 h-5 w-5 shrink-0" />
                <div><strong>{{ __('app.festival_admission_type_locked_title') }}</strong><p class="mt-1">{{ __('app.festival_admission_type_locked_copy') }}</p></div>
            </div>
        </div>
    @endif

    <x-ui.panel class="max-w-4xl">
        <form method="POST" action="{{ $admissionType->exists ? route('dashboard.accounts.festivals.admission-types.update', [$account, $edition, $admissionType]) : route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @if ($admissionType->exists) @method('PUT') @endif

            @error('admission_type') <div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</div> @enderror

            <fieldset class="space-y-5" @disabled($isLocked)>
                <div class="grid gap-5 md:grid-cols-2">
                    <label class="md:col-span-2">
                        <span class="crm-label">{{ __('app.festival_admission_delivery_mode') }}</span>
                        <select name="delivery_mode" required class="crm-field">
                            <option value="venue" @selected(old('delivery_mode', $admissionType->delivery_mode?->value ?? 'venue') === 'venue')>{{ __('app.festival_admission_delivery_venue') }}</option>
                            <option value="online_stream" @selected(old('delivery_mode', $admissionType->delivery_mode?->value) === 'online_stream') @disabled(! $onlineStream?->is_enabled)>{{ __('app.festival_admission_delivery_online_stream') }}</option>
                        </select>
                        <span class="crm-help">{{ $onlineStream?->is_enabled ? __('app.festival_online_ticket_separate_order') : __('app.festival_online_ticket_requires_stream') }}</span>
                        @error('delivery_mode') <span class="crm-help text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="md:col-span-2">
                        <span class="crm-label">{{ __('app.festival_admission_name') }}</span>
                        <input name="name" value="{{ old('name', $admissionType->name) }}" maxlength="255" required class="crm-field">
                        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="md:col-span-2">
                        <span class="crm-label">{{ __('app.description') }}</span>
                        <textarea name="description" rows="3" maxlength="3000" class="crm-field">{{ old('description', $admissionType->description) }}</textarea>
                        @error('description') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_inventory') }}</span>
                        <input type="number" name="inventory" min="1" value="{{ old('inventory', $admissionType->inventory) }}" required class="crm-field">
                        @error('inventory') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_max_per_order') }}</span>
                        <input type="number" name="max_per_order" min="1" max="20" value="{{ old('max_per_order', $admissionType->max_per_order ?? 10) }}" required class="crm-field">
                        @error('max_per_order') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_regular_price', ['currency' => $account->default_currency]) }}</span>
                        <input type="number" name="price" min="0" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('price', $admissionType->price_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($admissionType->price_cents)) }}" required class="crm-field">
                        <span class="crm-help">{{ __('app.festival_money_input_help', ['currency' => $account->default_currency]) }}</span>
                        @error('price') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_early_price', ['currency' => $account->default_currency]) }}</span>
                        <input type="number" name="early_bird_price" min="0" max="999999.99" step="0.01" inputmode="decimal" value="{{ old('early_bird_price', $admissionType->early_bird_price_cents === null ? null : \App\Support\Payments\PaymentAmounts::centsToDecimalString($admissionType->early_bird_price_cents)) }}" class="crm-field">
                        @error('early_bird_price') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_early_quota') }}</span>
                        <input type="number" name="early_bird_quota" min="1" value="{{ old('early_bird_quota', $admissionType->early_bird_quota) }}" class="crm-field">
                        @error('early_bird_quota') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_early_ends') }}</span>
                        <input type="datetime-local" name="early_bird_ends_at" value="{{ old('early_bird_ends_at', $admissionType->early_bird_ends_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                        @error('early_bird_ends_at') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_sales_start') }}</span>
                        <input type="datetime-local" name="sales_starts_at" value="{{ old('sales_starts_at', $admissionType->sales_starts_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                        @error('sales_starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.festival_sales_end') }}</span>
                        <input type="datetime-local" name="sales_ends_at" value="{{ old('sales_ends_at', $admissionType->sales_ends_at?->timezone($edition->timezone)->format('Y-m-d\TH:i')) }}" class="crm-field">
                        @error('sales_ends_at') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>
                <input type="hidden" name="is_active" value="0">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" class="crm-checkbox" @checked(old('is_active', $admissionType->is_active ?? true))>
                    {{ __('app.active') }}
                </label>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                @unless ($isLocked)
                    <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" /> {{ __('app.save') }}</x-ui.button>
                @endunless
                <x-ui.button :href="route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types'])" variant="secondary">{{ $isLocked ? __('app.back') : __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
