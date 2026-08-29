<div
    class="space-y-6"
    data-festival-judge-list
    data-fragment-url="{{ $fragmentUrl }}"
    data-refresh-seconds="5"
    data-refresh-error="{{ __('app.festival_judge_list_refresh_error') }}"
>
    <section class="overflow-hidden rounded-2xl border border-violet-crm-200 bg-linear-to-br from-violet-crm-50 via-white to-rose-50 shadow-crm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-violet-crm-100 px-5 py-4">
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_current_performance') }}</h2>
            <div class="flex items-center gap-2 rounded-full border border-violet-crm-200 bg-white px-3 py-1.5 text-xs font-semibold text-violet-crm-700">
                <x-ui.icon name="refresh-cw" class="h-3.5 w-3.5" />
                <span>{{ __('app.festival_judge_list_refresh_in') }}</span>
                <span data-judge-refresh-countdown>5</span>
            </div>
        </div>

        <div class="grid gap-3 p-4 lg:grid-cols-2">
            @forelse ($liveScenes as $scene)
                <article class="rounded-xl border border-white/80 bg-white/90 p-4 shadow-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-slate-950">{{ $scene['scene_name'] }}</h3>
                        @if ($scene['paused'])
                            <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">{{ __('app.festival_timeline_paused') }}</span>
                        @elseif ($scene['next_transition_iso'])
                            <span class="rounded-full bg-slate-950 px-2.5 py-1 font-mono text-xs font-semibold text-white" data-judge-timeline-countdown data-boundary="{{ $scene['next_transition_iso'] }}">--:--:--</span>
                        @endif
                    </div>
                    <div class="mt-3 flex items-start gap-3">
                        <span class="mt-1 block h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-rose-500"></span>
                        <div class="min-w-0">
                            @if ($scene['current_performances']->isNotEmpty())
                                @foreach ($scene['current_performances'] as $performance)
                                    @if ($performance['sheet'])
                                        <a
                                            href="{{ $guest ? route('festival.portal.judging.edit', [$account->slug, $performance['sheet']]) : route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $performance['sheet']]) }}"
                                            class="inline-flex max-w-full items-center gap-2 text-lg font-bold text-rose-700 underline decoration-rose-300 underline-offset-4 hover:text-rose-900"
                                            data-current-performance-link
                                        >
                                            <span class="truncate">{{ $performance['label'] }}</span>
                                            <x-ui.icon name="arrow-up-right" class="h-4 w-4 shrink-0" />
                                        </a>
                                    @else
                                        <p class="truncate text-lg font-bold text-rose-700">{{ $performance['label'] }}</p>
                                    @endif
                                @endforeach
                            @else
                                <p class="font-semibold text-slate-500">{{ __('app.festival_nobody_dancing_now') }}</p>
                            @endif
                            <p class="mt-2 text-sm text-slate-600">
                                <span class="font-semibold text-amber-700">{{ __('app.festival_next_performance') }}:</span>
                                {{ $scene['next_label'] ?: __('app.not_set') }}
                            </p>
                        </div>
                    </div>
                </article>
            @empty
                <p class="p-2 text-sm font-medium text-slate-600 lg:col-span-2">{{ __('app.festival_nobody_dancing_now') }}</p>
            @endforelse
        </div>
        <p class="hidden border-t border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700" data-judge-list-error role="status"></p>
    </section>

    <div class="space-y-7">
        @forelse ($judgeGroups as $group)
            <section @class([
                'rounded-2xl border p-4 shadow-xs sm:p-5',
                'border-violet-crm-300 bg-violet-crm-50/70 ring-2 ring-violet-crm-100' => $group['active'],
                'border-stone-200 bg-white' => ! $group['active'],
            ])>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        @if ($group['category']->direction)
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ $group['category']->direction->name }}</p>
                        @endif
                        <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ $group['category']->name }}</h2>
                    </div>
                    @if ($group['active'])
                        <span class="inline-flex items-center gap-2 rounded-full bg-violet-crm-700 px-3 py-1.5 text-xs font-bold text-white">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-white"></span>
                            {{ __('app.festival_active_category') }}
                        </span>
                    @endif
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($group['cards'] as $cardIndex => $card)
                        @php
                            $sheet = $card['sheet'];
                            $timelineStatus = $card['timeline_status'];
                            $editUrl = $guest
                                ? route('festival.portal.judging.edit', [$account->slug, $sheet])
                                : route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $sheet]);
                        @endphp
                        <a
                            href="{{ $editUrl }}"
                            data-judge-sheet-card
                            data-sheet-id="{{ $sheet->id }}"
                            @class([
                                'group relative overflow-hidden rounded-xl border p-4 transition hover:-translate-y-0.5 hover:shadow-md',
                                'border-rose-300 bg-rose-50 ring-2 ring-rose-100' => $timelineStatus === 'active',
                                'border-amber-300 bg-amber-50' => $timelineStatus === 'next',
                                'border-slate-200 bg-slate-100/80 text-slate-600' => $timelineStatus === 'passed',
                                'border-stone-200 bg-white' => $timelineStatus === 'future' && $cardIndex % 2 === 0,
                                'border-violet-crm-100 bg-violet-crm-50/40' => $timelineStatus === 'future' && $cardIndex % 2 === 1,
                            ])
                        >
                            <span @class([
                                'absolute inset-y-0 left-0 w-1.5',
                                'bg-rose-500' => $timelineStatus === 'active',
                                'bg-amber-400' => $timelineStatus === 'next',
                                'bg-slate-300' => $timelineStatus === 'passed',
                                'bg-violet-crm-300' => $timelineStatus === 'future',
                            ])></span>
                            <div class="flex gap-4 pl-1">
                                @if ($card['photo_participants']->isNotEmpty())
                                    <div class="flex shrink-0 -space-x-3">
                                        @foreach ($card['photo_participants']->take(3) as $participant)
                                            <img
                                                src="{{ $guest ? route('festival.portal.judging.participants.photo', [$account->slug, $sheet, $participant]) : route('dashboard.accounts.festivals.judging.score-sheets.participants.photo', [$account, $edition, $sheet, $participant]) }}"
                                                alt="{{ $participant->displayName() }}"
                                                class="h-14 w-14 rounded-xl border-2 border-white object-cover shadow-sm"
                                                loading="lazy"
                                            >
                                        @endforeach
                                    </div>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <h3 class="min-w-0 flex-1 truncate text-lg font-bold text-slate-950 group-hover:text-violet-crm-800">{{ $sheet->entry->entry_name }}</h3>
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-xs font-bold',
                                            'bg-rose-600 text-white' => $timelineStatus === 'active',
                                            'bg-amber-200 text-amber-900' => $timelineStatus === 'next',
                                            'bg-slate-200 text-slate-600' => $timelineStatus === 'passed',
                                            'bg-white text-slate-600 shadow-xs' => $timelineStatus === 'future',
                                        ])>{{ __('app.festival_timeline_status_'.$timelineStatus) }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-sm text-slate-600">{{ $sheet->entry->participants->map->displayName()->join(', ') }}</p>
                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                        @if ($card['progress']['ready'])
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                                <x-ui.icon name="circle-check" class="h-3.5 w-3.5" />
                                                {{ __('app.festival_score_ready') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-800">
                                                <x-ui.icon name="circle-alert" class="h-3.5 w-3.5" />
                                                {{ trans_choice('app.festival_scores_missing', $card['progress']['missing'], ['count' => $card['progress']['missing']]) }}
                                            </span>
                                        @endif
                                        <span class="text-xs font-semibold text-slate-500">{{ __('app.festival_score_progress', ['completed' => $card['progress']['completed'], 'required' => $card['progress']['required']]) }}</span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <x-ui.empty-state icon="clipboard-check">{{ __('app.festival_score_sheets_empty') }}</x-ui.empty-state>
        @endforelse
    </div>

    @if ($sheets instanceof \Illuminate\Pagination\AbstractPaginator)
        <div>{{ $sheets->links() }}</div>
    @endif
</div>
