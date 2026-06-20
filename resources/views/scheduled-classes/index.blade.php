@extends('layouts.app')

@section('title', __('app.generated_classes').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.generated_classes') }}</h1>
            <p class="crm-page-copy">{{ __('app.generated_classes_copy') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="button" data-class-record-mock-open>
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.add_class_record') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.schedule-series.index', $account)" variant="secondary">{{ __('app.schedule_series') }}</x-ui.button>
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

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="class-record-mock-title"
        data-class-record-mock-modal
    >
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                    <x-ui.icon name="calendar-plus" class="h-5 w-5" />
                </div>
                <div>
                    <h2 id="class-record-mock-title" class="text-lg font-semibold text-slate-950">{{ __('app.class_record_mock_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.class_record_mock_body') }}</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <x-ui.button type="button" variant="secondary" data-class-record-mock-close>{{ __('app.cancel') }}</x-ui.button>
            </div>
        </div>
    </div>
@endsection
