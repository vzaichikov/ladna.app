<div
    class="rounded-lg border border-stone-200 bg-white p-3"
    data-festival-application-fragment
    data-festival-application-fragment-key="charge-{{ $charge->id }}"
>
    <div
        data-async-form-status
        data-error-message="{{ __('app.async_request_failed') }}"
        data-validation-message="{{ __('app.async_validation_failed') }}"
        class="hidden"
        role="status"
        aria-live="polite"
    ></div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <strong>{{ $charge->name }}</strong>
            <span class="ml-2 text-xs text-slate-500">{{ __('app.festival_charge_status_'.$charge->status->value) }}</span>
        </div>
        <strong>{{ \App\Support\MoneyFormatter::format($charge->amount_cents, $charge->currency) }}</strong>
    </div>
    @if ($charge->paymentAttempts->isNotEmpty())
        <div class="mt-3 space-y-2">
            @foreach ($charge->paymentAttempts->sortByDesc('id') as $attempt)
                @php
                    $receipt = $attempt->fiscalReceipt;
                @endphp
                <dl class="grid gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 sm:grid-cols-2">
                    <div>
                        <dt>{{ __('app.festival_payment_reference') }}</dt>
                        <dd class="mt-1 break-all font-semibold text-slate-900">{{ $attempt->order_id }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('app.status') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ $attempt->provider }} · {{ __('app.festival_payment_status_'.$attempt->status->value) }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('app.festival_online_payment_time') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-900">{{ ($attempt->paid_at ?? $attempt->created_at)->timezone($edition->timezone)->format('d.m.Y H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt>{{ __('app.festival_gateway_invoice') }}</dt>
                        <dd class="mt-1 break-all font-semibold text-slate-900">{{ $attempt->gateway_invoice_id ?: '—' }}</dd>
                    </div>
                    @if ($attempt->status === \App\Enums\FestivalPaymentStatus::Paid)
                        <div class="sm:col-span-2">
                            <dt>{{ __('app.festival_fiscal_receipt') }}</dt>
                            <dd class="mt-1 font-semibold text-slate-900">
                                @if ($receipt)
                                    {{ __('app.fiscal_status_'.$receipt->status->value) }}
                                    @if ($receipt->fiscal_number)
                                        · {{ $receipt->fiscal_number }}
                                    @endif
                                @elseif ($festivalFiscalizationEnabled ?? app(\App\Support\Fiscalization\FiscalizationAvailability::class)->enabledForAccount($account))
                                    {{ __('app.fiscal_status_pending') }}
                                @else
                                    {{ __('app.festival_fiscal_not_configured') }}
                                @endif
                            </dd>
                            @if ($receipt?->last_error)
                                <p class="mt-1 text-rose-700">{{ $receipt->last_error }}</p>
                            @endif
                        </div>
                    @endif
                </dl>
            @endforeach
        </div>
    @endif
    <form
        method="POST"
        action="{{ route('dashboard.accounts.festivals.charges.manual-review', [$account, $edition, $charge]) }}"
        class="mt-3 grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto]"
        data-async-form
    >
        @csrf
        @method('PATCH')
        <select name="decision" class="crm-field mt-0">
            <option value="approve">{{ __('app.accept') }}</option>
            <option value="reject">{{ __('app.reject') }}</option>
        </select>
        <input name="notes" value="{{ $charge->notes }}" placeholder="{{ __('app.notes') }}" class="crm-field mt-0">
        <x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button>
    </form>
</div>
