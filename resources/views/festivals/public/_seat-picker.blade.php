<div
    class="mt-5 scroll-mt-6 space-y-4"
    data-festival-seat-picker
    data-map-url="{{ route('public.festivals.admission.seats', [$account->slug, $edition->slug]) }}"
    data-hold-url="{{ route('public.festivals.admission.seat-hold', [$account->slug, $edition->slug]) }}"
    data-quote-url="{{ route('public.festivals.admission.seat-quote', [$account->slug, $edition->slug]) }}"
    data-seat-label="{{ __('app.festival_seating.seat_label', ['number' => ':number']) }}"
    data-unavailable-label="{{ __('app.festival_seating.unavailable_state') }}"
    data-unavailable-message="{{ __('app.festival_seating.unavailable') }}"
    data-expired-message="{{ __('app.festival_seating.hold_expired') }}"
    data-network-error="{{ __('app.festival_seating.network_error') }}"
    data-empty-message="{{ __('app.festival_seating.empty') }}"
    data-limit-message="{{ __('app.festival_seating.limit') }}"
    data-quote-wait="{{ __('app.festival_seating.quote_wait') }}"
    data-all-sections="{{ __('app.festival_seating.all_sections') }}"
    data-accessibility-label="{{ __('app.festival_seating.accessibility') }}"
    data-sightline-label="{{ __('app.festival_seating.sightline') }}"
    data-has-quantity-options="{{ $festivalAdmissionOptions->isNotEmpty() ? 'true' : 'false' }}"
>
    <div data-festival-seat-fields></div>
    <input type="hidden" name="seat_checkout_id" data-festival-seat-checkout-id disabled>
    <input type="hidden" name="seat_quote" data-festival-seat-quote disabled>
    <input type="hidden" name="seat_quote_total" data-festival-seat-quote-total disabled>
    <p class="text-sm festival-muted" data-festival-seat-loading role="status">{{ __('app.festival_seating.loading') }}</p>
    <noscript><p class="text-sm festival-muted">{{ __('app.festival_seating.checkout_map_unavailable') }}</p></noscript>
    <p class="text-sm font-semibold festival-muted" data-festival-seat-timer hidden>{{ __('app.festival_seating.held') }} <output class="tabular-nums" data-festival-seat-countdown></output></p>
    <div class="space-y-3" data-festival-seat-editor hidden>
        <div>
            <h3 class="text-lg font-semibold festival-text">{{ __('app.festival_seating.choose_seats') }}</h3>
            <p class="mt-1 text-sm festival-muted">{{ __('app.festival_seating.choose_help') }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="crm-label">{{ __('app.festival_seating.floor') }}</span>
                <select class="crm-field" data-festival-seat-floor></select>
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.festival_seating.section') }}</span>
                <select class="crm-field" data-festival-seat-section></select>
            </label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="inline-flex gap-1 rounded-lg border festival-border p-1">
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-map-toggle aria-pressed="true">{{ __('app.festival_seating.map') }}</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-list-toggle aria-pressed="false">{{ __('app.festival_seating.list') }}</x-ui.button>
            </div>
            <div class="flex gap-1" data-festival-seat-zoom>
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-zoom-out aria-label="{{ __('app.festival_seating.zoom_out') }}">−</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-zoom-in aria-label="{{ __('app.festival_seating.zoom_in') }}">+</x-ui.button>
            </div>
        </div>
        <div class="rounded-xl border festival-border festival-page p-3">
            <p class="mx-auto mb-3 w-fit rounded-md bg-slate-700 px-5 py-1 text-center text-xs font-semibold text-white" data-festival-seat-stage>{{ __('app.festival_seating.stage') }}</p>
            <div class="max-h-96 overflow-auto rounded-lg" data-festival-seat-viewport>
                <div class="relative w-full overflow-hidden bg-white" data-festival-seat-map></div>
            </div>
            <div class="max-h-96 overflow-auto" data-festival-seat-list hidden></div>
            <p class="py-3 text-center text-sm festival-muted" data-festival-seat-empty hidden>{{ __('app.festival_seating.empty') }}</p>
        </div>
        <div class="space-y-2 text-xs festival-muted">
            <div class="flex flex-wrap gap-x-4 gap-y-2" aria-label="{{ __('app.festival_seating.price_legend') }}" data-festival-seat-legend></div>
            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full border border-slate-500 bg-white" aria-hidden="true"></span>{{ __('app.festival_seating.available') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-slate-950 text-center text-[9px] leading-3 text-white" aria-hidden="true">✓</span>{{ __('app.festival_seating.selected') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-full bg-slate-300 text-center text-[9px] leading-3 text-slate-600" aria-hidden="true">×</span>{{ __('app.festival_seating.unavailable_state') }}</span>
            </div>
        </div>
        <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border festival-border festival-surface p-3">
            <output class="text-sm font-semibold festival-text" data-festival-seat-estimate></output>
            <x-ui.button type="button" variant="success" size="sm" data-festival-seat-continue disabled>{{ __('app.festival_seating.continue') }}</x-ui.button>
        </div>
    </div>
    <div class="space-y-2 rounded-xl border festival-border festival-page p-3 text-sm festival-text" data-festival-seat-summary hidden>
        <p class="font-semibold">{{ __('app.festival_seating.selected_seats') }}</p>
        <ul class="space-y-1" data-festival-seat-cart></ul>
        <div class="space-y-2" data-festival-seat-summary-actions hidden>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-edit>{{ __('app.festival_seating.edit') }}</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-release>{{ __('app.festival_seating.cancel') }}</x-ui.button>
            </div>
            <p class="text-xs festival-muted">{{ __('app.festival_seating.online_separate') }}</p>
        </div>
    </div>
    <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900" data-festival-seat-message role="status" hidden></p>
    <x-ui.button type="button" variant="secondary" size="sm" data-festival-seat-retry hidden>{{ __('app.festival_seating.retry') }}</x-ui.button>
</div>
