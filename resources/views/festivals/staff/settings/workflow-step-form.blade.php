@extends('layouts.app')

@section('title', ($step->exists ? __('app.festival_edit_workflow_step') : __('app.festival_add_workflow_step')).' - '.$workflow->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$step->exists ? __('app.festival_edit_workflow_step') : __('app.festival_add_workflow_step')" :copy="__('app.festival_workflow_step_form_copy', ['workflow' => $workflow->name])" />

    @if ($step->exists)
        <nav class="grid gap-1 rounded-lg bg-stone-100 p-1 sm:inline-grid sm:grid-flow-col" aria-label="{{ __('app.festival_workflow_step_tabs') }}">
            <a href="{{ route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $step, 'tab' => 'details']) }}" class="crm-tab justify-start sm:justify-center" @if (($activeTab ?? 'details') === 'details') aria-current="page" @endif>
                {{ __('app.festival_workflow_step_tab_details') }}
            </a>
            <a href="{{ route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $step, 'tab' => 'completion-notifications']) }}" class="crm-tab justify-start sm:justify-center" @if (($activeTab ?? 'details') === 'completion-notifications') aria-current="page" @endif>
                {{ __('app.festival_workflow_step_tab_completion_notifications') }}
            </a>
        </nav>
    @endif

    <x-ui.panel>
        @if ($step->exists && ($activeTab ?? 'details') === 'completion-notifications')
            <x-festivals.workflow-step-completion-notifications-form :$account :$edition :$workflow :$step />
        @else
            <x-festivals.workflow-step-form :$account :$edition :$workflow :$step :has-summary-step="$hasSummaryStep" />
        @endif
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
