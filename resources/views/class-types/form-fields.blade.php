<label class="block">
    <span class="crm-label">{{ __('app.direction') }}</span>
    <select name="activity_direction_id" class="crm-field">
        <option value="">-</option>
        @foreach ($activityDirections as $activityDirection)
            <option value="{{ $activityDirection->id }}" @selected((int) old('activity_direction_id', $classType->activity_direction_id) === $activityDirection->id)>{{ $activityDirection->name }}</option>
        @endforeach
    </select>
    @error('activity_direction_id') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $classType->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $classType->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $classType->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.color') }}</span>
        @php
            $colorValue = old('color', $classType->color);
            $colorPickerValue = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $colorValue) ? $colorValue : '#A78AB9';
        @endphp
        <span class="mt-2 flex items-center gap-3">
            <input
                type="color"
                value="{{ $colorPickerValue }}"
                class="h-11 w-14 cursor-pointer rounded-lg border border-stone-200 bg-white p-1"
                data-color-picker
            >
            <input
                name="color"
                value="{{ $colorValue }}"
                placeholder="#A78AB9"
                class="crm-field mt-0"
                data-color-value
            >
        </span>
        @error('color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block">
        <span class="crm-label">{{ __('app.default_duration') }}</span>
        <input name="default_duration_minutes" type="number" min="15" value="{{ old('default_duration_minutes', $classType->default_duration_minutes) }}" required class="crm-field">
        @error('default_duration_minutes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.booking_cutoff') }}</span>
        <input name="booking_cutoff_minutes" type="number" min="0" value="{{ old('booking_cutoff_minutes', $classType->booking_cutoff_minutes) }}" class="crm-field">
        @error('booking_cutoff_minutes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.cancellation_cutoff') }}</span>
        <input name="cancellation_cutoff_minutes" type="number" min="0" value="{{ old('cancellation_cutoff_minutes', $classType->cancellation_cutoff_minutes ?? 1440) }}" class="crm-field">
        @error('cancellation_cutoff_minutes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.'.$scheduleKindDefinition['capacity_label_key']) }}</span>
        <input name="default_capacity" type="number" min="1" value="{{ old('default_capacity', $classType->default_capacity) }}" class="crm-field">
        @error('default_capacity') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $classType->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>
