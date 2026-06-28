@extends('layouts.app')

@section('title', __('app.edit').' '.$trainer->name)

@section('content')
    <h1 class="crm-page-title">{{ __('app.edit') }} {{ $trainer->name }}</h1>
    <p class="crm-page-copy">{{ $account->name }}</p>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px] xl:items-start">
        <form method="POST" action="{{ route('dashboard.accounts.trainers.update', [$account, $trainer]) }}" enctype="multipart/form-data" class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')
            @include('trainers.form-fields')
            <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
        </form>

        <x-ui.panel padding="none" class="overflow-hidden">
            <div class="border-b border-stone-100 px-5 py-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('app.trainer_substitutions') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.trainer_substitutions_copy') }}</p>
            </div>
            <div class="space-y-3 p-5">
                <x-ui.button type="button" class="w-full" data-trainer-substitution-open="classes">
                    <x-ui.icon name="calendar-plus" class="h-4 w-4" />
                    {{ __('app.add_single_trainer_substitution') }}
                </x-ui.button>
                <x-ui.button type="button" variant="secondary" class="w-full" data-trainer-substitution-open="period">
                    <x-ui.icon name="calendar-range" class="h-4 w-4" />
                    {{ __('app.add_period_trainer_substitution') }}
                </x-ui.button>
            </div>

            <div class="border-t border-stone-100">
                @forelse ($trainerSubstitutions as $substitution)
                    @php
                        $mode = $substitution->mode->value;
                        $classTypeIds = $substitution->class_type_ids ?? [];
                        $scheduledClassIds = $substitution->scheduled_class_ids ?? [];
                    @endphp
                    <div class="border-b border-stone-100 p-5 last:border-b-0">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-950">
                                    {{ $substitution->substituteTrainer?->name ?? $substitution->substitute_trainer_name ?? __('app.trainer_not_assigned') }}
                                </div>
                                <div class="mt-1 text-sm text-slate-500">
                                    {{ __('app.replaces_trainer', ['trainer' => $substitution->replacedTrainer?->name ?? $substitution->replaced_trainer_name ?? $trainer->name]) }}
                                </div>
                            </div>
                            <span class="{{ $substitution->isPeriodMode() ? 'crm-status-scheduled' : 'crm-status-muted' }}">
                                {{ $substitution->isPeriodMode() ? __('app.period') : __('app.single_classes') }}
                            </span>
                        </div>
                        <div class="mt-3 text-sm text-slate-600">
                            <div>{{ $substitution->date_from->toDateString() }} @if (! $substitution->date_from->isSameDay($substitution->date_to)) - {{ $substitution->date_to->toDateString() }} @endif</div>
                            <div class="mt-1">{{ $substitution->location?->name ?? $substitution->location_name }} · {{ $substitution->room?->name ?? $substitution->room_name }}</div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.action-button
                                type="button"
                                icon="edit"
                                :label="__('app.edit')"
                                data-trainer-substitution-edit="{{ $mode }}"
                                data-action="{{ route('dashboard.accounts.trainers.substitutions.update', [$account, $trainer, $substitution]) }}"
                                data-substitute-trainer-id="{{ $substitution->substitute_trainer_id }}"
                                data-location-id="{{ $substitution->location_id }}"
                                data-room-id="{{ $substitution->room_id }}"
                                data-date-from="{{ $substitution->date_from->toDateString() }}"
                                data-date-to="{{ $substitution->date_to->toDateString() }}"
                                data-scheduled-class-ids='@json($scheduledClassIds)'
                                data-class-type-ids='@json($classTypeIds)'
                            />
                            <form method="POST" action="{{ route('dashboard.accounts.trainers.substitutions.destroy', [$account, $trainer, $substitution]) }}" data-confirm-delete>
                                @csrf
                                @method('DELETE')
                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                            </form>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state :title="__('app.no_trainer_substitutions')" icon="calendar" class="m-5" />
                @endforelse
            </div>

            @if ($trainerSubstitutions->hasPages())
                <div class="border-t border-stone-100 px-5 py-4">
                    {{ $trainerSubstitutions->links() }}
                </div>
            @endif
        </x-ui.panel>
    </div>

    @include('trainers._substitution-modals')
@endsection
