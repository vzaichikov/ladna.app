@extends('layouts.public')

@section('title', __('app.customer_portal').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $formatMoney = fn (?int $amount, ?string $currency): string => $amount === null ? __('app.not_set') : \App\Support\MoneyFormatter::format($amount, $currency);
        $passes = $customer->customerClassPasses
            ->sortByDesc(fn ($pass) => ($pass->is_active ? '1' : '0').($pass->opened_at?->timestamp ?? $pass->purchased_at?->timestamp ?? 0));
        $activePasses = $passes
            ->filter(fn ($pass) => $pass->is_active && $pass->status === \App\Enums\CustomerClassPassStatus::Active)
            ->values();
        $bookings = $customer->classBookings
            ->sortByDesc(fn ($booking) => $booking->scheduledClass?->starts_at?->timestamp ?? $booking->created_at?->timestamp ?? 0);
        $cancellationWindow = app(\App\Support\ClassBookingCancellationWindow::class);
        $formatDate = static fn ($date): string => \App\Support\DateTimePresenter::date($date, $account) ?? __('app.not_set');
        $formatOptionalDate = static fn ($date): string => \App\Support\DateTimePresenter::date($date, $account) ?? '—';
        $formatDateTime = static fn ($date): string => \App\Support\DateTimePresenter::format($date, $account) ?? __('app.not_set');
        $providerLabel = static function (string $provider): string {
            $translationKey = 'app.provider_'.$provider;
            $label = __($translationKey);

            return $label === $translationKey ? config('integrations.providers.'.$provider.'.label', $provider) : $label;
        };
        $formatBookingDate = static function ($booking) use ($account): string {
            $scheduledClass = $booking->scheduledClass;
            $date = $scheduledClass?->starts_at ?? $booking->created_at;
            $timezone = $scheduledClass?->displayTimezone() ?? $account->timezone ?? config('app.timezone');

            return \App\Support\DateTimePresenter::formatInTimezone($date, $timezone) ?? __('app.not_set');
        };
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
        <section class="mx-auto max-w-6xl">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                        <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                    </span>
                    <div>
                        <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                        <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.customer_portal') }}</h1>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button :href="route('customer.profile.edit', $account->slug)" variant="secondary">
                        <x-ui.icon name="user-round" class="h-4 w-4" />
                        {{ __('app.profile') }}
                    </x-ui.button>
                    <form method="POST" action="{{ route('customer.logout', $account->slug) }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost">{{ __('app.logout') }}</x-ui.button>
                    </form>
                </div>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <section class="mt-6 grid gap-3 md:grid-cols-3">
                <x-ui.metric :label="__('app.active_class_passes_short')" :value="$activePasses->count()" icon="class-pass-plans" :mobile-inline="true" />
                <x-ui.metric :label="__('app.customer_remaining_sessions')" :value="$activePasses->sum(fn ($pass) => max(0, $pass->remainingSessionsCount()))" icon="check-circle" accent="emerald" :mobile-inline="true" />
                <x-ui.metric :label="__('app.bookings')" :value="$bookings->count()" icon="schedule" accent="brand" :mobile-inline="true" />
            </section>

            <section class="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_0.8fr]">
                <div class="rounded-xl border border-stone-200 bg-white shadow-crm">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_class_passes') }}</h2>
                    </div>
                    <div class="divide-y divide-stone-100">
                        @forelse ($activePasses as $pass)
                            @php
                                $statusClass = match ($pass->status) {
                                    \App\Enums\CustomerClassPassStatus::Active => 'crm-status-active',
                                    \App\Enums\CustomerClassPassStatus::Freezed => 'crm-status-warning',
                                    default => 'crm-status-muted',
                                };
                                $useByAt = $pass->opened_at ? $pass->expires_at : $pass->usableUntilAt();
                            @endphp
                            <article class="p-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-slate-950">{{ $pass->plan_name }}</div>
                                        <div class="mt-1 text-sm text-slate-500">{{ $pass->code }} · {{ $formatMoney($pass->price_cents, $pass->currency) }}</div>
                                    </div>
                                    <span class="shrink-0 {{ $statusClass }}">{{ __('app.'.$pass->status->value) }}</span>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <dl class="grid grid-cols-3 gap-3 text-sm">
                                        <div>
                                            <dt class="text-xs font-medium leading-snug text-slate-500">{{ __('app.customer_remaining_sessions') }}</dt>
                                            <dd class="mt-1 text-lg font-semibold text-slate-950">{{ $pass->remainingSessionsCount() }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium leading-snug text-slate-500">{{ __('app.reserved_sessions') }}</dt>
                                            <dd class="mt-1 text-lg font-semibold text-slate-950">{{ $pass->reserved_sessions_count }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium leading-snug text-slate-500">{{ __('app.used_sessions') }}</dt>
                                            <dd class="mt-1 text-lg font-semibold text-slate-950">{{ $pass->used_sessions_count }}</dd>
                                        </div>
                                    </dl>

                                    <dl class="grid gap-2 text-sm">
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-xs font-medium text-slate-500">{{ __('app.purchased_at') }}</dt>
                                            <dd class="font-medium text-slate-700">{{ $formatOptionalDate($pass->purchased_at) }}</dd>
                                        </div>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-xs font-medium text-slate-500">{{ __('app.opened_at') }}</dt>
                                            <dd class="font-medium text-slate-700">{{ $formatOptionalDate($pass->opened_at) }}</dd>
                                        </div>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <dt class="text-xs font-medium text-slate-500">{{ __('app.usable_until_at') }}</dt>
                                            <dd class="font-medium text-slate-700">{{ $formatOptionalDate($useByAt) }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </article>
                        @empty
                            <x-ui.empty-state :title="__('app.no_customer_class_passes')" icon="class-pass-plans" class="m-5" />
                        @endforelse
                    </div>
                </div>

                <div class="rounded-xl border border-stone-200 bg-white shadow-crm">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.visit_history') }}</h2>
                    </div>
                    <div class="divide-y divide-stone-100">
                        @forelse ($bookings as $booking)
                            @php
                                $bookingCancellationLocked = $cancellationWindow->isLockedForBooking($booking);
                                $canCancelBooking = $booking->status === \App\Enums\ClassBookingStatus::Booked
                                    && $booking->scheduledClass?->starts_at?->greaterThan(now())
                                    && ! $bookingCancellationLocked;
                            @endphp
                            <article class="p-5 text-sm">
                                <div class="font-semibold text-slate-950">{{ $booking->scheduledClass?->title ?? $booking->scheduledClass?->classType?->name ?? __('app.class_type') }}</div>
                                <div class="mt-1 text-slate-500">{{ $formatBookingDate($booking) }}</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="crm-status-muted">{{ __('app.'.$booking->status->value) }}</span>
                                    @if ($booking->classPassReservation?->customerClassPass)
                                        <span class="crm-status-muted">{{ $booking->classPassReservation->customerClassPass->code }}</span>
                                    @endif
                                    @if ($booking->status === \App\Enums\ClassBookingStatus::Booked && $bookingCancellationLocked)
                                        <span class="crm-status-warning">{{ __('app.booking_cancellation_cutoff_marker') }}</span>
                                    @endif
                                </div>
                                @if ($canCancelBooking)
                                    <form method="POST" action="{{ route('customer.bookings.cancel', [$account->slug, $booking]) }}" class="mt-3">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.cancel_booking') }}</x-ui.button>
                                    </form>
                                @endif
                            </article>
                        @empty
                            <x-ui.empty-state :title="__('app.no_bookings')" icon="schedule" class="m-5" />
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="mt-6 rounded-xl border border-stone-200 bg-white shadow-crm">
                <div class="border-b border-stone-100 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.payment_history') }}</h2>
                </div>
                <div class="divide-y divide-stone-100">
                    @forelse ($purchaseHistory as $purchase)
                        @php
                            $currentProviderLabel = $providerLabel($purchase->provider);
                            $statusClass = match ($purchase->status->value) {
                                'payment_paid' => 'crm-status-active',
                                'payment_failed', 'payment_cancelled', 'payment_expired' => 'crm-status-danger',
                                default => 'crm-status-muted',
                            };
                        @endphp
                        <article class="p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $purchase->plan_name }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $purchase->order_id }} &middot; {{ $currentProviderLabel }} &middot; {{ $formatMoney($purchase->amount_cents, $purchase->currency) }}</div>
                                </div>
                                <span class="{{ $statusClass }}">{{ __('app.'.$purchase->status->value) }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                <span>{{ __('app.started_at') }}: {{ $formatDateTime($purchase->started_at) }}</span>
                                <span>{{ __('app.paid_at') }}: {{ $formatDateTime($purchase->paid_at) }}</span>
                                @if ($purchase->location)
                                    <span>{{ __('app.payment_location') }}: {{ $purchase->location->name }}</span>
                                @endif
                                @if ($purchase->customerClassPass)
                                    <span>{{ __('app.class_pass_code') }}: {{ $purchase->customerClassPass->code }}</span>
                                    <span>{{ __('app.usable_until_at') }}: {{ $formatDate($purchase->customerClassPass->usableUntilAt()) }}</span>
                                @endif
                            </div>
                            @if ($purchase->failure_reason)
                                <div class="mt-3 text-sm text-rose-700">{{ $purchase->failure_reason }}</div>
                            @endif
                        </article>
                    @empty
                        <x-ui.empty-state :title="__('app.no_payment_history')" icon="credit-card" class="m-5" />
                    @endforelse
                </div>
                @if ($purchaseHistory->hasPages())
                    <div class="border-t border-stone-100 px-5 py-4">
                        {{ $purchaseHistory->links() }}
                    </div>
                @endif
            </section>
        </section>
    </main>
@endsection
