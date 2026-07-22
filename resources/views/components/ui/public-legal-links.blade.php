@props([
    'account',
    'returnUrl',
    'variant' => 'pill',
])

@php
    $linkClass = match ($variant) {
        'landing' => 'inline-flex items-center justify-center gap-2 rounded-lg border border-stone-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs transition hover:border-brand-100 hover:bg-brand-50 hover:text-brand-700',
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

@if (filled($account->studio_rules_html))
    <a href="{{ $studioRulesUrl }}" class="{{ $linkClass }}" data-public-legal-link>
        @if ($variant === 'landing')
            <x-ui.icon name="file-text" class="h-4 w-4" />
        @endif
        {{ __('app.studio_rules') }}
    </a>
@endif

@if (filled($account->public_offer_html))
    <a href="{{ $publicOfferUrl }}" class="{{ $linkClass }}" data-public-legal-link>
        @if ($variant === 'landing')
            <x-ui.icon name="file-text" class="h-4 w-4" />
        @endif
        {{ __('app.public_offer') }}
    </a>
@endif
