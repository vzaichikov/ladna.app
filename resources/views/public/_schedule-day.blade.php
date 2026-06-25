@php
    $firstClass = $classes->first();
    $timezone = $firstClass?->displayTimezone() ?? $location->timezone ?? $account->timezone ?? config('app.timezone');
    $day = \Illuminate\Support\Carbon::parse($date, $timezone);
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
    $timelineHeight = max(112, 28 + ($classes->count() * 88));
@endphp

<section id="schedule-day-{{ $date }}" class="scroll-mt-24" data-public-schedule-day="{{ $date }}">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <h2 class="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-800">
            {{ $day->translatedFormat('l, j F') }}
        </h2>
        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
            {{ trans_choice('app.classes_count_label', $classes->count(), ['count' => $classes->count()]) }}
        </span>
    </div>

    <div class="mb-5 space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-xs sm:hidden">
        @foreach ($classes as $scheduledClass)
            @php
                $mobileTimezone = $scheduledClass->displayTimezone();
                $startsAt = $scheduledClass->starts_at->copy()->timezone($mobileTimezone);
                $endsAt = $scheduledClass->ends_at->copy()->timezone($mobileTimezone);
                $scheduleKind = $scheduledClass->classType?->schedule_kind;
                $direction = $scheduledClass->classType?->activityDirection?->name;
                $timelineColor = $scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F';
                $formatColor = $account->scheduleKindColor($scheduleKind);
                $formatTextColor = $account->scheduleKindTextColor($scheduleKind);
            @endphp
            <a href="#scheduled-class-{{ $scheduledClass->id }}" class="block rounded-lg border border-stone-200 bg-slate-50 p-3 shadow-xs" style="border-left-color: {{ $timelineColor }}; border-left-width: 5px;">
                <span class="text-xs font-semibold text-brand-700">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</span>
                <span class="mt-1 block text-base font-semibold leading-snug text-slate-950">{{ $scheduledClass->title }}</span>
                <span class="mt-1 block text-sm leading-snug text-slate-600">
                    {{ $direction ? $direction.' · ' : '' }}{{ $scheduledClass->classType?->name ?? __('app.class_type') }}
                </span>
                @if ($scheduleKind)
                    <span class="mt-2 inline-flex rounded-md px-2 py-1 text-xs font-semibold" style="background-color: {{ $formatColor }}; color: {{ $formatTextColor }};">
                        {{ __('app.'.$scheduleKind->value) }}
                    </span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="mb-5 hidden overflow-x-auto rounded-xl border border-stone-200 bg-white p-4 shadow-xs sm:block">
        <div class="min-w-[840px]">
            <div class="flex justify-between text-[11px] font-semibold text-slate-500">
                @foreach ($timelineHours as $hour)
                    <span>{{ sprintf('%02d:00', $hour) }}</span>
                @endforeach
            </div>
            <div class="relative mt-2 rounded-lg border border-stone-200 bg-slate-50" style="height: {{ $timelineHeight }}px;">
                <div class="absolute left-0 right-0 top-4 h-px bg-stone-300"></div>
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
                        $widthPercent = min(100 - $leftPercent, max(14, ($durationMinutes / $timelineTotalMinutes) * 100));
                        $timelineTop = 18 + ($loop->index * 88);
                        $scheduleKind = $scheduledClass->classType?->schedule_kind;
                        $direction = $scheduledClass->classType?->activityDirection?->name;
                        $timelineColor = $scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F';
                        $timelineTextColor = $scheduledClass->classType?->activityDirection?->colorText('#3B223F') ?? '#FFFFFF';
                        $timelineKindColor = $account->scheduleKindColor($scheduleKind);
                    @endphp
                    <a
                        href="#scheduled-class-{{ $scheduledClass->id }}"
                        class="absolute flex min-h-16 min-w-60 flex-col justify-center gap-1 whitespace-normal break-words rounded-lg border px-3 py-2 text-left shadow-sm transition hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2"
                        style="left: {{ number_format($leftPercent, 4, '.', '') }}%; width: {{ number_format($widthPercent, 4, '.', '') }}%; top: {{ $timelineTop }}px; background-color: {{ $timelineColor }}; border-color: {{ $timelineColor }}; border-right-color: {{ $timelineKindColor }}; border-right-width: 5px; color: {{ $timelineTextColor }};"
                    >
                        <span class="text-[11px] font-semibold opacity-90">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</span>
                        <span class="text-sm font-semibold leading-snug">{{ $scheduledClass->title }}</span>
                        <span class="text-[11px] font-semibold leading-snug opacity-90">
                            {{ $direction ? $direction.' · ' : '' }}{{ $scheduledClass->classType?->name ?? __('app.class_type') }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach ($classes as $scheduledClass)
            @php
                $cardTimezone = $scheduledClass->displayTimezone();
                $startsAt = $scheduledClass->starts_at->copy()->timezone($cardTimezone);
                $endsAt = $scheduledClass->ends_at->copy()->timezone($cardTimezone);
                $capacity = (int) ($scheduledClass->capacity ?? 0);
                $activeBookingsCount = (int) ($scheduledClass->active_bookings_count ?? 0);
                $availableSpots = max(0, $capacity - $activeBookingsCount);
                $isFull = $capacity <= 0 || $availableSpots < 1;
                $isBookingOpen = $scheduledClass->isBookingOpen();
                $canBook = $isBookingOpen && ! $isFull;
                $scheduleKind = $scheduledClass->classType?->schedule_kind;
                $directionColor = $scheduledClass->classType?->activityDirection?->colorAccent('#3B223F') ?? '#3B223F';
                $formatColor = $account->scheduleKindColor($scheduleKind);
                $formatTextColor = $account->scheduleKindTextColor($scheduleKind);
            @endphp

            <article id="scheduled-class-{{ $scheduledClass->id }}" class="scroll-mt-24 rounded-xl border border-slate-200 bg-white p-5 shadow-crm" style="border-top-color: {{ $directionColor }}; border-top-width: 4px; border-right-color: {{ $formatColor }}; border-right-width: 4px;">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-brand-600">{{ $startsAt->format('H:i') }} - {{ $endsAt->format('H:i') }}</div>
                        <h3 class="mt-2 text-2xl font-semibold leading-tight text-slate-950">{{ $scheduledClass->title }}</h3>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($scheduledClass->classType?->activityDirection)
                                <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">{{ $scheduledClass->classType->activityDirection->name }}</span>
                            @endif
                            <span class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">{{ $scheduledClass->classType?->name ?? __('app.class_type') }}</span>
                            @if ($scheduleKind)
                                <span class="rounded-md px-2 py-1 text-xs font-semibold" style="background-color: {{ $formatColor }}; color: {{ $formatTextColor }};">
                                    {{ __('app.'.$scheduleKind->value) }}
                                </span>
                            @endif
                        </div>
                        @if ($scheduledClass->description)
                            <p class="mt-3 text-sm leading-6 text-slate-500">{{ $scheduledClass->description }}</p>
                        @endif
                    </div>
                    <div class="rounded-xl bg-ink-950 px-4 py-3 text-center text-white">
                        <div class="text-xl font-semibold">{{ $startsAt->format('H:i') }}</div>
                        <div class="text-xs text-slate-300">{{ $scheduledClass->durationMinutes() }} {{ __('app.minutes') }}</div>
                    </div>
                </div>

                <dl class="mt-5 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.trainer') }}</dt>
                        <dd class="mt-1 flex items-center gap-2 font-semibold text-slate-950">
                            @if ($scheduledClass->trainer?->photoUrl())
                                <img src="{{ $scheduledClass->trainer->photoUrl() }}" alt="" class="h-7 w-7 rounded-full object-cover">
                            @endif
                            <span>{{ $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned') }}</span>
                        </dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.room') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $scheduledClass->room?->name ?? $location->name }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.capacity') }}</dt>
                        <dd class="mt-1 font-semibold text-slate-950">{{ $capacity > 0 ? $capacity : __('app.capacity_not_set') }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="text-slate-500">{{ __('app.available_slots') }}</dt>
                        <dd class="mt-1 font-semibold {{ $isFull ? 'text-rose-700' : 'text-emerald-700' }}">{{ $capacity > 0 ? $availableSpots : __('app.not_set') }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    @if ($canBook)
                        <p class="text-sm text-slate-500">{{ __('app.book_stub') }}</p>
                        <x-ui.button :href="$customer ? route('customer.dashboard', $account->slug) : route('customer.studio.login', $account->slug)" variant="brand">
                            {{ __('app.book') }}
                        </x-ui.button>
                    @elseif ($isFull)
                        <p class="text-sm font-semibold text-rose-700">{{ __('app.no_available_group_slots') }}</p>
                        <x-ui.button type="button" variant="secondary" disabled>
                            {{ __('app.booking_full') }}
                        </x-ui.button>
                    @else
                        <p class="text-sm font-semibold text-amber-700">{{ __('app.booking_cutoff_closed') }}</p>
                        <x-ui.button type="button" variant="secondary" disabled>
                            {{ __('app.booking_closed') }}
                        </x-ui.button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
</section>
