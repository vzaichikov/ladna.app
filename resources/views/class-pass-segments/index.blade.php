@extends('layouts.app')

@section('title', __('app.class_pass_segments').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.class_pass_segments') }}</h1>
            <p class="crm-page-copy">{{ __('app.class_pass_segments_copy') }}</p>
        </div>
        @if ($scheduleKindTabs !== [])
            <x-ui.button :href="route('dashboard.accounts.class-pass-segments.create', $account)">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.create_class_pass_segment') }}
            </x-ui.button>
        @endif
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @if ($scheduleKindTabs === [])
            <x-ui.empty-state :title="__('app.no_class_pass_eligible_formats')" icon="class-pass-plans" class="m-5" />
        @else
        @forelse ($classPassSegments as $classPassSegment)
            @php
                $scheduleKindValue = $classPassSegment->schedule_kind->value;
                $scheduleKindDefinition = $scheduleKindTabs[$scheduleKindValue] ?? null;
            @endphp
            <div class="crm-row lg:grid-cols-[1fr_0.7fr_1fr_0.5fr_auto] lg:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $classPassSegment->name }}</h2>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-slate-500">
                        <span>{{ $classPassSegment->slug }}</span>
                        <span>{{ __('app.sort_order') }}: {{ $classPassSegment->sort_order }}</span>
                    </div>
                </div>
                <div class="text-sm font-semibold text-slate-700">
                    {{ $scheduleKindDefinition ? __('app.'.$scheduleKindDefinition['title_key']) : __('app.'.$scheduleKindValue) }}
                </div>
                <div class="flex flex-wrap gap-2">
                    @forelse ($classPassSegment->activityDirections as $activityDirection)
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $activityDirection->name }}</span>
                    @empty
                        <span class="text-sm text-slate-500">{{ __('app.all_activity_directions') }}</span>
                    @endforelse
                </div>
                <div class="text-sm text-slate-500">
                    {{ __('app.class_pass_plans') }}: {{ $classPassSegment->class_pass_plans_count }}
                </div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <span class="{{ $classPassSegment->is_active ? 'crm-status-active' : 'crm-status-muted' }}">
                        {{ $classPassSegment->is_active ? __('app.active') : __('app.inactive') }}
                    </span>
                    <x-ui.action-button :href="route('dashboard.accounts.class-pass-segments.edit', [$account, $classPassSegment])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route('dashboard.accounts.class-pass-segments.destroy', [$account, $classPassSegment]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_class_pass_segments')" icon="class-pass-plans" class="m-5" />
        @endforelse
        @endif
    </x-ui.panel>
@endsection
