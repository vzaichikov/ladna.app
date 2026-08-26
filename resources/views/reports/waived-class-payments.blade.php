@extends('layouts.app')

@section('title', __('app.waived_class_booking_payments').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.waived_class_booking_payments') }}</h1>
            <p class="crm-page-copy">{{ __('app.waived_class_booking_payments_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.unpaid-class-payments', $account)" variant="secondary">
            {{ __('app.unpaid_class_payments') }}
        </x-ui.button>
    </div>

    <nav class="mt-6 flex flex-wrap gap-2" aria-label="{{ __('app.filter_by_status') }}">
        @foreach ([
            'all' => __('app.all'),
            'active' => __('app.waived_payment_status_active'),
            'unwaived' => __('app.waived_payment_status_unwaived'),
        ] as $statusValue => $statusLabel)
            <x-ui.button
                :href="route('dashboard.accounts.reports.unpaid-class-payments.waived', ['account' => $account, 'status' => $statusValue])"
                :variant="$status === $statusValue ? 'primary' : 'secondary'"
                size="sm"
            >
                {{ $statusLabel }} · {{ $statusCounts[$statusValue] }}
            </x-ui.button>
        @endforeach
    </nav>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="hidden gap-3 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 xl:grid xl:grid-cols-[1.25fr_1fr_1.25fr_1fr]">
            <div>{{ __('app.booking_section') }}</div>
            <div>{{ __('app.unpaid_class_booking_payment_reason') }}</div>
            <div>{{ __('app.waiver_details') }}</div>
            <div>{{ __('app.status') }}</div>
        </div>

        @forelse ($waivers as $waiver)
            @php
                $startsAt = $waiver->scheduled_class_starts_at->copy()->timezone(
                    \App\Support\DateTimePresenter::safeTimezone($waiver->scheduled_class_timezone),
                );
                $waivedAt = \App\Support\DateTimePresenter::format($waiver->waived_at, $account);
                $unwaivedAt = \App\Support\DateTimePresenter::format($waiver->unwaived_at, $account);
                $dueKindLabel = match ($waiver->payment_due_kind) {
                    \App\Models\ClassBooking::ManualPaymentDueAnyTimeAddon => __('app.unpaid_class_booking_payment_reason_any_time'),
                    \App\Models\ClassBooking::ManualPaymentDueRoomRental => __('app.unpaid_class_booking_payment_reason_room_rental'),
                    default => $waiver->payment_due_kind,
                };
                $booking = $waiver->classBooking;
                $canUnwaive = $waiver->isActive()
                    && $booking
                    && $booking->manualCashPaymentRequirementKind($booking->scheduledClass) === $waiver->payment_due_kind;
            @endphp
            <article class="crm-row xl:grid-cols-[1.25fr_1fr_1.25fr_1fr] xl:items-start">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('Y-m-d H:i') }}</div>
                    <h2 class="mt-1 font-semibold text-slate-950">{{ $waiver->scheduled_class_title }}</h2>
                    <div class="mt-1 text-sm font-semibold text-slate-700">{{ $waiver->customer_name }}</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-slate-500">
                        @if ($waiver->location_name)
                            <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">{{ $waiver->location_name }}</span>
                        @endif
                        @if ($waiver->room_name)
                            <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">{{ $waiver->room_name }}</span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="font-semibold text-slate-950">{{ $dueKindLabel }}</div>
                    @if ($waiver->customer_class_pass_code)
                        <div class="mt-1 text-xs font-semibold text-slate-500">{{ $waiver->customer_class_pass_code }}</div>
                    @endif
                    @if ($waiver->amount_cents !== null)
                        <div class="mt-2 text-sm text-slate-600">
                            {{ \App\Support\MoneyFormatter::format($waiver->amount_cents, $waiver->currency) }}
                        </div>
                    @endif
                </div>

                <div class="text-sm text-slate-600">
                    <p class="font-medium text-slate-900">{{ $waiver->reason }}</p>
                    <p class="mt-2 text-xs">{{ __('app.waived_by') }}: {{ $waiver->waived_by_actor_name ?? __('app.system') }}</p>
                    <p class="mt-1 text-xs">{{ $waivedAt }}</p>
                    @if (! $waiver->isActive())
                        <div class="mt-3 border-t border-stone-100 pt-3">
                            <p class="font-medium text-slate-900">{{ $waiver->unwaive_reason }}</p>
                            <p class="mt-2 text-xs">{{ __('app.unwaived_by') }}: {{ $waiver->unwaived_by_actor_name ?? __('app.system') }}</p>
                            <p class="mt-1 text-xs">{{ $unwaivedAt }}</p>
                        </div>
                    @endif
                </div>

                <div>
                    <span class="{{ $waiver->isActive() ? 'crm-status-scheduled' : 'crm-status-active' }}">
                        {{ $waiver->isActive() ? __('app.waived_payment_status_active') : __('app.waived_payment_status_unwaived') }}
                    </span>

                    @if ($canUnwaive)
                        <form
                            method="POST"
                            action="{{ route('dashboard.accounts.booking-payment-waivers.unwaive', [$account, $waiver]) }}"
                            class="mt-3"
                            data-confirm-action
                            data-confirm-title="{{ __('app.unwaive_class_booking_payment_title') }}"
                            data-confirm-body="{{ __('app.unwaive_class_booking_payment_copy') }}"
                            data-confirm-accept="{{ __('app.unwaive_payment') }}"
                            data-confirm-variant="danger"
                            data-confirm-icon="undo-2"
                            data-confirm-reason-required="true"
                            data-confirm-reason-maxlength="2000"
                            data-confirm-reason-label="{{ __('app.unwaive_payment_reason') }}"
                            data-confirm-reason-placeholder="{{ __('app.unwaive_payment_reason_placeholder') }}"
                        >
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                            <input type="hidden" name="reason" data-confirm-reason-output>
                            <x-ui.button type="submit" variant="danger" size="sm">
                                {{ __('app.unwaive_payment') }}
                            </x-ui.button>
                        </form>
                    @elseif ($waiver->isActive())
                        <p class="mt-3 text-xs leading-5 text-slate-500">{{ __('app.class_booking_payment_unwaive_unavailable') }}</p>
                    @endif
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="__('app.no_waived_class_booking_payments')" icon="payments" class="m-5" />
        @endforelse
    </x-ui.panel>

    @if ($waivers->hasPages())
        <div class="mt-6">
            {{ $waivers->links() }}
        </div>
    @endif
@endsection
