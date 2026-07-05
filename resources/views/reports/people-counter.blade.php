@extends('layouts.app')

@section('title', __('app.people_counter_report_title').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.people_counter_report_title') }}</h1>
            <p class="crm-page-copy">{{ __('app.people_counter_report_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.reports.index', $account)" variant="secondary">
            {{ __('app.reports') }}
        </x-ui.button>
    </div>

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
                            $sample = $scheduledClass->latestSuccessfulPeopleCounterSample;
                            $status = $summary?->status ?? \App\Models\ScheduledClassPeopleCount::StatusInsufficientData;
                            $attended = $summary?->attended_count ?? (int) $scheduledClass->attended_bookings_count;
                            $detected = $summary?->detected_count;
                            $delta = $summary?->delta;
                            $displayTimezone = $scheduledClass->displayTimezone();
                            $startsAt = $scheduledClass->starts_at->copy()->timezone($displayTimezone);
                            $endsAt = $scheduledClass->ends_at->copy()->timezone($displayTimezone);
                            $title = $scheduledClass->displayTitle() ?: ($scheduledClass->classType?->name ?? __('app.class'));
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
                                @if ($sample)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($sample->original_image_path)
                                            <a class="text-sm font-semibold text-brand-700 hover:text-brand-800" href="{{ route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'original']) }}" target="_blank" rel="noopener">
                                                {{ __('app.original') }}
                                            </a>
                                        @endif
                                        @if ($sample->masked_image_path)
                                            <a class="text-sm font-semibold text-brand-700 hover:text-brand-800" href="{{ route('dashboard.accounts.people-counter-samples.image', [$account, $sample, 'masked']) }}" target="_blank" rel="noopener">
                                                {{ __('app.masked') }}
                                            </a>
                                        @endif
                                    </div>
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
