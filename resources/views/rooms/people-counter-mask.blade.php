@extends('layouts.app')

@section('title', __('app.people_counter_mask_title').' - '.$room->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.people_counter_mask_title') }}</h1>
            <p class="crm-page-copy">{{ $room->location?->name }} · {{ $room->name }}</p>
        </div>
        <div class="flex flex-wrap gap-2 sm:justify-end">
            <x-ui.button :href="route('dashboard.accounts.rooms.edit', [$account, $room])" variant="secondary">
                {{ __('app.edit') }}
            </x-ui.button>
            <form method="POST" action="{{ route('dashboard.accounts.rooms.people-counter-mask.capture', [$account, $room]) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">
                    <x-ui.icon name="refresh" class="h-4 w-4" />
                    {{ __('app.capture_snapshot') }}
                </x-ui.button>
            </form>
        </div>
    </div>

    @if ($snapshotUrl)
        <form method="POST" action="{{ route('dashboard.accounts.rooms.people-counter-mask.update', [$account, $room]) }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')
            <input
                type="hidden"
                name="people_counter_mask_polygons"
                value="{{ old('people_counter_mask_polygons', json_encode($room->people_counter_mask_polygons ?? [])) }}"
                data-people-counter-mask-input
            >

            <section
                class="rounded-xl border border-stone-200 bg-white p-4 shadow-crm"
                data-people-counter-mask-editor
            >
                <div class="relative overflow-hidden rounded-lg bg-slate-950" data-people-counter-mask-stage>
                    <img
                        src="{{ $snapshotUrl }}"
                        alt=""
                        class="block h-auto w-full select-none"
                        draggable="false"
                        data-people-counter-mask-image
                    >
                    <canvas class="absolute inset-0 h-full w-full touch-none cursor-crosshair" data-people-counter-mask-canvas></canvas>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" variant="secondary" size="sm" data-people-counter-mask-finish>
                        {{ __('app.finish_zone') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" data-people-counter-mask-undo>
                        {{ __('app.undo') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" size="sm" data-people-counter-mask-clear>
                        {{ __('app.clear') }}
                    </x-ui.button>
                    <x-ui.button type="submit" size="sm">
                        {{ __('app.save') }}
                    </x-ui.button>
                </div>
            </section>

            @error('people_counter_mask_polygons') <span class="crm-help">{{ $message }}</span> @enderror
        </form>
    @else
        <x-ui.empty-state :title="__('app.people_counter_snapshot_required')" icon="video" class="mt-6" />
    @endif
@endsection
