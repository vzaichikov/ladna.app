@php
    $firstClass = $classes->first();
    $day = \Illuminate\Support\Carbon::parse($date, $firstClass?->displayTimezone() ?? config('app.timezone'));
    $timelineStartHour = max(0, $classes->map(fn ($scheduledClass) => $scheduledClass->starts_at->copy()->timezone($scheduledClass->displayTimezone())->hour)->min() - 1);
    $timelineEndHour = min(24, $classes->map(function ($scheduledClass): int {
        $endsAt = $scheduledClass->ends_at->copy()->timezone($scheduledClass->displayTimezone());

        return $endsAt->minute > 0 || $endsAt->second > 0 ? $endsAt->hour + 1 : $endsAt->hour;
    })->max() + 1);

    if ($timelineEndHour <= $timelineStartHour) {
        $timelineEndHour = min(24, $timelineStartHour + 2);
    }

    $timelineHours = range($timelineStartHour, $timelineEndHour);
    $timelineTotalMinutes = max(60, ($timelineEndHour - $timelineStartHour) * 60);
@endphp

<section data-scheduled-class-day="{{ $date }}">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <h2 class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-800">
            {{ $day->translatedFormat('l, j F') }}
        </h2>
        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
            {{ $classes->count() }}
        </span>
    </div>

    <div class="mb-5 overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="min-w-[720px]">
            <div class="flex justify-between text-[11px] font-semibold text-slate-500">
                @foreach ($timelineHours as $hour)
                    <span>{{ sprintf('%02d:00', $hour) }}</span>
                @endforeach
            </div>
            <div class="relative mt-2 h-24 rounded-lg border border-stone-200 bg-slate-50">
                <div class="absolute left-0 right-0 top-1/2 h-px bg-stone-300"></div>
                @foreach ($timelineHours as $hour)
                    @php
                        $tickLeft = (($hour - $timelineStartHour) * 60 / $timelineTotalMinutes) * 100;
                    @endphp
                    <span class="absolute inset-y-0 w-px bg-stone-200" style="left: {{ number_format($tickLeft, 4, '.', '') }}%"></span>
                @endforeach

                @foreach ($classes as $scheduledClass)
                    @php
                        $timelineTimezone = $scheduledClass->displayTimezone();
                        $timelineStartsAt = \Illuminate\Support\Carbon::parse($date, $timelineTimezone)->setTime($timelineStartHour, 0);
                        $startsAt = $scheduledClass->starts_at->copy()->timezone($timelineTimezone);
                        $endsAt = $scheduledClass->ends_at->copy()->timezone($timelineTimezone);
                        $offsetMinutes = max(0, (int) round(($startsAt->getTimestamp() - $timelineStartsAt->getTimestamp()) / 60));
                        $durationMinutes = max(15, (int) $startsAt->diffInMinutes($endsAt));
                        $leftPercent = min(100, max(0, ($offsetMinutes / $timelineTotalMinutes) * 100));
                        $widthPercent = min(100 - $leftPercent, max(6, ($durationMinutes / $timelineTotalMinutes) * 100));
                        $timelineTop = 14 + ($loop->index % 2) * 34;
                        $activityDirection = $scheduledClass->classType?->activityDirection;
                        $timelineColor = $activityDirection?->colorAccent() ?? '#3B223F';
                        $timelineTextColor = $activityDirection?->colorText() ?? '#FFFFFF';
                    @endphp
                    <a
                        href="#scheduled-class-{{ $scheduledClass->id }}"
                        class="absolute flex h-8 items-center gap-2 overflow-hidden rounded-lg border px-2 text-xs font-semibold shadow-sm transition hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                        style="left: {{ number_format($leftPercent, 4, '.', '') }}%; width: {{ number_format($widthPercent, 4, '.', '') }}%; top: {{ $timelineTop }}px; background-color: {{ $timelineColor }}; border-color: {{ $timelineColor }}; color: {{ $timelineTextColor }};"
                        title="{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }} · {{ $scheduledClass->title }}"
                    >
                        <span class="shrink-0">{{ $startsAt->format('H:i') }}</span>
                        <span class="truncate">{{ $scheduledClass->title }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        @foreach ($classes as $scheduledClass)
            @include('scheduled-classes._card', [
                'account' => $account,
                'scheduledClass' => $scheduledClass,
                'customerSearchUrl' => $customerSearchUrl,
                'bookingStatuses' => $bookingStatuses,
            ])
        @endforeach
    </div>
</section>
