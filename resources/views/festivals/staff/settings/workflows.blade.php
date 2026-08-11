@extends('layouts.app')

@section('title', __('app.festival_registration_workflows').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_registration_workflows')" :copy="__('app.festival_workflows_page_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.workflows.create', [$account, $edition])">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_workflow') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help :title="__('app.festival_workflows_help_title')" :description="__('app.festival_workflows_help_copy')" :dependencies="[__('app.festival_registration_workflows'), __('app.festival_categories'), __('app.festival_registration_fields'), __('app.festival_fees'), __('app.festival_entries')]" />

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.workflows', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.workflows', [$account, $edition])" class="sm:grid-cols-2">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
    </x-ui.filter-bar>

    <div class="space-y-4">
        @forelse ($workflows as $workflow)
            @php($globalIndex = ($workflows->firstItem() ?? 1) + $loop->index)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><h2 class="text-lg font-semibold text-slate-950">{{ $workflow->name }}</h2>@unless ($workflow->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div>
                        <p class="mt-1 text-sm text-slate-500">{{ trans_choice('app.festival_category_usage_count', $workflow->categories_count, ['count' => $workflow->categories_count]) }} · {{ trans_choice('app.festival_workflow_steps_count', $workflow->steps_count, ['count' => $workflow->steps_count]) }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-ui.action-button :href="route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow])" icon="list-tree" :label="__('app.festival_manage_workflow_steps')" />
                        <x-festivals.settings-actions
                            :active="$workflow->is_active"
                            :toggle-route="route('dashboard.accounts.festivals.workflows.toggle', [$account, $edition, $workflow])"
                            :move-route="route('dashboard.accounts.festivals.workflows.move', [$account, $edition, $workflow])"
                            :edit-route="route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow])"
                            :show-ordering="! $hasFilters"
                            :can-move-up="$globalIndex > 1"
                            :can-move-down="$globalIndex < $workflows->total()"
                        />
                    </div>
                </div>
            </article>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_workflows_empty')" icon="workflow">
                {{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_add_workflow_copy') }}
                @if ($hasFilters)<div><x-ui.button :href="route('dashboard.accounts.festivals.settings.workflows', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button></div>@endif
            </x-ui.empty-state>
        @endforelse
    </div>

    <div>{{ $workflows->links() }}</div>
</x-festivals.staff.workspace>
@endsection
