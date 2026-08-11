@extends('layouts.app')

@section('title', __('app.festival_results').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_results')" :copy="__('app.festival_results_publish_copy')" />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])"
        class="sm:grid-cols-2"
    >
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_publication_state') }}</span>
            <select name="publication" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="published" @selected($filters['publication'] === 'published')>{{ __('app.published') }}</option>
                <option value="unpublished" @selected($filters['publication'] === 'unpublished')>{{ __('app.festival_unpublished') }}</option>
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($categories as $category)
            @php($published = $category->published_results_count > 0)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_190px_190px_auto] lg:items-center">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-950">{{ $category->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ trans_choice('app.festival_accepted_entry_usage_count', $category->accepted_entries_count, ['count' => $category->accepted_entries_count]) }}</p>
                </div>
                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold {{ $published ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-600' }}">{{ $published ? __('app.published') : __('app.festival_unpublished') }}</span>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_published_result_usage_count', $category->published_results_count, ['count' => $category->published_results_count]) }}</p>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.results.publish', [$account, $edition, $category]) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">
                        {{ $published ? __('app.festival_republish') : __('app.publish') }}
                    </x-ui.button>
                </form>
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
