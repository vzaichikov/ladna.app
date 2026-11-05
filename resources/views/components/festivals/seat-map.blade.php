@props(['projection', 'mode' => 'inspect', 'refreshUrl' => null])
<section {{ $attributes->class(['min-w-0 space-y-3 rounded-2xl border border-stone-200 bg-white p-4 text-slate-950 shadow-crm sm:p-5']) }} data-festival-seat-map-view data-mode="{{ $mode }}" data-refresh-url="{{ $refreshUrl }}">
    <script type="application/json" data-seat-map-data>@json($projection)</script>
    <script type="application/json" data-seat-map-text>@json(__('app.festival_journey.map'))</script>
    @if ($mode !== 'own' && ! $projection['assigned_seating_enabled'])
        <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">{{ __('app.festival_journey.sales_off') }}</p>
    @endif
    <div class="flex flex-wrap items-end gap-3">
        <label><span class="crm-label">{{ __('app.festival_seating_staff.floor') }}</span><select class="crm-field" data-seat-map-floor></select></label>
        <label><span class="crm-label">{{ __('app.festival_seating.section') }}</span><select class="crm-field" data-seat-map-section></select></label>
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="button" variant="secondary" size="sm" data-seat-map-zoom="-1" aria-label="{{ __('app.festival_seating_staff.zoom_out') }}">−</x-ui.button>
            <x-ui.button type="button" variant="secondary" size="sm" data-seat-map-zoom="1" aria-label="{{ __('app.festival_seating_staff.zoom_in') }}">+</x-ui.button>
            <x-ui.button type="button" variant="secondary" size="sm" data-seat-map-zoom="fit">{{ __('app.festival_journey.map.fit') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" size="sm" data-seat-map-fullscreen>{{ __('app.festival_journey.map.fullscreen') }}</x-ui.button>
        </div>
    </div>
    <p class="text-sm font-medium" data-seat-map-counts></p>
    <div class="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 overflow-auto overscroll-contain rounded-xl border border-stone-200 bg-slate-50" style="max-height:70vh" data-seat-map-viewport>
            <svg class="mx-auto block" data-seat-map-canvas aria-hidden="true"></svg>
        </div>
        <div class="min-w-0 space-y-3">
            <div class="rounded-xl bg-slate-100 p-4">
                <p class="text-sm font-semibold" data-seat-map-details>{{ __('app.festival_journey.map.choose') }}</p>
                <a class="crm-focus mt-3 block text-sm font-semibold text-brand-700 underline" data-seat-map-ticket hidden>{{ __('app.festival_journey.map.ticket') }}</a>
                <a class="crm-focus mt-3 block text-sm font-semibold text-brand-700 underline" data-seat-map-issue hidden>{{ __('app.festival_journey.map.issue') }}</a>
            </div>
            <details open><summary class="crm-focus cursor-pointer text-sm font-semibold">{{ __('app.festival_journey.map.seat_list') }}</summary><div class="mt-3 max-h-80 space-y-2 overflow-auto" data-seat-map-list></div></details>
        </div>
    </div>
    @if ($mode !== 'own')
        <ul class="flex flex-wrap gap-3 text-xs">
            @foreach (['free' => '#2563eb', 'held' => '#d97706', 'assigned' => '#7c3aed', 'checked_in' => '#047857', 'invalid' => '#be123c', 'blocked' => '#94a3b8'] as $state => $color)
                <li class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full" style="background:{{ $color }}" aria-hidden="true"></span>{{ __('app.festival_journey.map.'.$state) }}</li>
            @endforeach
        </ul>
    @endif
    <div class="flex flex-wrap gap-3 text-xs"><span role="status" data-seat-map-status></span><button type="button" class="crm-focus font-semibold underline" data-seat-map-retry hidden>{{ __('app.festival_seating.retry') }}</button></div>
    <noscript><ul>@foreach ($projection['seats'] as $seat) @if ($mode !== 'own' || ($seat['own'] ?? false))<li>{{ $seat['label'] }}</li>@endif @endforeach</ul></noscript>
</section>
