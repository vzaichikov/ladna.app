@props([
    'event',
    'ticketType',
])

@php
    $timezone = $event->timezone ?: config('app.timezone');
@endphp

<div {{ $attributes->class(['grid gap-5 sm:grid-cols-2 lg:grid-cols-3']) }}>
    <label class="block sm:col-span-2">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" required value="{{ old('name', $ticketType->name) }}" class="crm-field" autocomplete="off">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.price') }} · {{ $event->currency }}</span>
        <input name="price" required value="{{ old('price', \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) $ticketType->price_cents)) }}" class="crm-field" inputmode="decimal">
        @error('price') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block sm:col-span-2 lg:col-span-3">
        <span class="crm-label">{{ __('app.description') }}</span>
        <textarea name="description" rows="3" class="crm-field">{{ old('description', $ticketType->description) }}</textarea>
        @error('description') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.event_inventory') }}</span>
        <input type="number" name="inventory" required min="1" max="1000000" value="{{ old('inventory', $ticketType->inventory) }}" class="crm-field" inputmode="numeric">
        @error('inventory') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.event_max_per_order') }}</span>
        <input type="number" name="max_per_order" required min="1" max="100" value="{{ old('max_per_order', $ticketType->max_per_order) }}" class="crm-field" inputmode="numeric">
        @error('max_per_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.sort_order') }}</span>
        <input type="number" name="sort_order" required min="0" max="32767" value="{{ old('sort_order', $ticketType->sort_order) }}" class="crm-field" inputmode="numeric">
        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <div class="rounded-xl border border-stone-200 bg-slate-50 p-4 sm:col-span-2 lg:col-span-3">
        <h2 class="font-semibold text-slate-950">{{ __('app.event_early_bird') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ __('app.event_early_bird_help') }}</p>
        <div class="mt-4 grid gap-5 sm:grid-cols-3">
            <label class="block">
                <span class="crm-label">{{ __('app.event_early_price') }} · {{ $event->currency }}</span>
                <input name="early_bird_price" value="{{ old('early_bird_price', $ticketType->early_bird_price_cents !== null ? \App\Support\Payments\PaymentAmounts::centsToDecimalString($ticketType->early_bird_price_cents) : '') }}" class="crm-field" inputmode="decimal">
                @error('early_bird_price') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.event_early_ends') }}</span>
                <input type="datetime-local" name="early_bird_ends_at" value="{{ old('early_bird_ends_at', $ticketType->early_bird_ends_at?->timezone($timezone)->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('early_bird_ends_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.event_early_quota') }}</span>
                <input type="number" name="early_bird_quota" min="1" max="1000000" value="{{ old('early_bird_quota', $ticketType->early_bird_quota) }}" class="crm-field" inputmode="numeric">
                @error('early_bird_quota') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </div>

    <div class="rounded-xl border border-stone-200 bg-slate-50 p-4 sm:col-span-2 lg:col-span-3">
        <h2 class="font-semibold text-slate-950">{{ __('app.event_sales_window') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ __('app.event_sales_window_help') }}</p>
        <div class="mt-4 grid gap-5 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.event_sales_starts') }}</span>
                <input type="datetime-local" name="sales_starts_at" value="{{ old('sales_starts_at', $ticketType->sales_starts_at?->timezone($timezone)->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('sales_starts_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.event_sales_ends') }}</span>
                <input type="datetime-local" name="sales_ends_at" value="{{ old('sales_ends_at', $ticketType->sales_ends_at?->timezone($timezone)->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('sales_ends_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>
    </div>

    <label class="flex items-start gap-3 rounded-xl border border-stone-200 bg-white p-4 text-sm font-medium text-slate-700 sm:col-span-2 lg:col-span-3">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $ticketType->is_active)) class="crm-checkbox mt-0.5">
        <span>
            <span class="block text-slate-950">{{ __('app.active') }}</span>
            <span class="mt-1 block font-normal text-slate-500">{{ __('app.event_ticket_type_active_help') }}</span>
        </span>
    </label>
    @error('is_active') <span class="crm-help sm:col-span-2 lg:col-span-3">{{ $message }}</span> @enderror
</div>
