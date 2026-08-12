@extends('layouts.app')

@section('title', __('app.festival_tab_performances').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_performances_title')" :copy="__('app.festival_performances_copy')" />

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-xl font-semibold">{{ __('app.festival_performances_title') }}</h2>
            <span class="text-sm font-semibold text-slate-500">{{ $entries->total() }}</span>
        </div>

        <x-ui.filter-bar
            :action="route('dashboard.accounts.festivals.performances', [$account, $edition])"
            :reset-href="route('dashboard.accounts.festivals.performances', [$account, $edition])"
            class="mt-5 sm:grid-cols-2"
        >
            <label>
                <span class="crm-label">{{ __('app.search') }}</span>
                <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_performance_search_placeholder') }}">
            </label>
            <label>
                <span class="crm-label">{{ __('app.festival_category') }}</span>
                <select name="category" class="crm-field">
                    <option value="">{{ __('app.all') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
        </x-ui.filter-bar>

        <div class="mt-5 space-y-3">
            @forelse ($entries as $entry)
                <article class="rounded-xl border border-stone-200 bg-slate-50/70 p-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                                <span class="crm-status-active">{{ __('app.festival_entry_status_accepted') }}</span>
                            </div>
                            <h3 class="mt-2 truncate text-lg font-semibold text-slate-950">{{ $entry->entry_name }}</h3>
                            <p class="text-sm text-slate-500">{{ $entry->category->direction->name }} · {{ $entry->category->name }}</p>
                            <p class="mt-1 text-sm text-slate-700">{{ __('app.festival_applicant') }}: {{ $entry->portalUser->displayName() }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button :href="route('dashboard.accounts.festivals.performances.show', [$account, $edition, $entry])" variant="secondary">
                                <x-ui.icon name="eye" class="h-4 w-4" />{{ __('app.festival_readonly_summary') }}
                            </x-ui.button>
                            <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])">
                                <x-ui.icon name="edit" class="h-4 w-4" />{{ __('app.festival_open_application') }}
                            </x-ui.button>
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_performances_empty')" icon="trophy">
                    @if ($hasFilters)
                        <x-ui.button :href="route('dashboard.accounts.festivals.performances', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @endforelse
        </div>

        <div class="mt-5">{{ $entries->links() }}</div>
    </section>
</x-festivals.staff.workspace>
@endsection
