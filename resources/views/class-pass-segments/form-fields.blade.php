@php
    $rawScheduleKind = $classPassSegment->schedule_kind;
    $scheduleKindValue = $rawScheduleKind instanceof \App\Enums\ScheduleKind ? $rawScheduleKind->value : (string) $rawScheduleKind;
    $selectedScheduleKind = old('schedule_kind', $scheduleKindValue ?: array_key_first($scheduleKindTabs));
    $selectedActivityDirectionIds = old('activity_direction_ids');

    if ($selectedActivityDirectionIds === null) {
        $selectedActivityDirectionIds = $classPassSegment->relationLoaded('activityDirections')
            ? $classPassSegment->activityDirections->pluck('id')->all()
            : [];
    }

    $selectedActivityDirectionIds = collect($selectedActivityDirectionIds)->map(fn ($id) => (int) $id)->all();
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $classPassSegment->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $classPassSegment->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.schedule_kind') }}</span>
        <select name="schedule_kind" required class="crm-field">
            @foreach ($scheduleKindTabs as $scheduleKindTabValue => $scheduleKindDefinition)
                <option value="{{ $scheduleKindTabValue }}" @selected($selectedScheduleKind === $scheduleKindTabValue)>{{ __('app.'.$scheduleKindDefinition['title_key']) }}</option>
            @endforeach
        </select>
        @error('schedule_kind') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.sort_order') }}</span>
        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $classPassSegment->sort_order ?? 0) }}" required class="crm-field">
        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<fieldset>
    <legend class="crm-label">{{ __('app.activity_directions') }}</legend>
    <p class="mt-1 text-sm text-slate-500">{{ __('app.class_pass_segment_directions_help') }}</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @forelse ($activityDirections as $activityDirection)
            <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-700">
                <input name="activity_direction_ids[]" type="checkbox" value="{{ $activityDirection->id }}" @checked(in_array($activityDirection->id, $selectedActivityDirectionIds, true)) class="crm-checkbox">
                <span>{{ $activityDirection->name }}</span>
            </label>
        @empty
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">{{ __('app.no_activity_directions') }}</div>
        @endforelse
    </div>
    @error('activity_direction_ids') <span class="crm-help">{{ $message }}</span> @enderror
    @error('activity_direction_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
</fieldset>

<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $classPassSegment->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>
