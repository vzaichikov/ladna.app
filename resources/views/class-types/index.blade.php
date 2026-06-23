@extends('layouts.app')

@section('title', __('app.'.$scheduleKindDefinition['title_key']).' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.'.$scheduleKindDefinition['title_key']) }}</h1>
            <p class="crm-page-copy">{{ __('app.'.$scheduleKindDefinition['copy_key']) }}</p>
        </div>
        <x-ui.button :href="route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'create'), $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.'.$scheduleKindDefinition['create_key']) }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($classTypes as $classType)
            <div class="crm-row md:grid-cols-[1.2fr_1fr_1fr_auto] md:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $classType->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $classType->activityDirection?->name ?? __('app.direction') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ $classType->default_duration_minutes }} {{ __('app.minutes') }}</div>
                <div class="text-sm text-slate-500">{{ __('app.'.$scheduleKindDefinition['capacity_label_key']) }} {{ $classType->default_capacity ?? __('app.capacity_not_set') }}</div>
                <div class="flex flex-wrap gap-2 md:justify-end">
                    <form method="POST" action="{{ route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'copy'), [$account, $classType]) }}">
                        @csrf
                        <x-ui.action-button type="submit" icon="copy" :label="__('app.copy')" />
                    </form>
                    <x-ui.action-button :href="route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'edit'), [$account, $classType])" icon="edit" :label="__('app.edit')" />
                    <form method="POST" action="{{ route(\App\Support\ScheduleKindRegistry::routeName($scheduleKind, 'destroy'), [$account, $classType]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.'.$scheduleKindDefinition['empty_key'])" :icon="$scheduleKindDefinition['icon']" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
