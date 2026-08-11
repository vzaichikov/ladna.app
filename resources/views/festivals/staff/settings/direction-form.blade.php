@extends('layouts.app')

@section('title', ($direction->exists ? __('app.festival_edit_direction') : __('app.festival_add_direction')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$direction->exists ? __('app.festival_edit_direction') : __('app.festival_add_direction')"
        :copy="__('app.festival_direction_form_copy')"
    />

    <x-ui.panel class="max-w-3xl">
        <form method="POST" action="{{ $direction->exists ? route('dashboard.accounts.festivals.directions.update', [$account, $edition, $direction]) : route('dashboard.accounts.festivals.directions.store', [$account, $edition]) }}" class="space-y-5">
            @csrf
            @if ($direction->exists)
                @method('PUT')
            @endif
            <label class="block">
                <span class="crm-label">{{ __('app.festival_direction_name') }}</span>
                <input name="name" value="{{ old('name', $direction->name) }}" maxlength="255" required class="crm-field" placeholder="{{ __('app.festival_direction_name_placeholder') }}">
                @error('name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $direction->is_active ?? true))>
                {{ __('app.active') }}
            </label>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.settings.directions', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
