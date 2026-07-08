@props([
    'desktopOffset' => false,
])

<button
    type="button"
    data-pwa-install
    hidden
    class="fixed bottom-4 right-4 z-50 inline-flex h-11 items-center gap-2 rounded-lg border border-brand-200 bg-white px-4 text-sm font-semibold text-brand-800 shadow-2xl shadow-slate-950/18 ring-1 ring-violet-crm-100 transition hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-60 {{ $desktopOffset ? 'lg:right-6' : '' }}"
>
    <x-ui.icon name="download" class="h-4 w-4" />
    <span>{{ __('app.install_app') }}</span>
</button>
