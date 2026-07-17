@extends('layouts.app')

@section('title', __('app.scheduled_classes_history').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.scheduled_classes_history') }}</h1>
            <p class="crm-page-copy">{{ __('app.scheduled_classes_history_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2 sm:justify-end">
            <x-ui.button :href="route('dashboard.accounts.scheduled-classes.index', $account)" variant="secondary">{{ __('app.generated_classes') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">{{ __('app.schedule_series') }}</x-ui.button>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.scheduled-classes-history.index', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            <label>
                <span class="crm-label">{{ __('app.date_from') }}</span>
                <input type="date" name="date_from" value="{{ $selectedDateFrom }}" class="crm-field">
            </label>

            <label>
                <span class="crm-label">{{ __('app.date_to') }}</span>
                <input type="date" name="date_to" value="{{ $selectedDateTo }}" class="crm-field">
            </label>

            <fieldset>
                <legend class="crm-label">{{ __('app.filter_locations') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($filterLocations as $location)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                            'border-brand-200 bg-brand-50 text-brand-700' => in_array($location->id, $selectedLocationIds, true),
                            'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($location->id, $selectedLocationIds, true),
                        ])>
                            <input type="checkbox" name="locations[]" value="{{ $location->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($location->id, $selectedLocationIds, true))>
                            <span>{{ $location->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="crm-label">{{ __('app.filter_rooms') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($filterRooms as $room)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                            'border-brand-200 bg-brand-50 text-brand-700' => in_array($room->id, $selectedRoomIds, true),
                            'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($room->id, $selectedRoomIds, true),
                        ])>
                            <input type="checkbox" name="rooms[]" value="{{ $room->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($room->id, $selectedRoomIds, true))>
                            <span>{{ $room->location?->name }} · {{ $room->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if ($filterTrainers->isNotEmpty())
                <fieldset>
                    <legend class="crm-label">{{ __('app.filter_trainers') }}</legend>
                    <div class="mt-2 max-h-40 overflow-y-auto pr-1">
                        <div class="flex flex-col gap-2">
                            @foreach ($filterTrainers as $trainer)
                                <label @class([
                                    'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                                    'border-brand-200 bg-brand-50 text-brand-700' => in_array($trainer->id, $selectedTrainerIds, true),
                                    'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($trainer->id, $selectedTrainerIds, true),
                                ])>
                                    <input type="checkbox" name="trainers[]" value="{{ $trainer->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($trainer->id, $selectedTrainerIds, true))>
                                    <span>{{ $trainer->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </fieldset>
            @endif

            @if ($filterClassTypes->isNotEmpty())
                <fieldset>
                    <legend class="crm-label">{{ __('app.filter_class_types') }}</legend>
                    <div class="mt-2 max-h-40 overflow-y-auto pr-1">
                        <div class="flex flex-col gap-2">
                            @foreach ($filterClassTypes as $classType)
                                @php
                                    $classTypeLabel = $classType->activityDirection?->name
                                        ? $classType->activityDirection->name.' · '.$classType->name
                                        : $classType->name;
                                @endphp
                                <label @class([
                                    'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                                    'border-brand-200 bg-brand-50 text-brand-700' => in_array($classType->id, $selectedClassTypeIds, true),
                                    'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($classType->id, $selectedClassTypeIds, true),
                                ])>
                                    <input type="checkbox" name="class_types[]" value="{{ $classType->id }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($classType->id, $selectedClassTypeIds, true))>
                                    <span>{{ $classTypeLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </fieldset>
            @endif

            <fieldset>
                <legend class="crm-label">{{ __('app.filter_class_formats') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($filterScheduleKinds as $scheduleKind)
                        <label @class([
                            'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                            'border-brand-200 bg-brand-50 text-brand-700' => in_array($scheduleKind, $selectedScheduleKinds, true),
                            'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! in_array($scheduleKind, $selectedScheduleKinds, true),
                        ])>
                            <input type="checkbox" name="schedule_kinds[]" value="{{ $scheduleKind }}" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked(in_array($scheduleKind, $selectedScheduleKinds, true))>
                            <span>{{ __('app.'.$scheduleKind) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="crm-label">{{ __('app.filter_attendance') }}</legend>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label @class([
                        'inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold transition',
                        'border-brand-200 bg-brand-50 text-brand-700' => $withoutAttendance,
                        'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' => ! $withoutAttendance,
                    ])>
                        <input type="checkbox" name="without_attendance" value="1" class="size-4 rounded border-stone-300 text-brand-600 focus:ring-brand-500" @checked($withoutAttendance)>
                        <span>{{ __('app.filter_without_attendance') }}</span>
                    </label>
                </div>
            </fieldset>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.scheduled-classes-history.index', $account)" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <section class="mt-6 space-y-8" data-scheduled-class-history-page>
        @foreach ($scheduledClassDays as $date => $classes)
            @include('scheduled-classes._day', [
                'account' => $account,
                'date' => $date,
                'classes' => $classes,
                'customerSearchUrl' => $customerSearchUrl,
                'bookingStatuses' => $bookingStatuses,
                'trainerOptions' => $trainerOptions,
                'readonly' => true,
            ])
        @endforeach

        @if ($scheduledClassDays->isEmpty())
            <x-ui.empty-state :title="__('app.no_history_classes')" icon="calendar" />
        @endif

        @if ($scheduledClasses->hasPages())
            <div>
                {{ $scheduledClasses->links() }}
            </div>
        @endif
    </section>
@endsection
