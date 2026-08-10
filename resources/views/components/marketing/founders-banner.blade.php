@props([
    'program',
    'href',
])

@if ($program['banner_visible'] ?? false)
    @php
        $remainingStudios = (int) ($program['remaining_studios'] ?? 0);
    @endphp

    <aside {{ $attributes->class(['w-full bg-[#3B223F] text-white']) }} aria-label="{{ __('founders.banner.aria') }}">
        <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-5 py-3 text-center sm:px-8 md:flex-row md:text-left lg:px-10">
            <p class="flex items-center gap-2 text-sm font-medium leading-6 text-white/90">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#DCCFF0] text-[#3B223F]">
                    <x-ui.icon name="sparkles" class="h-4 w-4" />
                </span>
                <span>{{ trans_choice('founders.banner.message', $remainingStudios, ['count' => $remainingStudios]) }}</span>
            </p>

            <a href="{{ $href }}" class="inline-flex shrink-0 items-center gap-2 rounded-md px-2 py-1 text-sm font-semibold text-[#F3E8FF] underline decoration-[#C7B4D3]/65 decoration-2 underline-offset-4 transition hover:text-white hover:decoration-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#3B223F]">
                {{ __('founders.banner.link') }}
                <x-ui.icon name="arrow-right" class="h-4 w-4" />
            </a>
        </div>
    </aside>
@endif
