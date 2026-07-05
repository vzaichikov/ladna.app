@extends('layouts.app')

@section('title', __('app.unknown_presence_report_title').' - '.$account->name)

@section('content')
    @php
        $selectedDate = $filters['date'];
        $selectedLocationId = $filters['location_id'];
        $selectedRoomId = $filters['room_id'];
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.unknown_presence_report_title') }}</h1>
            <p class="crm-page-copy">{{ __('app.unknown_presence_report_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
            {{ __('app.reports') }}
        </x-ui.button>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.reports.unknown-presence', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="grid gap-4 md:grid-cols-3">
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
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-ui.button type="submit" size="sm">
                <x-ui.icon name="search" class="h-4 w-4" />
                {{ __('app.apply_filters') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.reports.unknown-presence', $account)" variant="secondary" size="sm">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ __('app.interval') }}</th>
                        <th class="px-4 py-3">{{ __('app.location') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.duration') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.peak_detected') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('app.samples') }}</th>
                        <th class="px-4 py-3">{{ __('app.screenshots') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100 bg-white">
                    @forelse ($intervals as $interval)
                        @php
                            $displayTimezone = $interval->location?->timezone ?: ($account->timezone ?: config('app.timezone'));
                            $startedAt = $interval->started_at->copy()->timezone($displayTimezone);
                            $endedAt = $interval->ended_at->copy()->timezone($displayTimezone);
                            $durationMinutes = max(0, (int) $interval->started_at->diffInMinutes($interval->ended_at));
                            $intervalLabel = $startedAt->isSameDay($endedAt)
                                ? $startedAt->format('d.m.Y H:i').' - '.$endedAt->format('H:i')
                                : $startedAt->format('d.m.Y H:i').' - '.$endedAt->format('d.m.Y H:i');
                            $sampleGallery = $interval->samples
                                ->filter(fn (\App\Models\PeopleCounterSample $peopleCounterSample): bool => filled($peopleCounterSample->original_image_path))
                                ->map(function (\App\Models\PeopleCounterSample $peopleCounterSample) use ($account, $displayTimezone, $intervalLabel): array {
                                    $capturedAt = $peopleCounterSample->captured_at?->copy()->timezone($displayTimezone);
                                    $sampleMeta = collect([
                                        $capturedAt?->format('d.m.Y H:i'),
                                        $peopleCounterSample->detected_count === null ? null : __('app.detected').': '.$peopleCounterSample->detected_count,
                                    ])->filter()->join(' · ');
                                    $imageUrl = route('dashboard.accounts.people-counter-samples.image', [$account, $peopleCounterSample, 'original']);

                                    return [
                                        'url' => $imageUrl,
                                        'thumbnail_url' => $imageUrl,
                                        'title' => __('app.unknown_presence_report_title').' · '.$intervalLabel,
                                        'meta' => $sampleMeta,
                                        'alt' => __('app.unknown_presence_report_title').' · '.$intervalLabel,
                                    ];
                                })
                                ->values();
                        @endphp
                        <tr
                            class="align-top"
                            data-unknown-presence-row="{{ $interval->id }}:{{ $interval->sample_count }}:{{ $interval->peak_detected_count }}"
                        >
                            <td class="px-4 py-4">
                                <div class="font-semibold text-slate-950">{{ $intervalLabel }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $displayTimezone }}</div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="font-medium text-slate-800">{{ $interval->location?->name ?? __('app.not_set') }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $interval->room?->name ?? __('app.not_set') }}</div>
                            </td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">
                                {{ $durationMinutes }} {{ __('app.minutes') }}
                            </td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">{{ $interval->peak_detected_count }}</td>
                            <td class="px-4 py-4 text-center font-semibold text-slate-950">{{ $interval->sample_count }}</td>
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
                            <td colspan="6" class="px-4 py-8">
                                <x-ui.empty-state :title="__('app.no_unknown_presence_rows')" icon="video" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.panel>

    @if ($intervals->hasPages())
        <div class="mt-6">
            {{ $intervals->links() }}
        </div>
    @endif
@endsection
