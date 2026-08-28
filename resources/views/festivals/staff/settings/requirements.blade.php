@extends('layouts.app')

@section('title', __('app.festival_registration_fields').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_registration_fields')" :copy="__('app.festival_registration_fields_page_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.requirements.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_registration_field') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    <x-festivals.settings-help :title="__('app.festival_registration_fields_help_title')" :description="__('app.festival_registration_fields_help_copy')" :dependencies="[__('app.festival_registration_workflows'), __('app.festival_categories'), __('app.festival_registration_fields'), __('app.festival_entries')]" />

    <x-ui.filter-bar :action="route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])" class="sm:grid-cols-2 xl:grid-cols-3">
        <label><span class="crm-label">{{ __('app.name') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_filter_name_placeholder') }}"></label>
        <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
        <label><span class="crm-label">{{ __('app.festival_category') }}</span><select name="category" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_registration_workflow_step') }}</span><select name="workflow_step" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($workflows as $workflow)@foreach ($workflow->steps as $step)<option value="{{ $step->id }}" @selected($filters['workflow_step'] === $step->id)>{{ $workflow->name }} · {{ $step->title }}</option>@endforeach @endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_input_type') }}</span><select name="input_type" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach (\App\Enums\FestivalRequirementInputType::cases() as $inputType)<option value="{{ $inputType->value }}" @selected($filters['input_type'] === $inputType->value)>{{ __('app.festival_input_'.$inputType->value) }}</option>@endforeach</select></label>
        <label><span class="crm-label">{{ __('app.festival_field_scope') }}</span><select name="scope" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach (\App\Enums\FestivalFieldScope::cases() as $scope)<option value="{{ $scope->value }}" @selected($filters['scope'] === $scope->value)>{{ __('app.festival_scope_'.$scope->value) }}</option>@endforeach</select></label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        <div class="hidden gap-3 border-b border-stone-100 px-5 py-4 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.55fr)_minmax(0,0.85fr)_15rem] lg:items-center" data-festival-registration-field-header>
            <div>{{ __('app.name') }}</div>
            <div>{{ __('app.festival_registration_workflow_step') }}</div>
            <div>{{ __('app.festival_media_report') }}</div>
            <div>{{ __('app.festival_allow_post_confirmation_edits') }}</div>
            <div class="text-right">{{ __('app.actions') }}</div>
        </div>

        @forelse ($requirements as $requirement)
            @php
                $globalIndex = ($requirements->firstItem() ?? 1) + $loop->index;
                $postConfirmationEditing = $postConfirmationEditingByRequirement->get($requirement->id);
                $editableUntil = $postConfirmationEditing['editable_until'] ?? null;
            @endphp
            <div class="crm-row lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.55fr)_minmax(0,0.85fr)_15rem] lg:items-center" data-festival-registration-field-row>
                <div><div class="flex flex-wrap items-center gap-2"><h2 class="font-semibold text-slate-950">{{ $requirement->name }}</h2>@unless ($requirement->is_active)<span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>@endunless</div><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_input_'.$requirement->input_type->value) }} · {{ __('app.festival_scope_'.$requirement->subject_scope->value) }}</p></div>
                <div><p class="text-xs font-semibold uppercase tracking-wide text-slate-500 lg:hidden">{{ __('app.festival_registration_workflow_step') }}</p><p class="mt-1 text-sm text-slate-500 lg:mt-0">{{ $requirement->workflowStep?->workflow?->name }} · {{ $requirement->workflowStep?->title }} · {{ $requirement->category?->name ?? __('app.all') }}</p></div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 lg:hidden">{{ __('app.festival_media_report') }}</p>
                    <span @class([
                        'mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold lg:mt-0',
                        'bg-emerald-100 text-emerald-800' => $requirement->show_in_media_report,
                        'bg-stone-100 text-stone-600' => ! $requirement->show_in_media_report,
                    ])>{{ $requirement->show_in_media_report ? __('app.yes') : __('app.no') }}</span>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 lg:hidden">{{ __('app.festival_allow_post_confirmation_edits') }}</p>
                    <span @class([
                        'mt-1 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold lg:mt-0',
                        'bg-emerald-100 text-emerald-800' => $postConfirmationEditing['allowed'] ?? false,
                        'bg-stone-100 text-stone-600' => ! ($postConfirmationEditing['allowed'] ?? false),
                    ])>{{ ($postConfirmationEditing['allowed'] ?? false) ? __('app.yes') : __('app.no') }}</span>
                    @if ($editableUntil)
                        <time class="mt-1 block text-xs leading-5 text-slate-500" datetime="{{ $editableUntil->toAtomString() }}">{{ __('app.festival_editable_until_value', ['date' => $editableUntil->timezone($edition->timezone)->format('d.m.Y H:i')]) }}</time>
                    @endif
                </div>
                <x-festivals.settings-actions
                    class="lg:w-60"
                    :active="$requirement->is_active"
                    :toggle-route="route('dashboard.accounts.festivals.requirements.toggle', [$account, $edition, $requirement])"
                    :move-route="route('dashboard.accounts.festivals.requirements.move', [$account, $edition, $requirement])"
                    :edit-route="route('dashboard.accounts.festivals.requirements.edit', [$account, $edition, $requirement])"
                    :show-ordering="$canReorder"
                    :can-move-up="$globalIndex > 1"
                    :can-move-down="$globalIndex < $requirements->total()"
                    :move-parameters="$filters['workflow_step'] > 0 ? ['ordering_scope' => 'workflow_step'] : []"
                    :delete-route="$requirement->entry_requirements_exists ? null : route('dashboard.accounts.festivals.requirements.destroy', [$account, $edition, $requirement])"
                    :delete-label="__('app.festival_delete_registration_field')"
                    :delete-confirm-title="__('app.festival_delete_registration_field_confirm_title')"
                    :delete-confirm-body="__('app.festival_delete_registration_field_confirm_body')"
                />
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_registration_fields_empty')" icon="clipboard-list" class="m-5">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_registration_fields_empty_copy') }}</x-ui.empty-state>
        @endforelse
    </x-ui.panel>
    <div>{{ $requirements->links() }}</div>
</x-festivals.staff.workspace>
@endsection
