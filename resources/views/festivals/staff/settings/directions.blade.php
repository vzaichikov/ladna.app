@extends('layouts.app')

@section('title', __('app.festival_taxonomy_directions').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_taxonomy_directions')" :copy="__('app.festival_directions_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.directions.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_direction') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help
        :title="__('app.festival_directions_help_title')"
        :description="__('app.festival_directions_help_copy')"
        :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_categories'), __('app.festival_entries')]"
    />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.settings.directions', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.settings.directions', [$account, $edition])"
        class="sm:grid-cols-2"
    >
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($directions as $direction)
            @php($globalIndex = ($directions->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_180px_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $direction->name }}</h2>
                        @unless ($direction->is_active)
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                        @endunless
                    </div>
                </div>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_category_usage_count', $direction->categories_count, ['count' => $direction->categories_count]) }}</p>
                <x-festivals.settings-actions
                    :active="$direction->is_active"
                    :toggle-route="route('dashboard.accounts.festivals.directions.toggle', [$account, $edition, $direction])"
                    :move-route="route('dashboard.accounts.festivals.directions.move', [$account, $edition, $direction])"
                    :edit-route="route('dashboard.accounts.festivals.directions.edit', [$account, $edition, $direction])"
                    :show-ordering="! $hasFilters"
                    :can-move-up="$globalIndex > 1"
                    :can-move-down="$globalIndex < $directions->total()"
                />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_directions_empty')" icon="compass" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.settings.directions', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_add_direction_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $directions->links() }}</div>
</x-festivals.staff.workspace>
@endsection
