@extends('layouts.app')

@section('title', __('app.edit').' '.$customer->name)

@section('content')
    @php
        $formatMoney = static function (?int $priceCents, string $currency = 'UAH'): string {
            if ($priceCents === null) {
                return '';
            }

            return \App\Support\MoneyFormatter::format($priceCents, $currency);
        };
        $formatMoneyInput = static function (?int $priceCents): string {
            if ($priceCents === null) {
                return '';
            }

            $whole = intdiv($priceCents, 100);
            $fraction = $priceCents % 100;

            return $fraction === 0
                ? (string) $whole
                : number_format($priceCents / 100, 2, '.', '');
        };
        $bookings = $customer->classBookings
            ->sortByDesc(fn ($booking) => $booking->scheduledClass?->starts_at?->timestamp ?? $booking->created_at?->timestamp ?? 0);
        $formatBookingDate = static function ($booking) use ($account): string {
            $scheduledClass = $booking->scheduledClass;
            $date = $scheduledClass?->starts_at ?? $booking->created_at;
            $timezone = $scheduledClass?->displayTimezone() ?? $account->timezone ?? config('app.timezone');

            return \App\Support\DateTimePresenter::formatInTimezone($date, $timezone) ?? '';
        };
        $formatDate = static fn ($date): string => \App\Support\DateTimePresenter::date($date, $account) ?? __('app.not_set');
        $canIssueCustomerClassPasses = auth()->user()?->can('issueCustomerClassPasses', $account) ?? false;
        $canManageCustomerClassPasses = auth()->user()?->can('manageCustomerClassPasses', $account) ?? false;
        $canLoginAsCustomer = $account->isOwnedBy(auth()->user());
        $classPassBackfillPreview ??= null;
        $locations ??= collect();
        $selectedIssueLocationId = old('issued_location_id');
        $classPassTab = ($classPassTab ?? 'active') === 'history' ? 'history' : 'active';
        $classPassTabQuery = request()->except('class_pass_tab', 'class_passes_page', 'class_pass_history_page', 'class_pass_backfill_preview');
        $activeClassPassTabUrl = route('dashboard.accounts.customers.edit', [$account, $customer, ...$classPassTabQuery, 'class_pass_tab' => 'active']);
        $historyClassPassTabUrl = route('dashboard.accounts.customers.edit', [$account, $customer, ...$classPassTabQuery, 'class_pass_tab' => 'history']);
        $displayedCustomerClassPasses = $classPassTab === 'history' ? $customerClassPassHistory : $customerClassPasses;
        $emptyCustomerClassPassTitle = $classPassTab === 'history' ? __('app.no_customer_class_pass_history') : __('app.no_customer_class_passes');
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.edit') }} {{ $customer->name }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        @if ($canLoginAsCustomer)
            <form method="POST" action="{{ route('dashboard.accounts.customers.admin-login.store', [$account, $customer]) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    <x-ui.icon name="log-in" class="h-4 w-4" />
                    {{ __('app.login_as_customer') }}
                </x-ui.button>
            </form>
        @endif
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div class="space-y-6">
            <form method="POST" action="{{ route('dashboard.accounts.customers.update', [$account, $customer]) }}" class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                @csrf
                @method('PUT')
                @include('customers.form-fields')
                <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
            </form>

            @if ($canIssueCustomerClassPasses)
                <x-ui.panel>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.issue_class_pass') }}</h2>
                    <form
                        method="POST"
                        action="{{ route('dashboard.accounts.customers.class-passes.store', [$account, $customer]) }}"
                        class="mt-4 space-y-4"
                        data-confirm-action
                        data-confirm-title="{{ __('app.confirm_issue_class_pass_title') }}"
                        data-confirm-body="{{ __('app.confirm_issue_class_pass_body') }}"
                        data-confirm-accept="{{ __('app.issue_class_pass') }}"
                        data-confirm-icon="ticket"
                        data-confirm-variant="success"
                    >
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
                        @if ($locations->count() === 1)
                            @php
                                $onlyLocation = $locations->first();
                            @endphp
                            <input type="hidden" name="issued_location_id" value="{{ $onlyLocation->id }}">
                            <div>
                                <span class="crm-label">{{ __('app.issued_location') }}</span>
                                <div class="rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm font-medium text-slate-700">{{ $onlyLocation->name }}</div>
                            </div>
                            @error('issued_location_id') <span class="crm-help">{{ $message }}</span> @enderror
                        @else
                            <label class="block">
                                <span class="crm-label">{{ __('app.issued_location') }}</span>
                                <select name="issued_location_id" class="crm-field" required>
                                    <option value="">{{ __('app.location') }}</option>
                                    @foreach ($locations as $location)
                                        <option value="{{ $location->id }}" @selected((string) $selectedIssueLocationId === (string) $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                                @error('issued_location_id') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                        @endif
                        <input type="hidden" name="is_paid" value="0">
                        <label class="block">
                            <span class="crm-label">{{ __('app.class_pass_paid_today') }}</span>
                            <input name="paid_amount" value="{{ old('paid_amount') }}" inputmode="decimal" placeholder="{{ $formatMoneyInput(0) }}" class="crm-field">
                            @error('paid_amount') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        @error('is_paid') <span class="crm-help">{{ $message }}</span> @enderror
                        <x-ui.button type="submit">
                            <x-ui.icon name="ticket" class="h-4 w-4" />
                            {{ __('app.issue_class_pass') }}
                        </x-ui.button>
                    </form>
                </x-ui.panel>
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-stone-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.customer_class_passes_panel') }}</h2>
                    @if ($canManageCustomerClassPasses && $customerClassPasses->total() > 0)
                        <x-ui.button
                            :href="route('dashboard.accounts.customers.edit', [$account, $customer, 'class_pass_backfill_preview' => 1])"
                            variant="secondary"
                            size="sm"
                        >
                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                            {{ __('app.preview_class_pass_backfill') }}
                        </x-ui.button>
                    @endif
                </div>
                <div class="border-b border-stone-100 px-5 py-3">
                    <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" role="tablist" aria-label="{{ __('app.customer_class_passes_panel') }}">
                        <a
                            href="{{ $activeClassPassTabUrl }}"
                            id="customer-class-passes-tab-active"
                            class="crm-tab justify-start sm:justify-center"
                            role="tab"
                            aria-controls="customer-class-passes-panel"
                            aria-selected="{{ $classPassTab === 'active' ? 'true' : 'false' }}"
                            tabindex="{{ $classPassTab === 'active' ? '0' : '-1' }}"
                        >
                            {{ __('app.customer_class_passes') }}
                            <span class="ml-2 rounded bg-stone-200 px-1.5 py-0.5 text-xs text-slate-600">{{ $customerClassPasses->total() }}</span>
                        </a>
                        <a
                            href="{{ $historyClassPassTabUrl }}"
                            id="customer-class-passes-tab-history"
                            class="crm-tab justify-start sm:justify-center"
                            role="tab"
                            aria-controls="customer-class-passes-panel"
                            aria-selected="{{ $classPassTab === 'history' ? 'true' : 'false' }}"
                            tabindex="{{ $classPassTab === 'history' ? '0' : '-1' }}"
                        >
                            {{ __('app.class_pass_history') }}
                            <span class="ml-2 rounded bg-stone-200 px-1.5 py-0.5 text-xs text-slate-600">{{ $customerClassPassHistory->total() }}</span>
                        </a>
                    </div>
                </div>
                <div id="customer-class-passes-panel" role="tabpanel" aria-labelledby="customer-class-passes-tab-{{ $classPassTab }}">
                    @forelse ($displayedCustomerClassPasses as $customerClassPass)
                        @php
                            $statusClass = match ($customerClassPass->status) {
                                \App\Enums\CustomerClassPassStatus::Active => 'crm-status-active',
                                \App\Enums\CustomerClassPassStatus::Freezed => 'crm-status-warning',
                                default => 'crm-status-muted',
                            };
                            $currentPaymentStatus = $customerClassPass->paymentStatus();
                            $paymentStatusClass = match ($currentPaymentStatus) {
                                'paid' => 'crm-status-active',
                                'partial' => 'crm-status-warning',
                                default => 'crm-status-danger',
                            };
                        @endphp
                        <div class="border-b border-stone-100 px-5 py-4 last:border-b-0">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <div class="font-semibold text-slate-950">{{ $customerClassPass->plan_name }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $customerClassPass->code }} · {{ $formatMoney($customerClassPass->price_cents, $customerClassPass->currency) }}</div>
                                    @if ($customerClassPass->isPartiallyPaid())
                                        <div class="mt-1 text-xs font-semibold text-amber-700">
                                            {{ __('app.class_pass_paid_amount') }}: {{ $formatMoney($customerClassPass->paidAmountCents(), $customerClassPass->currency) }} ·
                                            {{ __('app.class_pass_remaining_amount') }}: {{ $formatMoney($customerClassPass->remainingPaymentCents(), $customerClassPass->currency) }}
                                        </div>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2 sm:justify-end">
                                    <span class="{{ $paymentStatusClass }}">{{ __('app.class_pass_'.$currentPaymentStatus) }}</span>
                                    <span class="{{ $statusClass }}">{{ __('app.'.$customerClassPass->status->value) }}</span>
                                </div>
                            </div>
                            <div class="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-3">
                                <div>{{ __('app.remaining_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->remainingSessionsCount() }}</span></div>
                                <div>{{ __('app.reserved_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->reserved_sessions_count }}</span></div>
                                <div>{{ __('app.used_sessions') }}: <span class="font-semibold text-slate-950">{{ $customerClassPass->used_sessions_count }}</span></div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                <span>{{ __('app.purchased_at') }}: {{ $formatDate($customerClassPass->purchased_at) }}</span>
                                @if ($customerClassPass->issuedLocation)
                                    <span>{{ __('app.issued_location') }}: {{ $customerClassPass->issuedLocation->name }}</span>
                                @endif
                                <span>{{ __('app.opened_at') }}: {{ $formatDate($customerClassPass->opened_at) }}</span>
                                <span>{{ __('app.expires_after_first_class') }}: {{ $formatDate($customerClassPass->expires_at) }}</span>
                                <span>{{ __('app.usable_until_at') }}: {{ $formatDate($customerClassPass->usableUntilAt()) }}</span>
                            </div>
                            @if ($canManageCustomerClassPasses)
                                <div class="mt-3">
                                    <x-ui.action-button :href="route('dashboard.accounts.customer-class-passes.edit', [$account, $customerClassPass])" icon="edit" :label="__('app.edit')" />
                                </div>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state :title="$emptyCustomerClassPassTitle" icon="class-pass-plans" class="m-5" />
                    @endforelse
                </div>
                @if ($displayedCustomerClassPasses->hasPages())
                    <div class="border-t border-stone-100 px-5 py-4">
                        {{ $displayedCustomerClassPasses->onEachSide(1)->links() }}
                    </div>
                @endif
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
                                <div class="mt-1 text-slate-500">{{ $formatBookingDate($booking) }} · {{ $booking->scheduledClass?->classType?->name }}</div>
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

    @if ($classPassBackfillPreview)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4" role="dialog" aria-modal="true" aria-labelledby="class-pass-backfill-title">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-2xl">
                <div class="border-b border-stone-100 px-6 py-5">
                    <h2 id="class-pass-backfill-title" class="text-lg font-semibold text-slate-950">{{ __('app.class_pass_backfill_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.class_pass_backfill_body') }}</p>
                </div>

                <div class="space-y-3 px-6 py-5">
                    @if (! $classPassBackfillPreview['has_changes'])
                        <div class="rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-slate-600">
                            {{ __('app.class_pass_backfill_empty') }}
                        </div>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3">
                                <div class="text-xs font-semibold uppercase text-emerald-700">{{ __('app.used_sessions') }}</div>
                                <div class="mt-1 text-2xl font-semibold text-emerald-900">{{ $classPassBackfillPreview['totals']['used'] }}</div>
                            </div>
                            <div class="rounded-lg border border-sky-100 bg-sky-50 px-4 py-3">
                                <div class="text-xs font-semibold uppercase text-sky-700">{{ __('app.reserved_sessions') }}</div>
                                <div class="mt-1 text-2xl font-semibold text-sky-900">{{ $classPassBackfillPreview['totals']['reserved'] }}</div>
                            </div>
                        </div>

                        @foreach ($classPassBackfillPreview['passes'] as $passSummary)
                            <div class="rounded-lg border border-stone-200 px-4 py-3">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <div class="font-semibold text-slate-950">{{ $passSummary['pass']->plan_name }}</div>
                                        <div class="mt-1 text-sm text-slate-500">{{ $passSummary['pass']->code }}</div>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-sm">
                                        <span class="rounded-md bg-emerald-50 px-2 py-1 font-semibold text-emerald-700">{{ __('app.used') }}: {{ $passSummary['used_count'] }}</span>
                                        <span class="rounded-md bg-sky-50 px-2 py-1 font-semibold text-sky-700">{{ __('app.reserved') }}: {{ $passSummary['reserved_count'] }}</span>
                                    </div>
                                </div>
                                <div class="mt-3 divide-y divide-stone-100 text-sm">
                                    @foreach ($passSummary['bookings'] as $bookingChange)
                                        @php
                                            $booking = $bookingChange['booking'];
                                            $reservationStatus = $bookingChange['reservation_status'];
                                        @endphp
                                        <div class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <div class="font-medium text-slate-800">{{ $booking->scheduledClass?->title }}</div>
                                                <div class="text-xs text-slate-500">{{ $formatBookingDate($booking) }} · {{ $booking->scheduledClass?->classType?->name }}</div>
                                            </div>
                                            <span class="text-xs font-semibold text-slate-600">{{ __('app.'.$reservationStatus->value) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-stone-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.customers.edit', [$account, $customer])" variant="secondary">
                        {{ __('app.cancel') }}
                    </x-ui.button>
                    @if ($classPassBackfillPreview['has_changes'])
                        <form method="POST" action="{{ route('dashboard.accounts.customers.class-passes.backfill', [$account, $customer]) }}">
                            @csrf
                            <x-ui.button type="submit" variant="success">
                                <x-ui.icon name="check" class="h-4 w-4" />
                                {{ __('app.apply_class_pass_backfill') }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection
