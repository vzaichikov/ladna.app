@extends('layouts.public')

@section('title', __('app.customer_portal').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $formatMoney = fn (?int $amount, ?string $currency): string => $amount === null ? __('app.not_set') : number_format($amount / 100, 0, '.', ' ').' '.$currency;
        $passes = $customer->customerClassPasses
            ->sortByDesc(fn ($pass) => ($pass->is_active ? '1' : '0').($pass->opened_at?->timestamp ?? $pass->purchased_at?->timestamp ?? 0));
        $bookings = $customer->classBookings
            ->sortByDesc(fn ($booking) => $booking->scheduledClass?->starts_at?->timestamp ?? $booking->created_at?->timestamp ?? 0);
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

            <section class="mt-6 grid gap-4 md:grid-cols-3">
                <x-ui.metric :label="__('app.active_class_passes_short')" :value="$passes->where('is_active', true)->count()" icon="class-pass-plans" />
                <x-ui.metric :label="__('app.remaining_sessions')" :value="$passes->sum(fn ($pass) => max(0, $pass->remainingSessionsCount()))" icon="check-circle" accent="emerald" />
                <x-ui.metric :label="__('app.bookings')" :value="$bookings->count()" icon="schedule" accent="brand" />
            </section>

            <section class="mt-6 grid gap-6 lg:grid-cols-[1fr_0.8fr]">
                <div class="rounded-xl border border-stone-200 bg-white shadow-crm">
                    <div class="border-b border-stone-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_class_passes') }}</h2>
                    </div>
                    <div class="divide-y divide-stone-100">
                        @forelse ($passes as $pass)
                            <article class="p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $pass->plan_name }}</div>
                                        <div class="mt-1 text-sm text-slate-500">{{ $pass->code }} · {{ $formatMoney($pass->price_cents, $pass->currency) }}</div>
                                    </div>
                                    <span class="{{ $pass->is_active ? 'crm-status-active' : 'crm-status-muted' }}">{{ __('app.'.$pass->status->value) }}</span>
                                </div>
                                <dl class="mt-4 grid gap-3 text-sm text-slate-600 sm:grid-cols-3">
                                    <div>{{ __('app.remaining_sessions') }}: <span class="font-semibold text-slate-950">{{ $pass->remainingSessionsCount() }}</span></div>
                                    <div>{{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $pass->reserved_sessions_count }}</span></div>
                                    <div>{{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $pass->used_sessions_count }}</span></div>
                                </dl>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                    <span>{{ __('app.purchased_at') }}: {{ $pass->purchased_at?->format('Y-m-d') }}</span>
                                    <span>{{ __('app.opened_at') }}: {{ $pass->opened_at?->format('Y-m-d') ?? __('app.not_set') }}</span>
                                    <span>{{ __('app.expires_after_first_class') }}: {{ $pass->expires_at?->format('Y-m-d') ?? __('app.not_set') }}</span>
                                    <span>{{ __('app.usable_until_at') }}: {{ $pass->usableUntilAt()?->format('Y-m-d') ?? __('app.not_set') }}</span>
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
                            <article class="p-5 text-sm">
                                <div class="font-semibold text-slate-950">{{ $booking->scheduledClass?->title ?? $booking->scheduledClass?->classType?->name ?? __('app.class_type') }}</div>
                                <div class="mt-1 text-slate-500">{{ $booking->scheduledClass?->starts_at?->format('Y-m-d H:i') ?? $booking->created_at?->format('Y-m-d H:i') }}</div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="crm-status-muted">{{ __('app.'.$booking->status->value) }}</span>
                                    @if ($booking->classPassReservation?->customerClassPass)
                                        <span class="crm-status-muted">{{ $booking->classPassReservation->customerClassPass->code }}</span>
                                    @endif
                                </div>
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
                            $providerLabel = config('integrations.providers.'.$purchase->provider.'.label', $purchase->provider);
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
                                    <div class="mt-1 text-sm text-slate-500">{{ $purchase->order_id }} &middot; {{ $providerLabel }} &middot; {{ $formatMoney($purchase->amount_cents, $purchase->currency) }}</div>
                                </div>
                                <span class="{{ $statusClass }}">{{ __('app.'.$purchase->status->value) }}</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                <span>{{ __('app.started_at') }}: {{ $purchase->started_at?->format('Y-m-d H:i') ?? __('app.not_set') }}</span>
                                <span>{{ __('app.paid_at') }}: {{ $purchase->paid_at?->format('Y-m-d H:i') ?? __('app.not_set') }}</span>
                                @if ($purchase->customerClassPass)
                                    <span>{{ __('app.class_pass_code') }}: {{ $purchase->customerClassPass->code }}</span>
                                    <span>{{ __('app.usable_until_at') }}: {{ $purchase->customerClassPass->usableUntilAt()?->format('Y-m-d') ?? __('app.not_set') }}</span>
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
