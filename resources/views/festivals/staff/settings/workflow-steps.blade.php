@extends('layouts.app')

@section('title', __('app.festival_workflow_steps').' - '.$workflow->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_workflow_steps')" :copy="__('app.festival_workflow_steps_page_copy', ['workflow' => $workflow->name])">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.workflow-steps.create', [$account, $edition, $workflow])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_workflow_step') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow])" :reset-href="route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow])" class="sm:grid-cols-2 xl:grid-cols-4">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.festival_step_type') }}</span><select name="type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach (\App\Enums\FestivalWorkflowStepType::cases() as $type)<option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ __('app.festival_step_type_'.$type->value) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_review_mode') }}</span><select name="review_mode" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach (\App\Enums\FestivalWorkflowReviewMode::cases() as $mode)<option value="{{ $mode->value }}" @selected($filters['review_mode'] === $mode->value)>{{ __('app.festival_review_mode_'.$mode->value) }}</option>@endforeach</select></label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($steps as $step)
            @php($globalIndex = ($steps->firstItem() ?? 1) + $loop->index)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center">
                <div><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ $step->title }}</h2>@unless ($step->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_step_type_'.$step->type->value) }} · {{ __('app.festival_review_mode_'.$step->review_mode->value) }}</p></div>
                <p class="text-sm text-slate-500">{{ trans_choice('app.festival_dependency_usage_count', $step->requirement_definitions_count + $step->charge_definitions_count, ['count' => $step->requirement_definitions_count + $step->charge_definitions_count]) }}</p>
                <x-festivals.settings-actions :active="$step->is_active" :toggle-route="route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $step])" :move-route="route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $step])" :edit-route="route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $step])" :show-ordering="! $hasFilters" :can-move-up="$globalIndex > 1" :can-move-down="$globalIndex < $steps->total()" />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_workflow_steps_empty')" icon="list-tree" class="m-5">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_workflow_steps_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $steps->links() }}</div>
</x-festivals.staff.workspace>
@endsection
