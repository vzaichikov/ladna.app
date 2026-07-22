@props([
    'activePage' => 'home',
    'authHref',
    'authLabel',
    'currentLocale',
    'featuresHref',
    'flowHref',
    'homeHref',
    'localeLinks',
    'pricingHref' => null,
    'studiosHref' => null,
])

<header {{ $attributes->class(['flex items-center justify-between gap-4']) }}>
    <a href="{{ $homeHref }}" class="inline-flex items-center gap-3 text-[#2B1731]">
        <x-ui.app-logo
            mark-class="h-10 w-10"
            text-class="text-[#2B1731]"
        />
    </a>

    <nav class="hidden items-center gap-8 text-sm font-semibold text-[#4D3152] md:flex" aria-label="{{ __('features.navigation.aria') }}">
        <a
            href="{{ $featuresHref }}"
            @class([
                'transition hover:text-[#2B1731]',
                'text-[#2B1731]' => $activePage === 'features',
            ])
            @if ($activePage === 'features') aria-current="page" @endif
        >
            {{ __('features.navigation.features') }}
        </a>
        <a href="{{ $flowHref }}" class="transition hover:text-[#2B1731]">{{ __('features.navigation.flow') }}</a>
        @if ($studiosHref)
            <a href="{{ $studiosHref }}" class="transition hover:text-[#2B1731]">{{ __('features.navigation.studios') }}</a>
        @endif
        @if ($pricingHref)
            <a href="{{ $pricingHref }}" class="transition hover:text-[#2B1731]">{{ __('features.navigation.pricing') }}</a>
        @endif
    </nav>

    <div class="flex items-center gap-2">
        <nav class="inline-flex h-10 items-center rounded-lg border border-[#A78AB9]/30 bg-white/70 p-1 shadow-xs" aria-label="{{ __('app.default_language') }}">
            @foreach ($localeLinks as $locale => $localeOption)
                <a
                    href="{{ $localeOption['href'] }}"
                    @class([
                        'flex h-8 min-w-9 items-center justify-center rounded-md px-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2',
                        'bg-[#3B223F] text-white shadow-[0_8px_18px_rgba(59,34,63,0.16)]' => $currentLocale === $locale,
                        'text-[#4D3152] hover:bg-[#DCCFF0]/45 hover:text-[#2B1731]' => $currentLocale !== $locale,
                    ])
                >
                    {{ $localeOption['label'] }}
                </a>
            @endforeach
        </nav>

        <a href="{{ $authHref }}" data-landing-header-auth class="inline-flex h-10 items-center justify-center rounded-lg bg-[#3B223F] px-4 text-sm font-semibold text-white shadow-[0_14px_32px_rgba(59,34,63,0.2)] transition hover:bg-[#2B1731] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#A78AB9] focus-visible:ring-offset-2">
            {{ $authLabel }}
        </a>
    </div>
</header>
