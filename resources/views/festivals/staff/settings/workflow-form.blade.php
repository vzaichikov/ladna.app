@extends('layouts.app')

@section('title', ($workflow->exists ? __('app.festival_edit_workflow') : __('app.festival_add_workflow')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$workflow->exists ? __('app.festival_edit_workflow') : __('app.festival_add_workflow')" :copy="__('app.festival_workflow_form_copy')" />

    <x-ui.panel class="max-w-4xl">
        <form method="POST" action="{{ $workflow->exists ? route('dashboard.accounts.festivals.workflows.update', [$account, $edition, $workflow]) : route('dashboard.accounts.festivals.workflows.store', [$account, $edition]) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf
            @if ($workflow->exists) @method('PUT') @endif
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.name') }}</span><input name="name" value="{{ old('name', $workflow->name ?: __('app.festival_standard_workflow')) }}" required class="crm-field">@error('name') <span class="crm-help">{{ $message }}</span> @enderror</label>
            @unless ($workflow->exists)
                <label><span class="crm-label">{{ __('app.festival_application_review') }}</span><select name="application_review_mode" class="crm-field"><option value="organizer" @selected(old('application_review_mode', 'organizer') === 'organizer')>{{ __('app.festival_review_mode_organizer') }}</option><option value="automatic" @selected(old('application_review_mode') === 'automatic')>{{ __('app.festival_review_mode_automatic') }}</option></select>@error('application_review_mode') <span class="crm-help">{{ $message }}</span> @enderror</label>
                <label><span class="crm-label">{{ __('app.festival_technical_review') }}</span><select name="technical_review_mode" class="crm-field"><option value="organizer" @selected(old('technical_review_mode', 'organizer') === 'organizer')>{{ __('app.festival_review_mode_organizer') }}</option><option value="automatic" @selected(old('technical_review_mode') === 'automatic')>{{ __('app.festival_review_mode_automatic') }}</option></select>@error('technical_review_mode') <span class="crm-help">{{ $message }}</span> @enderror</label>
            @endunless
            <div class="sm:col-span-2"><input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $workflow->is_active ?? true))>{{ __('app.active') }}</label></div>
            <div class="flex flex-wrap gap-2 sm:col-span-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.settings.workflows', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
