@php
    $rightsErrorFields = ['create_login', 'user_email', 'user_password', 'permissions'];
    $trainerFormErrorFields = collect($errors->keys());
    $rightsHaveErrors = $trainerFormErrorFields->contains(
        fn (string $field): bool => collect($rightsErrorFields)->contains(
            fn (string $rightsField): bool => $field === $rightsField || str_starts_with($field, $rightsField.'.'),
        ),
    );
    $informationHasErrors = $trainerFormErrorFields->contains(
        fn (string $field): bool => ! collect($rightsErrorFields)->contains(
            fn (string $rightsField): bool => $field === $rightsField || str_starts_with($field, $rightsField.'.'),
        ),
    );
    $requestedTrainerFormTab = old('trainer_form_tab', 'information');
    $activeTrainerFormTab = $informationHasErrors
        ? 'information'
        : ($rightsHaveErrors ? 'rights' : (in_array($requestedTrainerFormTab, ['information', 'rights'], true) ? $requestedTrainerFormTab : 'information'));
    $permissionSelection = old('permissions', $selectedPermissions);
@endphp

<div data-trainer-form-tabs data-active-tab="{{ $activeTrainerFormTab }}">
    <input type="hidden" name="trainer_form_tab" value="{{ $activeTrainerFormTab }}" data-trainer-form-active-tab>

    <div class="mb-5 border-b border-stone-100 pb-4">
        <div class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" role="tablist" aria-label="{{ __('app.trainer_card_sections') }}">
            <button
                type="button"
                id="trainer-form-tab-information"
                class="crm-tab justify-start sm:justify-center"
                role="tab"
                data-trainer-form-tab="information"
                aria-controls="trainer-form-panel-information"
                aria-selected="{{ $activeTrainerFormTab === 'information' ? 'true' : 'false' }}"
                tabindex="{{ $activeTrainerFormTab === 'information' ? '0' : '-1' }}"
            >
                <x-ui.icon name="trainers" class="h-4 w-4" />
                {{ __('app.trainer_information_and_classes') }}
            </button>
            <button
                type="button"
                id="trainer-form-tab-rights"
                class="crm-tab justify-start sm:justify-center"
                role="tab"
                data-trainer-form-tab="rights"
                aria-controls="trainer-form-panel-rights"
                aria-selected="{{ $activeTrainerFormTab === 'rights' ? 'true' : 'false' }}"
                tabindex="{{ $activeTrainerFormTab === 'rights' ? '0' : '-1' }}"
            >
                <x-ui.icon name="key" class="h-4 w-4" />
                {{ __('app.trainer_access_rights') }}
            </button>
        </div>
    </div>

    <section
        id="trainer-form-panel-information"
        data-trainer-form-panel="information"
        role="tabpanel"
        aria-labelledby="trainer-form-tab-information"
        @class(['space-y-5', 'hidden' => $activeTrainerFormTab !== 'information'])
    >
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.trainer_information_and_classes') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.trainer_information_and_classes_copy') }}</p>
        </div>

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
    </section>

    <section
        id="trainer-form-panel-rights"
        data-trainer-form-panel="rights"
        role="tabpanel"
        aria-labelledby="trainer-form-tab-rights"
        @class(['space-y-6', 'hidden' => $activeTrainerFormTab !== 'rights'])
    >
        <div>
            <h2 class="text-lg font-semibold text-slate-950">{{ __('app.trainer_access_rights') }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.trainer_access_rights_copy') }}</p>
        </div>

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
        </section>

        <div>
            <div class="crm-label">{{ __('app.permissions') }}</div>
            <div class="mt-4 space-y-7">
                @foreach ($studioPermissionGroups as $permissionGroup => $permissions)
                    @php
                        $groupPermission = $permissions->first();
                    @endphp
                    <section aria-labelledby="trainer-permission-group-{{ $permissionGroup }}">
                        <div>
                            <h3 id="trainer-permission-group-{{ $permissionGroup }}" class="text-base font-semibold text-slate-950">{{ __($groupPermission->groupLabelKey()) }}</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">{{ __($groupPermission->groupDescriptionKey()) }}</p>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ($permissions as $permission)
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
                                <div class="flex items-start gap-3 rounded-lg border px-3 py-3 text-sm font-medium {{ $permissionCardClass }}">
                                    <input
                                        id="trainer-permission-{{ $permission->value }}"
                                        name="permissions[]"
                                        type="checkbox"
                                        value="{{ $permission->value }}"
                                        @checked(in_array($permission->value, $permissionSelection, true))
                                        class="crm-checkbox mt-1"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <label for="trainer-permission-{{ $permission->value }}" class="block cursor-pointer">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-semibold">{{ __($permission->labelKey()) }}</span>
                                                <span class="rounded-md border px-2 py-0.5 text-[11px] font-semibold uppercase {{ $badgeClass }}">{{ __('app.permission_sensitivity_'.$sensitivity) }}</span>
                                            </span>
                                            <span class="mt-1 block text-xs leading-5 opacity-80">{{ __($permission->descriptionKey()) }}</span>
                                        </label>
                                        <button
                                            type="button"
                                            class="mt-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold underline decoration-current/30 underline-offset-2 transition hover:bg-white/70 hover:decoration-current focus:outline-none focus-visible:ring-2 focus-visible:ring-current focus-visible:ring-offset-2"
                                            data-trainer-permission-details-open
                                            data-permission-title="{{ __($permission->labelKey()) }}"
                                            data-permission-summary="{{ __($permission->descriptionKey()) }}"
                                            data-permission-details="{{ __($permission->detailsKey()) }}"
                                            data-permission-group="{{ __($permission->groupLabelKey()) }}"
                                            data-permission-sensitivity="{{ __('app.permission_sensitivity_'.$sensitivity) }}"
                                            data-permission-sensitivity-copy="{{ __('app.permission_sensitivity_'.$sensitivity.'_description') }}"
                                            aria-controls="trainer-permission-details-modal"
                                            aria-haspopup="dialog"
                                            aria-label="{{ __('app.permission_details_about', ['permission' => __($permission->labelKey())]) }}"
                                        >
                                            <x-ui.icon name="info" class="h-3.5 w-3.5" />
                                            {{ __('app.permission_details') }}
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
            @error('permissions') <span class="crm-help">{{ $message }}</span> @enderror
            @error('permissions.*') <span class="crm-help">{{ $message }}</span> @enderror
        </div>
    </section>
</div>

<div
    id="trainer-permission-details-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="trainer-permission-details-title"
    data-trainer-permission-details-modal
>
    <div class="flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <span data-trainer-permission-details-group></span>
                    <span aria-hidden="true">·</span>
                    <span class="rounded-md border border-stone-200 bg-stone-50 px-2 py-0.5" data-trainer-permission-details-sensitivity></span>
                </div>
                <h2 id="trainer-permission-details-title" class="mt-2 text-lg font-semibold text-slate-950" data-trainer-permission-details-title></h2>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.close')" data-trainer-permission-details-close />
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
            <p class="text-sm leading-6 text-slate-600" data-trainer-permission-details-summary></p>

            <section class="rounded-lg border border-brand-100 bg-brand-50 p-4">
                <h3 class="text-sm font-semibold text-brand-950">{{ __('app.permission_details_in_practice') }}</h3>
                <p class="mt-2 text-sm leading-6 text-brand-900" data-trainer-permission-details-copy></p>
            </section>

            <section class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                <h3 class="text-sm font-semibold text-slate-950">{{ __('app.permission_sensitivity_explanation') }}</h3>
                <p class="mt-2 text-sm leading-6 text-slate-600" data-trainer-permission-details-sensitivity-copy></p>
            </section>
        </div>

        <div class="flex shrink-0 justify-end border-t border-stone-200 bg-white p-5">
            <x-ui.button type="button" variant="secondary" data-trainer-permission-details-close>{{ __('app.close') }}</x-ui.button>
        </div>
    </div>
</div>
