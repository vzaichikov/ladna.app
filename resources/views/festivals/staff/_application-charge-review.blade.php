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
    @php
        $allocatedPaymentAttempts = $charge->allocatedPaymentAttempts();
    @endphp
    @if ($allocatedPaymentAttempts->isNotEmpty())
        <div class="mt-3 space-y-2">
            @foreach ($allocatedPaymentAttempts->sortByDesc('id') as $attempt)
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
    @php
        $manualPaymentDecisionConfig = [
            'approve' => [
                'button_label' => __('app.festival_manual_payment_confirm'),
                'button_variant' => 'success',
                'confirm_title' => __('app.festival_manual_payment_confirm_title'),
                'confirm_body' => __('app.festival_manual_payment_confirm_copy'),
                'confirm_accept' => __('app.festival_manual_payment_confirm'),
                'confirm_icon' => 'circle-check',
                'confirm_variant' => 'success',
                'comment_required' => false,
                'deadline_required' => false,
            ],
            'reject' => [
                'button_label' => __('app.festival_manual_payment_reject'),
                'button_variant' => 'danger',
                'confirm_title' => __('app.festival_manual_payment_reject_title'),
                'confirm_body' => __('app.festival_manual_payment_reject_copy'),
                'confirm_accept' => __('app.festival_manual_payment_reject'),
                'confirm_icon' => 'circle-x',
                'confirm_variant' => 'danger',
                'comment_required' => false,
                'deadline_required' => false,
            ],
        ];
        $manualPaymentConfirmationDetails = [
            ['label' => __('app.festival_charge'), 'value' => $charge->name],
            ['label' => __('app.amount'), 'value' => \App\Support\MoneyFormatter::format($charge->amount_cents, $charge->currency)],
        ];
    @endphp
    <form
        method="POST"
        action="{{ route('dashboard.accounts.festivals.charges.manual-review', [$account, $edition, $charge]) }}"
        class="mt-3 grid gap-2 sm:grid-cols-[auto_minmax(0,1fr)_auto]"
        data-async-form
        data-confirm-action
        data-festival-decision-form
        data-decision-config='@json($manualPaymentDecisionConfig)'
        data-decision-base-details='@json($manualPaymentConfirmationDetails)'
        data-decision-notes-label="{{ __('app.notes') }}"
        data-decision-empty-value="—"
        data-confirm-title="{{ __('app.festival_manual_payment_confirm_title') }}"
        data-confirm-body="{{ __('app.festival_manual_payment_confirm_copy') }}"
        data-confirm-accept="{{ __('app.festival_manual_payment_confirm') }}"
        data-confirm-icon="circle-check"
        data-confirm-variant="success"
        data-confirm-details='@json($manualPaymentConfirmationDetails)'
    >
        @csrf
        @method('PATCH')
        <select name="decision" class="crm-field mt-0" data-festival-decision>
            <option value="approve">{{ __('app.festival_manual_payment_confirm') }}</option>
            <option value="reject">{{ __('app.festival_manual_payment_reject') }}</option>
        </select>
        <input name="notes" value="{{ $charge->notes }}" placeholder="{{ __('app.notes') }}" class="crm-field mt-0" data-festival-decision-notes>
        <x-ui.button type="submit" size="sm" variant="success" data-festival-decision-submit>
            <span data-festival-decision-submit-label>{{ __('app.festival_manual_payment_confirm') }}</span>
        </x-ui.button>
    </form>
</div>
