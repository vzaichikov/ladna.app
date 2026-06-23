@php
    $scheduleKindDefinitions = \App\Support\ScheduleKindRegistry::all();
    $selectedScheduleKinds = old('enabled_schedule_kinds', $account->enabledScheduleKindValues());
    $scheduleKindColors = old('schedule_kind_colors', $account->scheduleKindColors());
@endphp

<input type="hidden" name="brand_tab" value="formats">
<input type="hidden" name="name" value="{{ $account->name }}">
<input type="hidden" name="slug" value="{{ $account->slug }}">
<input type="hidden" name="default_language" value="{{ $account->default_language }}">
<input type="hidden" name="country_code" value="{{ $account->country_code ?? 'UA' }}">
<input type="hidden" name="default_currency" value="{{ $account->default_currency }}">
<input type="hidden" name="brand_color" value="{{ $account->brand_color }}">
<input type="hidden" name="timezone" value="{{ $account->timezone }}">

<fieldset>
    <input type="hidden" name="enabled_schedule_kinds_present" value="1">
    <input type="hidden" name="schedule_kind_colors_present" value="1">
    <legend class="crm-label">{{ __('app.studio_class_formats') }}</legend>
    <p class="mt-1 text-sm text-slate-500">{{ __('app.studio_class_formats_help') }}</p>
    <div class="mt-4 grid gap-3">
        @foreach ($scheduleKindDefinitions as $scheduleKindValue => $scheduleKindDefinition)
            @php
                $colorValue = $scheduleKindColors[$scheduleKindValue] ?? $scheduleKindDefinition['default_color'];
                $colorPickerValue = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $colorValue) ? $colorValue : $scheduleKindDefinition['default_color'];
            @endphp
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <label class="flex min-w-0 items-start gap-3 text-sm font-medium text-slate-700">
                        <input name="enabled_schedule_kinds[]" type="checkbox" value="{{ $scheduleKindValue }}" @checked(in_array($scheduleKindValue, $selectedScheduleKinds, true)) class="crm-checkbox mt-0.5">
                        <span class="min-w-0">
                            <span class="block text-slate-950">{{ __('app.'.$scheduleKindDefinition['title_key']) }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">{{ __('app.'.$scheduleKindValue) }}</span>
                        </span>
                    </label>
                    <label class="block sm:w-72">
                        <span class="crm-label">{{ __('app.schedule_format_color') }}</span>
                        <span class="mt-2 flex items-center gap-3">
                            <input
                                type="color"
                                value="{{ $colorPickerValue }}"
                                class="h-11 w-14 cursor-pointer rounded-lg border border-stone-200 bg-white p-1"
                                data-color-picker
                            >
                            <input
                                name="schedule_kind_colors[{{ $scheduleKindValue }}]"
                                value="{{ $colorValue }}"
                                placeholder="{{ $scheduleKindDefinition['default_color'] }}"
                                class="crm-field mt-0"
                                data-color-value
                            >
                        </span>
                        @error('schedule_kind_colors.'.$scheduleKindValue) <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>
            </div>
        @endforeach
    </div>
    @error('enabled_schedule_kinds') <span class="crm-help">{{ $message }}</span> @enderror
    @error('enabled_schedule_kinds.*') <span class="crm-help">{{ $message }}</span> @enderror
    @error('schedule_kind_colors') <span class="crm-help">{{ $message }}</span> @enderror
</fieldset>
