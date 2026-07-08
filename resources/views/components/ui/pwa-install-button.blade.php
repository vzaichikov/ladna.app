<div
    data-pwa-install-banner
    hidden
    {{ $attributes->class('w-full border-b border-stone-200 bg-white shadow-sm shadow-slate-950/5') }}
>
    <div class="mx-auto flex min-h-12 w-full max-w-7xl items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
        <button
            type="button"
            data-pwa-install
            class="inline-flex h-9 min-w-0 flex-1 items-center justify-center gap-2 rounded-lg border border-brand-600 bg-brand-600 px-3 text-sm font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-60 sm:flex-none sm:px-4"
        >
            <x-ui.icon name="download" class="h-4 w-4 shrink-0" />
            <span class="truncate">{{ __('app.install_app') }}</span>
        </button>

        <button
            type="button"
            data-pwa-install-dismiss
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
            aria-label="{{ __('app.close') }}"
        >
            <x-ui.icon name="close" class="h-4 w-4" />
        </button>
    </div>
</div>
