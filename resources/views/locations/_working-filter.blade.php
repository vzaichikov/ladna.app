<form method="GET" action="{{ url()->current() }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
    @foreach (($preserveQuery ?? []) as $queryKey => $queryValue)
        <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
    @endforeach
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <label class="block min-w-0 flex-1 sm:max-w-sm">
            <span class="crm-label">{{ __('app.location') }}</span>
            <select name="location_id" class="crm-field">
                <option value="">{{ __('app.all_locations') }}</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected($selectedLocationId === $location->id)>
                        {{ $location->name }}@unless ($location->is_active) · {{ __('app.inactive') }}@endunless
                    </option>
                @endforeach
            </select>
        </label>
        <div class="flex gap-2">
            <x-ui.button type="submit" size="sm">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="url()->current()" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </div>
</form>
