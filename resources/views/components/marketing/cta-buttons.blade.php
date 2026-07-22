@props([
    'demoHref' => null,
    'demoLabel' => null,
    'onDark' => false,
    'pricingHref' => null,
    'pricingLabel' => null,
    'primaryHref' => null,
    'primaryLabel' => null,
    'showDemo' => false,
    'showPrimary' => false,
])

<div {{ $attributes->class(['flex flex-col gap-3 sm:flex-row sm:flex-wrap']) }}>
    @if ($showPrimary && $primaryHref && $primaryLabel)
        <a
            href="{{ $primaryHref }}"
            @class([
                'inline-flex h-12 items-center justify-center rounded-lg px-6 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                'bg-white text-[#3B223F] shadow-[0_18px_34px_rgba(0,0,0,0.18)] hover:bg-[#FAF8F5] focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-[#2B1731]' => $onDark,
                'bg-[#3B223F] text-white shadow-[0_18px_34px_rgba(59,34,63,0.2)] hover:bg-[#2B1731] focus-visible:ring-[#A78AB9]' => ! $onDark,
            ])
        >
            {{ $primaryLabel }}
        </a>
    @endif

    @if ($showDemo && $demoHref && $demoLabel)
        <a
            href="{{ $demoHref }}"
            @class([
                'inline-flex h-12 items-center justify-center rounded-lg border px-6 text-sm font-semibold shadow-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                'border-white/25 bg-white/10 text-white hover:border-white/45 hover:bg-white/15 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-[#2B1731]' => $onDark,
                'border-[#A78AB9]/40 bg-white text-[#3B223F] hover:border-[#A78AB9]/70 hover:bg-[#DCCFF0]/25 focus-visible:ring-[#A78AB9]' => ! $onDark,
            ])
        >
            {{ $demoLabel }}
        </a>
    @endif

    @if ($pricingHref && $pricingLabel)
        <a
            href="{{ $pricingHref }}"
            @class([
                'inline-flex h-12 items-center justify-center rounded-lg border px-6 text-sm font-semibold shadow-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                'border-[#C7B4D3]/40 text-[#E7DDC9] hover:border-[#C7B4D3]/70 hover:bg-white/10 hover:text-white focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-[#2B1731]' => $onDark,
                'border-[#A78AB9]/30 bg-white/70 text-[#3B223F] hover:border-[#A78AB9]/60 hover:bg-white focus-visible:ring-[#A78AB9]' => ! $onDark,
            ])
        >
            {{ $pricingLabel }}
        </a>
    @endif
</div>
