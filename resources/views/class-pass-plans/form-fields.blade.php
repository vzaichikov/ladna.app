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
    $formatMoneyInput = static function (?int $priceCents): string {
        if ($priceCents === null) {
            return '';
        }

        $whole = intdiv($priceCents, 100);
        $fraction = $priceCents % 100;

        return $fraction === 0
            ? (string) $whole
            : number_format($priceCents / 100, 2, '.', '');
    };
    $selectedCurrency = old('currency', $classPassPlan->currency);
    $price = old('price', $formatMoneyInput($classPassPlan->price_cents ?? 0));
    $allowsAnyTime = (bool) old('allows_any_time', $classPassPlan->allows_any_time ?? false);
    $anyTimeAddonPrice = old('any_time_addon_price', $formatMoneyInput($classPassPlan->any_time_addon_price_cents));
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
        <span class="crm-label">{{ __('app.price') }}</span>
        <input name="price" type="number" min="0" step="0.01" value="{{ $price }}" required class="crm-field">
        @error('price') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.currency') }}</span>
        <select name="currency" class="crm-field" data-class-pass-currency>
            @foreach ($currencies as $currency)
                <option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>
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

<div class="grid gap-4 sm:grid-cols-2" data-any-time-addon>
    <label class="flex items-center gap-3 text-sm font-medium text-slate-700">
        <input type="hidden" name="allows_any_time" value="0">
        <input name="allows_any_time" type="checkbox" value="1" @checked($allowsAnyTime) class="crm-checkbox" data-any-time-toggle>
        {{ __('app.allows_any_time') }}
    </label>
    <label class="{{ $allowsAnyTime ? 'block' : 'hidden' }}" data-any-time-addon-fields>
        <span class="crm-label">{{ __('app.any_time_addon_price') }}</span>
        <span class="mt-2 flex items-center gap-2">
            <span class="text-base font-semibold text-slate-500">+</span>
            <input name="any_time_addon_price" type="number" min="0" step="0.01" value="{{ $anyTimeAddonPrice }}" class="crm-field mt-0" data-any-time-addon-price @required($allowsAnyTime)>
            <span class="min-w-12 text-sm font-semibold text-slate-600" data-any-time-currency>{{ $selectedCurrency }}</span>
        </span>
        @error('any_time_addon_price') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    @error('allows_any_time') <span class="crm-help">{{ $message }}</span> @enderror
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
