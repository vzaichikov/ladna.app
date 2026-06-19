@extends('layouts.app')

@section('title', __('app.class_types').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.class_types') }}</h1>
            <p class="crm-page-copy">{{ __('app.class_types_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.class-types.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_class_type') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($classTypes as $classType)
            <div class="crm-row md:grid-cols-[1.2fr_1fr_1fr_auto] md:items-center">
                <div>
                    <div class="font-semibold text-slate-950">{{ $classType->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $classType->activityDirection?->name ?? __('app.direction') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ __('app.'.$classType->schedule_kind->value) }}</div>
                <div class="text-sm text-slate-500">{{ $classType->default_duration_minutes }} {{ __('app.minutes') }} · {{ __('app.capacity') }} {{ $classType->default_capacity ?? 'TBA' }}</div>
                <div class="flex flex-wrap gap-2 md:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.class-types.edit', [$account, $classType])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('dashboard.accounts.class-types.destroy', [$account, $classType]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_class_types')" icon="class-types" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
