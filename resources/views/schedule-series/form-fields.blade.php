<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.location') }}</span>
        <select name="location_id" required class="crm-field">
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected((int) old('location_id', $scheduleSeries->location_id) === $location->id)>{{ $location->name }}</option>
            @endforeach
        </select>
        @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.room') }}</span>
        <select name="room_id" required class="crm-field">
            @foreach ($rooms as $room)
                <option value="{{ $room->id }}" @selected((int) old('room_id', $scheduleSeries->room_id) === $room->id)>{{ $room->location->name }} · {{ $room->name }}</option>
            @endforeach
        </select>
        @error('room_id') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.class_type') }}</span>
        <select name="class_type_id" required class="crm-field">
            @foreach ($classTypes as $classType)
                <option value="{{ $classType->id }}" @selected((int) old('class_type_id', $scheduleSeries->class_type_id) === $classType->id)>{{ $classType->name }}</option>
            @endforeach
        </select>
        @error('class_type_id') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.trainer') }}</span>
        <select name="trainer_id" class="crm-field">
            <option value="">TBA</option>
            @foreach ($trainers as $trainer)
                <option value="{{ $trainer->id }}" @selected((int) old('trainer_id', $scheduleSeries->trainer_id) === $trainer->id)>{{ $trainer->name }}</option>
            @endforeach
        </select>
        @error('trainer_id') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="title" value="{{ old('title', $scheduleSeries->title) }}" class="crm-field">
        @error('title') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.status') }}</span>
        <select name="status" class="crm-field">
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected(old('status', $scheduleSeries->status?->value ?? $scheduleSeries->status) === $status->value)>{{ __('app.'.$status->value) }}</option>
            @endforeach
        </select>
        @error('status') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $scheduleSeries->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-4">
    <label class="block">
        <span class="crm-label">{{ __('app.weekday') }}</span>
        <select name="weekday" class="crm-field">
            @foreach ($weekdays as $value => $label)
                <option value="{{ $value }}" @selected((int) old('weekday', $scheduleSeries->weekday) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('weekday') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.start_time') }}</span>
        <input name="start_time" type="time" value="{{ old('start_time', substr((string) $scheduleSeries->start_time, 0, 5)) }}" required class="crm-field">
        @error('start_time') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.start_date') }}</span>
        <input name="start_date" type="date" value="{{ old('start_date', optional($scheduleSeries->start_date)->toDateString()) }}" required class="crm-field">
        @error('start_date') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.end_date') }}</span>
        <input name="end_date" type="date" value="{{ old('end_date', optional($scheduleSeries->end_date)->toDateString()) }}" class="crm-field">
        @error('end_date') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <label class="block">
        <span class="crm-label">{{ __('app.duration') }}</span>
        <input name="duration_minutes" type="number" min="15" value="{{ old('duration_minutes', $scheduleSeries->duration_minutes) }}" class="crm-field">
        @error('duration_minutes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.booking_cutoff') }}</span>
        <input name="booking_cutoff_minutes" type="number" min="0" value="{{ old('booking_cutoff_minutes', $scheduleSeries->booking_cutoff_minutes) }}" class="crm-field">
        @error('booking_cutoff_minutes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.capacity') }}</span>
        <input name="capacity" type="number" min="1" value="{{ old('capacity', $scheduleSeries->capacity) }}" class="crm-field">
        @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
