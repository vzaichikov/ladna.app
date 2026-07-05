@extends('layouts.app')

@section('title', __('app.people_counter_report_title').' - '.$account->name)

@section('content')
    @php
        $selectedDate = $filters['date'];
        $selectedLocationId = $filters['location_id'];
        $selectedRoomId = $filters['room_id'];
        $selectedTrainerId = $filters['trainer_id'];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.people_counter_report_title') }}</h1>
            <p class="crm-page-copy">{{ __('app.people_counter_report_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
            {{ __('app.reports') }}
        </x-ui.button>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.reports.people-counter', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label class="block">
                <span class="crm-label">{{ __('app.filter_date') }}</span>
                <input type="date" name="date" value="{{ $selectedDate }}" class="crm-field">
                @error('date') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.location') }}</span>
                <select name="location_id" class="crm-field">
                    <option value="">{{ __('app.all_locations') }}</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($selectedLocationId === $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
                @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.room') }}</span>
                <select name="room_id" class="crm-field">
                    <option value="">{{ __('app.all_rooms') }}</option>
                    @foreach ($rooms as $room)
                        <option value="{{ $room->id }}" @selected($selectedRoomId === $room->id)>
                            {{ $room->location?->name ? $room->location->name.' - '.$room->name : $room->name }}
                        </option>
                    @endforeach
                </select>
                @error('room_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.trainer') }}</span>
                <select name="trainer_id" class="crm-field">
                    <option value="">{{ __('app.all_trainers') }}</option>
                    @foreach ($trainers as $trainer)
                        <option value="{{ $trainer->id }}" @selected($selectedTrainerId === $trainer->id)>{{ $trainer->name }}</option>
                    @endforeach
                </select>
                @error('trainer_id') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.reports.people-counter', $account)" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('app.class') }}</th>
                        <th class="px-4 py-3">{{ __('app.location') }}</th>
                        <th class="px-4 py-3">{{ __('app.trainer') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.attended') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.detected') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.difference') }}</th>
                        <th class="px-4 py-3">{{ __('app.status') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.samples') }}</th>
                        <th class="px-4 py-3">{{ __('app.screenshots') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 bg-white">
                    @forelse ($classes as $scheduledClass)
                        @php
                            $summary = $scheduledClass->peopleCount;
                            $status = $summary?->status ?? \App\Models\ScheduledClassPeopleCount::StatusInsufficientData;
                            $attended = $summary?->attended_count ?? (int) $scheduledClass->attended_bookings_count;
                            $detected = $summary?->detected_count;
                            $delta = $summary?->delta;
                            $displayTimezone = $scheduledClass->displayTimezone();
                            $startsAt = $scheduledClass->starts_at->copy()->timezone($displayTimezone);
                            $endsAt = $scheduledClass->ends_at->copy()->timezone($displayTimezone);
                            $title = $scheduledClass->displayTitle() ?: ($scheduledClass->classType?->name ?? __('app.class'));
                            $sampleGallery = $scheduledClass->peopleCounterSamples
                                ->flatMap(function (\App\Models\PeopleCounterSample $peopleCounterSample) use ($account, $displayTimezone, $title) {
                                    $capturedAt = $peopleCounterSample->captured_at?->copy()->timezone($displayTimezone);
                                    $sampleMeta = collect([
                                        $capturedAt?->format('d.m.Y H:i'),
                                        $peopleCounterSample->detected_count === null ? null : __('app.detected').': '.$peopleCounterSample->detected_count,
                                        __('app.people_counter_sample_status_'.$peopleCounterSample->status),
                                    ])->filter()->join(' · ');

                                    return collect([
                                        'original' => $peopleCounterSample->original_image_path,
                                        'masked' => $peopleCounterSample->masked_image_path,
                                    ])
                                        ->filter()
                                        ->map(fn (string $path, string $variant): array => [
                                            'url' => route('dashboard.accounts.people-counter-samples.image', [$account, $peopleCounterSample, $variant]),
                                            'thumbnail_url' => route('dashboard.accounts.people-counter-samples.image', [$account, $peopleCounterSample, $variant]),
                                            'title' => $title.' · '.__('app.'.$variant),
                                            'meta' => $sampleMeta,
                                            'alt' => $title.' · '.__('app.'.$variant),
                                        ])
                                        ->values();
                                })
                                ->values();
                            $statusClass = match ($status) {
                                \App\Models\ScheduledClassPeopleCount::StatusMatched => 'crm-status-active',
                                \App\Models\ScheduledClassPeopleCount::StatusMismatch => 'crm-status-danger',
                                \App\Models\ScheduledClassPeopleCount::StatusNoCamera => 'crm-status-muted',
                                default => 'crm-status-scheduled',
                            };
                        @endphp
                        <tr
                            @class([
                                'align-top',
                                'bg-rose-50/60' => $status === \App\Models\ScheduledClassPeopleCount::StatusMismatch,
                            ])
                            data-people-counter-row
                            data-class-counts="{{ $scheduledClass->id }}:{{ $attended }}:{{ $detected ?? 'none' }}:{{ $status }}"
                        >
                            <td class="px-4 py-4">
                                <div class="font-semibold text-slate-950">{{ $title }}</div>
                                <div class="mt-1 whitespace-nowrap text-xs text-slate-500">
                                    {{ $startsAt->format('d.m.Y H:i') }} - {{ $endsAt->format('H:i') }}
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-medium text-slate-800">{{ $scheduledClass->location?->name ?? __('app.not_set') }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $scheduledClass->room?->name ?? __('app.not_set') }}</div>
                            </td>
                            <td class="px-4 py-4 text-slate-700">{{ $scheduledClass->trainer?->name ?? __('app.not_set') }}</td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">{{ $attended }}</td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">{{ $detected ?? '—' }}</td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">
                                {{ $delta === null ? '—' : sprintf('%+d', $delta) }}
                            </td>
                            <td class="px-4 py-4">
                                <span class="{{ $statusClass }}">{{ __('app.people_counter_status_'.$status) }}</span>
                            </td>
                            <td class="px-4 py-4 text-center text-slate-700">
                                {{ $summary?->successful_samples_count ?? 0 }} / {{ $summary?->failed_samples_count ?? 0 }}
                            </td>
                            <td class="px-4 py-4">
                                @if ($sampleGallery->isNotEmpty())
                                    <x-people-counter.screenshot-trigger
                                        :gallery="$sampleGallery->all()"
                                        :label="__('app.open_screenshot_gallery_with_count', ['count' => $sampleGallery->count()])"
                                    />
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8">
                                <x-ui.empty-state :title="__('app.no_people_counter_rows')" icon="video" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>

    @if ($classes->hasPages())
        <div class="mt-6">
            {{ $classes->links() }}
        </div>
    @endif
@endsection
