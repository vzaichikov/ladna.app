@props([
    'account' => null,
    'border' => true,
    'returnUrl' => request()->fullUrl(),
    'showStudioLegalLinks' => true,
    'showLocaleSwitcher' => false,
    'studioUrl' => null,
])

@php
    $hasStudioAccount = $account instanceof \App\Models\Account;
    $hasStudioLegalLinks = $showStudioLegalLinks && $hasStudioAccount
        && (filled($account->studio_rules_html) || filled($account->public_offer_html));
    $resolvedStudioUrl = $studioUrl ?? ($hasStudioAccount ? route('public.studio', $account->slug) : null);
    $hasPrimaryLinks = trim((string) $slot) !== '';
    $hasMiddleContent = $hasPrimaryLinks || $hasStudioLegalLinks || $showLocaleSwitcher;
@endphp

<footer
    {{
        $attributes
            ->class([
                'grid grid-cols-1 items-center justify-items-center gap-5 text-center lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:justify-items-stretch lg:text-left',
                'border-t border-stone-200 pt-6' => $border,
            ])
    }}
>
    @if ($hasStudioAccount)
        <a
            href="{{ $resolvedStudioUrl }}"
            class="inline-flex max-w-full items-center gap-3 text-left text-slate-950 transition hover:text-brand-700 lg:justify-self-start"
            data-public-studio-footer-identity
        >
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-white p-2 shadow-xs" data-public-studio-footer-mark>
                <img src="{{ $account->logoUrl() }}" alt="" class="max-h-7 max-w-7 object-contain">
            </span>
            <span class="min-w-0">
                <span class="block truncate text-base font-semibold" data-public-studio-footer-name>{{ $account->name }}</span>
                @if ($account->studio_slogan)
                    <span class="mt-0.5 block truncate text-xs text-slate-500" data-public-studio-footer-slogan>{{ $account->studio_slogan }}</span>
                @endif
            </span>
        </a>
    @else
        <x-ui.app-logo mark-class="h-9 w-9" text-class="text-slate-950" class="lg:justify-self-start" />
    @endif

    @if ($hasMiddleContent)
        <div class="flex flex-col items-center gap-3 lg:justify-self-center" data-public-footer-links>
            @if ($hasPrimaryLinks)
                <nav class="flex flex-wrap justify-center gap-x-3 gap-y-2" aria-label="{{ __('app.footer_links') }}">
                    {{ $slot }}
                </nav>
            @endif

            @if ($hasStudioLegalLinks)
                <nav
                    class="flex flex-wrap justify-center gap-x-5 gap-y-2"
                    aria-label="{{ __('app.footer_links') }}"
                    data-customer-footer-legal-links
                >
                    <x-ui.public-legal-links
                        :account="$account"
                        :return-url="$returnUrl"
                        variant="footer"
                    />
                </nav>
            @endif

            @if ($showLocaleSwitcher)
                <x-ui.customer-locale-switcher data-customer-footer-locale-switcher />
            @endif
        </div>
    @else
        <span class="hidden lg:block" aria-hidden="true"></span>
    @endif

    <div class="inline-flex items-center gap-1.5 text-xs font-semibold tracking-[0.14em] text-slate-500 lg:justify-self-end">
        <img src="{{ asset('brand/ladna-mark.svg') }}" alt="" class="h-4 w-4 object-contain">
        <span>{{ __('app.powered_by_ladna') }}</span>
    </div>
</footer>
