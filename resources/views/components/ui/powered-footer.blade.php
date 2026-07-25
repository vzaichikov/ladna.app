@props([
    'account' => null,
    'border' => true,
    'returnUrl' => request()->fullUrl(),
    'showLocaleSwitcher' => false,
])

@php
    $showStudioLegalLinks = $account instanceof \App\Models\Account
        && (filled($account->studio_rules_html) || filled($account->public_offer_html));
@endphp

<footer
    {{
        $attributes
            ->class([
                'flex flex-col items-center gap-2 text-center',
                'border-t border-stone-200 pt-6' => $border,
            ])
    }}
>
    @if ($showStudioLegalLinks)
        <nav
            class="mb-2 flex flex-wrap justify-center gap-x-5 gap-y-2"
            aria-label="{{ __('app.footer_links') }}"
            data-customer-footer-legal-links
        >
            <x-ui.public-legal-links
                :account="$account"
                :return-url="$returnUrl"
                variant="footer"
            />
        </nav>
        <div class="mb-2 h-px w-full max-w-sm bg-stone-200" aria-hidden="true"></div>
    @endif

    <x-ui.app-logo mark-class="h-9 w-9" text-class="text-slate-950" />
    <div class="text-xs font-semibold tracking-[0.2em] text-slate-500">{{ __('app.powered_by_ladna') }}</div>
    @if ($showLocaleSwitcher)
        <x-ui.customer-locale-switcher class="mt-2" data-customer-footer-locale-switcher />
    @endif
</footer>
