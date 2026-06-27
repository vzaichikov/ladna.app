@extends('layouts.app')

@section('title', __('app.edit').' '.$customerClassPass->code)

@section('content')
    @php
        $formatDateTimeLocal = static fn ($date): ?string => \App\Support\DateTimePresenter::dateTimeLocal($date, $account);
        $formatDateTime = static fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
    @endphp

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
                <input name="purchased_at" type="datetime-local" value="{{ old('purchased_at', $formatDateTimeLocal($customerClassPass->purchased_at)) }}" class="crm-field" required>
                @error('purchased_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.opened_at') }}</span>
                <input name="opened_at" type="datetime-local" value="{{ old('opened_at', $formatDateTimeLocal($customerClassPass->opened_at)) }}" class="crm-field">
                @error('opened_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.expires_after_first_class') }}</span>
                <input name="expires_at" type="datetime-local" value="{{ old('expires_at', $formatDateTimeLocal($customerClassPass->expires_at)) }}" class="crm-field">
                @error('expires_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.usable_until_at') }}</span>
                <input type="datetime-local" value="{{ $formatDateTimeLocal($customerClassPass->usableUntilAt()) }}" class="crm-field" disabled>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.closed_at') }}</span>
                <input name="closed_at" type="datetime-local" value="{{ old('closed_at', $formatDateTimeLocal($customerClassPass->closed_at)) }}" class="crm-field">
                @error('closed_at') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            {{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span> ·
            {{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span> ·
            {{ __('app.sessions_count') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->sessions_count }}</span>
            <div class="mt-2 text-xs text-slate-500">{{ __('app.issued_by') }}: {{ $customerClassPass->issued_by_actor_name ?? __('app.system') }}</div>
        </div>

        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
    </form>

    <x-ui.panel class="mt-6 max-w-3xl">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_session_adjustment') }}</h2>
        <form
            method="POST"
            action="{{ route('dashboard.accounts.customer-class-passes.adjustments.store', [$account, $customerClassPass]) }}"
            class="mt-4 space-y-4"
            data-confirm-action
            data-confirm-title="{{ __('app.confirm_add_class_pass_sessions_title') }}"
            data-confirm-body="{{ __('app.confirm_add_class_pass_sessions_body') }}"
            data-confirm-accept="{{ __('app.add_sessions') }}"
            data-confirm-variant="success"
        >
            @csrf
            <label class="block">
                <span class="crm-label">{{ __('app.sessions_to_adjust') }}</span>
                <input name="sessions_delta" type="number" min="1" max="500" value="{{ old('sessions_delta', 1) }}" class="crm-field" required>
                @error('sessions_delta') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.adjustment_reason') }}</span>
                <textarea name="reason" rows="4" class="crm-field" required>{{ old('reason') }}</textarea>
                @error('reason') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            @error('direction') <span class="crm-help">{{ $message }}</span> @enderror
            <div class="flex flex-col gap-3 sm:flex-row">
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="add"
                    variant="success"
                    data-confirm-title="{{ __('app.confirm_add_class_pass_sessions_title') }}"
                    data-confirm-body="{{ __('app.confirm_add_class_pass_sessions_body') }}"
                    data-confirm-accept="{{ __('app.add_sessions') }}"
                    data-confirm-icon="plus"
                    data-confirm-variant="success"
                >
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.add_sessions') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="subtract"
                    variant="danger"
                    data-confirm-title="{{ __('app.confirm_remove_class_pass_sessions_title') }}"
                    data-confirm-body="{{ __('app.confirm_remove_class_pass_sessions_body') }}"
                    data-confirm-accept="{{ __('app.remove_sessions') }}"
                    data-confirm-icon="minus"
                    data-confirm-variant="danger"
                >
                    <x-ui.icon name="minus" class="h-4 w-4" />
                    {{ __('app.remove_sessions') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.panel>

    <x-ui.panel padding="none" class="mt-6 max-w-5xl overflow-hidden">
        <div class="border-b border-stone-100 px-5 py-4">
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_adjustments') }}</h2>
        </div>
        @forelse ($customerClassPass->adjustments->sortByDesc('created_at') as $adjustment)
            @php
                $sessionsDelta = (int) $adjustment->sessions_delta;
                $sessionsDeltaLabel = ($sessionsDelta > 0 ? '+' : '').$sessionsDelta;
            @endphp
            <div class="border-b border-stone-100 px-5 py-4 text-sm last:border-b-0">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="font-semibold text-slate-950">{{ $sessionsDeltaLabel }} {{ __('app.classes_count') }}</div>
                        <div class="mt-1 text-slate-500">{{ $adjustment->previous_sessions_count }} -> {{ $adjustment->new_sessions_count }}</div>
                    </div>
                    <div class="text-slate-500">{{ $formatDateTime($adjustment->created_at) }}</div>
                </div>
                <div class="mt-2 text-slate-600">{{ $adjustment->reason }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ __('app.adjusted_by') }}: {{ $adjustment->actor_name ?? $adjustment->user?->name ?? __('app.system') }}</div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_class_pass_adjustments')" icon="class-pass-plans" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
