@extends('layouts.app')

@section('title', __('app.festival_taxonomy_directions').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header>
        <p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p>
        <h1 class="crm-page-title mt-2">{{ __('app.festival_taxonomy_directions') }}</h1>
        <p class="crm-page-copy">{{ __('app.festival_directions_page_copy') }}</p>
    </header>

    <x-festivals.settings-help
        :title="__('app.festival_directions_help_title')"
        :description="__('app.festival_directions_help_copy')"
        :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_categories'), __('app.festival_entries')]"
    />

    <div class="space-y-4">
        @forelse($directions as $direction)
            @php($directionEditId = 'festival-direction-edit-'.$direction->id)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">{{ $direction->name }}</h2>
                            @unless($direction->is_active)
                                <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                            @endunless
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ trans_choice('app.festival_category_usage_count', $direction->categories_count, ['count' => $direction->categories_count]) }}</p>
                    </div>
                    <x-festivals.settings-actions
                        :active="$direction->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.directions.toggle', [$account, $edition, $direction])"
                        :move-route="route('dashboard.accounts.festivals.directions.move', [$account, $edition, $direction])"
                        :edit-target="$directionEditId"
                        class="lg:justify-end"
                    />
                </div>

                <form id="{{ $directionEditId }}" method="POST" action="{{ route('dashboard.accounts.festivals.directions.update', [$account, $edition, $direction]) }}" class="mt-4 hidden gap-3 rounded-xl bg-stone-50 p-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                    @csrf
                    @method('PUT')
                    <label>
                        <span class="crm-label">{{ __('app.festival_direction_name') }}</span>
                        <input name="name" value="{{ old('name', $direction->name) }}" maxlength="255" required class="crm-field">
                        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <input type="hidden" name="is_active" value="{{ $direction->is_active ? 1 : 0 }}">
                    <x-ui.button type="submit" class="self-end">
                        <x-ui.icon name="save" class="h-4 w-4" />
                        {{ __('app.save') }}
                    </x-ui.button>
                </form>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-stone-300 bg-white p-6 text-sm text-slate-600">{{ __('app.festival_directions_empty') }}</p>
        @endforelse
    </div>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <h2 class="text-lg font-semibold text-slate-950">{{ __('app.festival_add_direction') }}</h2>
        <p class="mt-1 text-sm leading-6 text-slate-600">{{ __('app.festival_add_direction_copy') }}</p>
        <form method="POST" action="{{ route('dashboard.accounts.festivals.directions.store', [$account, $edition]) }}" class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            @csrf
            <label>
                <span class="crm-label">{{ __('app.festival_direction_name') }}</span>
                <input name="name" value="{{ old('name') }}" maxlength="255" required class="crm-field" placeholder="{{ __('app.festival_direction_name_placeholder') }}">
                @error('name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <x-ui.button type="submit" class="self-end">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.add') }}
            </x-ui.button>
        </form>
    </section>
</x-festivals.staff.workspace>
@endsection
