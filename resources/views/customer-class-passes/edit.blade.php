@extends('layouts.app')

@section('title', __('app.edit').' '.$customerClassPass->code)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ $customerClassPass->code }}</h1>
            <p class="crm-page-copy">{{ $customerClassPass->customer?->name }} · {{ $customerClassPass->plan_name }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.customer-class-passes.index', $account)" variant="secondary">{{ __('app.customer_class_passes') }}</x-ui.button>
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    @foreach (\App\Enums\CustomerClassPassStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(old('status', $customerClassPass->status->value) === $status->value)>{{ __('app.'.$status->value) }}</option>
                    @endforeach
                </select>
                @error('status') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                <input type="hidden" name="is_active" value="0">
                <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $customerClassPass->is_active)) class="crm-checkbox">
                {{ __('app.active') }}
            </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.purchased_at') }}</span>
                <input name="purchased_at" type="datetime-local" value="{{ old('purchased_at', $customerClassPass->purchased_at?->format('Y-m-d\\TH:i')) }}" class="crm-field" required>
                @error('purchased_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.opened_at') }}</span>
                <input name="opened_at" type="datetime-local" value="{{ old('opened_at', $customerClassPass->opened_at?->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('opened_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.expires_at') }}</span>
                <input name="expires_at" type="datetime-local" value="{{ old('expires_at', $customerClassPass->expires_at?->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('expires_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.closed_at') }}</span>
                <input name="closed_at" type="datetime-local" value="{{ old('closed_at', $customerClassPass->closed_at?->format('Y-m-d\\TH:i')) }}" class="crm-field">
                @error('closed_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            {{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span> ·
            {{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span> ·
            {{ __('app.sessions_count') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->sessions_count }}</span>
        </div>

        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>
@endsection
