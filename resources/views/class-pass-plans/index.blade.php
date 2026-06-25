@extends('layouts.app')

@section('title', __('app.class_pass_plans').' - '.$account->name)

@section('content')
    @php
        $formatMoney = static function (?int $priceCents): string {
            if ($priceCents === null) {
                return '';
            }

            return number_format($priceCents / 100, $priceCents % 100 === 0 ? 0 : 2, '.', ' ');
        };
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.class_pass_plans') }}</h1>
            <p class="crm-page-copy">{{ __('app.class_pass_plans_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.class-pass-plans.create', [$account, 'tab' => $activeScheduleKindValue])">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_class_pass_plan') }}
        </x-ui.button>
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

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($classPassPlans as $classPassPlan)
            @php
                $fromTime = $classPassPlan->available_from_time ? substr((string) $classPassPlan->available_from_time, 0, 5) : null;
                $untilTime = $classPassPlan->available_until_time ? substr((string) $classPassPlan->available_until_time, 0, 5) : null;
            @endphp
            <div class="crm-row xl:grid-cols-[1.1fr_0.7fr_0.8fr_1.1fr_1.1fr_1fr_auto] xl:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $classPassPlan->name }}</h2>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-slate-500">
                        <span>{{ $classPassPlan->slug }}</span>
                        @if ($classPassPlan->is_trial)
                            <span class="crm-status-scheduled">{{ __('app.trial_class_pass_short') }}</span>
                        @endif
                    </div>
                </div>
                <div class="text-sm text-slate-600">
                    <div class="font-semibold text-slate-950">{{ $formatMoney($classPassPlan->price_cents) }} {{ $classPassPlan->currency }}</div>
                    @if ($classPassPlan->allows_any_time && $classPassPlan->any_time_addon_price_cents !== null)
                        <div class="mt-1 text-xs font-semibold text-violet-crm-700">+ {{ $formatMoney($classPassPlan->any_time_addon_price_cents) }} {{ $classPassPlan->currency }} {{ __('app.any_time_addon_summary') }}</div>
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
                    <form method="POST" action="{{ route('dashboard.accounts.class-pass-plans.copy', [$account, $classPassPlan, 'tab' => $activeScheduleKindValue]) }}">
                        @csrf
                        <x-ui.action-button type="submit" icon="copy" :label="__('app.copy')" />
                    </form>
                    <x-ui.action-button :href="route('dashboard.accounts.class-pass-plans.edit', [$account, $classPassPlan])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.class-pass-plans.destroy', [$account, $classPassPlan, 'tab' => $activeScheduleKindValue]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_class_pass_plans')" icon="class-pass-plans" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
