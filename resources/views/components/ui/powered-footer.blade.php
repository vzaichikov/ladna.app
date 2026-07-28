@props([
    'account' => null,
    'border' => true,
    'returnUrl' => request()->fullUrl(),
    'showLocaleSwitcher' => false,
])

@php
    $hasStudioAccount = $account instanceof \App\Models\Account;
    $showStudioLegalLinks = $hasStudioAccount
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
    @if ($hasStudioAccount)
        <a
            href="{{ route('public.studio', $account->slug) }}"
            class="mb-2 inline-flex max-w-full items-center gap-3 text-left text-slate-950 transition hover:text-brand-700"
            data-public-studio-footer-identity
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-white p-2 shadow-xs">
                <img src="{{ $account->logoUrl() }}" alt="" class="max-h-7 max-w-7 object-contain">
            </span>
            <span class="min-w-0">
                <span class="block truncate text-base font-semibold">{{ $account->name }}</span>
                @if ($account->studio_slogan)
                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $account->studio_slogan }}</span>
                @endif
            </span>
        </a>
    @endif

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

    @if (! $hasStudioAccount)
        <x-ui.app-logo mark-class="h-9 w-9" text-class="text-slate-950" />
    @endif
    <div class="inline-flex items-center gap-1.5 text-xs font-semibold tracking-[0.14em] text-slate-500">
        <img src="{{ asset('brand/ladna-mark.svg') }}" alt="" class="h-4 w-4 object-contain">
        <span>{{ __('app.powered_by_ladna') }}</span>
    </div>
    @if ($showLocaleSwitcher)
        <x-ui.customer-locale-switcher class="mt-2" data-customer-footer-locale-switcher />
    @endif
</footer>
