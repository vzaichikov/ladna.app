@php
    $paymentBag = 'festival_payment_'.$charge->id;
    $paymentErrors = $errors->getBag($paymentBag);
    $isPayable = $selectedState['available'] && in_array($charge->status, [\App\Enums\FestivalChargeStatus::Pending, \App\Enums\FestivalChargeStatus::Failed], true);
    $summaryClass = match ($charge->status) {
        \App\Enums\FestivalChargeStatus::Paid => 'border-emerald-300 bg-emerald-50',
        \App\Enums\FestivalChargeStatus::Failed,
        \App\Enums\FestivalChargeStatus::PaidRequiresRefund => 'border-rose-300 bg-rose-50',
        \App\Enums\FestivalChargeStatus::PaymentPending => 'border-amber-300 bg-amber-50',
        default => 'border-stone-200 bg-white',
    };
    $paymentClass = match ($charge->status) {
        \App\Enums\FestivalChargeStatus::Paid => 'border-emerald-300 bg-emerald-50',
        \App\Enums\FestivalChargeStatus::PaidRequiresRefund => 'border-rose-300 bg-rose-50',
        \App\Enums\FestivalChargeStatus::PaymentPending => 'border-amber-300 bg-amber-50',
        default => 'border-stone-200 bg-white',
    };
    $statusClass = match ($charge->status) {
        \App\Enums\FestivalChargeStatus::Paid => 'crm-status-active',
        \App\Enums\FestivalChargeStatus::Failed,
        \App\Enums\FestivalChargeStatus::PaidRequiresRefund => 'crm-status-danger',
        \App\Enums\FestivalChargeStatus::PaymentPending => 'crm-status-warning',
        default => 'crm-status-muted',
    };
    $festivalRulesUrl = route('public.festivals.show', [$account->slug, $entry->edition->slug]).'#festival-rules';
@endphp

<div id="festival-charge-{{ $charge->id }}" data-festival-charge-card class="scroll-mt-6 grid gap-6 lg:grid-cols-[1fr_0.75fr]">
    <article class="rounded-xl border p-6 shadow-crm {{ $summaryClass }}">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="text-sm font-semibold uppercase text-brand-600">{{ __('app.festival_payment_for') }}</div>
                <h4 class="mt-2 text-3xl font-semibold leading-tight text-slate-950">{{ $charge->name }}</h4>
                @if (filled($charge->notes))
                    <p class="mt-3 max-w-2xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ $charge->notes }}</p>
                @endif
            </div>
            <span class="{{ $statusClass }}">{{ __('app.festival_charge_status_'.$charge->status->value) }}</span>
        </div>

        <div class="mt-6 text-4xl font-semibold text-slate-950">{{ \App\Support\MoneyFormatter::format($charge->amount_cents, $charge->currency) }}</div>

        @if ($charge->due_at)
            <dl class="mt-6 max-w-xs rounded-lg bg-white/70 p-3 text-sm">
                <dt class="text-slate-500">{{ __('app.festival_payment_due') }}</dt>
                <dd class="mt-1 font-semibold text-slate-950">{{ $charge->due_at->timezone($entry->edition->timezone)->format('d.m.Y H:i') }}</dd>
            </dl>
        @endif
    </article>

    <aside class="rounded-xl border p-5 shadow-crm {{ $paymentClass }}">
        <h4 class="text-lg font-semibold text-slate-950">{{ __('app.payment_method') }}</h4>

        @if ($paymentErrors->has('provider'))
            <div data-festival-payment-error class="mt-4 rounded-xl border border-rose-200 bg-white/80 px-4 py-3 text-sm font-semibold text-rose-800">
                {{ $paymentErrors->first('provider') }}
            </div>
        @endif

        @if ($charge->status === \App\Enums\FestivalChargeStatus::Paid)
            <div class="mt-4 rounded-xl border border-emerald-300 bg-white/80 px-4 py-5 text-center text-emerald-900">
                <span class="crm-status-active">{{ __('app.festival_charge_status_paid') }}</span>
            </div>
        @elseif ($charge->status === \App\Enums\FestivalChargeStatus::PaidRequiresRefund)
            <div class="mt-4 rounded-xl border border-rose-300 bg-white/80 px-4 py-5 text-center text-rose-900">
                <span class="crm-status-danger">{{ __('app.festival_charge_status_paid_requires_refund') }}</span>
            </div>
        @elseif ($charge->status === \App\Enums\FestivalChargeStatus::PaymentPending)
            <div class="mt-4 rounded-xl border border-amber-300 bg-white/80 px-4 py-5 text-center text-amber-900">
                <span class="crm-status-warning">{{ __('app.festival_charge_status_payment_pending') }}</span>
                <p class="mt-3 text-sm font-semibold">{{ __('app.festival_payment_already_pending') }}</p>
            </div>
        @elseif ($isPayable && $providers->isNotEmpty())
            @if ($charge->status === \App\Enums\FestivalChargeStatus::Failed)
                <p class="mt-4 text-sm font-semibold text-rose-700">{{ __('app.festival_payment_failed_retry') }}</p>
            @endif

            <div class="mt-4 space-y-3">
                @foreach ($providers as $provider)
                    <form method="POST" action="{{ route('festival.portal.charges.pay', [$account->slug, $entry, $charge]) }}" data-festival-combined-payment>
                        @csrf
                        <input type="hidden" name="provider" value="{{ $provider->provider->value }}">
                        <label class="mb-3 flex items-start gap-3 rounded-lg border border-stone-200 bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-600">
                            <input
                                type="checkbox"
                                name="festival_rules_accepted"
                                value="1"
                                checked
                                required
                                class="crm-checkbox mt-1"
                            >
                            <span>
                                {{ __('app.festival_rules_agreement_prefix') }}
                                <a href="{{ $festivalRulesUrl }}" target="_blank" rel="noopener" class="font-semibold text-brand-700 transition hover:text-brand-600">
                                    {{ __('app.festival_rules_link_text') }}
                                </a>.
                            </span>
                        </label>
                        @if ($paymentErrors->has('festival_rules_accepted'))
                            <span data-festival-payment-error class="crm-help mb-3 block">{{ $paymentErrors->first('festival_rules_accepted') }}</span>
                        @endif
                        <p data-festival-progress-blocked-message @class(['mb-3 text-sm font-semibold text-amber-800', 'hidden' => $selectedState['requirements_complete']])>{{ __('app.festival_complete_required_fields_first') }}</p>
                        <x-ui.button type="submit" variant="success" size="lg" class="w-full justify-start px-3" data-festival-progress-action :disabled="!$selectedState['requirements_complete']">
                            <x-ui.payment-brand :provider="$provider->provider->value" :label="config('integrations.providers.'.$provider->provider->value.'.label')" presentation="card" :action-label="__('app.festival_submit_and_pay')" class="w-full" />
                        </x-ui.button>
                    </form>
                @endforeach
            </div>

            <x-ui.accepted-card-brands class="mt-5" />
        @elseif ($isPayable)
            <div class="mt-4 rounded-xl border border-amber-200 bg-white/80 px-4 py-3 text-sm font-semibold text-amber-900">
                {{ __('app.no_payment_methods_available') }}
            </div>
        @endif
    </aside>
</div>
