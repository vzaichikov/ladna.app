@php
    $defaultTimezone = $account->timezone ?? config('app.timezone');
    $defaultStartsAt = now($defaultTimezone)->addHour()->startOfHour()->format('Y-m-d\TH:i');
    $singleLocation = $quickBookingLocations->count() === 1 ? $quickBookingLocations->first() : null;
@endphp

@foreach ($quickBookingOptions as $quickBookingOption)
    @php
        $scheduleKind = $quickBookingOption['kind'];
        $definition = $quickBookingOption['definition'];
        $modalId = 'manual-class-title-'.$scheduleKind->value;
        $isPrivateLesson = $scheduleKind === \App\Enums\ScheduleKind::PrivateLesson;
    @endphp

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $modalId }}"
        data-manual-class-modal="{{ $scheduleKind->value }}"
    >
        <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                        <x-ui.icon :name="$definition['icon']" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 id="{{ $modalId }}" class="text-lg font-semibold text-slate-950">{{ __('app.add_'.$scheduleKind->value.'_record') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.manual_class_record_copy') }}</p>
                    </div>
                </div>
                <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-manual-class-close />
            </div>

            @if ($quickBookingOption['classTypes']->isEmpty())
                <div class="overflow-y-auto p-5">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        {{ __('app.'.$definition['empty_key']) }}
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" data-manual-class-close>{{ __('app.cancel') }}</x-ui.button>
                        @can('manageStudioSettings', $account)
                            <x-ui.button :href="route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'create'), $account)">
                                <x-ui.icon name="plus" class="h-4 w-4" />
                                {{ __('app.'.$definition['create_key']) }}
                            </x-ui.button>
                        @endcan
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('dashboard.accounts.scheduled-classes.manual.store', [$account, $scheduleKind->value]) }}" class="flex min-h-0 flex-1 flex-col" data-manual-class-form>
                    @csrf

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            @if ($singleLocation)
                                <input type="hidden" name="location_id" value="{{ $singleLocation->id }}" data-quick-booking-location>
                            @else
                                <label class="block">
                                    <span class="crm-label">{{ __('app.location') }}</span>
                                    <select name="location_id" required class="crm-field" data-quick-booking-location>
                                        @foreach ($quickBookingLocations as $location)
                                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif

                            <label class="block">
                                <span class="crm-label">{{ __('app.room') }}</span>
                                <select name="room_id" required class="crm-field" data-quick-booking-room>
                                    @foreach ($quickBookingRooms as $room)
                                        <option value="{{ $room->id }}" data-location-id="{{ $room->location_id }}">{{ $room->location?->name }} · {{ $room->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.class_type') }}</span>
                                <select name="class_type_id" required class="crm-field">
                                    @foreach ($quickBookingOption['classTypes'] as $classType)
                                        <option value="{{ $classType->id }}">{{ $classType->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="block">
                                <span class="crm-label">{{ __('app.trainer') }}</span>
                                <select name="trainer_id" @required($isPrivateLesson) class="crm-field">
                                    <option value="">{{ __('app.trainer_not_assigned') }}</option>
                                    @foreach ($quickBookingTrainers as $trainer)
                                        <option value="{{ $trainer->id }}">{{ $trainer->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.start_time') }}</span>
                                <input name="starts_at" type="datetime-local" value="{{ $defaultStartsAt }}" required class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.duration_minutes') }}</span>
                                <input name="duration_minutes" type="number" min="15" max="480" step="5" class="crm-field" placeholder="60">
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block">
                                <span class="crm-label">{{ __('app.capacity') }}</span>
                                <input name="capacity" type="number" min="1" max="999" class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.booking_cutoff_minutes') }}</span>
                                <input name="booking_cutoff_minutes" type="number" min="0" max="10080" class="crm-field">
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.cancellation_cutoff_minutes') }}</span>
                                <input name="cancellation_cutoff_minutes" type="number" min="0" max="10080" class="crm-field">
                            </label>
                        </div>

                        <label class="block">
                            <span class="crm-label">{{ __('app.title') }}</span>
                            <input name="title" class="crm-field">
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.description') }}</span>
                            <textarea name="description" rows="3" class="crm-field"></textarea>
                        </label>
                    </div>

                    <div class="flex shrink-0 justify-end gap-2 border-t border-stone-200 bg-white p-5">
                        <x-ui.button type="button" variant="secondary" data-manual-class-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">
                            <x-ui.icon name="calendar" class="h-4 w-4" />
                            {{ __('app.create_class_record') }}
                        </x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endforeach
