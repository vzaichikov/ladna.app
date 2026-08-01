@props([
    'provider',
    'label' => null,
    'presentation' => 'provider',
])

@php
    $providerValue = $provider instanceof \BackedEnum ? $provider->value : (string) $provider;
    $displayLabel = match ($providerValue) {
        'monopay' => 'Monopay',
        'liqpay' => 'LiqPay',
        'wayforpay' => 'WayForPay',
        default => $label ?? config('integrations.providers.'.$providerValue.'.label', $providerValue),
    };
    $showCardPresentation = $presentation === 'card' && $providerValue === 'monopay';
@endphp

@if ($showCardPresentation)
    <span {{ $attributes->class(['inline-flex min-w-0 items-center gap-2 text-left']) }}>
        <x-ui.icon name="credit-card" class="h-5 w-5 shrink-0 text-white" />
        <span class="min-w-0 text-sm font-semibold">{{ __('app.pay_by_card') }}</span>
        <span class="ml-auto inline-flex shrink-0 items-center gap-1.5">
            <img src="{{ asset('assets/payment-methods/google-pay-mark.svg') }}" alt="Google Pay" class="h-9 w-auto">
            <img src="{{ asset('assets/payment-methods/apple-pay-mark.svg') }}" alt="Apple Pay" class="h-5 w-auto">
        </span>
    </span>
@else
    <span {{ $attributes->class(['inline-flex min-w-0 items-center gap-3 text-left']) }}>
        <span class="flex h-8 w-24 shrink-0 items-center justify-center rounded-md border border-stone-200 bg-white px-2 shadow-xs" aria-hidden="true">
            @switch($providerValue)
                @case('monopay')
                    <span class="text-[15px] font-black leading-none">
                        <span class="text-slate-950">mono</span><span class="text-[#00a651]">pay</span>
                    </span>
                    @break

                @case('liqpay')
                    <span class="text-[15px] font-black leading-none">
                        <span class="text-[#79b829]">Liq</span><span class="text-[#1f69b3]">Pay</span>
                    </span>
                    @break

                @case('wayforpay')
                    <span class="text-[13px] font-black leading-none">
                        <span class="text-[#2c6fb7]">Way</span><span class="text-slate-700">For</span><span class="text-[#f28c28]">Pay</span>
                    </span>
                    @break

                @default
                    <span class="text-[13px] font-black leading-none text-slate-800">{{ $displayLabel }}</span>
            @endswitch
        </span>
        <span class="block min-w-0 truncate text-sm font-semibold">
            {{ __('app.pay_with_provider', ['provider' => $displayLabel]) }}
        </span>
    </span>
@endif
