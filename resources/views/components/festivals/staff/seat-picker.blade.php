@props(['chart', 'typeField', 'availabilityUrl', 'selectedSeatId' => null, 'large' => false])

@if ($chart)
    <section
        class="space-y-3 rounded-xl border border-stone-200 bg-slate-50 p-3"
        data-festival-staff-seat-picker
        data-type-field="{{ $typeField }}"
        data-availability-url="{{ $availabilityUrl }}"
        hidden
    >
        <script type="application/json" data-staff-seat-chart>@json($chart)</script>
        <div class="flex flex-wrap items-end justify-between gap-2">
            <label>
                <span class="crm-label">{{ __('app.festival_seating_staff.floor') }}</span>
                <select class="crm-field" data-staff-seat-floor></select>
            </label>
            <div class="flex gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" data-staff-seat-zoom="-1" aria-label="{{ __('app.festival_seating_staff.zoom_out') }}">−</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" data-staff-seat-zoom="1" aria-label="{{ __('app.festival_seating_staff.zoom_in') }}">+</x-ui.button>
            </div>
        </div>
        <p class="hidden rounded-md bg-slate-200 px-3 py-1 text-center text-sm font-semibold text-slate-700" data-staff-seat-stage></p>
        <div class="overflow-auto overscroll-contain rounded-lg border border-stone-200 bg-white" style="max-height:{{ $large ? '65vh' : '16rem' }}">
            <svg class="block" data-staff-seat-map aria-hidden="true"></svg>
        </div>
        <ul class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
            <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>{{ __('app.festival_seating_staff.available') }}</li>
            <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2.5 w-2.5 rounded-full bg-emerald-700"></span>{{ __('app.festival_seating_staff.selected') }}</li>
            <li class="inline-flex items-center gap-1.5"><span aria-hidden="true" class="h-2.5 w-2.5 rounded-full bg-slate-300"></span>{{ __('app.unavailable') }}</li>
        </ul>
        <label class="block">
            <span class="crm-label">{{ __('app.festival_seating_staff.seat') }}</span>
            <select name="seat_id" class="crm-field min-w-0" data-staff-seat-selection data-old-seat="{{ old('seat_id', $selectedSeatId) }}" disabled>
                <option value="">{{ __('app.festival_seating_staff.choose_seat') }}</option>
            </select>
        </label>
        <p class="text-sm text-slate-700" role="status" data-staff-seat-details></p>
        @error('seat_id')<span class="crm-help">{{ $message }}</span>@enderror
        @error('seat_ids')<span class="crm-help">{{ $message }}</span>@enderror
    </section>
@endif
