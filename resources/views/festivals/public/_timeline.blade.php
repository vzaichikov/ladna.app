@if ($timelineWithinDates)
    <div
        @if ($publicTimelineViews->isNotEmpty()) data-festival-timeline @else data-festival-timeline-poller @endif
        data-timeline-scope="public"
        data-timeline-fragment-url="{{ $timelinePollingUrl }}"
        class="festival-live-timeline"
    >
        @if ($publicTimelineViews->isNotEmpty())
            <section class="festival-surface festival-border rounded-3xl border p-5 shadow-xl sm:p-7" aria-labelledby="festival-live-timeline-title">
                <div class="mb-6">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] festival-muted">{{ __('app.festival_timeline_live_eyebrow') }}</p>
                    <h2 id="festival-live-timeline-title" class="mt-2 text-2xl font-bold sm:text-3xl">{{ __('app.festival_timeline_public_title') }}</h2>
                </div>

                <div class="space-y-8">
                    @foreach ($publicTimelineViews as $timelineView)
                        <section data-timeline-scene="{{ $timelineView['stage_id'] }}" data-timeline-state="{{ $timelineView['state'] }}" aria-labelledby="festival-live-scene-{{ $timelineView['stage_id'] }}">
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <h3 id="festival-live-scene-{{ $timelineView['stage_id'] }}" class="text-xl font-bold">{{ $timelineView['scene_name'] }}</h3>
                                    <p class="mt-1 text-sm festival-muted">
                                        @if ($timelineView['state'] === 'paused')
                                            {{ __('app.festival_timeline_paused_state') }}
                                        @elseif ($timelineView['state'] === 'completed')
                                            {{ __('app.festival_timeline_completed_state') }}
                                        @elseif ($timelineView['state'] === 'active')
                                            {{ $timelineView['next_label'] ? __('app.festival_timeline_switch_to', ['item' => $timelineView['next_label']]) : __('app.festival_timeline_finish_after_current') }}
                                        @else
                                            {{ __('app.festival_timeline_waiting_for', ['item' => $timelineView['next_label']]) }}
                                        @endif
                                    </p>
                                </div>
                                @if ($timelineView['next_transition_iso'] && ! $timelineView['paused'] && ! $timelineView['completed'])
                                    <div class="text-right">
                                        <time datetime="{{ $timelineView['next_transition_iso'] }}" class="block text-xs festival-muted">{{ $timelineView['next_transition_local'] }}</time>
                                        <span class="mt-1 block font-mono text-xl font-bold tabular-nums" aria-hidden="true" data-timeline-countdown data-timeline-boundary="{{ $timelineView['next_transition_iso'] }}">--:--:--</span>
                                    </div>
                                @endif
                            </div>

                            <ol class="mt-4 grid gap-3 lg:grid-cols-2">
                                @foreach ($timelineView['items'] as $item)
                                    @php
                                        $publicCardClasses = match ($item['status']) {
                                            'passed' => 'border-emerald-300 bg-emerald-50 text-emerald-950',
                                            'active' => 'border-amber-400 bg-amber-50 text-amber-950 ring-2 ring-amber-200',
                                            default => 'border-rose-300 bg-rose-50 text-rose-950',
                                        };
                                    @endphp
                                    <li class="rounded-2xl border p-4 {{ $publicCardClasses }}" @if ($item['status'] === 'active') aria-current="time" @endif>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <strong>{{ $item['label'] }}</strong>
                                            <span class="rounded-full bg-white/75 px-2.5 py-1 text-xs font-bold">{{ __('app.festival_timeline_status_'.$item['status']) }}</span>
                                        </div>
                                        <p class="mt-2 text-sm">
                                            <time datetime="{{ $item['starts_at_iso'] }}">{{ $item['starts_at_local'] }}</time>
                                            —
                                            <time datetime="{{ $item['ends_at_iso'] }}">{{ $item['ends_at_local'] }}</time>
                                        </p>
                                        <p class="mt-1 text-sm opacity-80">{{ $item['type_label'] }} · {{ $item['duration_label'] }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endif
