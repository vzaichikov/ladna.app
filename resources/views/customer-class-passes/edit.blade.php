@extends('layouts.app')

@section('title', __('app.edit').' '.$customerClassPass->code)

@section('content')
    @php
        $formatDateTimeLocal = static fn ($date): ?string => \App\Support\DateTimePresenter::dateTimeLocal($date, $account);
        $formatDateTime = static fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
        $formatStatus = static fn (?string $status): string => $status ? __('app.'.$status) : __('app.not_set');
        $statusOptions = collect(\App\Enums\CustomerClassPassStatus::cases())
            ->filter(fn ($status): bool => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed
                ? $status === \App\Enums\CustomerClassPassStatus::Freezed
                : $status !== \App\Enums\CustomerClassPassStatus::Freezed);
        $locations ??= collect();
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ $customerClassPass->code }}</h1>
            <p class="crm-page-copy">{{ $customerClassPass->customer?->name }} · {{ $customerClassPass->plan_name }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Active && $customerClassPass->is_active)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.customer-class-passes.freeze', [$account, $customerClassPass]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_freeze_class_pass_title') }}"
                    data-confirm-body="{{ __('app.confirm_freeze_class_pass_body') }}"
                    data-confirm-accept="{{ __('app.freeze_class_pass') }}"
                    data-confirm-icon="snowflake"
                    data-confirm-variant="danger"
                >
                    @csrf
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="snowflake" class="h-4 w-4" />
                        {{ __('app.freeze_class_pass') }}
                    </x-ui.button>
                </form>
            @elseif ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed && $customerClassPass->is_active)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.customer-class-passes.unfreeze', [$account, $customerClassPass]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.confirm_unfreeze_class_pass_title') }}"
                    data-confirm-body="{{ __('app.confirm_unfreeze_class_pass_body') }}"
                    data-confirm-accept="{{ __('app.unfreeze_class_pass') }}"
                    data-confirm-icon="sun"
                    data-confirm-variant="success"
                >
                    @csrf
                    <x-ui.button type="submit" variant="success">
                        <x-ui.icon name="sun" class="h-4 w-4" />
                        {{ __('app.unfreeze_class_pass') }}
                    </x-ui.button>
                </form>
            @endif
            <x-ui.button :href="route('dashboard.accounts.customer-class-passes.index', $account)" variant="secondary">{{ __('app.customer_class_passes') }}</x-ui.button>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboard.accounts.customer-class-passes.update', [$account, $customerClassPass]) }}" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
        @csrf
        @method('PUT')

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status->value }}" @selected(old('status', $customerClassPass->status->value) === $status->value)>{{ __('app.'.$status->value) }}</option>
                    @endforeach
                </select>
                @error('status') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            @if ($customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed)
                <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="is_active" value="1">
                    <input type="checkbox" value="1" checked disabled class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            @else
                <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                    <input type="hidden" name="is_active" value="0">
                    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $customerClassPass->is_active)) class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            @endif
            <label class="block">
                <span class="crm-label">{{ __('app.issued_location') }}</span>
                <select name="issued_location_id" class="crm-field" required>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((string) old('issued_location_id', $customerClassPass->issued_location_id) === (string) $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
                @error('issued_location_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
                <input type="hidden" name="is_paid" value="0">
                <input name="is_paid" type="checkbox" value="1" @checked(old('is_paid', $customerClassPass->is_paid)) class="crm-checkbox">
                {{ __('app.class_pass_paid') }}
            </label>
            @error('is_paid') <span class="crm-help">{{ $message }}</span> @enderror
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
            <label class="block">
                <span class="crm-label">{{ __('app.frozen_at') }}</span>
                <input type="datetime-local" value="{{ $formatDateTimeLocal($customerClassPass->frozen_at) }}" class="crm-field" disabled>
            </label>
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
            {{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span> ·
            {{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span> ·
            {{ __('app.sessions_count') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->sessions_count }}</span>
            <div class="mt-2">
                <span class="{{ $customerClassPass->is_paid ? 'crm-status-active' : 'crm-status-danger' }}">{{ $customerClassPass->is_paid ? __('app.class_pass_paid') : __('app.class_pass_unpaid') }}</span>
                <span @class([
                    'crm-status-active' => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Active,
                    'crm-status-warning' => $customerClassPass->status === \App\Enums\CustomerClassPassStatus::Freezed,
                    'crm-status-muted' => ! in_array($customerClassPass->status, [\App\Enums\CustomerClassPassStatus::Active, \App\Enums\CustomerClassPassStatus::Freezed], true),
                ])">{{ __('app.'.$customerClassPass->status->value) }}</span>
            </div>
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

    <x-ui.panel class="mt-6 max-w-3xl">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_validity_days_adjustment') }}</h2>
        <form
            method="POST"
            action="{{ route('dashboard.accounts.customer-class-passes.validity-adjustments.store', [$account, $customerClassPass]) }}"
            class="mt-4 space-y-4"
            data-confirm-action
            data-confirm-title="{{ __('app.confirm_add_class_pass_days_title') }}"
            data-confirm-body="{{ __('app.confirm_add_class_pass_days_body') }}"
            data-confirm-accept="{{ __('app.add_days') }}"
            data-confirm-variant="success"
        >
            @csrf
            <label class="block">
                <span class="crm-label">{{ __('app.days_to_adjust') }}</span>
                <input name="days_delta" type="number" min="1" max="3650" value="{{ old('days_delta', 1) }}" class="crm-field" required>
                @error('days_delta') <span class="crm-help">{{ $message }}</span> @enderror
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
                    data-confirm-title="{{ __('app.confirm_add_class_pass_days_title') }}"
                    data-confirm-body="{{ __('app.confirm_add_class_pass_days_body') }}"
                    data-confirm-accept="{{ __('app.add_days') }}"
                    data-confirm-icon="plus"
                    data-confirm-variant="success"
                >
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.add_days') }}
                </x-ui.button>
                <x-ui.button
                    type="submit"
                    name="direction"
                    value="subtract"
                    variant="danger"
                    data-confirm-title="{{ __('app.confirm_remove_class_pass_days_title') }}"
                    data-confirm-body="{{ __('app.confirm_remove_class_pass_days_body') }}"
                    data-confirm-accept="{{ __('app.remove_days') }}"
                    data-confirm-icon="minus"
                    data-confirm-variant="danger"
                >
                    <x-ui.icon name="minus" class="h-4 w-4" />
                    {{ __('app.remove_days') }}
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
                $adjustmentType = $adjustment->adjustment_type;
                $adjustmentTypeLabel = __('app.adjustment_'.$adjustmentType->value);
                $adjustmentTypeClass = match ($adjustmentType) {
                    \App\Enums\CustomerClassPassAdjustmentType::Sessions => 'crm-status-active',
                    \App\Enums\CustomerClassPassAdjustmentType::ValidityDays => 'crm-status-scheduled',
                    \App\Enums\CustomerClassPassAdjustmentType::Freeze => 'crm-status-warning',
                    \App\Enums\CustomerClassPassAdjustmentType::Unfreeze => 'crm-status-active',
                };
                $sessionsDelta = $adjustment->sessions_delta;
                $daysDelta = $adjustment->days_delta;
                $sessionsDeltaLabel = $sessionsDelta !== null ? (($sessionsDelta > 0 ? '+' : '').$sessionsDelta.' '.__('app.classes_count')) : null;
                $daysDeltaLabel = $daysDelta !== null ? (($daysDelta > 0 ? '+' : '').$daysDelta.' '.__('app.days')) : null;
                $primaryLabel = match ($adjustmentType) {
                    \App\Enums\CustomerClassPassAdjustmentType::Sessions => $sessionsDeltaLabel,
                    \App\Enums\CustomerClassPassAdjustmentType::ValidityDays,
                    \App\Enums\CustomerClassPassAdjustmentType::Unfreeze => $daysDeltaLabel,
                    \App\Enums\CustomerClassPassAdjustmentType::Freeze => __('app.freeze_class_pass'),
                };
            @endphp
            <div class="border-b border-stone-100 px-5 py-4 text-sm last:border-b-0">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $adjustmentTypeClass }}">{{ $adjustmentTypeLabel }}</span>
                            @if ($primaryLabel)
                                <span class="font-semibold text-slate-950">{{ $primaryLabel }}</span>
                            @endif
                        </div>
                        <div class="mt-2 space-y-1 text-slate-500">
                            @if ($adjustment->previous_sessions_count !== null || $adjustment->new_sessions_count !== null)
                                <div>{{ __('app.sessions_count') }}: {{ $adjustment->previous_sessions_count ?? __('app.not_set') }} -> {{ $adjustment->new_sessions_count ?? __('app.not_set') }}</div>
                            @endif
                            @if ($adjustment->previous_validity_days !== null || $adjustment->new_validity_days !== null)
                                <div>{{ __('app.validity_days_delta') }}: {{ $adjustment->previous_validity_days ?? __('app.not_set') }} -> {{ $adjustment->new_validity_days ?? __('app.not_set') }}</div>
                            @endif
                            @if ($adjustment->previous_status || $adjustment->new_status)
                                <div>{{ __('app.status') }}: {{ __('app.status_change', ['from' => $formatStatus($adjustment->previous_status), 'to' => $formatStatus($adjustment->new_status)]) }}</div>
                            @endif
                            @if ($adjustment->freeze_started_at || $adjustment->freeze_finished_at)
                                <div>{{ __('app.freeze_period') }}: {{ $formatDateTime($adjustment->freeze_started_at) }} -> {{ $formatDateTime($adjustment->freeze_finished_at) }}</div>
                            @endif
                            @if ($adjustment->freeze_days_count !== null)
                                <div>{{ __('app.freeze_days_count', ['count' => $adjustment->freeze_days_count]) }}</div>
                            @endif
                        </div>
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
