@extends('layouts.app')

@section('title', __('app.edit').' '.$customer->name)

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return number_format($priceCents / 100, $priceCents % 100 === 0 ? 0 : 2, '.', ' ').' '.$currency;
        };
        $customerClassPasses = $customer->customerClassPasses
            ->sortByDesc(fn ($customerClassPass) => ($customerClassPass->is_active ? '1' : '0').($customerClassPass->opened_at?->timestamp ?? $customerClassPass->purchased_at?->timestamp ?? 0));
        $bookings = $customer->classBookings
            ->sortByDesc(fn ($booking) => $booking->scheduledClass?->starts_at?->timestamp ?? $booking->created_at?->timestamp ?? 0);
    @endphp

    <h1 class="crm-page-title">{{ __('app.edit') }} {{ $customer->name }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div class="space-y-6">
            <form method="POST" action="{{ route('dashboard.accounts.customers.update', [$account, $customer]) }}" class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                @csrf
                @method('PUT')
                @include('customers.form-fields')
                <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
            </form>

            <x-ui.panel>
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.issue_class_pass') }}</h2>
                <form method="POST" action="{{ route('dashboard.accounts.customers.class-passes.store', [$account, $customer]) }}" class="mt-4 space-y-4">
                    @csrf
                    <label class="block">
                        <span class="crm-label">{{ __('app.class_pass_plan') }}</span>
                        <select name="class_pass_plan_id" class="crm-field" required>
                            @foreach ($classPassPlans as $classPassPlan)
                                <option value="{{ $classPassPlan->id }}">{{ $classPassPlan->name }} · {{ $formatMoney($classPassPlan->price_cents, $classPassPlan->currency) }}</option>
                            @endforeach
                        </select>
                        @error('class_pass_plan_id') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <x-ui.button type="submit">
                        <x-ui.icon name="ticket" class="h-4 w-4" />
                        {{ __('app.issue_class_pass') }}
                    </x-ui.button>
                </form>
            </x-ui.panel>
        </div>

        <div class="space-y-6">
            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_class_passes') }}</h2>
                </div>
                @forelse ($customerClassPasses as $customerClassPass)
                    <div class="border-b border-stone-100 px-5 py-4 last:border-b-0">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="font-semibold text-slate-950">{{ $customerClassPass->plan_name }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->code }} · {{ $formatMoney($customerClassPass->price_cents, $customerClassPass->currency) }}</div>
                            </div>
                            <span class="{{ $customerClassPass->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.'.$customerClassPass->status->value) }}</span>
                        </div>
                        <div class="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-3">
                            <div>{{ __('app.remaining_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->remainingSessionsCount() }}</span></div>
                            <div>{{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span></div>
                            <div>{{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span></div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                            <span>{{ __('app.purchased_at') }}: {{ $customerClassPass->purchased_at?->format('Y-m-d') }}</span>
                            <span>{{ __('app.opened_at') }}: {{ $customerClassPass->opened_at?->format('Y-m-d') ?? __('app.not_set') }}</span>
                            <span>{{ __('app.expires_at') }}: {{ $customerClassPass->expires_at?->format('Y-m-d') ?? __('app.not_set') }}</span>
                        </div>
                        <div class="mt-3">
                            <x-ui.button :href="route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.no_customer_class_passes')" icon="class-pass-plans" class="m-5" />
                @endforelse
            </x-ui.panel>

            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.visit_history') }}</h2>
                </div>
                @forelse ($bookings->take(20) as $booking)
                    <div class="border-b border-stone-100 px-5 py-4 text-sm last:border-b-0">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <div class="font-semibold text-slate-950">{{ $booking->scheduledClass?->title }}</div>
                                <div class="mt-1 text-slate-500">{{ $booking->scheduledClass?->starts_at?->format('Y-m-d H:i') }} · {{ $booking->scheduledClass?->classType?->name }}</div>
                                @if ($booking->classPassReservation?->customerClassPass)
                                    <div class="mt-1 text-slate-500">{{ __('app.class_pass') }}: {{ $booking->classPassReservation->customerClassPass->code }}</div>
                                @endif
                            </div>
                            <span class="crm-status-muted">{{ __('app.'.$booking->status->value) }}</span>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.no_bookings')" icon="calendar" class="m-5" />
                @endforelse
            </x-ui.panel>
        </div>
    </div>
@endsection
