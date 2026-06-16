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
        <span class="crm-label">{{ __('app.schedule_kind') }}</span>
        <select name="schedule_kind" class="crm-field">
            @foreach ($scheduleKinds as $scheduleKind)
                <option value="{{ $scheduleKind->value }}" @selected(old('schedule_kind', $classType->schedule_kind?->value ?? $classType->schedule_kind) === $scheduleKind->value)>{{ __('app.'.$scheduleKind->value) }}</option>
            @endforeach
        </select>
        @error('schedule_kind') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.color') }}</span>
        <input name="color" value="{{ old('color', $classType->color) }}" placeholder="#e11d48" class="crm-field">
        @error('color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-3">
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
        <span class="crm-label">{{ __('app.default_capacity') }}</span>
        <input name="default_capacity" type="number" min="1" value="{{ old('default_capacity', $classType->default_capacity) }}" class="crm-field">
        @error('default_capacity') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $classType->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>
