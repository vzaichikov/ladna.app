@extends('layouts.app')

@section('title', __('app.festival_criteria').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_criteria')" :copy="__('app.festival_criteria_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.judging.criteria.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_rubric') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])"
        class="sm:grid-cols-3"
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
        <label>
            <span class="crm-label">{{ __('app.festival_category') }}</span>
            <select name="category_id" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($rubrics as $rubric)
            @php($globalIndex = ($rubrics->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_180px_200px_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate font-semibold text-slate-950">{{ $rubric->name }}</h2>
                        @unless ($rubric->is_active)
                            <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                        @endunless
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $rubric->category?->name ?? __('app.festival_all_categories') }}</p>
                    @if ($rubric->uncovered_section_names !== [])
                        <p class="mt-2 text-sm font-medium text-amber-700">{{ __('app.festival_uncovered_sections', ['sections' => implode(', ', $rubric->uncovered_section_names)]) }}</p>
                    @endif
                </div>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_rubric_section_usage_count', $rubric->sections->count(), ['count' => $rubric->sections->count()]) }} · {{ trans_choice('app.festival_criterion_usage_count', $rubric->sections->sum('criteria_count'), ['count' => $rubric->sections->sum('criteria_count')]) }}</p>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_score_sheet_usage_count', $rubric->score_sheets_count, ['count' => $rubric->score_sheets_count]) }}</p>
                <x-festivals.settings-actions
                    :active="$rubric->is_active"
                    :toggle-route="route('dashboard.accounts.festivals.judging.criteria.toggle', [$account, $edition, $rubric])"
                    :move-route="route('dashboard.accounts.festivals.judging.criteria.move', [$account, $edition, $rubric])"
                    :edit-route="route('dashboard.accounts.festivals.judging.criteria.edit', [$account, $edition, $rubric])"
                    :show-ordering="! $hasFilters"
                    :can-move-up="$globalIndex > 1"
                    :can-move-down="$globalIndex < $rubrics->total()"
                />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_rubrics_empty')" icon="list-checks" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ __('app.festival_rubrics_empty_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $rubrics->links() }}</div>
</x-festivals.staff.workspace>
@endsection
