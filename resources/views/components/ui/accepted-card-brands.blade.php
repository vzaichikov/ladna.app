<div {{ $attributes->class(['flex items-center justify-center gap-2 border-t border-stone-100 pt-4']) }}>
    <span class="sr-only">{{ __('app.accepted_cards') }}</span>
    <img src="{{ asset('assets/payment-methods/visa.svg') }}" alt="Visa" class="h-auto w-20">
    <img src="{{ asset('assets/payment-methods/mastercard.svg') }}" alt="Mastercard" class="h-auto w-14">
</div>
