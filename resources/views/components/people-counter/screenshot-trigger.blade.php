@props([
    'gallery' => [],
    'label' => null,
    'thumbnailUrl' => null,
    'thumbnailAlt' => '',
    'thumbnailImageClass' => 'h-20 w-full object-cover sm:w-28',
])

@php
    $items = collect($gallery)
        ->map(fn ($item): array => [
            'url' => (string) ($item['url'] ?? ''),
            'thumbnail_url' => (string) ($item['thumbnail_url'] ?? $item['url'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'meta' => (string) ($item['meta'] ?? ''),
            'alt' => (string) ($item['alt'] ?? $item['title'] ?? ''),
        ])
        ->filter(fn (array $item): bool => filled($item['url']))
        ->values();
    $label ??= __('app.open_screenshot_gallery');
    $galleryJson = $items->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG);
@endphp

@if ($items->isNotEmpty())
    @if ($thumbnailUrl)
        <button
            type="button"
            {{ $attributes->merge(['class' => 'block overflow-hidden rounded-lg border border-stone-200 bg-slate-50 transition hover:border-brand-100 hover:shadow-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2']) }}
            data-people-counter-screenshot-trigger
            data-people-counter-gallery="{{ $galleryJson }}"
            aria-label="{{ $label }}"
        >
            <img src="{{ $thumbnailUrl }}" alt="{{ $thumbnailAlt }}" class="{{ $thumbnailImageClass }}">
        </button>
    @else
        <button
            type="button"
            {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-md border border-stone-200 bg-white px-2.5 py-1.5 text-sm font-semibold text-brand-700 transition hover:border-brand-100 hover:bg-brand-50 hover:text-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2']) }}
            data-people-counter-screenshot-trigger
            data-people-counter-gallery="{{ $galleryJson }}"
        >
            <x-ui.icon name="image" class="h-4 w-4" />
            <span>{{ $label }}</span>
        </button>
    @endif
@endif

@once
    @push('modals')
        <div
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/75 p-3 backdrop-blur-sm sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="people-counter-screenshot-title"
            data-people-counter-screenshot-modal
        >
            <div class="flex h-[92vh] max-h-[920px] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-slate-700 bg-slate-950 shadow-2xl">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-white/10 bg-slate-950 px-4 py-3 text-white sm:px-5">
                    <div class="min-w-0">
                        <h2 id="people-counter-screenshot-title" class="truncate text-base font-semibold sm:text-lg" data-people-counter-screenshot-title>{{ __('app.screenshots') }}</h2>
                        <p class="mt-1 hidden truncate text-xs font-medium text-slate-300 sm:block" data-people-counter-screenshot-meta></p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-prev aria-label="{{ __('app.previous_screenshot') }}">
                            <x-ui.icon name="chevron-left" class="h-5 w-5" />
                        </button>
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-next aria-label="{{ __('app.next_screenshot') }}">
                            <x-ui.icon name="chevron-right" class="h-5 w-5" />
                        </button>
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-zoom-out aria-label="{{ __('app.zoom_out') }}">
                            <x-ui.icon name="minus" class="h-5 w-5" />
                        </button>
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-zoom-in aria-label="{{ __('app.zoom_in') }}">
                            <x-ui.icon name="plus" class="h-5 w-5" />
                        </button>
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-reset aria-label="{{ __('app.reset_zoom') }}">
                            <x-ui.icon name="rotate-ccw" class="h-5 w-5" />
                        </button>
                        <button type="button" class="rounded-lg p-2 text-slate-300 transition hover:bg-white/10 hover:text-white" data-people-counter-screenshot-close aria-label="{{ __('app.close') }}">
                            <x-ui.icon name="close" class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <div class="flex min-h-0 flex-1 flex-col bg-slate-950">
                    <div class="relative flex min-h-0 flex-1 touch-none items-center justify-center overflow-hidden bg-black" data-people-counter-screenshot-stage>
                        <img src="" alt="" class="max-h-full max-w-full select-none object-contain" data-people-counter-screenshot-image draggable="false">
                    </div>
                    <div class="shrink-0 overflow-x-auto border-t border-white/10 bg-slate-950 p-3">
                        <div class="flex gap-2" data-people-counter-screenshot-thumbs></div>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endonce
