@extends('layouts.app')

@section('title', __('app.unpaid_class_booking_payments_report_title').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.unpaid_class_booking_payments_report_title') }}</h1>
            <p class="crm-page-copy">{{ __('app.unpaid_class_booking_payments_report_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
            {{ __('app.reports') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="hidden gap-3 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid xl:grid-cols-[1.2fr_1.1fr_1fr_1.3fr]">
            <div>{{ __('app.booking_section') }}</div>
            <div>{{ __('app.customer_section') }}</div>
            <div>{{ __('app.unpaid_class_booking_payment_reason') }}</div>
            <div>{{ __('app.class_booking_payment') }}</div>
        </div>

        @forelse ($bookings as $booking)
            @php
                $scheduledClass = $booking->scheduledClass;
                $timezone = $scheduledClass->displayTimezone();
                $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
                $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);
                $dueKind = $booking->manualCashPaymentDueKind($scheduledClass);
                $dueAmountCents = $booking->manualCashPaymentDueAmountCents($scheduledClass);
                $reservedPass = $booking->activeClassPassReservation()?->customerClassPass;
                $currency = $reservedPass?->currency ?? $account->default_currency;
                $amountValue = $dueAmountCents !== null
                    ? \App\Support\Payments\PaymentAmounts::centsToDecimalString((int) $dueAmountCents)
                    : '';
                $reasonLabel = match ($dueKind) {
                    \App\Models\ClassBooking::ManualPaymentDueAnyTimeAddon => __('app.unpaid_class_booking_payment_reason_any_time'),
                    \App\Models\ClassBooking::ManualPaymentDueRoomRental => __('app.unpaid_class_booking_payment_reason_room_rental'),
                    default => __('app.class_booking_payment'),
                };
            @endphp
            <article class="crm-row xl:grid-cols-[1.2fr_1.1fr_1fr_1.3fr] xl:items-start">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('Y-m-d H:i') }} - {{ $endsAt->format('H:i') }}</div>
                    <h2 class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->displayTitle() }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">{{ $scheduledClass->location?->name }}</span>
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">{{ $scheduledClass->room?->name ?? __('app.room') }}</span>
                        <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">{{ __('app.'.$booking->status->value) }}</span>
                    </div>
                </div>

                <div class="min-w-0">
                    <div class="font-semibold text-slate-950">{{ $booking->customer->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $booking->customer->phone ?? $booking->customer->email ?? __('app.no_contact') }}</div>
                    @can('manageClients', $account)
                        <x-ui.button :href="route('dashboard.accounts.customers.edit', [$account, $booking->customer])" variant="secondary" size="sm" class="mt-3 w-fit">
                            {{ __('app.open_customer') }}
                        </x-ui.button>
                    @endcan
                </div>

                <div>
                    <span class="inline-flex rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">
                        {{ __('app.unpaid_class_booking_payment_alert') }}
                    </span>
                    <div class="mt-2 text-sm font-semibold text-slate-950">{{ $reasonLabel }}</div>
                    @if ($reservedPass)
                        <div class="mt-1 text-xs font-semibold text-slate-500">{{ $reservedPass->code }}</div>
                    @endif
                    @if ($dueAmountCents !== null)
                        <div class="mt-2 text-sm text-slate-600">{{ __('app.unpaid_class_booking_payment_due_amount') }}: {{ \App\Support\MoneyFormatter::format($dueAmountCents, $currency) }}</div>
                    @endif
                </div>

                <div>
                    @if ($canManageBookingPayments)
                        <form method="POST" action="{{ route('dashboard.accounts.bookings.payment.store', [$account, $booking]) }}" class="grid gap-3 rounded-lg border border-sky-100 bg-sky-50 p-3 sm:grid-cols-[1fr_auto] sm:items-end">
                            @csrf
                            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                            <label class="block">
                                <span class="crm-label">{{ $dueAmountCents !== null ? __('app.any_time_addon_price') : __('app.class_booking_payment_amount') }}</span>
                                <input
                                    name="amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputmode="decimal"
                                    value="{{ $amountValue }}"
                                    class="crm-field"
                                    placeholder="0.00"
                                    @readonly($dueAmountCents !== null)
                                >
                            </label>
                            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.record_payment') }}</x-ui.button>
                        </form>
                    @else
                        <p class="rounded-lg border border-stone-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">{{ __('app.unpaid_class_booking_payment_no_permission') }}</p>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_unpaid_class_booking_payments')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($bookings->hasPages())
        <div class="mt-6">
            {{ $bookings->links() }}
        </div>
    @endif
@endsection
