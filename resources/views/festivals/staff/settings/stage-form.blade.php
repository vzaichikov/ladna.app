@extends('layouts.app')

@section('title', ($stage->exists ? __('app.festival_edit_scene') : __('app.festival_add_scene')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$stage->exists ? __('app.festival_edit_scene') : __('app.festival_add_scene')"
        :copy="__('app.festival_scene_form_copy')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('help.show', 'festivals').'#help-section-festivals-program-scenes'" variant="secondary" target="_blank" rel="noopener">
                <x-ui.icon name="circle-help" class="h-4 w-4" />
                {{ __('app.help') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel class="max-w-3xl">
        <form method="POST" action="{{ $stage->exists ? route('dashboard.accounts.festivals.stages.update', [$account, $edition, $stage]) : route('dashboard.accounts.festivals.stages.store', [$account, $edition]) }}" class="space-y-5">
            @csrf
            @if ($stage->exists)
                @method('PUT')
            @endif
            <label class="block">
                <span class="crm-label">{{ __('app.name') }}</span>
                <input name="name" value="{{ old('name', $stage->name) }}" maxlength="255" required class="crm-field" placeholder="{{ __('app.festival_scene_name_placeholder') }}">
                @error('name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.description') }}</span>
                <textarea name="description" rows="4" maxlength="3000" class="crm-field">{{ old('description', $stage->description) }}</textarea>
                @error('description') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $stage->is_active ?? true)) class="crm-checkbox">
                {{ __('app.active') }}
            </label>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.settings.stages', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
