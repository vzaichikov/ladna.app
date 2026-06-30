@props([
    'revision',
    'desktopOffset' => false,
])

<div
    data-app-update
    data-current-revision="{{ $revision }}"
    data-version-url="{{ route('pwa.version') }}"
    data-service-worker-url="{{ route('pwa.service-worker') }}"
    class="fixed bottom-4 left-4 z-50 hidden w-[calc(100vw-2rem)] max-w-sm {{ $desktopOffset ? 'lg:left-80' : '' }}"
    role="status"
    aria-live="polite"
>
    <div class="rounded-lg border border-brand-100 bg-white p-4 text-slate-950 shadow-2xl shadow-slate-950/18 ring-1 ring-violet-crm-100">
        <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                <x-ui.icon name="refresh-cw" class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold">{{ __('app.app_update_available_title') }}</div>
                <div class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.app_update_available_body') }}</div>
                <button
                    type="button"
                    data-app-update-reload
                    class="mt-3 inline-flex h-10 items-center justify-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm shadow-brand-600/20 transition hover:bg-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-60"
                >
                    {{ __('app.reload_page') }}
                </button>
            </div>
        </div>
    </div>
</div>
