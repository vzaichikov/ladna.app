@props([
    'account',
    'returnUrl',
    'variant' => 'pill',
    'showOffer' => true,
    'showRules' => true,
])

@php
    $linkClass = match ($variant) {
        'landing' => 'inline-flex w-full items-center justify-center gap-2 rounded-lg border border-stone-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs transition hover:border-brand-100 hover:bg-brand-50 hover:text-brand-700 sm:w-auto',
        'footer' => 'text-sm font-semibold text-brand-700 transition hover:text-brand-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-200 focus-visible:ring-offset-2',
        'text' => 'text-sm font-semibold text-brand-700 transition hover:text-brand-600',
        default => 'inline-flex items-center rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs transition hover:border-brand-100 hover:bg-brand-50 hover:text-brand-700',
    };
    $studioRulesUrl = route('public.studio-rules', [
        'accountSlug' => $account->slug,
        'return_to' => $returnUrl,
    ]);
    $publicOfferUrl = route('public.studio-offer', [
        'accountSlug' => $account->slug,
        'return_to' => $returnUrl,
    ]);
@endphp

@if ($showRules && filled($account->studio_rules_html))
    <a
        href="{{ $studioRulesUrl }}"
        class="{{ $linkClass }}"
        data-public-legal-link
        @if ($variant === 'footer') data-public-rules-footer-link @endif
    >
        @if ($variant === 'landing')
            <x-ui.icon name="file-text" class="h-4 w-4" />
        @endif
        {{ __('app.studio_rules') }}
    </a>
@endif

@if ($showOffer && filled($account->public_offer_html))
    <a
        href="{{ $publicOfferUrl }}"
        class="{{ $linkClass }}"
        data-public-legal-link
        @if ($variant === 'footer') data-public-offer-footer-link @endif
    >
        @if ($variant === 'landing')
            <x-ui.icon name="file-text" class="h-4 w-4" />
        @endif
        {{ __('app.public_offer') }}
    </a>
@endif
