<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.person_name') }}</span>
        <input name="name" value="{{ old('name', $trainer->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $trainer->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="block">
    <span class="crm-label">{{ __('app.trainer_type') }}</span>
    <select name="trainer_type_id" class="crm-field">
        @foreach ($trainerTypes as $trainerType)
            <option value="{{ $trainerType->id }}" @selected((int) old('trainer_type_id', $trainer->trainer_type_id) === $trainerType->id)>
                {{ $trainerType->name }}
            </option>
        @endforeach
    </select>
    @error('trainer_type_id') <span class="crm-help">{{ $message }}</span> @enderror
</label>
@if ($activeLocations->isNotEmpty())
    @php
        $locationSelection = old('location_ids', $selectedLocationIds ?? []);
    @endphp
    <fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
        <legend class="crm-label px-1">{{ __('app.trainer_locations') }}</legend>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.trainer_locations_help') }}</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach ($activeLocations as $location)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700">
                    <input
                        name="location_ids[]"
                        type="checkbox"
                        value="{{ $location->id }}"
                        @checked(in_array($location->id, array_map('intval', $locationSelection), true))
                        class="crm-checkbox"
                    >
                    {{ $location->name }}
                </label>
            @endforeach
        </div>
        @error('location_ids') <span class="crm-help">{{ $message }}</span> @enderror
        @error('location_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
    </fieldset>
@endif
@if ($activeActivityDirections->isNotEmpty())
    @php
        $activityDirectionSelection = old('activity_direction_ids', $selectedActivityDirectionIds ?? []);
    @endphp
    <fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
        <legend class="crm-label px-1">{{ __('app.trainer_activity_directions') }}</legend>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.trainer_activity_directions_help') }}</p>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            @foreach ($activeActivityDirections as $activityDirection)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700">
                    <input
                        name="activity_direction_ids[]"
                        type="checkbox"
                        value="{{ $activityDirection->id }}"
                        @checked(in_array($activityDirection->id, array_map('intval', $activityDirectionSelection), true))
                        class="crm-checkbox"
                    >
                    {{ $activityDirection->name }}
                </label>
            @endforeach
        </div>
        @error('activity_direction_ids') <span class="crm-help">{{ $message }}</span> @enderror
        @error('activity_direction_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
    </fieldset>
@endif
<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $trainer->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" type="tel" value="{{ old('phone', $trainer->phone) }}" class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="block">
    <span class="crm-label">{{ __('app.bio') }}</span>
    <textarea name="bio" rows="3" class="crm-field">{{ old('bio', $trainer->bio) }}</textarea>
    @error('bio') <span class="crm-help">{{ $message }}</span> @enderror
</label>
<div class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
    @if ($trainer->photoUrl())
        <img src="{{ $trainer->photoUrl() }}" alt="" class="h-16 w-16 rounded-full object-cover ring-2 ring-slate-100">
    @else
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-lg font-semibold text-slate-500">
            {{ mb_substr($trainer->name ?: __('app.trainer'), 0, 1) }}
        </span>
    @endif
    <label class="block">
        <span class="crm-label">{{ __('app.photo') }}</span>
        <input name="photo" type="file" accept="image/png,image/jpeg,image/webp" class="crm-field">
        @error('photo') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $trainer->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>

<section class="rounded-lg border border-slate-200 bg-slate-50 p-4">
    <label class="flex items-center gap-3 text-sm font-semibold text-slate-800">
        <input type="hidden" name="create_login" value="0">
        <input name="create_login" type="checkbox" value="1" @checked(old('create_login', $trainer->user_id !== null)) class="crm-checkbox">
        {{ __('app.enable_staff_login') }}
    </label>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block">
            <span class="crm-label">{{ __('app.login_email') }}</span>
            <input name="user_email" type="email" value="{{ old('user_email', $trainer->user?->email ?? $trainer->email) }}" class="crm-field">
            @error('user_email') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.password') }}</span>
            <input name="user_password" type="password" class="crm-field">
            @error('user_password') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </div>

    <div class="mt-4">
        <div class="crm-label">{{ __('app.permissions') }}</div>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            @foreach ($studioPermissions as $permission)
                @php
                    $sensitivity = $permission->sensitivity();
                    $permissionCardClass = match ($sensitivity) {
                        'critical' => 'border-rose-200 bg-rose-50 text-rose-900',
                        'high' => 'border-amber-200 bg-amber-50 text-amber-900',
                        default => 'border-slate-200 bg-white text-slate-700',
                    };
                    $badgeClass = match ($sensitivity) {
                        'critical' => 'border-rose-200 bg-white text-rose-700',
                        'high' => 'border-amber-200 bg-white text-amber-700',
                        default => 'border-slate-200 bg-slate-50 text-slate-600',
                    };
                @endphp
                <label class="flex items-start gap-3 rounded-lg border px-3 py-3 text-sm font-medium {{ $permissionCardClass }}">
                    <input
                        name="permissions[]"
                        type="checkbox"
                        value="{{ $permission->value }}"
                        @checked(in_array($permission->value, old('permissions', $selectedPermissions), true))
                        class="crm-checkbox mt-1"
                    >
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ __($permission->labelKey()) }}</span>
                            <span class="rounded-md border px-2 py-0.5 text-[11px] font-semibold uppercase {{ $badgeClass }}">{{ __('app.permission_sensitivity_'.$sensitivity) }}</span>
                        </span>
                        <span class="mt-1 block text-xs leading-5 opacity-80">{{ __($permission->descriptionKey()) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('permissions') <span class="crm-help">{{ $message }}</span> @enderror
        @error('permissions.*') <span class="crm-help">{{ $message }}</span> @enderror
    </div>
</section>
