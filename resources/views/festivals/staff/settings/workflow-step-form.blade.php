@extends('layouts.app')

@section('title', ($step->exists ? __('app.festival_edit_workflow_step') : __('app.festival_add_workflow_step')).' - '.$workflow->name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$step->exists ? __('app.festival_edit_workflow_step') : __('app.festival_add_workflow_step')" :copy="__('app.festival_workflow_step_form_copy', ['workflow' => $workflow->name])" />
    <x-ui.panel>
        <x-festivals.workflow-step-form :$account :$edition :$workflow :$step />
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
