@extends('layouts.app')

@section('title', __('app.festival_registration_fields').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <header><p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p><h1 class="crm-page-title mt-2">{{ __('app.festival_registration_fields') }}</h1><p class="crm-page-copy">{{ __('app.festival_registration_fields_page_copy') }}</p></header>
    <x-festivals.settings-help :title="__('app.festival_registration_fields_help_title')" :description="__('app.festival_registration_fields_help_copy')" :dependencies="[__('app.festival_registration_workflows'), __('app.festival_categories'), __('app.festival_registration_fields'), __('app.festival_entries')]" />
    <div class="space-y-4">
        @foreach($requirements as $requirement)
            @php($requirementEditId = 'festival-requirement-edit-'.$requirement->id)
            <article class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div><h2 class="text-lg font-semibold">{{ $requirement->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('app.festival_input_'.$requirement->input_type->value) }} · {{ $requirement->workflowStep?->title }} · {{ $requirement->category?->name ?? __('app.all') }}</p></div>
                    <x-festivals.settings-actions :active="$requirement->is_active" :toggle-route="route('dashboard.accounts.festivals.requirements.toggle', [$account, $edition, $requirement])" :move-route="route('dashboard.accounts.festivals.requirements.move', [$account, $edition, $requirement])" :edit-target="$requirementEditId" />
                </div>
                <div id="{{ $requirementEditId }}" class="mt-4 hidden gap-3 rounded-xl bg-stone-50 p-4">
                    <x-festivals.requirement-form :$account :$edition :$requirement />
                </div>
            </article>
        @endforeach
    </div>
    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm"><h2 class="text-lg font-semibold">{{ __('app.festival_add_registration_field') }}</h2><div class="mt-4"><x-festivals.requirement-form :$account :$edition /></div></section>
</x-festivals.staff.workspace>
@endsection
