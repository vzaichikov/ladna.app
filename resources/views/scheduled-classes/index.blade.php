@extends('layouts.app')

@section('title', __('app.generated_classes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.generated_classes') }}</h1>
            <p class="crm-page-copy">{{ __('app.generated_classes_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($manualClassOptions as $manualClassOption)
                <x-ui.button type="button" data-manual-class-open="{{ $manualClassOption['kind']->value }}">
                    <x-ui.icon name="plus" class="h-4 w-4" />
                    {{ __('app.add_'.$manualClassOption['kind']->value.'_record') }}
                </x-ui.button>
            @endforeach
            @if ($account->hasScheduleKindEnabled(\App\Enums\ScheduleKind::GroupClass))
                <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">{{ __('app.schedule_series') }}</x-ui.button>
            @endif
        </div>
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('app.generated_classes') }}">
        @foreach ($tabs as $tab => $label)
            <a
                href="{{ route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => $tab, 'locations' => $selectedLocationIds, 'rooms' => $selectedRoomIds]) }}"
                class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm font-semibold transition {{ $activeTab === $tab ? 'border-brand-600 bg-brand-600 text-white shadow-sm shadow-brand-600/20' : 'border-stone-200 bg-white text-slate-700 hover:border-brand-100 hover:bg-brand-50' }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('dashboard.accounts.scheduled-classes.index', $account) }}" class="mt-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <div class="grid gap-4 lg:grid-cols-2">
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
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.scheduled-classes.index', ['account' => $account, 'tab' => $activeTab])" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <section class="mt-6 space-y-8" data-scheduled-class-current>
        @foreach ($scheduledClassDays as $date => $classes)
            @include('scheduled-classes._day', [
                'account' => $account,
                'date' => $date,
                'classes' => $classes,
                'customerSearchUrl' => $customerSearchUrl,
                'bookingStatuses' => $bookingStatuses,
            ])
        @endforeach

        @if ($scheduledClassDays->isEmpty() && $pastScheduledClassDays->isEmpty())
            <x-ui.empty-state :title="__('app.no_public_classes')" icon="calendar" />
        @endif

        @if ($pastScheduledClassDays->isNotEmpty())
            <details class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs" data-scheduled-class-history>
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-lg px-2 py-1 text-sm font-semibold text-slate-800 marker:hidden">
                    <span>{{ __('app.older_today_classes') }}</span>
                    <span class="crm-status-muted">{{ __('app.older_today_classes_count', ['count' => $pastScheduledClassesCount]) }}</span>
                </summary>
                <div class="mt-5 space-y-8 border-t border-stone-100 pt-5">
                    @foreach ($pastScheduledClassDays as $date => $classes)
                        @include('scheduled-classes._day', [
                            'account' => $account,
                            'date' => $date,
                            'classes' => $classes,
                            'customerSearchUrl' => $customerSearchUrl,
                            'bookingStatuses' => $bookingStatuses,
                        ])
                    @endforeach
                </div>
            </details>
        @endif
    </section>

    @foreach ($manualClassOptions as $manualClassOption)
        @php
            $manualKind = $manualClassOption['kind'];
            $manualDefinition = $manualClassOption['definition'];
            $manualTimezone = $account->timezone ?? config('app.timezone');
            $defaultStartsAt = now($manualTimezone)->addHour()->minute(0)->second(0)->format('Y-m-d\TH:i');
        @endphp
        <div
            class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="manual-class-title-{{ $manualKind->value }}"
            data-manual-class-modal="{{ $manualKind->value }}"
        >
            <div class="my-8 w-full max-w-2xl rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                            <x-ui.icon :name="$manualDefinition['icon']" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 id="manual-class-title-{{ $manualKind->value }}" class="text-lg font-semibold text-slate-950">{{ __('app.add_'.$manualKind->value.'_record') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.manual_class_record_copy') }}</p>
                        </div>
                    </div>
                    <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-manual-class-close />
                </div>

                @if ($manualClassOption['classTypes']->isEmpty())
                    <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        {{ __('app.'.$manualDefinition['empty_key']) }}
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" data-manual-class-close>{{ __('app.cancel') }}</x-ui.button>
                        @can('manageStudioSettings', $account)
                            <x-ui.button :href="route(\App\Support\ScheduleKindRegistry::routeName($manualKind, 'create'), $account)">
                                <x-ui.icon name="plus" class="h-4 w-4" />
                                {{ __('app.'.$manualDefinition['create_key']) }}
                            </x-ui.button>
                        @endcan
                    </div>
                @else
                    <form method="POST" action="{{ route('dashboard.accounts.scheduled-classes.manual.store', [$account, $manualKind->value]) }}" class="mt-6 space-y-5">
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.location') }}</span>
                                <select name="location_id" required class="crm-field">
                                    @foreach ($filterLocations as $location)
                                        <option value="{{ $location->id }}" @selected((int) old('location_id') === $location->id)>{{ $location->name }}</option>
                                    @endforeach
                                </select>
                                @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.room') }}</span>
                                <select name="room_id" required class="crm-field">
                                    @foreach ($filterRooms as $room)
                                        <option value="{{ $room->id }}" @selected((int) old('room_id') === $room->id)>{{ $room->location?->name }} · {{ $room->name }}</option>
                                    @endforeach
                                </select>
                                @error('room_id') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.class_type') }}</span>
                                <select name="class_type_id" required class="crm-field">
                                    @foreach ($manualClassOption['classTypes'] as $classType)
                                        <option value="{{ $classType->id }}" @selected((int) old('class_type_id') === $classType->id)>{{ $classType->name }}</option>
                                    @endforeach
                                </select>
                                @error('class_type_id') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            @if ($manualKind === \App\Enums\ScheduleKind::PrivateLesson)
                                <label class="block">
                                    <span class="crm-label">{{ __('app.trainer') }}</span>
                                    <select name="trainer_id" required class="crm-field">
                                        <option value="">{{ __('app.trainer_not_assigned') }}</option>
                                        @foreach ($manualTrainers as $trainer)
                                            <option value="{{ $trainer->id }}" @selected((int) old('trainer_id') === $trainer->id)>{{ $trainer->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('trainer_id') <span class="crm-help">{{ $message }}</span> @enderror
                                </label>
                            @endif
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="crm-label">{{ __('app.name') }}</span>
                                <input name="title" value="{{ old('title') }}" class="crm-field">
                                @error('title') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.start_time') }}</span>
                                <input name="starts_at" type="datetime-local" value="{{ old('starts_at', $defaultStartsAt) }}" required class="crm-field">
                                @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block">
                                <span class="crm-label">{{ __('app.duration') }}</span>
                                <input name="duration_minutes" type="number" min="15" max="480" value="{{ old('duration_minutes') }}" class="crm-field">
                                @error('duration_minutes') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.'.$manualDefinition['capacity_label_key']) }}</span>
                                <input name="capacity" type="number" min="1" max="999" value="{{ old('capacity') }}" class="crm-field">
                                @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label class="block">
                                <span class="crm-label">{{ __('app.booking_cutoff') }}</span>
                                <input name="booking_cutoff_minutes" type="number" min="0" max="10080" value="{{ old('booking_cutoff_minutes') }}" class="crm-field">
                                @error('booking_cutoff_minutes') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                        </div>

                        <label class="block">
                            <span class="crm-label">{{ __('app.description') }}</span>
                            <textarea name="description" rows="3" class="crm-field">{{ old('description') }}</textarea>
                            @error('description') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="secondary" data-manual-class-close>{{ __('app.cancel') }}</x-ui.button>
                            <x-ui.button type="submit">
                                <x-ui.icon name="plus" class="h-4 w-4" />
                                {{ __('app.add_class_record') }}
                            </x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
@endsection
