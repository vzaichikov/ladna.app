@extends('layouts.app')

@section('title', __('app.festival_results').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_results')" :copy="__('app.festival_results_realtime_copy')" />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])"
    >
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}">
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($categories as $category)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-center">
                <div class="min-w-0">
                    @if ($category->direction)
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{{ $category->direction->name }}</p>
                    @endif
                    <h2 class="mt-1 truncate font-semibold text-slate-950">{{ $category->name }}</h2>
                </div>
                <span class="w-fit rounded-full bg-violet-crm-50 px-2.5 py-1 text-xs font-semibold text-violet-crm-700">
                    {{ trans_choice('app.festival_accepted_entry_usage_count', $category->accepted_entries_count, ['count' => $category->accepted_entries_count]) }}
                </span>
                <div class="flex flex-wrap gap-2"><x-ui.button :href="route('dashboard.accounts.festivals.judging.results.show', [$account, $edition, $category])" size="sm" variant="secondary">{{ __('app.festival_view_results') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.judging.results.table', [$account, $edition, $category])" size="sm">{{ __('app.festival_result_table') }}</x-ui.button></div>
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_results_categories_empty')" icon="trophy" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_results_categories_empty_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $categories->links() }}</div>
</x-festivals.staff.workspace>
@endsection
