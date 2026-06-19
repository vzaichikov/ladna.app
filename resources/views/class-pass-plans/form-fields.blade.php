@php
    $selectedActivityDirectionIds = old('activity_direction_ids');
    $selectedTrainerTypeIds = old('trainer_type_ids');

    if ($selectedActivityDirectionIds === null) {
        $selectedActivityDirectionIds = $classPassPlan->relationLoaded('activityDirections')
            ? $classPassPlan->activityDirections->pluck('id')->all()
            : [];
    }

    if ($selectedTrainerTypeIds === null) {
        $selectedTrainerTypeIds = $classPassPlan->relationLoaded('trainerTypes')
            ? $classPassPlan->trainerTypes->pluck('id')->all()
            : $trainerTypes->pluck('id')->all();
    }

    $selectedActivityDirectionIds = collect($selectedActivityDirectionIds)->map(fn ($id) => (int) $id)->all();
    $selectedTrainerTypeIds = collect($selectedTrainerTypeIds)->map(fn ($id) => (int) $id)->all();
    $availableFromTime = old('available_from_time', $classPassPlan->available_from_time ? substr((string) $classPassPlan->available_from_time, 0, 5) : null);
    $availableUntilTime = old('available_until_time', $classPassPlan->available_until_time ? substr((string) $classPassPlan->available_until_time, 0, 5) : null);
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $classPassPlan->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $classPassPlan->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div data-trainer-type-group>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <span class="crm-label">{{ __('app.trainer_types') }}</span>
        <x-ui.button type="button" variant="secondary" size="sm" data-select-all-trainer-types>
            {{ __('app.select_all') }}
        </x-ui.button>
    </div>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @forelse ($trainerTypes as $trainerType)
            <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-700">
                <input name="trainer_type_ids[]" type="checkbox" value="{{ $trainerType->id }}" @checked(in_array($trainerType->id, $selectedTrainerTypeIds, true)) class="crm-checkbox" data-trainer-type-checkbox>
                <x-ui.trainer-type-badge :trainer-type="$trainerType" />
            </label>
        @empty
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">{{ __('app.no_trainer_types') }}</div>
        @endforelse
    </div>
    @error('trainer_type_ids') <span class="crm-help">{{ $message }}</span> @enderror
    @error('trainer_type_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
</div>

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $classPassPlan->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block">
        <span class="crm-label">{{ __('app.price_cents') }}</span>
        <input name="price_cents" type="number" min="0" value="{{ old('price_cents', $classPassPlan->price_cents ?? 0) }}" required class="crm-field">
        <span class="mt-1 block text-xs font-medium text-slate-500">{{ __('app.price_cents_hint') }}</span>
        @error('price_cents') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="currency" class="crm-field">
            @foreach ($currencies as $currency)
                <option value="{{ $currency }}" @selected(old('currency', $classPassPlan->currency) === $currency)>{{ $currency }}</option>
            @endforeach
        </select>
        @error('currency') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.sessions_count') }}</span>
        <input name="sessions_count" type="number" min="1" value="{{ old('sessions_count', $classPassPlan->sessions_count) }}" required class="crm-field">
        @error('sessions_count') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.validity_days') }}</span>
        <input name="validity_days" type="number" min="1" value="{{ old('validity_days', $classPassPlan->validity_days ?? 30) }}" required class="crm-field">
        @error('validity_days') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <label class="block">
        <span class="crm-label">{{ __('app.available_from_time') }}</span>
        <input name="available_from_time" type="time" value="{{ $availableFromTime }}" class="crm-field">
        @error('available_from_time') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.available_until_time') }}</span>
        <input name="available_until_time" type="time" value="{{ $availableUntilTime }}" class="crm-field">
        @error('available_until_time') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.sort_order') }}</span>
        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $classPassPlan->sort_order ?? 0) }}" required class="crm-field">
        @error('sort_order') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="mt-7 flex items-center gap-3 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $classPassPlan->is_active)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>
</div>

<div data-activity-direction-group>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <span class="crm-label">{{ __('app.activity_directions') }}</span>
        <x-ui.button type="button" variant="secondary" size="sm" data-select-all-directions>
            {{ __('app.select_all') }}
        </x-ui.button>
    </div>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @forelse ($activityDirections as $activityDirection)
            <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-700">
                <input name="activity_direction_ids[]" type="checkbox" value="{{ $activityDirection->id }}" @checked(in_array($activityDirection->id, $selectedActivityDirectionIds, true)) class="crm-checkbox" data-activity-direction-checkbox>
                <span>{{ $activityDirection->name }}</span>
            </label>
        @empty
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">{{ __('app.no_activity_directions') }}</div>
        @endforelse
    </div>
    @error('activity_direction_ids') <span class="crm-help">{{ $message }}</span> @enderror
    @error('activity_direction_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
</div>
