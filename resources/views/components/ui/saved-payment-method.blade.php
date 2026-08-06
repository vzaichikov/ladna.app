@props([
    'account',
    'paymentMethod' => null,
    'returnTo',
    'canChange' => true,
])

@php
    $isActive = $paymentMethod?->isActive() === true;
    $isPending = $paymentMethod?->status === \App\Enums\SubscriptionPaymentMethodStatus::PendingVerification
        && filled($paymentMethod->verification_invoice_id);
    $isStalePending = $isPending
        && $paymentMethod->updated_at?->isBefore(now()->subHour()) === true;
    $displayValue = match (true) {
        $isActive => trim(($paymentMethod->card_brand ? strtoupper($paymentMethod->card_brand).' ' : '').$paymentMethod->masked_pan),
        $isPending => __('app.payment_method_verification_pending'),
        default => __('app.not_set'),
    };
    $buttonLabel = match (true) {
        $isActive => __('app.change_payment_method'),
        $isPending => __('app.retry_payment_method_verification'),
        default => __('app.link_payment_method'),
    };
@endphp

<div {{ $attributes }}>
    <dt class="text-slate-500">{{ __('app.saved_payment_method') }}</dt>
    <dd class="mt-1">
        <span class="font-semibold text-slate-950">{{ $displayValue }}</span>

        @if ($canChange && (! $isPending || $isStalePending))
            <form
                method="POST"
                action="{{ route('dashboard.accounts.payment-method.change', $account) }}"
                class="mt-4"
                @if ($isActive)
                    data-confirm-action
                    data-confirm-title="{{ __('app.change_payment_method_confirm_title') }}"
                    data-confirm-body="{{ __('app.change_payment_method_confirm_body') }}"
                    data-confirm-accept="{{ __('app.change_payment_method_confirm_accept') }}"
                    data-confirm-icon="payments"
                    data-confirm-variant="primary"
                @endif
            >
                @csrf
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <x-ui.button type="submit" variant="secondary" size="sm">
                    <x-ui.icon name="payments" class="h-4 w-4" />
                    {{ $buttonLabel }}
                </x-ui.button>
            </form>
        @elseif ($canChange && $isPending)
            <div class="mt-4">
                <x-ui.button variant="secondary" size="sm" disabled>
                    <x-ui.icon name="payments" class="h-4 w-4" />
                    {{ __('app.payment_method_verification_pending') }}
                </x-ui.button>
            </div>
        @endif

        @if ($canChange)
            @error('payment_method')
                <p class="mt-3 text-sm text-rose-700">{{ $message }}</p>
            @enderror
        @endif
    </dd>
</div>
