@extends('layouts.app')

@section('title', __('app.festival_scenes').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_scenes')" :copy="__('app.festival_scenes_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('help.show', 'festivals').'#help-section-festivals-program-scenes'" variant="secondary" target="_blank" rel="noopener">
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.help') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.stages.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_scene') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help
        :title="__('app.festival_scenes_help_title')"
        :description="__('app.festival_scenes_help_copy')"
        :dependencies="[__('app.festival_scenes'), __('app.festival_tab_program'), __('app.festival_program_items')]"
    />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])"
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
        @forelse ($stages as $stage)
            @php($globalIndex = ($stages->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_180px_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $stage->name }}</h2>
                        @unless ($stage->is_active)
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                        @endunless
                    </div>
                    @if ($stage->description)
                        <p class="mt-1 text-sm text-slate-500">{{ $stage->description }}</p>
                    @endif
                </div>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_scene_item_count', $stage->slots_count, ['count' => $stage->slots_count]) }}</p>
                <div class="flex items-center justify-end gap-1">
                    <x-ui.action-button :href="route('dashboard.accounts.festivals.timeline.show', [$account, $edition, $stage])" icon="timer" :label="__('app.festival_timeline_open_scene', ['scene' => $stage->name])" />
                    <x-festivals.settings-actions
                        :active="$stage->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.stages.toggle', [$account, $edition, $stage])"
                        :move-route="route('dashboard.accounts.festivals.stages.move', [$account, $edition, $stage])"
                        :edit-route="route('dashboard.accounts.festivals.stages.edit', [$account, $edition, $stage])"
                        :show-ordering="! $hasFilters"
                        :can-move-up="$globalIndex > 1"
                        :can-move-down="$globalIndex < $stages->total()"
                    />
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_scenes_empty')" icon="theater" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_add_scene_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $stages->links() }}</div>
</x-festivals.staff.workspace>
@endsection
