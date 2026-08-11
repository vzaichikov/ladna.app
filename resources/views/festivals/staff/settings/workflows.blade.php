@extends('layouts.app')

@section('title', __('app.festival_registration_workflows').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_registration_workflows') }}</h1><p class="crm-page-copy">{{ __('app.festival_workflows_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_workflows_help_title')" :description="__('app.festival_workflows_help_copy')" :dependencies="[__('app.festival_registration_workflows'), __('app.festival_categories'), __('app.festival_registration_fields'), __('app.festival_fees'), __('app.festival_entries')]" />
    <div class="space-y-5">
        @foreach($edition->workflows as $workflow)
            @php($workflowEditId = 'festival-workflow-edit-'.$workflow->id)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div><h2 class="text-lg font-semibold">{{ $workflow->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ trans_choice('app.festival_category_usage_count', $workflow->categories_count, ['count' => $workflow->categories_count]) }}</p></div>
                    <x-festivals.settings-actions :active="$workflow->is_active" :toggle-route="route('dashboard.accounts.festivals.workflows.toggle', [$account, $edition, $workflow])" :move-route="route('dashboard.accounts.festivals.workflows.move', [$account, $edition, $workflow])" :edit-target="$workflowEditId" />
                </div>
                <form id="{{ $workflowEditId }}" method="POST" action="{{ route('dashboard.accounts.festivals.workflows.update', [$account, $edition, $workflow]) }}" class="mt-4 hidden gap-3 rounded-xl bg-stone-50 p-4 sm:grid-cols-[minmax(0,1fr)_auto]">
                    @csrf
                    @method('PUT')
                    <input name="name" value="{{ $workflow->name }}" required class="crm-field mt-0">
                    <input type="hidden" name="is_active" value="{{ $workflow->is_active ? 1 : 0 }}">
                    <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
                </form>
                <ol class="mt-5 space-y-3">
                    @foreach($workflow->steps as $step)
                        @php($stepEditId = 'festival-workflow-step-edit-'.$step->id)
                        <li class="rounded-xl border border-stone-200 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div><strong>{{ $loop->iteration }}. {{ $step->title }}</strong><p class="mt-1 text-xs text-slate-500">{{ __('app.festival_step_type_'.$step->type->value) }} · {{ __('app.festival_review_mode_'.$step->review_mode->value) }} · {{ __('app.festival_review_effect_'.$step->review_effect->value) }} · {{ trans_choice('app.festival_dependency_usage_count', $step->requirement_definitions_count + $step->charge_definitions_count, ['count' => $step->requirement_definitions_count + $step->charge_definitions_count]) }}</p></div>
                                <x-festivals.settings-actions :active="$step->is_active" :toggle-route="route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $step])" :move-route="route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $step])" :edit-target="$stepEditId" />
                            </div>
                            <div id="{{ $stepEditId }}" class="mt-3 hidden gap-3 rounded-xl bg-stone-50 p-4">
                                <x-festivals.workflow-step-form :$account :$edition :$workflow :$step />
                            </div>
                        </li>
                    @endforeach
                </ol>
                <details class="mt-4 rounded-xl bg-stone-50 p-4"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ __('app.festival_add_workflow_step') }}</summary><div class="mt-4"><x-festivals.workflow-step-form :$account :$edition :$workflow /></div></details>
            </article>
        @endforeach
    </div>
    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><h2 class="text-lg font-semibold">{{ __('app.festival_add_workflow') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_add_workflow_copy') }}</p><form method="POST" action="{{ route('dashboard.accounts.festivals.workflows.store', [$account, $edition]) }}" class="mt-4 grid gap-3 sm:grid-cols-3">@csrf<label><span class="crm-label">{{ __('app.name') }}</span><input name="name" value="{{ __('app.festival_standard_workflow') }}" required class="crm-field"></label><label><span class="crm-label">{{ __('app.festival_application_review') }}</span><select name="application_review_mode" class="crm-field"><option value="organizer">{{ __('app.festival_review_mode_organizer') }}</option><option value="automatic">{{ __('app.festival_review_mode_automatic') }}</option></select></label><label><span class="crm-label">{{ __('app.festival_technical_review') }}</span><select name="technical_review_mode" class="crm-field"><option value="organizer">{{ __('app.festival_review_mode_organizer') }}</option><option value="automatic">{{ __('app.festival_review_mode_automatic') }}</option></select></label><div class="sm:col-span-3"><x-ui.button type="submit">{{ __('app.create') }}</x-ui.button></div></form></section>
</x-festivals.staff.workspace>
@endsection
