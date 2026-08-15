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

    <x-festivals.workflow-steps-list :$account :$edition :$workflow :$steps :has-filters="$hasFilters" />
    <div>{{ $steps->links() }}</div>
</x-festivals.staff.workspace>
@endsection
