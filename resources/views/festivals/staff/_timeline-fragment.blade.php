<section
    class="space-y-5"
    data-festival-timeline
    data-timeline-scope="staff"
    data-timeline-fragment-url="{{ $timelineFragmentUrl }}"
    data-csrf-token="{{ csrf_token() }}"
    data-order-error="{{ __('app.festival_timeline_order_error') }}"
    data-action-error="{{ __('app.festival_timeline_action_error') }}"
    @if ($timeline) data-timeline-order-url="{{ route('dashboard.accounts.festivals.timeline.reorder', [$account, $edition, $stage]) }}" @endif
>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-crm sm:p-5">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('app.festival_timeline_control') }}</p>
            <p class="mt-1 text-sm text-slate-700">
                @if (! $timelineView)
                    {{ __('app.festival_timeline_not_prepared') }}
                @elseif ($timelineView['state'] === 'prepared')
                    {{ __('app.festival_timeline_prepared') }}
                @elseif ($timelineView['state'] === 'paused')
                    {{ __('app.festival_timeline_paused_state') }}
                @elseif ($timelineView['state'] === 'completed')
                    {{ __('app.festival_timeline_completed_state') }}
                @elseif ($timelineView['state'] === 'active')
                    {{ $timelineView['next_label'] ? __('app.festival_timeline_switch_to', ['item' => $timelineView['next_label']]) : __('app.festival_timeline_finish_after_current') }}
                @else
                    {{ __('app.festival_timeline_waiting_for', ['item' => $timelineView['next_label']]) }}
                @endif
            </p>
            @if ($timelineView && $timelineView['next_transition_iso'] && ! $timelineView['paused'] && ! $timelineView['completed'])
                <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <time datetime="{{ $timelineView['next_transition_iso'] }}" class="text-sm font-medium text-slate-600">{{ $timelineView['next_transition_local'] }} · {{ $timelineView['timezone'] }}</time>
                    <span class="font-mono text-2xl font-bold tabular-nums text-slate-950" aria-hidden="true" data-timeline-countdown data-timeline-boundary="{{ $timelineView['next_transition_iso'] }}">--:--:--</span>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            @if (! $timeline?->started_at)
                <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.fill', [$account, $edition]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.festival_timeline_fill') }}"
                    data-confirm-body="{{ __('app.festival_timeline_fill_confirmation') }}"
                    data-confirm-accept="{{ __('app.festival_timeline_fill') }}"
                    data-confirm-icon="refresh-cw"
                    data-confirm-variant="danger">
                    @csrf
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                        {{ __('app.festival_timeline_fill') }}
                    </x-ui.button>
                </form>
                @if ($timeline && $edition->status === \App\Enums\FestivalEditionStatus::Published)
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.start', [$account, $edition]) }}"
                        data-confirm-action
                        data-confirm-title="{{ __('app.festival_timeline_start') }}"
                        data-confirm-body="{{ __('app.festival_timeline_start_confirmation') }}"
                        data-confirm-accept="{{ __('app.festival_timeline_start') }}"
                        data-confirm-icon="play"
                        data-confirm-variant="success">
                        @csrf
                        <x-ui.button type="submit" variant="success">
                            <x-ui.icon name="play" class="h-4 w-4" />
                            {{ __('app.festival_timeline_start') }}
                        </x-ui.button>
                    </form>
                @endif
            @elseif (! $timeline->completed_at)
                @if ($timeline->paused_at)
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.resume', [$account, $edition, $stage]) }}" data-timeline-action="resume">
                        @csrf @method('PATCH')
                        <x-ui.button type="submit" variant="success"><x-ui.icon name="play" class="h-4 w-4" />{{ __('app.resume') }}</x-ui.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.pause', [$account, $edition, $stage]) }}" data-timeline-action="pause">
                        @csrf @method('PATCH')
                        <x-ui.button type="submit" variant="secondary"><x-ui.icon name="pause" class="h-4 w-4" />{{ __('app.pause') }}</x-ui.button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    @if ($timelineView)
        <p class="hidden rounded-lg px-3 py-2 text-sm" role="status" aria-live="polite" data-timeline-save-status></p>
        <ol class="relative space-y-3 before:absolute before:bottom-6 before:left-5 before:top-6 before:w-px before:bg-stone-300 sm:before:left-6" data-timeline-list>
            @foreach ($timelineView['items'] as $item)
                @php
                    $cardClasses = match ($item['status']) {
                        'passed' => 'border-emerald-300 bg-emerald-50',
                        'active' => 'border-amber-400 bg-amber-50 ring-2 ring-amber-200',
                        'disabled' => 'border-stone-300 bg-stone-100 opacity-80',
                        default => 'border-rose-300 bg-rose-50',
                    };
                    $badgeClasses = match ($item['status']) {
                        'passed' => 'bg-emerald-700 text-white',
                        'active' => 'bg-amber-500 text-slate-950',
                        'disabled' => 'bg-stone-500 text-white',
                        default => 'bg-rose-700 text-white',
                    };
                    $confirmationDetails = [
                        ['label' => __('app.festival_scene'), 'value' => $timelineView['scene_name']],
                        ['label' => __('app.type'), 'value' => $item['type_label']],
                        ['label' => __('app.event'), 'value' => $item['label']],
                        ['label' => __('app.time'), 'value' => $item['starts_at_local'].' — '.$item['ends_at_local']],
                        ['label' => __('app.duration'), 'value' => $item['duration_label']],
                        ['label' => __('app.notes'), 'value' => filled($item['notes']) ? $item['notes'] : __('app.none')],
                    ];
                @endphp
                <li class="relative grid gap-3 rounded-2xl border p-4 pl-12 shadow-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:p-5 sm:pl-14 {{ $cardClasses }}"
                    draggable="true"
                    data-timeline-item
                    data-item-id="{{ $item['id'] }}"
                    @if ($item['status'] === 'active') aria-current="time" @endif>
                    <span class="absolute left-[0.85rem] top-6 z-10 h-3 w-3 rounded-full bg-white ring-4 ring-current sm:left-[1.1rem]" aria-hidden="true"></span>
                    @if ($item['enabled'])
                        <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.activate', [$account, $edition, $stage, $item['model']]) }}"
                            data-confirm-action
                            data-timeline-action="activate"
                            data-confirm-title="{{ __('app.festival_timeline_start_now') }}"
                            data-confirm-body="{{ __('app.festival_timeline_activate_confirmation') }}"
                            data-confirm-details='@json($confirmationDetails)'
                            data-confirm-accept="{{ __('app.festival_timeline_start_now') }}"
                            data-confirm-icon="clock"
                            data-confirm-variant="success">
                            @csrf @method('PATCH')
                            <button type="submit" class="block min-h-11 w-full rounded-lg text-left crm-focus">
                                <span class="flex flex-wrap items-center gap-2">
                                    <strong class="text-base text-slate-950 sm:text-lg">{{ $item['label'] }}</strong>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $badgeClasses }}">{{ __('app.festival_timeline_status_'.$item['status']) }}</span>
                                </span>
                                <span class="mt-2 block text-sm text-slate-700">
                                    <time datetime="{{ $item['starts_at_iso'] }}">{{ $item['starts_at_local'] }}</time>
                                    —
                                    <time datetime="{{ $item['ends_at_iso'] }}">{{ $item['ends_at_local'] }}</time>
                                    · {{ $item['duration_label'] }}
                                </span>
                                <span class="mt-1 block text-sm text-slate-600">{{ $item['type_label'] }}@if ($item['entry_reference']) · {{ $item['entry_reference'] }}@endif</span>
                                @if ($item['notes'])<span class="mt-2 block whitespace-pre-line text-sm text-slate-700">{{ $item['notes'] }}</span>@endif
                            </button>
                        </form>
                    @else
                        <div>
                            <span class="flex flex-wrap items-center gap-2">
                                <strong class="text-base text-slate-700 sm:text-lg">{{ $item['label'] }}</strong>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $badgeClasses }}">{{ __('app.festival_timeline_status_disabled') }}</span>
                            </span>
                            <span class="mt-2 block text-sm text-slate-600">{{ $item['starts_at_local'] }} — {{ $item['ends_at_local'] }} · {{ $item['duration_label'] }}</span>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-1 sm:justify-end">
                        @if ($item['status'] === 'active' && $timeline->started_at && ! $timeline->completed_at)
                            <form method="POST"
                                action="{{ route($timelineView['paused'] ? 'dashboard.accounts.festivals.timeline.resume' : 'dashboard.accounts.festivals.timeline.pause', [$account, $edition, $stage]) }}"
                                data-timeline-current-control
                                data-timeline-action="{{ $timelineView['paused'] ? 'resume' : 'pause' }}">
                                @csrf @method('PATCH')
                                <x-ui.button type="submit" :variant="$timelineView['paused'] ? 'success' : 'secondary'" size="sm">
                                    <x-ui.icon :name="$timelineView['paused'] ? 'play' : 'pause'" class="h-4 w-4" />
                                    {{ $timelineView['paused'] ? __('app.resume') : __('app.pause') }}
                                </x-ui.button>
                            </form>
                        @endif
                        <x-ui.action-button icon="grip-vertical" :label="__('app.reorder')" data-timeline-drag-handle />
                        <x-ui.action-button icon="arrow-up" :label="__('app.move_up')" :disabled="$loop->first" data-timeline-move="up" />
                        <x-ui.action-button icon="arrow-down" :label="__('app.move_down')" :disabled="$loop->last" data-timeline-move="down" />
                        <form method="POST" action="{{ route('dashboard.accounts.festivals.timeline.toggle', [$account, $edition, $stage, $item['model']]) }}" data-timeline-action="toggle">
                            @csrf @method('PATCH')
                            <x-ui.action-button type="submit" :icon="$item['enabled'] ? 'eye-off' : 'eye'" :label="$item['enabled'] ? __('app.disable') : __('app.enable')" />
                        </form>
                    </div>
                </li>
            @endforeach
        </ol>
    @else
        <x-ui.empty-state :title="__('app.festival_timeline_not_prepared')" icon="timer">
            <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_timeline_fill_help') }}</p>
        </x-ui.empty-state>
    @endif
</section>
