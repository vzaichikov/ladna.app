@php
    $internalClassOption = $manualClassOptions->first(
        fn (array $option): bool => $option['kind'] === \App\Enums\ScheduleKind::InternalClass,
    );
    $internalScheduledClasses = $scheduledClassDays
        ->flatMap(fn ($classes) => $classes)
        ->filter(fn ($scheduledClass): bool => $scheduledClass->classType?->schedule_kind === \App\Enums\ScheduleKind::InternalClass
            && $scheduledClass->isFullyEditableOccurrence());
@endphp

@if ($internalClassOption)
    @foreach ($internalScheduledClasses as $scheduledClass)
        @php
            $modalKey = 'internal-edit-'.$scheduledClass->id;
            $modalTitleId = $modalKey.'-title';
            $timezone = $scheduledClass->displayTimezone();
            $startsAtValue = $scheduledClass->starts_at->copy()->timezone($timezone)->format('Y-m-d\TH:i');
        @endphp

        <div
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $modalTitleId }}"
            data-manual-class-modal="{{ $modalKey }}"
        >
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-800">
                            <x-ui.icon name="lock" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 id="{{ $modalTitleId }}" class="text-lg font-semibold text-slate-950">{{ __('app.edit_internal_class') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.edit_internal_class_copy') }}</p>
                        </div>
                    </div>
                    <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-manual-class-close />
                </div>

                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.scheduled-classes.internal.update', [$account, $scheduledClass]) }}"
                    class="flex min-h-0 flex-1 flex-col"
                    data-manual-class-form
                    data-async-form
                    data-async-success="modal-reload"
                    novalidate
                >
                    @csrf
                    @method('PATCH')

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                        <div
                            data-async-form-status
                            data-error-message="{{ __('app.async_request_failed') }}"
                            data-validation-message="{{ __('app.async_validation_failed') }}"
                            class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 shadow-xs"
                        ></div>
                        <div data-async-error-for="_form"></div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.location') }}</span>
                                <select name="location_id" required class="crm-field" data-quick-booking-location>
                                    @foreach ($quickBookingLocations as $location)
                                        <option value="{{ $location->id }}" @selected($scheduledClass->location_id === $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.room') }}</span>
                                <select name="room_id" required class="crm-field" data-quick-booking-room>
                                    @foreach ($quickBookingRooms as $room)
                                        <option
                                            value="{{ $room->id }}"
                                            data-location-id="{{ $room->location_id }}"
                                            @selected($scheduledClass->room_id === $room->id)
                                        >{{ $room->location?->name }} · {{ $room->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.class_type') }}</span>
                                <select name="class_type_id" required class="crm-field">
                                    @foreach ($internalClassOption['classTypes'] as $classType)
                                        <option value="{{ $classType->id }}" @selected($scheduledClass->class_type_id === $classType->id)>{{ $classType->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.main_trainer') }}</span>
                                <select name="trainer_id" required class="crm-field">
                                    @foreach ($quickBookingTrainers as $trainer)
                                        <option value="{{ $trainer->id }}" @selected($scheduledClass->trainer_id === $trainer->id)>{{ $trainer->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <x-ui.trainer-multi-select
                            :trainers="$quickBookingTrainers"
                            :selected-ids="old('additional_trainer_ids', $scheduledClass->additionalTrainerIds()->all())"
                            input-id="additional-trainers-internal-edit-{{ $scheduledClass->id }}"
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.start_time') }}</span>
                                <input name="starts_at" type="datetime-local" value="{{ $startsAtValue }}" required class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.duration_minutes') }}</span>
                                <input name="duration_minutes" type="number" min="15" max="480" step="5" value="{{ $scheduledClass->durationMinutes() }}" required class="crm-field">
                            </label>
                        </div>

                        <label class="block">
                            <span class="crm-label">{{ __('app.title') }}</span>
                            <input name="title" value="{{ $scheduledClass->title }}" required class="crm-field">
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.description') }}</span>
                            <textarea name="description" rows="3" class="crm-field">{{ $scheduledClass->description }}</textarea>
                        </label>
                    </div>

                    <div class="flex shrink-0 justify-end gap-2 border-t border-stone-200 bg-white p-5">
                        <x-ui.button type="button" variant="secondary" data-manual-class-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">
                            <x-ui.icon name="save" class="h-4 w-4" />
                            {{ __('app.save') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endif
