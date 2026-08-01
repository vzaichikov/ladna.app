<form method="GET" action="{{ url()->current() }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.2fr_auto] xl:items-end">
        <label class="block">
            <span class="crm-label">{{ __('app.date_from') }}</span>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="crm-field min-h-11" required>
            @error('date_from') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.date_to') }}</span>
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="crm-field min-h-11" required>
            @error('date_to') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <label class="block">
            <span class="crm-label">{{ __('app.location') }}</span>
            <select name="location_id" class="crm-field min-h-11">
                <option value="">{{ __('app.all_locations') }}</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected($filters['location_id'] === $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
            @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
        </label>

        <div class="flex gap-2">
            <x-ui.button type="submit" size="sm" class="min-h-11">
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <x-ui.button :href="url()->current()" variant="secondary" size="sm" class="min-h-11">
                {{ __('app.reset_filters') }}
            </x-ui.button>
        </div>
    </div>
</form>

@if ($epoch?->starts_at && ! $epoch->is_legacy)
    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
        {{ __('app.finance_epoch_report_notice', [
            'date' => \App\Support\DateTimePresenter::format($epoch->starts_at, $account),
        ]) }}
    </div>
@endif
