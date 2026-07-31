@extends('layouts.app')

@section('title', __('app.class_pass_plans').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.class_pass_plans') }}</h1>
            <p class="crm-page-copy">{{ __('app.class_pass_plans_copy') }}</p>
        </div>
        @if ($scheduleKindTabs !== [])
            <x-ui.button :href="route('dashboard.accounts.class-pass-plans.create', [$account, 'tab' => $activeScheduleKindValue, 'segment' => $activeSegmentValue])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_class_pass_plan') }}
            </x-ui.button>
        @endif
    </div>

    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="{{ __('app.class_pass_plans') }}">
        @foreach ($scheduleKindTabs as $scheduleKindValue => $scheduleKindDefinition)
            <a
                href="{{ route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $scheduleKindValue]) }}"
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition {{ $activeScheduleKindValue === $scheduleKindValue ? 'border-violet-crm-600 text-violet-crm-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ __('app.'.$scheduleKindDefinition['title_key']) }}
            </a>
        @endforeach
    </nav>

    @if ($scheduleKindTabs !== [])
    <nav class="mt-4 flex gap-2 overflow-x-auto" aria-label="{{ __('app.class_pass_segments') }}">
        <a
            href="{{ route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $activeScheduleKindValue, 'segment' => 'all']) }}"
            class="inline-flex shrink-0 items-center rounded-lg border px-3 py-2 text-sm font-semibold transition {{ $activeSegmentValue === 'all' ? 'border-violet-crm-600 bg-violet-crm-50 text-violet-crm-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.all_class_pass_segments') }}
        </a>
        <a
            href="{{ route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $activeScheduleKindValue, 'segment' => 'none']) }}"
            class="inline-flex shrink-0 items-center rounded-lg border px-3 py-2 text-sm font-semibold transition {{ $activeSegmentValue === 'none' ? 'border-violet-crm-600 bg-violet-crm-50 text-violet-crm-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950' }}"
        >
            {{ __('app.without_class_pass_segment') }}
        </a>
        @foreach ($classPassSegmentFilters as $classPassSegment)
            <a
                href="{{ route('dashboard.accounts.class-pass-plans.index', [$account, 'tab' => $activeScheduleKindValue, 'segment' => $classPassSegment->id]) }}"
                class="inline-flex shrink-0 items-center rounded-lg border px-3 py-2 text-sm font-semibold transition {{ $activeSegmentValue === (string) $classPassSegment->id ? 'border-violet-crm-600 bg-violet-crm-50 text-violet-crm-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950' }}"
            >
                {{ $classPassSegment->name }}
            </a>
        @endforeach
    </nav>
    @endif

    <div class="mt-6 space-y-6">
        @if ($scheduleKindTabs === [])
            <x-ui.panel padding="none" class="overflow-hidden">
                <x-ui.empty-state :title="__('app.no_class_pass_eligible_formats')" icon="class-pass-plans" class="m-5" />
            </x-ui.panel>
        @else
        @forelse ($classPassPlanGroups as $classPassPlanGroup)
            <x-ui.panel
                padding="none"
                class="overflow-hidden"
                data-class-pass-sort-section
                data-class-pass-sort-group="{{ $classPassPlanGroup['key'] }}"
            >
                <div class="border-b border-stone-100 px-5 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-semibold text-slate-950">{{ $classPassPlanGroup['title'] }}</h2>
                                @if (! $classPassPlanGroup['segment_is_active'])
                                    <span class="crm-status-muted">{{ __('app.inactive') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ __('app.class_pass_plan_order_help') }}</p>
                        </div>
                        <span class="inline-flex w-fit rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">
                            {{ $classPassPlanGroup['plans']->count() }}
                        </span>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('dashboard.accounts.class-pass-plans.reorder', $account) }}"
                        class="mt-3"
                        data-class-pass-reorder-form
                    >
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="schedule_kind" value="{{ $activeScheduleKindValue }}">
                        <input type="hidden" name="class_pass_segment_id" value="{{ $classPassPlanGroup['segment_id'] }}">
                        @foreach ($classPassPlanGroup['plans'] as $classPassPlan)
                            <input type="hidden" name="plan_ids[]" value="{{ $classPassPlan->id }}">
                        @endforeach
                        <p
                            class="hidden"
                            role="status"
                            aria-live="polite"
                            data-async-form-status
                            data-error-message="{{ __('app.class_pass_plan_order_save_failed') }}"
                        ></p>
                    </form>
                </div>

                <div role="list" data-class-pass-sort-list>
                    @foreach ($classPassPlanGroup['plans'] as $classPassPlan)
                        @php
                            $fromTime = $classPassPlan->available_from_time ? substr((string) $classPassPlan->available_from_time, 0, 5) : null;
                            $untilTime = $classPassPlan->available_until_time ? substr((string) $classPassPlan->available_until_time, 0, 5) : null;
                        @endphp
                        <article
                            class="crm-row transition xl:grid-cols-[auto_minmax(10rem,1.2fr)_4.5rem_minmax(5rem,0.65fr)_minmax(6rem,0.8fr)_minmax(5rem,0.85fr)_minmax(5rem,0.85fr)_minmax(5rem,0.8fr)_auto] xl:items-center"
                            role="listitem"
                            data-class-pass-sort-item
                            data-plan-id="{{ $classPassPlan->id }}"
                        >
                            <div class="flex items-center gap-1">
                                <x-ui.action-button
                                    icon="grip-vertical"
                                    :label="__('app.drag_class_pass_to_reorder', ['name' => $classPassPlan->name])"
                                    :disabled="$classPassPlanGroup['plans']->count() < 2"
                                    draggable="{{ $classPassPlanGroup['plans']->count() > 1 ? 'true' : 'false' }}"
                                    class="cursor-grab active:cursor-grabbing"
                                    data-class-pass-sort-control
                                    data-class-pass-sort-handle
                                />
                                <x-ui.action-button
                                    icon="arrow-up"
                                    :label="__('app.move_class_pass_up', ['name' => $classPassPlan->name])"
                                    :disabled="$loop->first"
                                    data-class-pass-sort-control
                                    data-class-pass-sort-up
                                />
                                <x-ui.action-button
                                    icon="arrow-down"
                                    :label="__('app.move_class_pass_down', ['name' => $classPassPlan->name])"
                                    :disabled="$loop->last"
                                    data-class-pass-sort-control
                                    data-class-pass-sort-down
                                />
                            </div>
                            <div>
                                <h3 class="font-semibold text-slate-950">{{ $classPassPlan->name }}</h3>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                    <span>{{ $classPassPlan->slug }}</span>
                                    @if ($classPassPlan->is_trial)
                                        <span class="crm-status-scheduled">{{ __('app.trial_class_pass_short') }}</span>
                                    @endif
                                    @if ($classPassPlan->classPassSegment)
                                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-600">{{ $classPassPlan->classPassSegment->name }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-sm text-slate-600" data-class-pass-sort-order-cell>
                                <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('app.sort_order') }}</div>
                                <div class="mt-1 font-semibold text-slate-950" data-class-pass-sort-order-value>{{ $classPassPlan->sort_order }}</div>
                            </div>
                            <div class="text-sm text-slate-600">
                                <div class="font-semibold text-slate-950">{{ \App\Support\MoneyFormatter::format($classPassPlan->price_cents, $classPassPlan->currency) }}</div>
                                @if ($classPassPlan->allows_any_time && $classPassPlan->any_time_addon_price_cents !== null)
                                    <div class="mt-1 text-xs font-semibold text-violet-crm-700">+ {{ \App\Support\MoneyFormatter::format($classPassPlan->any_time_addon_price_cents, $classPassPlan->currency) }} {{ __('app.any_time_addon_summary') }}</div>
                                @endif
                                <div class="mt-1">{{ $classPassPlan->sessions_count }} {{ __('app.classes_count') }}</div>
                            </div>
                            <div class="text-sm text-slate-600">
                                <div>{{ __('app.validity_after_first_class_short') }}: {{ $classPassPlan->validity_days }} {{ __('app.days') }}</div>
                                <div class="mt-1">{{ __('app.total_validity_short') }}: {{ $classPassPlan->total_validity_days }} {{ __('app.days') }}</div>
                                <div class="mt-1">
                                    @if ($fromTime && $untilTime)
                                        {{ $fromTime }}-{{ $untilTime }}
                                    @elseif ($fromTime)
                                        {{ __('app.from_time', ['time' => $fromTime]) }}
                                    @elseif ($untilTime)
                                        {{ __('app.until_time', ['time' => $untilTime]) }}
                                    @else
                                        {{ __('app.full_day') }}
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($classPassPlan->classTypes as $classType)
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $classType->name }}</span>
                                @empty
                                    <span class="text-sm text-slate-500">{{ __('app.not_set') }}</span>
                                @endforelse
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($classPassPlan->trainerTypes as $trainerType)
                                    <x-ui.trainer-type-badge :trainer-type="$trainerType" />
                                @empty
                                    <span class="text-sm text-slate-500">{{ __('app.not_set') }}</span>
                                @endforelse
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @forelse ($classPassPlan->rooms as $room)
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $room->name }}</span>
                                @empty
                                    <span class="text-sm text-slate-500">{{ __('app.all_rooms') }}</span>
                                @endforelse
                            </div>
                            <div class="flex flex-wrap gap-2 xl:justify-end">
                                <span class="{{ $classPassPlan->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                                    {{ $classPassPlan->is_active ? __('app.active') : __('app.inactive') }}
                                </span>
                                <form method="POST" action="{{ route('dashboard.accounts.class-pass-plans.copy', [$account, $classPassPlan, 'tab' => $activeScheduleKindValue, 'segment' => $activeSegmentValue]) }}">
                                    @csrf
                                    <x-ui.action-button type="submit" icon="copy" :label="__('app.copy')" />
                                </form>
                                <x-ui.action-button :href="route('dashboard.accounts.class-pass-plans.edit', [$account, $classPassPlan])" icon="edit" :label="__('app.edit')" />
                                <form method="POST" action="{{ route('dashboard.accounts.class-pass-plans.destroy', [$account, $classPassPlan, 'tab' => $activeScheduleKindValue, 'segment' => $activeSegmentValue]) }}" data-confirm-delete>
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            </x-ui.panel>
        @empty
            <x-ui.panel padding="none" class="overflow-hidden">
                <x-ui.empty-state :title="__('app.no_class_pass_plans')" icon="class-pass-plans" class="m-5" />
            </x-ui.panel>
        @endforelse
        @endif
    </div>
@endsection
