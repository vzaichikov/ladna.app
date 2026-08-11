@extends('layouts.app')

@section('title', __('app.festival_categories').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_categories')" :copy="__('app.festival_categories_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.categories.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_category') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help
        :title="__('app.festival_categories_help_title')"
        :description="__('app.festival_categories_help_copy')"
        :dependencies="[__('app.festival_taxonomy_directions'), __('app.festival_categories'), __('app.festival_registration_workflows'), __('app.festival_registration_fields'), __('app.festival_entries')]"
    />

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.settings.categories', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.settings.categories', [$account, $edition])"
        class="sm:grid-cols-2 xl:grid-cols-4"
    >
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.festival_taxonomy_direction') }}</span><select name="direction" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($directions as $direction)<option value="{{ $direction->id }}" @selected($filters['direction'] === $direction->id)>{{ $direction->name }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_registration_workflow') }}</span><select name="workflow" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($workflows as $workflow)<option value="{{ $workflow->id }}" @selected($filters['workflow'] === $workflow->id)>{{ $workflow->name }}</option>@endforeach</select></label>
    </x-ui.filter-bar>

    <div class="space-y-4">
        @forelse ($categories as $category)
            @php($globalIndex = ($categories->firstItem() ?? 1) + $loop->index)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-slate-950">{{ $category->name }}</h2>
                            @unless ($category->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ $category->direction->name }} · {{ $category->registrationWorkflow?->name ?? __('app.festival_workflow_not_selected') }} · {{ trans_choice('app.festival_entry_usage_count', $category->entries_count, ['count' => $category->entries_count]) }}</p>
                        <dl class="mt-4 flex flex-wrap gap-2 text-xs text-slate-700">
                            <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $category->min_members, 'max' => $category->max_members]) }}</dd></div>
                            @if ($category->min_age !== null || $category->max_age !== null)<div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $category->min_age ?? '—', 'max' => $category->max_age ?? '—']) }}</dd></div>@endif
                            @if ($category->registration_closes_at)<div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>@endif
                        </dl>
                    </div>
                    <x-festivals.settings-actions
                        :active="$category->is_active"
                        :toggle-route="route('dashboard.accounts.festivals.categories.toggle', [$account, $edition, $category])"
                        :move-route="route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category])"
                        :edit-route="route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category])"
                        :show-ordering="! $hasFilters"
                        :can-move-up="$globalIndex > 1"
                        :can-move-down="$globalIndex < $categories->total()"
                    />
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_categories_empty')" icon="tags">
                {{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_categories_empty_copy') }}
                @if ($hasFilters)<div><x-ui.button :href="route('dashboard.accounts.festivals.settings.categories', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button></div>@endif
            </x-ui.empty-state>
        @endforelse
    </div>

    <div>{{ $categories->links() }}</div>
</x-festivals.staff.workspace>
@endsection
