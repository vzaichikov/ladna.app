@php
    $openingHours = old('opening_hours', $account->openingHours());
    $weekdayLabels = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];
@endphp

<input type="hidden" name="brand_tab" value="opening_hours">
<input type="hidden" name="name" value="{{ $account->name }}">
<input type="hidden" name="slug" value="{{ $account->slug }}">
<input type="hidden" name="default_language" value="{{ $account->default_language }}">
<input type="hidden" name="country_code" value="{{ $account->country_code ?? 'UA' }}">
<input type="hidden" name="default_currency" value="{{ $account->default_currency }}">
<input type="hidden" name="brand_color" value="{{ $account->brand_color }}">
<input type="hidden" name="timezone" value="{{ $account->timezone }}">
<input type="hidden" name="opening_hours_present" value="1">

<fieldset>
    <legend class="crm-label">{{ __('app.opening_hours') }}</legend>
    <p class="mt-1 text-sm text-slate-500">{{ __('app.opening_hours_help') }}</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-stone-200">
        @foreach ($weekdayLabels as $weekday => $labelKey)
            @php
                $dayHours = data_get($openingHours, (string) $weekday, []);
                $enabled = filter_var(data_get($dayHours, 'enabled', true), FILTER_VALIDATE_BOOLEAN);
                $opensAt = data_get($dayHours, 'opens_at', '08:00');
                $closesAt = data_get($dayHours, 'closes_at', '22:00');
            @endphp

            <div class="grid gap-3 border-b border-stone-200 bg-white p-4 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_8rem_8rem] sm:items-center">
                <label class="flex min-w-0 items-center gap-3 text-sm font-medium text-slate-800">
                    <input type="hidden" name="opening_hours[{{ $weekday }}][enabled]" value="0">
                    <input
                        name="opening_hours[{{ $weekday }}][enabled]"
                        type="checkbox"
                        value="1"
                        @checked($enabled)
                        class="crm-checkbox"
                    >
                    <span>{{ __('app.'.$labelKey) }}</span>
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.opens_at') }}</span>
                    <input
                        name="opening_hours[{{ $weekday }}][opens_at]"
                        type="time"
                        value="{{ $opensAt }}"
                        class="crm-field"
                    >
                    @error('opening_hours.'.$weekday.'.opens_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.closes_at') }}</span>
                    <input
                        name="opening_hours[{{ $weekday }}][closes_at]"
                        type="time"
                        value="{{ $closesAt }}"
                        class="crm-field"
                    >
                    @error('opening_hours.'.$weekday.'.closes_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        @endforeach
    </div>

    @error('opening_hours') <span class="crm-help">{{ $message }}</span> @enderror
</fieldset>
