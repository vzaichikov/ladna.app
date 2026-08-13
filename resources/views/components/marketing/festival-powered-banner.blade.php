<aside class="w-full bg-[#3B223F] text-white" aria-label="{{ __('app.festival_powered_banner_aria') }}" data-festival-powered-banner>
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-5 py-3 sm:px-8 lg:px-10">
        <a href="{{ $href }}" class="inline-flex min-w-0 items-center gap-2 text-sm font-medium leading-6 text-white/90 transition hover:text-white focus-visible:rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#3B223F]">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[#DCCFF0] text-[#3B223F]">
                <x-ui.icon name="sparkles" class="h-4 w-4" />
            </span>
            <span class="underline decoration-[#C7B4D3]/65 decoration-2 underline-offset-4">{{ __('app.festival_powered_banner_message') }}</span>
            <x-ui.icon name="arrow-right" class="hidden h-4 w-4 shrink-0 sm:block" />
        </a>

        <form method="POST" action="{{ route('festival-powered-banner.dismiss') }}" class="shrink-0">
            @csrf
            <button
                type="submit"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C7B4D3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#3B223F]"
                aria-label="{{ __('app.close') }}"
            >
                <x-ui.icon name="close" class="h-4 w-4" />
            </button>
        </form>
    </div>
</aside>
