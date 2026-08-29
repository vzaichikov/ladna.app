@extends('layouts.app')

@section('title', ($nomination->exists ? __('app.festival_edit_nomination') : __('app.festival_add_nomination')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$nomination->exists ? __('app.festival_edit_nomination') : __('app.festival_add_nomination')" :copy="__('app.festival_nomination_form_copy')" />
    <x-ui.panel class="max-w-3xl">
        <form method="POST" action="{{ $nomination->exists ? route('dashboard.accounts.festivals.nominations.update', [$account, $edition, $nomination]) : route('dashboard.accounts.festivals.nominations.store', [$account, $edition]) }}" class="space-y-5">
            @csrf
            @if($nomination->exists) @method('PUT') @endif
            <label class="block"><span class="crm-label">{{ __('app.name') }}</span><input name="name" value="{{ old('name', $nomination->name) }}" maxlength="255" required class="crm-field">@error('name')<span class="crm-help">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="crm-label">{{ __('app.festival_nomination_explanation') }}</span><textarea name="description" rows="5" maxlength="5000" class="crm-field">{{ old('description', $nomination->description) }}</textarea>@error('description')<span class="crm-help">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="crm-label">{{ __('app.festival_nomination_presented_by') }}</span><input name="presented_by" value="{{ old('presented_by', $nomination->presented_by) }}" maxlength="255" class="crm-field">@error('presented_by')<span class="crm-help">{{ $message }}</span>@enderror</label>
            <label class="block"><span class="crm-label">{{ __('app.festival_nomination_prize') }}</span><textarea name="prize" rows="3" maxlength="1000" class="crm-field">{{ old('prize', $nomination->prize) }}</textarea>@error('prize')<span class="crm-help">{{ $message }}</span>@enderror</label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $nomination->is_active ?? true))>{{ __('app.active') }}</label>
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="hidden" name="show_in_mini_app" value="0"><input type="checkbox" name="show_in_mini_app" value="1" @checked(old('show_in_mini_app', $nomination->show_in_mini_app ?? false))>{{ __('app.festival_show_in_mini_app') }}</label>
            </div>
            <div class="flex flex-wrap gap-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.settings.nominations', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
