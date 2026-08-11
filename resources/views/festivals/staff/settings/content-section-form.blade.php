@extends('layouts.app')

@section('title', ($section->exists ? __('app.festival_edit_content_section') : __('app.festival_add_content_section')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="$section->exists ? __('app.festival_edit_content_section') : __('app.festival_add_content_section')" :copy="__('app.festival_content_section_form_copy')" />
    <x-ui.panel>
        <form method="POST" action="{{ $section->exists ? route('dashboard.accounts.festivals.content.update', [$account, $edition, $section]) : route('dashboard.accounts.festivals.content.store', [$account, $edition]) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf
            @if ($section->exists) @method('PUT') @endif
            <label><span class="crm-label">{{ __('app.title') }}</span><input name="title" value="{{ old('title', $section->title) }}" maxlength="255" required class="crm-field">@error('title') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label><span class="crm-label">{{ __('app.visibility') }}</span><select name="visibility" class="crm-field">@foreach (['public', 'portal', 'staff'] as $visibility)<option value="{{ $visibility }}" @selected(old('visibility', $section->visibility ?? 'public') === $visibility)>{{ __('app.festival_visibility_'.$visibility) }}</option>@endforeach</select>@error('visibility') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <label class="sm:col-span-2"><span class="crm-label">{{ __('app.content') }}</span><textarea name="body_html" rows="12" class="crm-field">{{ old('body_html', $section->body_html) }}</textarea>@error('body_html') <span class="crm-help">{{ $message }}</span> @enderror</label>
            <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $section->is_active ?? true))>{{ __('app.active') }}</label>
            <div class="flex flex-wrap justify-end gap-2"><x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button><x-ui.button :href="route('dashboard.accounts.festivals.settings.content.sections', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button></div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
