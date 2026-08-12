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
    @if ($attempt = $charge->paymentAttempts->sortByDesc('id')->first())
        <p class="mt-2 text-xs text-slate-500">{{ $attempt->provider }} · {{ __('app.festival_payment_status_'.$attempt->status->value) }} · {{ $attempt->order_id }}</p>
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
